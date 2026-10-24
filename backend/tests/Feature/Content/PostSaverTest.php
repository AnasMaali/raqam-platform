<?php

use App\Content\PostRuleViolation;
use App\Content\PostSaver;
use App\Events\PostChanged;
use App\Media\MediaInUse;
use App\Media\MediaTrash;
use App\Media\MediaUploader;
use App\Models\Author;
use App\Models\Category;
use App\Models\Guest;
use App\Models\Organization;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake();
    ensurePostKinds();
    $this->user = User::factory()->staff()->create();
    $this->actingAs($this->user);
    $this->saver = app(PostSaver::class);
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'غلاف'], null)->asset;
    $this->energy = Category::where('slug', 'طاقة')->value('id');
});

/** Everything a news item needs to be published, to be overridden by the test. */
function newsData(array $extra = []): array
{
    return ['title' => 'ارتفاع أسعار الذهب في نابلس', 'body' => '<p>نص الخبر</p>', 'category_id' => test()->energy, 'cover_media_id' => test()->cover->id, ...$extra];
}

function violation(Closure $do): PostRuleViolation
{
    try {
        $do();
    } catch (PostRuleViolation $e) {
        return $e;
    }
    test()->fail('Nothing was refused.');
}

function draft(string $kind = 'news', string $title = 'مسودة'): Post
{
    return app(PostSaver::class)->create($kind, $title, auth()->id());
}

// ---- creating ------------------------------------------------------------------------------------

test('a new item is a draft with its kind, a slug made from the title and the person who made it', function () {
    $post = $this->saver->create('news', 'ارتفاع أسعار الذهب في نابلس اليوم', $this->user->id);

    expect($post)->status->toBe('draft')->kind_code->toBe('news')->slug->toBe('ارتفاع-أسعار-الذهب-نابلس-اليوم')
        ->created_by->toBe($this->user->id)->updated_by->toBe($this->user->id)->published_at->toBeNull();
});

test('a title is needed, and the kind must exist', function () {
    expect(violation(fn () => $this->saver->create('news', '   ', $this->user->id))->field)->toBe('title');
    expect(violation(fn () => $this->saver->create('news', str_repeat('ا', 256), $this->user->id))->field)->toBe('title');
    expect(violation(fn () => $this->saver->create('podcastx', 'عنوان', $this->user->id))->field)->toBe('kind_code');
});

test('the first revision is made with the item', function () {
    $post = draft();

    expect(DB::table('post_revisions')->where('post_id', $post->id)->pluck('revision_no')->all())->toBe([1]);
});

// ---- slugs ---------------------------------------------------------------------------------------

test('the address leaves out filler words but keeps the title whole when little would be left', function (string $title, string $slug) {
    expect(draft('news', $title)->slug)->toBe($slug);
})->with([
    ['أسعار الذهب في نابلس اليوم', 'أسعار-الذهب-نابلس-اليوم'],
    ['هل يرتفع الدولار؟', 'يرتفع-الدولار'],
    ['من هو', 'من-هو'],
    ['في', 'في'],
    ['الاقتصاد الرقمي', 'الاقتصاد-الرقمي'],
    ['اقتِصَادُ فلسطيـــن', 'اقتصاد-فلسطين'],
    ['؟؟ !!', 'post'],
]);

test('an address is at most twelve words', function () {
    expect(explode('-', draft('news', implode(' ', array_map(fn ($i) => "كلمة{$i}", range(1, 30))))->slug))->toHaveCount(12);
});

test('the editor can set the address by hand, and it is cleaned', function () {
    $post = draft();

    $this->saver->save($post, newsData(['slug' => 'عنوان / مخصص؟ #1']), $this->user->id);

    expect($post->fresh()->slug)->toBe('عنوان-مخصص-1');
});

test('an empty address is made from the title again', function () {
    $post = draft('news', 'عنوان أول');

    $this->saver->save($post, newsData(['title' => 'عنوان ثان', 'slug' => '']), $this->user->id);

    expect($post->fresh()->slug)->toBe('عنوان-ثان');
});

// ---- redirects -----------------------------------------------------------------------------------

test('changing the address of a published item redirects the old one with a 301', function () {
    $post = draft();
    $this->saver->save($post, newsData(['status' => 'published']), $this->user->id);
    $old = "/news/{$post->id}/".$post->fresh()->slug;

    $this->saver->save($post->fresh(), newsData(['status' => 'published', 'slug' => 'عنوان-جديد']), $this->user->id);

    $redirect = DB::table('redirects')->where('from_path', $old)->sole();
    expect($redirect)->to_path->toBe("/news/{$post->id}/عنوان-جديد")->status_code->toBe(301)->created_by->toBe($this->user->id);
});

test('a draft that was never published leaves no redirect behind when its address changes', function () {
    $post = draft();

    $this->saver->save($post, newsData(['slug' => 'غيّرته']), $this->user->id);
    $this->saver->save($post->fresh(), newsData(['slug' => 'غيّرته-ثانية']), $this->user->id);

    expect(DB::table('redirects')->count())->toBe(0);
});

test('redirects never chain: the oldest address goes straight to the newest', function () {
    $post = draft();
    $this->saver->save($post, newsData(['status' => 'published', 'slug' => 'أ']), $this->user->id);
    $this->saver->save($post->fresh(), newsData(['status' => 'published', 'slug' => 'ب']), $this->user->id);
    $this->saver->save($post->fresh(), newsData(['status' => 'published', 'slug' => 'ج']), $this->user->id);

    expect(DB::table('redirects')->orderBy('from_path')->pluck('to_path', 'from_path')->all())
        ->toBe(["/news/{$post->id}/أ" => "/news/{$post->id}/ج", "/news/{$post->id}/ب" => "/news/{$post->id}/ج"]);
});

test('going back to an earlier address removes that redirect, so the address is not both live and redirected', function () {
    $post = draft();
    $this->saver->save($post, newsData(['status' => 'published', 'slug' => 'أ']), $this->user->id);
    $this->saver->save($post->fresh(), newsData(['status' => 'published', 'slug' => 'ب']), $this->user->id);
    $this->saver->save($post->fresh(), newsData(['status' => 'published', 'slug' => 'أ']), $this->user->id);

    expect(DB::table('redirects')->pluck('from_path')->all())->toBe(["/news/{$post->id}/ب"]);
});

test('the redirect follows the kind of the item', function () {
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 1]);
    $post = draft('episode', 'حلقة');
    $this->saver->save($post, newsData(['status' => 'published', 'slug' => 'أ', 'episode' => ['season_id' => $season, 'episode_number' => 1]]), $this->user->id);
    $this->saver->save($post->fresh(), newsData(['status' => 'published', 'slug' => 'ب', 'episode' => ['season_id' => $season, 'episode_number' => 1]]), $this->user->id);

    expect(DB::table('redirects')->value('from_path'))->toBe("/podcast/{$post->id}/أ");
});

test('deleting for good leaves 410 (gone) for the address, the bare address and every old address', function () {
    $post = draft();
    $this->saver->save($post, newsData(['status' => 'published', 'slug' => 'أ']), $this->user->id);
    $this->saver->save($post->fresh(), newsData(['status' => 'published', 'slug' => 'ب']), $this->user->id);

    $this->saver->trash($post->fresh());
    $this->saver->purge(Post::withTrashed()->find($post->id), $this->user->id);

    $rows = DB::table('redirects')->orderBy('from_path')->get();
    expect($rows->pluck('from_path')->all())->toBe(["/news/{$post->id}", "/news/{$post->id}/أ", "/news/{$post->id}/ب"])
        ->and($rows->pluck('status_code')->unique()->all())->toBe([410])
        ->and($rows->pluck('to_path')->filter()->all())->toBe([]);
    expect(Post::withTrashed()->find($post->id))->toBeNull();
});

test('a post in the trash leaves its addresses alone until it is purged, and restoring it removes any 410', function () {
    $post = draft();
    $this->saver->save($post, newsData(['status' => 'published']), $this->user->id);
    $this->saver->trash($post->fresh());
    expect(DB::table('redirects')->count())->toBe(0);

    DB::table('redirects')->insert(['from_path' => "/news/{$post->id}", 'status_code' => 410]);
    $this->saver->restore(Post::withTrashed()->find($post->id));

    expect(DB::table('redirects')->count())->toBe(0)->and(Post::find($post->id))->not->toBeNull();
});

// ---- publishing rules ----------------------------------------------------------------------------

test('a draft can be saved with almost nothing', function () {
    $post = draft();

    $this->saver->save($post, ['title' => 'مسودة فقط'], $this->user->id);

    expect($post->fresh())->title->toBe('مسودة فقط')->status->toBe('draft');
});

test('an item cannot leave draft without a cover, a body or (for news) a category', function (array $without, string $field) {
    $post = draft();

    expect(violation(fn () => $this->saver->save($post, array_diff_key(newsData(['status' => 'published']), array_flip($without)), $this->user->id))->field)->toBe($field);
    expect($post->fresh()->status)->toBe('draft');
})->with([
    'cover' => [['cover_media_id'], 'cover_media_id'],
    'body' => [['body'], 'body'],
    'category' => [['category_id'], 'category_id'],
]);

test('a body of only empty tags counts as no body', function () {
    $post = draft();

    expect(violation(fn () => $this->saver->save($post, newsData(['status' => 'published', 'body' => '<p></p><p> </p>']), $this->user->id))->field)->toBe('body');
});

test('publishing sets the time, scheduling needs a future time', function () {
    $post = draft();

    $this->saver->save($post, newsData(['status' => 'published']), $this->user->id);
    expect($post->fresh())->status->toBe('published')->and($post->fresh()->published_at->diffInSeconds(now(), true))->toBeLessThan(5);

    $later = draft('news', 'مجدول');
    expect(violation(fn () => $this->saver->save($later, newsData(['status' => 'scheduled']), $this->user->id))->field)->toBe('published_at');
    expect(violation(fn () => $this->saver->save($later, newsData(['status' => 'scheduled', 'published_at' => now()->subHour()]), $this->user->id))->field)->toBe('published_at');

    $this->saver->save($later, newsData(['status' => 'scheduled', 'published_at' => now()->addDay()]), $this->user->id);
    expect($later->fresh()->status)->toBe('scheduled');
});

test('a scheduled item published by hand goes live now, not at the planned time', function () {
    $post = draft();
    $this->saver->save($post, newsData(['status' => 'scheduled', 'published_at' => now()->addDays(2)]), $this->user->id);

    $this->saver->save($post->fresh(), newsData(['status' => 'published', 'published_at' => $post->fresh()->published_at]), $this->user->id);

    expect($post->fresh()->published_at->isFuture())->toBeFalse();
});

test('a published item can be pulled back to draft, archived and published again', function () {
    $post = draft();
    $this->saver->save($post, newsData(['status' => 'published']), $this->user->id);
    $at = $post->fresh()->published_at;

    foreach (['draft', 'archived', 'published'] as $status) {
        $this->saver->save($post->fresh(), newsData(['status' => $status]), $this->user->id);
        expect($post->fresh()->status)->toBe($status);
    }
    expect($post->fresh()->published_at->equalTo($at))->toBeTrue();
});

test('a draft that was never published cannot be archived', function () {
    expect(violation(fn () => $this->saver->save(draft(), newsData(['status' => 'archived']), $this->user->id))->field)->toBe('status');
});

test('an unknown status is refused', function () {
    expect(violation(fn () => $this->saver->save(draft(), newsData(['status' => 'deleted']), $this->user->id))->field)->toBe('status');
});

test('the kind cannot be changed after creation, even directly', function () {
    $post = draft();

    expect(fn () => DB::transaction(fn () => DB::table('posts')->where('id', $post->id)->update(['kind_code' => 'report'])))->toThrow(QueryException::class);
});

// ---- breaking, featured, sponsored, correction -----------------------------------------------------

test('breaking news has an end time after the publishing time and in the future', function () {
    $post = draft();
    $this->saver->save($post, newsData(['status' => 'published', 'breaking_until' => now()->addHours(3)]), $this->user->id);
    expect($post->fresh()->isBreaking())->toBeTrue();

    expect(violation(fn () => $this->saver->save($post->fresh(), newsData(['status' => 'published', 'breaking_until' => now()->subMinute()]), $this->user->id))->field)->toBe('breaking_until');

    $this->saver->save($post->fresh(), newsData(['status' => 'published']), $this->user->id);
    expect($post->fresh()->isBreaking())->toBeFalse()->and($post->fresh()->breaking_until)->toBeNull();
});

test('breaking cannot end before the scheduled publishing time', function () {
    $post = draft();

    expect(violation(fn () => $this->saver->save($post, newsData(['status' => 'scheduled', 'published_at' => now()->addDays(2), 'breaking_until' => now()->addDay()]), $this->user->id))->field)->toBe('breaking_until');
});

test('featured and sponsored are saved, and a sponsor must exist', function () {
    $org = Organization::create(['name' => 'راعٍ', 'slug' => 'sponsor']);
    $post = draft();

    $this->saver->save($post, newsData(['is_featured' => true, 'sponsor_organization_id' => $org->id]), $this->user->id);
    expect($post->fresh())->is_featured->toBeTrue()->and($post->fresh()->isSponsored())->toBeTrue();

    expect(violation(fn () => $this->saver->save($post->fresh(), newsData(['sponsor_organization_id' => 99999]), $this->user->id))->field)->toBe('sponsor_organization_id');
    expect(violation(fn () => $this->saver->save($post->fresh(), newsData(['sponsor_organization_id' => 'abc']), $this->user->id))->field)->toBe('sponsor_organization_id');

    $this->saver->save($post->fresh(), newsData(), $this->user->id);
    expect($post->fresh())->is_featured->toBeFalse()->and($post->fresh()->isSponsored())->toBeFalse();
});

test('a correction note is dated when written and the date is kept while the note is unchanged', function () {
    $post = draft();
    $this->saver->save($post, newsData(['status' => 'published', 'correction_note' => 'صُحّح الرقم']), $this->user->id);
    $at = $post->fresh()->corrected_at;
    expect($at)->not->toBeNull();

    $this->travel(5)->minutes();
    $this->saver->save($post->fresh(), newsData(['status' => 'published', 'correction_note' => 'صُحّح الرقم']), $this->user->id);
    expect($post->fresh()->corrected_at->equalTo($at))->toBeTrue();

    $this->saver->save($post->fresh(), newsData(['status' => 'published', 'correction_note' => 'صُحّح الرقم مرة ثانية']), $this->user->id);
    expect($post->fresh()->corrected_at->gt($at))->toBeTrue();

    $this->saver->save($post->fresh(), newsData(['status' => 'published', 'correction_note' => '']), $this->user->id);
    expect($post->fresh())->correction_note->toBeNull()->corrected_at->toBeNull();
});

// ---- cover, category -----------------------------------------------------------------------------

test('the cover must be a live public picture', function () {
    Storage::fake('media_local_private');
    $private = app(MediaUploader::class)->store(uploadFile(imageBytes(301, 200)), 'p.jpg', ['visibility' => 'private'], null)->asset;
    $pdf = app(MediaUploader::class)->store(uploadFile("%PDF-1.4\n%%EOF"), 'p.pdf', [], null)->asset;

    foreach ([$private->id, $pdf->id, 99999, 'abc', '1; drop table posts'] as $bad) {
        expect(violation(fn () => $this->saver->save(draft(), newsData(['cover_media_id' => $bad]), $this->user->id))->field)->toBe('cover_media_id');
    }
});

test('a cover in use stops its picture from going to the trash', function () {
    $this->saver->save(draft(), newsData(), $this->user->id);

    expect(fn () => app(MediaTrash::class)->trash($this->cover))->toThrow(MediaInUse::class);
});

test('the category must exist', function () {
    expect(violation(fn () => $this->saver->save(draft(), newsData(['category_id' => 99999]), $this->user->id))->field)->toBe('category_id');
});

// ---- text ----------------------------------------------------------------------------------------

test('the body is cleaned before it is saved and what comes out is what is stored', function () {
    $post = draft();

    $this->saver->save($post, newsData(['body' => '<p onclick="x()">نص <script>alert(1)</script><a href="javascript:alert(1)">رابط</a></p>']), $this->user->id);

    expect($post->fresh()->body)->not->toContain('script')->not->toContain('onclick')->not->toContain('javascript')->toContain('نص');
});

test('titles and summaries are trimmed, control characters removed and lengths enforced', function () {
    $post = draft();

    $this->saver->save($post, newsData(['title' => "  عنوان\x07 مرتب  ", 'subtitle' => '', 'excerpt' => '   ']), $this->user->id);
    expect($post->fresh())->title->toBe('عنوان مرتب')->subtitle->toBeNull()->excerpt->toBeNull();

    foreach (['title' => 256, 'subtitle' => 256, 'excerpt' => 501] as $field => $length) {
        expect(violation(fn () => $this->saver->save($post->fresh(), newsData([$field => str_repeat('ا', $length)]), $this->user->id))->field)->toBe($field);
    }
});

test('a title is still needed on a later save', function () {
    expect(violation(fn () => $this->saver->save(draft(), newsData(['title' => '  ']), $this->user->id))->field)->toBe('title');
});

// ---- revisions -----------------------------------------------------------------------------------

test('every change to the text makes a revision, a save without a change does not', function () {
    $post = draft();

    $this->saver->save($post, newsData(['body' => '<p>أول</p>']), $this->user->id);
    $this->saver->save($post->fresh(), newsData(['body' => '<p>أول</p>', 'is_featured' => true]), $this->user->id);
    $this->saver->save($post->fresh(), newsData(['body' => '<p>ثان</p>']), $this->user->id);

    $revisions = DB::table('post_revisions')->where('post_id', $post->id)->orderBy('revision_no')->get();
    expect($revisions->pluck('revision_no')->all())->toBe([1, 2, 3])
        ->and($revisions->pluck('body')->all())->toBe(['', '<p>أول</p>', '<p>ثان</p>'])
        ->and($revisions->last()->created_by)->toBe($this->user->id);
});

test('only the newest hundred revisions are kept', function () {
    $post = draft();

    foreach (range(1, PostSaver::MAX_REVISIONS + 5) as $i) {
        $this->saver->save($post->fresh(), newsData(['body' => "<p>نسخة {$i}</p>"]), $this->user->id);
    }

    $numbers = DB::table('post_revisions')->where('post_id', $post->id)->pluck('revision_no');
    expect($numbers)->toHaveCount(PostSaver::MAX_REVISIONS)->and($numbers->max())->toBe(PostSaver::MAX_REVISIONS + 6)->and($numbers->min())->toBe(7);
});

// ---- writers, tags, guests, gallery --------------------------------------------------------------

test('writers are saved in order and can be changed or cleared', function () {
    $a = Author::create(['name' => 'أ', 'slug' => 'a']);
    $b = Author::create(['name' => 'ب', 'slug' => 'b']);
    $post = draft();

    $this->saver->save($post, newsData(['authors' => [$b->id, $a->id]]), $this->user->id);
    expect($post->fresh()->authors->pluck('id')->all())->toBe([$b->id, $a->id]);

    $this->saver->save($post->fresh(), newsData(['authors' => [$a->id]]), $this->user->id);
    expect($post->fresh()->authors->pluck('id')->all())->toBe([$a->id]);

    $this->saver->save($post->fresh(), newsData(['authors' => []]), $this->user->id);
    expect($post->fresh()->authors)->toHaveCount(0);
});

test('a writer who does not exist or was deleted is refused', function () {
    $gone = Author::create(['name' => 'ح', 'slug' => 'gone']);
    $gone->delete();

    foreach ([[99999], [$gone->id], ['abc'], [-1]] as $bad) {
        expect(violation(fn () => $this->saver->save(draft(), newsData(['authors' => $bad]), $this->user->id))->field)->toBe('authors');
    }
});

test('data that is not sent leaves writers, tags and gallery alone', function () {
    $a = Author::create(['name' => 'أ', 'slug' => 'a']);
    $post = draft();
    $this->saver->save($post, newsData(['authors' => [$a->id], 'tags' => ['ذهب']]), $this->user->id);

    $this->saver->save($post->fresh(), newsData(), $this->user->id);

    expect($post->fresh()->authors)->toHaveCount(1)->and($post->fresh()->tags)->toHaveCount(1);
});

test('tags typed as names are found or created, deduplicated and replaced as a set', function () {
    Tag::create(['name_ar' => 'الذهب', 'slug' => 'الذهب']);
    $post = draft();

    $this->saver->save($post, newsData(['tags' => ['الذهب', 'الذهب', 'أسواق', '#أسواق']]), $this->user->id);
    expect($post->fresh()->tags->pluck('name_ar')->sort()->values()->all())->toBe(['أسواق', 'الذهب']);

    $this->saver->save($post->fresh(), newsData(['tags' => ['أسواق']]), $this->user->id);
    expect($post->fresh()->tags->pluck('name_ar')->all())->toBe(['أسواق'])->and(Tag::count())->toBe(2);
});

test('guests belong to episodes and sessions only, once each, with a valid part', function () {
    $guest = Guest::create(['name' => 'ضيف', 'slug' => 'g']);
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 1]);
    $episode = draft('episode', 'حلقة');

    $this->saver->save($episode, newsData(['guests' => [['guest_id' => $guest->id, 'role_code' => 'host']]]), $this->user->id);
    expect(DB::table('post_guests')->where('post_id', $episode->id)->value('role_code'))->toBe('host');

    expect(violation(fn () => $this->saver->save(draft(), newsData(['guests' => [['guest_id' => $guest->id, 'role_code' => 'guest']]]), $this->user->id))->field)->toBe('guests');
    expect(violation(fn () => $this->saver->save($episode->fresh(), newsData(['guests' => [['guest_id' => $guest->id, 'role_code' => 'king']]]), $this->user->id))->field)->toBe('guests');
    expect(violation(fn () => $this->saver->save($episode->fresh(), newsData(['guests' => [['guest_id' => $guest->id, 'role_code' => 'host'], ['guest_id' => $guest->id, 'role_code' => 'guest']]]), $this->user->id))->field)->toBe('guests');
    expect(violation(fn () => $this->saver->save($episode->fresh(), newsData(['guests' => [['guest_id' => 99999, 'role_code' => 'host']]]), $this->user->id))->field)->toBe('guests');
});

test('the gallery keeps its order, takes public pictures only and has a size limit', function () {
    $second = app(MediaUploader::class)->store(uploadFile(imageBytes(302, 200)), 'g.jpg', ['alt_text' => 'x'], null)->asset;
    $post = draft();

    $this->saver->save($post, newsData(['gallery' => [$second->id, $this->cover->id]]), $this->user->id);
    expect(DB::table('post_gallery_items')->where('post_id', $post->id)->orderBy('sort_order')->pluck('media_id')->map(fn ($i) => (int) $i)->all())->toBe([$second->id, $this->cover->id]);

    expect(violation(fn () => $this->saver->save($post->fresh(), newsData(['gallery' => [99999]]), $this->user->id))->field)->toBe('gallery');
    expect(violation(fn () => $this->saver->save($post->fresh(), newsData(['gallery' => range(1, PostSaver::MAX_GALLERY + 1)]), $this->user->id))->field)->toBe('gallery');
});

test('a picture in a gallery cannot be trashed', function () {
    $this->saver->save(draft(), newsData(['gallery' => [$this->cover->id, app(MediaUploader::class)->store(uploadFile(imageBytes(303, 200)), 'g.jpg', ['alt_text' => 'x'], null)->asset->id]]), $this->user->id);

    expect(fn () => app(MediaTrash::class)->trash($this->cover))->toThrow(MediaInUse::class);
});

// ---- body references -----------------------------------------------------------------------------

test('the database learns which pictures and posts the body uses, and forgets them when they are removed', function () {
    $other = insertPost(['status' => 'published', 'published_at' => now()->subDay(), 'cover_media_id' => $this->cover->id, 'body' => 'x', 'category_id' => $this->energy]);
    $post = draft();
    $block = fn ($id, $config) => '<div data-type="customBlock" data-id="'.$id.'" data-config=\''.json_encode($config).'\'></div>';

    $this->saver->save($post, newsData(['body' => '<p>نص</p>'.$block('image', ['media_id' => $this->cover->id]).$block('read-also', ['post_ids' => [$other]])]), $this->user->id);

    expect(DB::table('post_body_media')->where('post_id', $post->id)->pluck('media_id')->all())->toBe([$this->cover->id])
        ->and(DB::table('post_related')->where('post_id', $post->id)->pluck('related_post_id')->all())->toBe([$other]);

    $this->saver->save($post->fresh(), newsData(['body' => '<p>نص فقط</p>']), $this->user->id);

    expect(DB::table('post_body_media')->where('post_id', $post->id)->count())->toBe(0)->and(DB::table('post_related')->where('post_id', $post->id)->count())->toBe(0);
});

test('a picture used inside the text cannot be trashed', function () {
    $picture = app(MediaUploader::class)->store(uploadFile(imageBytes(304, 200)), 'b.jpg', ['alt_text' => 'x'], null)->asset;
    $this->saver->save(draft(), newsData(['body' => '<div data-type="customBlock" data-id="image" data-config=\'{"media_id":'.$picture->id.'}\'></div>']), $this->user->id);

    expect(fn () => app(MediaTrash::class)->trash($picture))->toThrow(MediaInUse::class);
});

test('a post does not list itself as related', function () {
    $post = draft();

    $this->saver->save($post, newsData(['body' => '<p>x</p><div data-type="customBlock" data-id="read-also" data-config=\'{"post_ids":['.$post->id.']}\'></div>']), $this->user->id);

    expect(DB::table('post_related')->count())->toBe(0);
});

// ---- autosave ------------------------------------------------------------------------------------

test('autosave stores the text of a draft without a revision, a redirect or an audit row', function () {
    $post = draft();
    $audits = DB::table('audit_log')->count();

    $this->saver->autosave($post, ['title' => 'عنوان محفوظ تلقائياً', 'body' => '<p>نص <script>x</script></p>', 'excerpt' => 'ملخص'], $this->user->id);

    expect($post->fresh())->title->toBe('عنوان محفوظ تلقائياً')->excerpt->toBe('ملخص')->and($post->fresh()->body)->toBe('<p>نص </p>');
    expect(DB::table('post_revisions')->where('post_id', $post->id)->count())->toBe(1)->and(DB::table('audit_log')->count())->toBe($audits);
});

test('autosave never touches a published item', function () {
    $post = draft();
    $this->saver->save($post, newsData(['status' => 'published']), $this->user->id);

    expect(fn () => $this->saver->autosave($post->fresh(), ['body' => '<p>غيّرت</p>'], $this->user->id))->toThrow(PostRuleViolation::class);
    expect($post->fresh()->body)->toBe('<p>نص الخبر</p>');
});

test('autosave keeps the old title when the title was cleared while typing, and cleans the text', function () {
    $post = draft('news', 'العنوان');

    $this->saver->autosave($post, ['title' => '', 'body' => '<p>a</p>'], $this->user->id);

    expect($post->fresh())->title->toBe('العنوان');
});

test('autosave ignores everything but the text fields', function () {
    $post = draft();

    $this->saver->autosave($post, ['status' => 'published', 'slug' => 'x', 'is_featured' => true, 'cover_media_id' => $this->cover->id, 'kind_code' => 'report'], $this->user->id);

    expect($post->fresh())->status->toBe('draft')->slug->toBe('مسودة')->is_featured->toBeFalse()->kind_code->toBe('news');
});

// ---- two editors ---------------------------------------------------------------------------------

test('saving something that changed elsewhere meanwhile is refused instead of overwriting it', function () {
    $post = draft();
    $opened = $this->saver->token($post);
    $this->travel(2)->seconds();
    $this->saver->save($post->fresh(), newsData(['title' => 'عنوان المحرر الأول']), $this->user->id);

    expect(fn () => $this->saver->save($post->fresh(), newsData(['title' => 'عنوان المحرر الثاني']), $this->user->id, $opened))->toThrow(PostRuleViolation::class, 'عُدّلت هذه المادة');
    expect($post->fresh()->title)->toBe('عنوان المحرر الأول');

    $this->saver->save($post->fresh(), newsData(['title' => 'بعد التحديث']), $this->user->id, $this->saver->token($post));
    expect($post->fresh()->title)->toBe('بعد التحديث');
});

// ---- trash ---------------------------------------------------------------------------------------

test('trash and restore are audited and announce the change', function () {
    Event::fake([PostChanged::class]);
    $post = draft();

    $this->saver->trash($post);
    $this->saver->restore(Post::withTrashed()->find($post->id));

    expect(DB::table('audit_log')->where('subject_table', 'posts')->where('subject_id', $post->id)->orderBy('id')->pluck('action')->all())->toBe(['create', 'delete', 'restore']);
    Event::assertDispatched(PostChanged::class, fn ($e) => $e->postId === $post->id && $e->change === 'trashed');
});

test('publishing and unpublishing are audited as such, and the text itself is not copied into the log', function () {
    $post = draft();
    $this->saver->save($post, newsData(['status' => 'published', 'body' => '<p>'.str_repeat('نص ', 100).'</p>']), $this->user->id);
    $this->saver->save($post->fresh(), newsData(['status' => 'draft']), $this->user->id);

    $actions = DB::table('audit_log')->where('subject_table', 'posts')->where('subject_id', $post->id)->orderBy('id')->pluck('action')->all();
    expect($actions)->toContain('publish', 'unpublish');

    $fields = DB::table('audit_log_changes')->join('audit_log', 'audit_log.id', '=', 'audit_id')->where('subject_id', $post->id)->pluck('field')->all();
    expect($fields)->toContain('body_length', 'status')->not->toContain('body');
});
