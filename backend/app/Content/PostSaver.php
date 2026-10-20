<?php

namespace App\Content;

use App\Audit\AuditLogger;
use App\Content\Subtypes\SubtypeRegistry;
use App\Events\PostChanged;
use App\Jobs\PublishScheduledPost;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Video;
use App\Support\ArabicSlug;
use App\Taxonomy\TagService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saves a post and everything that belongs to it in one transaction: its own columns, the facts of its kind,
 * its writers, tags, guests and gallery, the references its body makes, a revision of its text and, when its
 * address changed, the redirect (decisions 3, 21.7, 23.2). Rules the application can name are checked here
 * and refused in Arabic with the field they belong to; the database repeats the important ones as the last word.
 *
 * Two things from the experiment that preceded this: the "subtype row before leaving draft" rule is a deferred
 * trigger, so it is forced to run inside this method (and the mode is put back, because it lasts until the
 * outermost transaction ends); and a kind's row is never written with updateOrCreate.
 */
final class PostSaver
{
    public const MAX_GALLERY = 40;

    public const MAX_REVISIONS = 100;

    /** Kinds that have guests (5.1, 5.6). */
    private const GUEST_KINDS = ['episode', 'roundtable'];

    public function __construct(
        private BodySanitizer $sanitizer,
        private SubtypeRegistry $subtypes,
        private TagService $tags,
        private RedirectManager $redirects,
        private BodyReferences $references,
    ) {}

    /** A new draft of this kind. The first save of a new item: kind, title and nothing else is needed. */
    public function create(string $kind, string $title, int $userId): Post
    {
        $title = trim($title);
        if ($title === '') {
            throw new PostRuleViolation('اكتب عنواناً.', 'title');
        }
        if (mb_strlen($title) > 255) {
            throw new PostRuleViolation('العنوان أطول من 255 حرفاً.', 'title');
        }
        if (! DB::table('post_kinds')->where('code', $kind)->exists()) {
            throw new PostRuleViolation('نوع المادة غير صحيح.', 'kind_code');
        }

        $post = new Post;
        $post->forceFill([
            'kind_code' => $kind, 'title' => $title, 'slug' => $this->slugFor($title) ?: 'post', 'body' => '',
            'created_by' => $userId, 'updated_by' => $userId,
        ]);

        DB::transaction(function () use ($post) {
            $post->save();
            $this->snapshot($post, $post->updated_by);
        });

        return $post->refresh();
    }

    /**
     * An explicit save from the editor.
     *
     * @param  array<string, mixed>  $data  core columns, plus authors / tags / guests / gallery and the kind's own part
     *
     * @throws PostRuleViolation
     */
    public function save(Post $post, array $data, int $userId, ?string $expectedUpdatedAt = null): Post
    {
        $this->guardAgainstOverwrite($post, $expectedUpdatedAt);
        $oldSlug = $post->slug;
        $wasPublic = $post->status !== 'draft' || $post->published_at !== null;
        $oldStatus = $post->status;

        try {
            DB::transaction(function () use ($post, $data, $userId) {
                // The mode lasts until the outermost transaction ends, so say it on both sides: inside a
                // test or an import an earlier save must not leave the rule switched to IMMEDIATE.
                DB::statement('SET CONSTRAINTS posts_subtype_present DEFERRED');

                $this->writeColumns($post, $data, $userId);
                $this->writeRelations($post, $data);
                $this->writeKindFacts($post, $data);
                $this->addToSeasonPlaylist($post);
                $this->references->sync($post->id, $post->body);
                $this->snapshot($post, $userId);

                DB::statement('SET CONSTRAINTS posts_subtype_present IMMEDIATE');
                DB::statement('SET CONSTRAINTS posts_subtype_present DEFERRED');
            });
        } catch (QueryException $e) {
            throw $this->translate($e) ?? $e;
        }

        $post->refresh();

        if ($wasPublic && $oldSlug !== $post->slug) {
            $this->redirects->slugChanged($post->kind_code, $post->id, $oldSlug, $post->slug, $userId);
        }

        if ($post->status === 'scheduled') {
            // Exactly on time; the every-minute task is only the net under it.
            PublishScheduledPost::dispatch($post->id)->delay($post->published_at)->afterCommit();
        }

        PostChanged::dispatch($post->id, $post->status === 'published' || $oldStatus === 'published' ? 'content' : 'draft');

        return $post;
    }

    /**
     * The 30-second save while typing (decision 21.7): only the text of a draft, no revision, no redirect.
     * A post that is already published is never touched this way, because that would change the live article
     * before the editor decided to; for those the browser keeps a local copy.
     *
     * @param  array<string, mixed>  $data
     */
    public function autosave(Post $post, array $data, int $userId, ?string $expectedUpdatedAt = null): Post
    {
        if ($post->status !== 'draft') {
            throw new PostRuleViolation('الحفظ التلقائي للمسودات فقط.');
        }
        $this->guardAgainstOverwrite($post, $expectedUpdatedAt);

        $changes = [];
        foreach (['title' => 255, 'subtitle' => 255, 'excerpt' => 500] as $column => $max) {
            if (array_key_exists($column, $data)) {
                $value = $this->text($data[$column], $max, $column);
                if ($column === 'title' && $value === null) {
                    continue;   // a title cleared while typing is not a reason to lose the rest
                }
                $changes[$column] = $value;
            }
        }
        if (array_key_exists('body', $data)) {
            $changes['body'] = $this->body((string) $data['body']);
        }

        $post->fill($changes);
        if ($post->isDirty()) {
            $post->forceFill(['updated_by' => $userId])->saveQuietly();   // quietly: thirty-second saves must not fill the audit log
        }

        return $post->refresh();
    }

    /**
     * Puts the text of an earlier revision back (decision 3). The current text is kept as a revision first, so this can be undone.
     *
     * @throws PostRuleViolation
     */
    public function restoreRevision(Post $post, int $revisionNo, int $userId): Post
    {
        $revision = DB::table('post_revisions')->where('post_id', $post->id)->where('revision_no', $revisionNo)->first();
        if ($revision === null) {
            throw new PostRuleViolation('هذه النسخة غير موجودة.');
        }

        return $this->save($post, [
            'title' => $revision->title, 'subtitle' => $revision->subtitle, 'excerpt' => $revision->excerpt, 'body' => $revision->body,
            // Everything else stays as it is now.
            'slug' => $post->slug, 'category_id' => $post->category_id, 'cover_media_id' => $post->cover_media_id,
            'status' => $post->status, 'published_at' => $post->published_at, 'is_featured' => $post->is_featured,
            'breaking_until' => $post->breaking_until, 'sponsor_organization_id' => $post->sponsor_organization_id,
            'correction_note' => $post->correction_note,
        ], $userId);
    }

    /** Moves the post to the trash (90 days) and keeps its address answering. */
    public function trash(Post $post): void
    {
        DB::transaction(fn () => $post->delete());
        PostChanged::dispatch($post->id, 'trashed');
    }

    public function restore(Post $post): void
    {
        DB::transaction(function () use ($post) {
            $post->restore();
            $this->redirects->revived($post->kind_code, $post->id, $post->slug);
        });
        PostChanged::dispatch($post->id, 'restored');
    }

    /** Removes a post from the trash for good; its addresses answer "gone" from now on (23.6). */
    public function purge(Post $post, ?int $userId = null): void
    {
        $kind = $post->kind_code;
        $id = $post->id;
        $slug = $post->slug;

        DB::transaction(function () use ($post, $kind, $id, $slug, $userId) {
            $post->forceDelete();
            $this->redirects->gone($kind, $id, $slug, $userId);
        });
    }

    // ---- the post's own columns -----------------------------------------------------------------------

    private function writeColumns(Post $post, array $data, int $userId): void
    {
        $values = [];
        $values['title'] = $this->text($data['title'] ?? $post->title, 255, 'title') ?? throw new PostRuleViolation('اكتب عنواناً.', 'title');
        $values['subtitle'] = $this->text($data['subtitle'] ?? null, 255, 'subtitle');
        $values['excerpt'] = $this->text($data['excerpt'] ?? null, 500, 'excerpt');
        $values['body'] = $this->body((string) ($data['body'] ?? ''));
        $values['category_id'] = $this->categoryId($data['category_id'] ?? null);
        $values['cover_media_id'] = $this->coverId($data['cover_media_id'] ?? null);
        // A caller that does not mention the video (bulk status change, restoring a revision) leaves it as it is.
        $values['primary_video_id'] = array_key_exists('primary_video_id', $data) ? $this->videoId($data['primary_video_id']) : $post->primary_video_id;
        $values['is_featured'] = (bool) ($data['is_featured'] ?? false);
        $values['sponsor_organization_id'] = $this->organizationId($data['sponsor_organization_id'] ?? null);
        $values['slug'] = $this->slugFor($values['title'], $data['slug'] ?? null, $post);
        [$values['correction_note'], $values['corrected_at']] = $this->correction($post, $data['correction_note'] ?? null);

        $status = $data['status'] ?? $post->status;
        if (! array_key_exists($status, Post::STATUSES)) {
            throw new PostRuleViolation('الحالة غير صحيحة.', 'status');
        }
        $values += $this->publication($post, $status, $data['published_at'] ?? null, $values);
        $values['breaking_until'] = $this->breakingUntil($data['breaking_until'] ?? null, $values['published_at'] ?? $post->published_at, $post->breaking_until);

        $this->checkReadyFor($status, $post->kind_code, $values);

        $post->fill($values)->forceFill(['updated_by' => $userId])->save();
    }

    /** @return array{status: string, published_at: mixed} */
    private function publication(Post $post, string $status, mixed $given, array $values): array
    {
        $at = $this->moment($given, 'published_at') ?? $post->published_at;

        return match ($status) {
            'draft' => ['status' => 'draft', 'published_at' => $at],
            'scheduled' => (function () use ($at, $post) {
                $unchanged = $post->status === 'scheduled' && $at !== null && $post->published_at?->equalTo($at);
                if ($at === null || (! $unchanged && $at->lte(now()->addMinute()))) {
                    throw new PostRuleViolation('وقت الجدولة يجب أن يكون بعد دقيقة من الآن على الأقل.', 'published_at');
                }

                return ['status' => 'scheduled', 'published_at' => $at];
            })(),
            'published' => ['status' => 'published', 'published_at' => $at === null || $at->isFuture() ? now() : $at],
            'archived' => $post->published_at === null && $post->status === 'draft'
                ? throw new PostRuleViolation('لا يمكن أرشفة مسودة لم تُنشر.', 'status')
                : ['status' => 'archived', 'published_at' => $at ?? now()],
        };
    }

    /** The conditions for leaving draft, in words (decision 3); the table repeats them. */
    private function checkReadyFor(string $status, string $kind, array $values): void
    {
        if ($status === 'draft') {
            return;
        }

        if (($values['cover_media_id'] ?? null) === null) {
            throw new PostRuleViolation('الصورة الرئيسية إجبارية قبل النشر.', 'cover_media_id');
        }
        if (trim(strip_tags((string) $values['body'], '<div>')) === '') {
            throw new PostRuleViolation('نص المادة إجباري قبل النشر.', 'body');
        }
        if (($values['category_id'] ?? null) === null && DB::table('post_kinds')->where('code', $kind)->value('requires_category')) {
            throw new PostRuleViolation('هذا النوع يحتاج تصنيفاً قبل النشر.', 'category_id');
        }
    }

    private function slugFor(string $title, ?string $given = null, ?Post $post = null): string
    {
        $given = trim((string) $given);

        $slug = $given !== '' ? ArabicSlug::make($given) : ArabicSlug::forTitle($title);

        // An empty slug (a title of only punctuation) would be refused by the address rules.
        return $slug !== '' ? $slug : ($post?->slug ?: 'post');
    }

    /** @return array{0: ?string, 1: mixed} a correction note and when it was made (23.13: the note is dated) */
    private function correction(Post $post, mixed $note): array
    {
        $note = $this->text($note, 2000, 'correction_note');

        return match (true) {
            $note === null => [null, null],
            $note === $post->correction_note => [$note, $post->corrected_at],
            default => [$note, now()],
        };
    }

    private function breakingUntil(mixed $given, mixed $publishedAt, ?Carbon $stored): ?Carbon
    {
        $until = $this->moment($given, 'breaking_until');

        // The same end time as before (it may have passed meanwhile) is not a new choice to be judged.
        if ($until !== null && $stored !== null && $until->equalTo($stored)) {
            return $until;
        }

        if ($until !== null && $publishedAt instanceof Carbon && $until->lte($publishedAt)) {
            throw new PostRuleViolation('وقت انتهاء العاجل يجب أن يكون بعد وقت النشر.', 'breaking_until');
        }
        if ($until !== null && $until->isPast()) {
            throw new PostRuleViolation('وقت انتهاء العاجل يجب أن يكون في المستقبل.', 'breaking_until');
        }

        return $until;
    }

    private function moment(mixed $value, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new PostRuleViolation('التاريخ أو الوقت غير صحيح.', $field);
        }
    }

    private function text(mixed $value, int $max, string $field): ?string
    {
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value));
        if (mb_strlen($text) > $max) {
            throw new PostRuleViolation("النص أطول من {$max} حرفاً.", $field);
        }

        return $text === '' ? null : $text;
    }

    private function body(string $html): string
    {
        try {
            return $this->sanitizer->clean($html);
        } catch (\LengthException) {
            throw new PostRuleViolation('النص طويل جداً.', 'body');
        }
    }

    private function categoryId(mixed $id): ?int
    {
        $id = $this->key($id);

        if ($id !== null && ! DB::table('categories')->where('id', $id)->exists()) {
            throw new PostRuleViolation('التصنيف غير موجود.', 'category_id');
        }

        return $id;
    }

    private function organizationId(mixed $id): ?int
    {
        $id = $this->key($id);

        if ($id !== null && ! DB::table('organizations')->where('id', $id)->exists()) {
            throw new PostRuleViolation('الجهة الراعية غير موجودة.', 'sponsor_organization_id');
        }

        return $id;
    }

    private function coverId(mixed $id): ?int
    {
        $id = $this->key($id);

        if ($id !== null && ! MediaAsset::query()->publicImages()->whereKey($id)->exists()) {
            throw new PostRuleViolation('الصورة الرئيسية يجب أن تكون صورة عامة من مكتبة الوسائط.', 'cover_media_id');
        }

        return $id;
    }

    private function videoId(mixed $id): ?int
    {
        $id = $this->key($id);

        if ($id !== null && ! Video::query()->whereKey($id)->exists()) {
            throw new PostRuleViolation('الفيديو الرئيسي غير موجود في مكتبة الفيديو.', 'primary_video_id');
        }

        return $id;
    }

    /** A positive whole number or nothing: whatever else was sent never reaches a query. */
    private function key(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 && (int) $value < PHP_INT_MAX / 2 ? (int) $value : -1;
    }

    /** An episode's video joins its season's playlist (5.1, 8.9), at the end, once. */
    private function addToSeasonPlaylist(Post $post): void
    {
        if ($post->kind_code !== 'episode' || $post->primary_video_id === null) {
            return;
        }

        $playlist = DB::table('podcast_episodes')->join('podcast_seasons', 'podcast_seasons.id', '=', 'podcast_episodes.season_id')
            ->where('podcast_episodes.post_id', $post->id)->value('podcast_seasons.playlist_id');
        if ($playlist === null) {
            return;
        }

        DB::table('playlists')->where('id', $playlist)->lockForUpdate()->first();   // two editors saving at once must not take the same position
        if (! DB::table('playlist_videos')->where('playlist_id', $playlist)->where('video_id', $post->primary_video_id)->exists()) {
            DB::table('playlist_videos')->insert([
                'playlist_id' => $playlist, 'video_id' => $post->primary_video_id,
                'sort_order' => (int) DB::table('playlist_videos')->where('playlist_id', $playlist)->max('sort_order') + 1,
            ]);
        }
    }

    // ---- relations -------------------------------------------------------------------------------------

    private function writeRelations(Post $post, array $data): void
    {
        $this->syncAuthors($post, $data['authors'] ?? null);
        $this->syncTags($post, $data['tags'] ?? null);
        $this->syncGuests($post, $data['guests'] ?? null);
        $this->syncGallery($post, $data['gallery'] ?? null);
    }

    /** @param  list<int|string>|null  $ids  null leaves the writers as they are */
    private function syncAuthors(Post $post, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }

        $ids = array_values(array_unique(array_map(fn ($id) => $this->key($id) ?? 0, $ids)));
        $found = DB::table('authors')->whereIn('id', array_filter($ids, fn ($i) => $i > 0))->whereNull('deleted_at')->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (array_diff($ids, $found) !== []) {
            throw new PostRuleViolation('أحد الكتّاب غير موجود أو محذوف.', 'authors');
        }

        $this->replace($post, 'post_authors', 'authors', array_map(fn ($id, $i) => ['author_id' => $id, 'sort_order' => $i], $ids, array_keys($ids)), 'author_id');
    }

    /** @param  list<string>|null  $names */
    private function syncTags(Post $post, ?array $names): void
    {
        if ($names === null) {
            return;
        }

        $ids = $this->tags->fromNames($names)->pluck('id')->all();
        $this->replace($post, 'post_tags', 'tags', array_map(fn ($id) => ['tag_id' => $id], $ids), 'tag_id');
    }

    /** @param  list<array{guest_id: int|string, role_code: string}>|null  $guests */
    private function syncGuests(Post $post, ?array $guests): void
    {
        if ($guests === null) {
            return;
        }
        if ($guests !== [] && ! in_array($post->kind_code, self::GUEST_KINDS, true)) {
            throw new PostRuleViolation('الضيوف للحلقات والجلسات فقط.', 'guests');
        }

        $rows = [];
        $seen = [];
        foreach (array_values($guests) as $i => $guest) {
            $id = $this->key($guest['guest_id'] ?? null);
            $role = (string) ($guest['role_code'] ?? '');
            if ($id === null || $id < 1 || isset($seen[$id])) {
                throw new PostRuleViolation('ضيف مكرر أو غير صحيح.', 'guests');
            }
            $seen[$id] = true;
            $rows[] = ['guest_id' => $id, 'role_code' => $role, 'sort_order' => $i];
        }

        if ($rows !== []) {
            $live = DB::table('guests')->whereIn('id', array_column($rows, 'guest_id'))->whereNull('deleted_at')->count();
            if ($live !== count($rows)) {
                throw new PostRuleViolation('أحد الضيوف غير موجود أو محذوف.', 'guests');
            }
            $roles = DB::table('guest_roles')->whereIn('code', array_unique(array_column($rows, 'role_code')))->count();
            if ($roles !== count(array_unique(array_column($rows, 'role_code')))) {
                throw new PostRuleViolation('دور الضيف غير صحيح.', 'guests');
            }
        }

        $this->replace($post, 'post_guests', 'guests', $rows, 'guest_id', 'role_code');
    }

    /** @param  list<int|string>|null  $ids */
    private function syncGallery(Post $post, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }

        $ids = array_values(array_unique(array_map(fn ($id) => $this->key($id) ?? 0, $ids)));
        if (count($ids) > self::MAX_GALLERY) {
            throw new PostRuleViolation('الحد الأقصى للمعرض '.self::MAX_GALLERY.' صورة.', 'gallery');
        }
        $found = MediaAsset::query()->publicImages()->whereIn('id', array_filter($ids, fn ($i) => $i > 0))->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (array_diff($ids, $found) !== []) {
            throw new PostRuleViolation('صور المعرض يجب أن تكون صوراً عامة من مكتبة الوسائط.', 'gallery');
        }

        $this->replace($post, 'post_gallery_items', 'gallery', array_map(fn ($id, $i) => ['media_id' => $id, 'sort_order' => $i], $ids, array_keys($ids)), 'media_id');
    }

    /**
     * Replaces a post's rows in a link table with these, and writes the change to the audit trail if the list differs.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function replace(Post $post, string $table, string $field, array $rows, string $key, ?string $extra = null): void
    {
        $describe = fn (iterable $list) => collect($list)->map(fn ($r) => ((array) $r)[$key].($extra ? ':'.((array) $r)[$extra] : ''))->implode(',');

        $before = DB::table($table)->where('post_id', $post->id)->orderBy($table === 'post_tags' ? 'tag_id' : 'sort_order')->get();
        $beforeText = $describe($before);
        $afterText = $describe($rows);

        if ($beforeText === $afterText && count($before) === count($rows)) {
            return;
        }

        DB::table($table)->where('post_id', $post->id)->delete();
        foreach ($rows as $row) {
            DB::table($table)->insert(['post_id' => $post->id] + $row);
        }

        AuditLogger::record('update', 'posts', $post->id, [$field => [$beforeText, $afterText]]);
    }

    private function writeKindFacts(Post $post, array $data): void
    {
        $subtype = $this->subtypes->for($post->kind_code);
        if ($subtype === null) {
            return;
        }

        $changes = $subtype->save($post->id, (array) ($data[$post->kind_code] ?? []));
        if ($changes !== []) {
            AuditLogger::record('update', 'posts', $post->id, $changes);
        }
    }

    // ---- revisions, locking, errors --------------------------------------------------------------------

    /** A revision of the text if it differs from the last one (decision 3). */
    private function snapshot(Post $post, ?int $userId): void
    {
        $last = DB::table('post_revisions')->where('post_id', $post->id)->orderByDesc('revision_no')->first();

        if ($last !== null
            && $last->title === $post->title && $last->subtitle === $post->subtitle
            && $last->excerpt === $post->excerpt && $last->body === $post->body) {
            return;
        }

        DB::table('post_revisions')->insert([
            'post_id' => $post->id, 'revision_no' => ($last->revision_no ?? 0) + 1, 'title' => $post->title,
            'subtitle' => $post->subtitle, 'excerpt' => $post->excerpt, 'body' => $post->body, 'created_by' => $userId,
        ]);

        // Old revisions beyond the newest hundred are of no use to anyone.
        $keep = DB::table('post_revisions')->where('post_id', $post->id)->orderByDesc('revision_no')->limit(self::MAX_REVISIONS)->pluck('id');
        DB::table('post_revisions')->where('post_id', $post->id)->whereNotIn('id', $keep)->delete();
    }

    /**
     * What the editor holds on to between saves: the exact time the database last changed the row (the database
     * keeps microseconds, a PHP date does not). Passed back to save() to notice that somebody else saved meanwhile.
     */
    public function token(Post|int $post): string
    {
        return (string) DB::table('posts')->where('id', $post instanceof Post ? $post->id : $post)->value('updated_at');
    }

    /** Two tabs, or two editors: the one that saves second is told instead of silently winning. */
    private function guardAgainstOverwrite(Post $post, ?string $expected): void
    {
        if ($expected === null) {
            return;
        }

        if ($this->token($post) !== $expected) {
            throw new PostRuleViolation('عُدّلت هذه المادة من مكان آخر منذ فتحتها. حدّث الصفحة لترى آخر نسخة، وانسخ تعديلاتك قبل ذلك إن أردت.');
        }
    }

    private function translate(QueryException $e): ?PostRuleViolation
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'needs its') => new PostRuleViolation('لا يمكن نشر هذه المادة قبل تعبئة بيانات نوعها.'),
            str_contains($message, 'cannot change after creation') => new PostRuleViolation('نوع المادة لا يتغير بعد إنشائها.', 'kind_code'),
            str_contains($message, 'needs a category') => new PostRuleViolation('هذا النوع يحتاج تصنيفاً قبل النشر.', 'category_id'),
            $e->getCode() === '23505' && str_contains($message, 'podcast_episodes') => new PostRuleViolation('رقم الحلقة مستخدم في هذا الموسم.', 'episode.episode_number'),
            default => null,
        };
    }
}
