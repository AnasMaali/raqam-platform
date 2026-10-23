<?php

namespace App\Api;

use App\Analytics\ViewCounts;
use App\Content\BodyRenderer;
use App\Content\PostPath;
use App\Media\MediaStorage;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Video;
use App\Video\VideoView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Turns records into the shapes the contract (docs/api/openapi.yaml) promises. Nothing here is written back. */
final class Presenter
{
    /** @var array<int, array<string, mixed>> */
    private array $media = [];

    public function __construct(private MediaStorage $storage, private BodyRenderer $renderer, private VideoView $videos) {}

    /** The main video of a post, if a visitor can play it. @return array<string, mixed>|null */
    public function primaryVideo(Post $post): ?array
    {
        $video = $post->primary_video_id ? Video::query()->with(['youtube', 'upload', 'poster'])->find($post->primary_video_id) : null;

        return $video !== null && $this->videos->playable($video) ? $this->videos->payload($video) : null;
    }

    /** Loads pictures in two queries so a list of twelve does not ask twelve times. */
    public function preload(iterable $ids): void
    {
        $ids = collect($ids)->filter()->map(fn ($i) => (int) $i)->unique()->reject(fn ($i) => isset($this->media[$i]))->values();
        if ($ids->isEmpty()) {
            return;
        }

        $assets = MediaAsset::query()->where('visibility', 'public')->whereIn('id', $ids)->get()->keyBy('id');
        $variants = DB::table('media_variants')->whereIn('media_id', $ids)->get()->groupBy('media_id');

        foreach ($ids as $id) {
            $this->media[$id] = ($asset = $assets->get($id)) ? $this->buildMedia($asset, $variants->get($id, collect())) : [];
        }
    }

    /** @return array<string, mixed>|null */
    public function media(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $this->preload([$id]);

        return $this->media[$id] ?: null;
    }

    private function buildMedia(MediaAsset $asset, Collection $variants): array
    {
        $grouped = [];
        foreach ($variants as $v) {
            $grouped[$v->variant][$v->format] = ['url' => $this->storage->url($asset, $v->path), 'width' => (int) $v->width, 'height' => (int) $v->height];
        }

        return [
            'id' => $asset->id, 'mime_type' => $asset->mime_type, 'url' => $this->storage->url($asset),
            'alt' => $asset->alt_text, 'caption' => $asset->caption, 'credit' => $asset->credit,
            'width' => $asset->width, 'height' => $asset->height,
            'focal' => $asset->focal_x === null ? null : ['x' => (float) $asset->focal_x, 'y' => (float) $asset->focal_y],
            'variants' => (object) array_map(fn ($formats) => (object) $formats, $grouped),
        ];
    }

    public function iso(mixed $time): ?string
    {
        return $time === null ? null : Carbon::parse($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** @param  Collection<int, Post>  $posts */
    public function summaries(Collection $posts): array
    {
        $this->preload($posts->pluck('cover_media_id'));
        $kinds = DB::table('post_kinds')->pluck('name_ar', 'code');
        $categories = DB::table('categories')->whereIn('id', $posts->pluck('category_id')->filter())->get()->keyBy('id');
        $authors = DB::table('post_authors')->join('authors', 'authors.id', '=', 'post_authors.author_id')->whereNull('authors.deleted_at')
            ->whereIn('post_id', $posts->pluck('id'))->orderBy('post_authors.sort_order')->get(['post_id', 'authors.id', 'authors.name', 'authors.slug'])->groupBy('post_id');
        $sponsors = DB::table('organizations')->whereIn('id', $posts->pluck('sponsor_organization_id')->filter())->get()->keyBy('id');
        // 22.12: the number of readings only when the admin shows it; otherwise the field is not sent at all.
        $views = ViewCounts::shown() ? ViewCounts::totals($posts->pluck('id')) : null;

        return $posts->map(function (Post $p) use ($kinds, $categories, $authors, $sponsors, $views) {
            $c = $categories->get($p->category_id);
            $s = $sponsors->get($p->sponsor_organization_id);

            return ($views === null ? [] : ['views' => $views[$p->id] ?? 0]) + [
                'id' => $p->id, 'kind' => ['code' => $p->kind_code, 'name' => $kinds[$p->kind_code] ?? $p->kind_code],
                'title' => $p->title, 'subtitle' => $p->subtitle, 'excerpt' => $p->excerpt, 'slug' => $p->slug,
                'path' => PostPath::for($p->kind_code, $p->id, $p->slug),
                'published_at' => $this->iso($p->published_at), 'updated_at' => $this->iso($p->updated_at),
                'cover' => $this->media($p->cover_media_id),
                'category' => $c ? ['id' => $c->id, 'name' => $c->name_ar, 'slug' => $c->slug] : null,
                'authors' => ($authors->get($p->id) ?? collect())->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name, 'slug' => $a->slug])->values()->all(),
                'is_featured' => (bool) $p->is_featured, 'is_breaking' => $p->isBreaking(),
                'breaking_until' => $p->isBreaking() ? $this->iso($p->breaking_until) : null,
                'sponsor' => $s ? $this->organization($s) : null,
            ];
        })->values()->all();
    }

    public function organization(object $o): array
    {
        return ['id' => (int) $o->id, 'name' => $o->name, 'slug' => $o->slug, 'website' => $o->website];
    }

    public function detail(Post $post): array
    {
        $summary = $this->summaries(collect([$post]))[0];

        $tags = DB::table('post_tags')->join('tags', 'tags.id', '=', 'post_tags.tag_id')->where('post_id', $post->id)->orderBy('tags.name_ar')->get(['tags.id', 'tags.name_ar', 'tags.slug']);
        $counts = DB::table('post_tags')->join('posts', 'posts.id', '=', 'post_tags.post_id')->whereIn('tag_id', $tags->pluck('id'))
            ->where('posts.status', 'published')->where('posts.published_at', '<=', now())->whereNull('posts.deleted_at')->whereNotIn('posts.id', Post::hiddenCharacterCards())->groupBy('tag_id')->selectRaw('tag_id, count(*) as n')->pluck('n', 'tag_id');

        $galleryIds = DB::table('post_gallery_items')->where('post_id', $post->id)->orderBy('sort_order')->pluck('media_id');
        $this->preload($galleryIds);

        $relatedIds = DB::table('post_related')->where('post_id', $post->id)->orderBy('sort_order')->pluck('related_post_id');
        $related = Post::query()->live()->whereIn('id', $relatedIds)->get()->sortBy(fn ($r) => $relatedIds->search($r->id))->values();

        $seo = $post->seoMeta;
        $html = $this->renderer->html($post->body);
        if ($post->isSponsored()) {
            $html = str_replace('rel="noopener noreferrer"', 'rel="sponsored noopener noreferrer"', $html);
        }

        return $summary + [
            'body_html' => $html,
            'tags' => $tags->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name_ar, 'slug' => $t->slug, 'posts_count' => (int) ($counts[$t->id] ?? 0)])->all(),
            'video' => $this->primaryVideo($post),
            'awareness_cards' => $this->linkedCards($post),
            'gallery' => $galleryIds->map(fn ($id) => $this->media((int) $id))->filter()->values()->all(),
            'guests' => $this->guests($post),
            'details' => $this->details($post),
            'related' => $this->summaries($related),
            'correction' => $post->correction_note ? ['note' => $post->correction_note, 'corrected_at' => $this->iso($post->corrected_at)] : null,
            'seo' => $this->seo($seo, $post->title, $post->excerpt, $post->cover_media_id),
            'revision' => md5((string) DB::table('posts')->where('id', $post->id)->value('updated_at').'|'.$post->id),
        ];
    }

    /** @return array<string, mixed> */
    public function seo(?object $meta, string $title, ?string $description, ?int $fallbackImage): array
    {
        $image = $meta?->share_media_id ?? $fallbackImage;

        return [
            'title' => $meta?->meta_title ?: $title, 'description' => $meta?->meta_description ?: $description,
            'canonical_url' => $meta?->canonical_url, 'noindex' => (bool) ($meta?->robots_noindex ?? false),
            'share_image' => $image ? $this->media((int) $image) : null,
        ];
    }

    private function guests(Post $post): array
    {
        $rows = DB::table('post_guests')->join('guest_roles', 'guest_roles.code', '=', 'post_guests.role_code')->join('guests', 'guests.id', '=', 'post_guests.guest_id')
            ->where('post_id', $post->id)->whereNull('guests.deleted_at')->orderBy('post_guests.sort_order')
            ->get(['guests.id as gid', 'guest_roles.code', 'guest_roles.name_ar']);

        return $rows->map(fn ($r) => ['role' => ['code' => $r->code, 'name' => $r->name_ar], 'guest' => $this->guest((int) $r->gid)])->all();
    }

    public function guest(int $id): array
    {
        $g = DB::table('guests')->where('id', $id)->first();
        $o = $g->organization_id ? DB::table('organizations')->where('id', $g->organization_id)->first() : null;
        $links = DB::table('guest_links')->where('guest_id', $id)->pluck('url', 'platform_code')->all();

        return [
            'id' => $id, 'name' => $g->name, 'slug' => $g->slug, 'job_title' => $g->job_title, 'bio' => $g->bio,
            'photo' => $this->media($g->photo_media_id), 'organization' => $o ? $this->organization($o) : null, 'links' => (object) $links,
        ];
    }

    /**
     * Every published item the guest appears in with the part played, newest first (decisions 5.10, 6.11).
     *
     * @return list<array{role: array{code: string, name: string}, post: array<string, mixed>}>
     */
    public function appearances(int $guestId): array
    {
        $rows = DB::table('post_guests')->join('guest_roles', 'guest_roles.code', '=', 'post_guests.role_code')->where('post_guests.guest_id', $guestId)
            ->get(['post_guests.post_id', 'guest_roles.code', 'guest_roles.name_ar'])->keyBy('post_id');
        $posts = Post::query()->live()->whereIn('id', $rows->keys())->orderByDesc('published_at')->orderByDesc('id')->limit(200)->get();
        $summaries = $this->summaries($posts);

        return $posts->values()->map(fn (Post $p, int $i) => ['role' => ['code' => $rows[$p->id]->code, 'name' => $rows[$p->id]->name_ar], 'post' => $summaries[$i]])->all();
    }

    public function author(object $a): array
    {
        $links = DB::table('author_links')->where('author_id', $a->id)->pluck('url', 'platform_code')->all();

        return [
            'id' => (int) $a->id, 'name' => $a->name, 'slug' => $a->slug, 'job_title' => $a->job_title, 'bio' => $a->bio,
            'photo' => $this->media($a->photo_media_id), 'team_order' => $a->team_sort_order === null ? null : (int) $a->team_sort_order, 'links' => (object) $links,
        ];
    }

    /**
     * Awareness cards with the character and pose they show (decisions 7.11, 7.13).
     *
     * @param  Collection<int, Post>  $cards
     * @return list<array<string, mixed>>
     */
    public function cards(Collection $cards): array
    {
        $summaries = $this->summaries($cards);

        return $cards->values()->map(fn (Post $card, int $i) => $summaries[$i] + ['awareness' => $this->details($card)])->all();
    }

    /** The published awareness cards a post points at, in order (decision 7.12). @return list<array<string, mixed>> */
    private function linkedCards(Post $post): array
    {
        if ($post->kind_code === 'awareness') {
            return [];
        }
        $ids = DB::table('post_related')->join('posts', 'posts.id', '=', 'post_related.related_post_id')->where('post_related.post_id', $post->id)
            ->where('posts.kind_code', 'awareness')->orderBy('post_related.sort_order')->pluck('posts.id');
        $cards = Post::query()->live()->whereIn('id', $ids)->get()->sortBy(fn ($c) => $ids->search($c->id))->values();

        return $this->cards($cards->filter(fn (Post $c) => $this->details($c) !== null));
    }

    /**
     * A character with its poses (decisions 7.9, 7.10, 7.14).
     *
     * @return array<string, mixed>
     */
    public function character(object $c, bool $withCount = true): array
    {
        $poses = DB::table('character_poses')->join('pose_types', 'pose_types.code', '=', 'character_poses.pose_type_code')->where('character_poses.character_id', $c->id)
            ->orderBy('pose_types.sort_order')->get(['character_poses.id', 'character_poses.pose_type_code', 'pose_types.name_ar', 'character_poses.static_media_id', 'character_poses.rive_state']);
        $this->preload($poses->pluck('static_media_id')->push($c->rive_media_id, $c->seo_meta_id ? DB::table('seo_meta')->where('id', $c->seo_meta_id)->value('share_media_id') : null));
        $seo = $c->seo_meta_id ? DB::table('seo_meta')->where('id', $c->seo_meta_id)->first() : null;

        return [
            'id' => (int) $c->id, 'name' => $c->name_ar, 'slug' => $c->slug, 'role' => $c->role_ar, 'description' => $c->description,
            'accent_color' => $c->accent_color, 'rive' => $this->media($c->rive_media_id),
            'poses' => $poses->map(fn ($p) => ['code' => $p->pose_type_code, 'name' => $p->name_ar, 'image' => $this->media($p->static_media_id), 'rive_state' => $p->rive_state])->all(),
            'cards_count' => $withCount ? $this->characterCards((int) $c->id)->count() : 0,
            'seo' => $this->seo($seo, $c->name_ar, $c->description ?: $c->role_ar, null),
        ];
    }

    /** Published cards of one character. */
    public function characterCards(int $characterId): Builder
    {
        return Post::query()->live()->where('kind_code', 'awareness')->whereIn('id', DB::table('awareness_cards')
            ->join('character_poses', 'character_poses.id', '=', 'awareness_cards.character_pose_id')->where('character_poses.character_id', $characterId)->select('awareness_cards.post_id'));
    }

    private function details(Post $post): ?array
    {
        $row = fn (string $table) => DB::table($table)->where('post_id', $post->id)->first();
        $org = fn ($id) => $this->organization(DB::table('organizations')->where('id', $id)->first());

        switch ($post->kind_code) {
            case 'episode':
                $r = $row('podcast_episodes');
                $s = $r ? DB::table('podcast_seasons')->where('id', $r->season_id)->first() : null;

                return $r ? ['kind' => 'episode', 'season' => ['number' => (int) $s->season_number, 'title' => $s->title_ar], 'episode_number' => (int) $r->episode_number] : null;
            case 'story':
                $r = $row('success_stories');

                return $r ? ['kind' => 'story', 'organization' => $org($r->organization_id), 'person_name' => $r->person_name,
                    'figures' => DB::table('success_story_figures')->where('post_id', $post->id)->orderBy('sort_order')->get(['value', 'label'])->map(fn ($f) => (array) $f)->all()] : null;
            case 'product':
                $r = $row('products');

                return $r ? ['kind' => 'product', 'product_name' => $r->product_name, 'organization' => $org($r->organization_id), 'contact_url' => $r->contact_url, 'contact_phone' => $r->contact_phone] : null;
            case 'history':
                $r = $row('history_entries');

                return $r ? ['kind' => 'history', 'era' => ['id' => (int) $r->era_id, 'name' => DB::table('eras')->where('id', $r->era_id)->value('name_ar')],
                    'period_label' => $r->period_label, 'year_from' => $r->year_from === null ? null : (int) $r->year_from, 'year_to' => $r->year_to === null ? null : (int) $r->year_to] : null;
            case 'initiative':
                $r = $row('initiatives');

                return $r ? ['kind' => 'initiative', 'initiative_name' => $r->initiative_name, 'organizer' => $org($r->organizer_organization_id), 'status' => $r->status, 'url' => $r->url] : null;
            case 'roundtable':
                $r = $row('roundtable_sessions');

                return $r ? ['kind' => 'roundtable', 'held_on' => substr((string) $r->held_on, 0, 10)] : null;
            case 'awareness':
                $r = $row('awareness_cards');
                if (! $r) {
                    return null;
                }
                $pose = DB::table('character_poses')->where('id', $r->character_pose_id)->first();
                $c = DB::table('characters')->where('id', $pose->character_id)->first();

                return ['kind' => 'awareness',
                    'type' => ['code' => $r->awareness_type_code, 'name' => DB::table('awareness_types')->where('code', $r->awareness_type_code)->value('name_ar')],
                    'character' => ['id' => (int) $c->id, 'name' => $c->name_ar, 'slug' => $c->slug, 'accent_color' => $c->accent_color],
                    'pose' => ['code' => $pose->pose_type_code, 'name' => DB::table('pose_types')->where('code', $pose->pose_type_code)->value('name_ar'), 'image' => $this->media($pose->static_media_id)]];
            case 'infographic':
                $r = $row('infographics');

                return $r ? ['kind' => 'infographic', 'download' => $this->media($r->download_media_id)] : null;
        }

        return null;
    }
}
