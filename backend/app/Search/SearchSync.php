<?php

namespace App\Search;

use App\Events\PostChanged;
use App\Jobs\SyncSearchDocuments;
use App\Models\Author;
use App\Models\Category;
use App\Models\Character;
use App\Models\Guest;
use App\Models\Organization;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Video;
use App\Models\YoutubeVideo;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * What to re-index when something changes. Every path ends in SyncSearchDocuments, which re-reads the rows, so
 * this class only has to name the ids that MAY have changed; it never decides what belongs in an index.
 *
 * An item drags along what depends on it: its videos (a video is public while a live item uses it) and its
 * tags (a tag is a result while a live item carries it). A character hidden or shown re-indexes its cards,
 * which pass the same Post::live() rule as everything else, so they leave the index and come back with it.
 */
final class SearchSync
{
    public static function register(): void
    {
        Event::listen(PostChanged::class, fn (PostChanged $e) => self::postChanged($e->postId));

        // A purge (90 days after the trash) writes no PostChanged: the item left the index when it was trashed,
        // and this removes it again in case that job never ran.
        Post::forceDeleting(function (Post $post) {
            $videos = self::videosOfPosts([$post->id]);
            $tags = self::tagsOfPosts([$post->id]);
            DB::afterCommit(function () use ($post, $videos, $tags) {
                self::queue('posts', [$post->id]);
                self::queue('videos', $videos);
                self::queue('tags', $tags);
            });
        });

        Character::updated(function (Character $character) {
            if ($character->wasChanged('is_active')) {
                self::posts(self::cardsOf($character->id));
            }
        });

        foreach ([Author::class => 'authors', Guest::class => 'guests'] as $model => $index) {
            $model::saved(function ($person) use ($index) {
                self::queue($index, [$person->id]);
                if ($person->wasChanged('name') || ($index === 'authors' && $person->wasChanged('is_active'))) {
                    self::queue('posts', self::postsOfPerson($index, $person->id));   // names are searched in items
                }
            });
            foreach (['deleted', 'restored'] as $event) {
                $model::$event(function ($person) use ($index) {
                    self::queue($index, [$person->id]);
                    self::queue('posts', self::postsOfPerson($index, $person->id));
                });
            }
        }

        Organization::updated(function (Organization $organization) {
            if ($organization->wasChanged('name')) {
                self::queue('guests', DB::table('guests')->where('organization_id', $organization->id)->pluck('id')->all());
            }
        });

        Tag::saved(function (Tag $tag) {
            self::queue('tags', [$tag->id]);
            if ($tag->wasChanged('name_ar')) {
                self::queue('posts', DB::table('post_tags')->where('tag_id', $tag->id)->pluck('post_id')->all());
            }
        });
        Tag::deleting(function (Tag $tag) {
            $posts = DB::table('post_tags')->where('tag_id', $tag->id)->pluck('post_id')->all();
            DB::afterCommit(function () use ($tag, $posts) {
                self::queue('tags', [$tag->id]);
                self::queue('posts', $posts);
            });
        });

        Category::updated(function (Category $category) {
            if ($category->wasChanged(['name_ar', 'parent_id'])) {
                $ids = [$category->id, ...DB::table('categories')->where('parent_id', $category->id)->pluck('id')->all()];
                self::queue('posts', DB::table('posts')->whereIn('category_id', $ids)->pluck('id')->all());
            }
        });

        foreach (['saved', 'deleted', 'restored'] as $event) {
            Video::$event(fn (Video $video) => self::queue('videos', [$video->id]));
        }
        YoutubeVideo::saved(fn (YoutubeVideo $youtube) => self::queue('videos', [$youtube->video_id]));
        Playlist::saved(function (Playlist $playlist) {
            if ($playlist->wasChanged('is_visible')) {
                self::queue('videos', self::videosOfPlaylist($playlist->id));
            }
        });
        Playlist::deleting(function (Playlist $playlist) {
            $videos = self::videosOfPlaylist($playlist->id);
            DB::afterCommit(fn () => self::queue('videos', $videos));
        });
    }

    /** A post was saved, published, unpublished, trashed or restored. */
    public static function postChanged(int $postId): void
    {
        self::posts([$postId]);

        // A scheduled item: a job at its publishing time re-checks it and indexes it if it is live by then (10.8).
        // The publisher's own PostChanged and the every-minute search:sync-due cover the same moment twice more.
        $at = DB::table('posts')->where('id', $postId)->where('status', 'scheduled')->value('published_at');
        if ($at !== null) {
            self::queue('posts', [$postId], now()->parse($at)->addSecond());
        }
    }

    /** @param  list<int>  $postIds */
    public static function posts(array $postIds): void
    {
        if (! MeiliClient::configured() || $postIds === []) {
            return;
        }
        self::queue('posts', $postIds);
        self::queue('videos', self::videosOfPosts($postIds));
        self::queue('tags', self::tagsOfPosts($postIds));
    }

    /** @param  list<int|string>  $ids */
    public static function queue(string $index, array $ids, ?DateTimeInterface $at = null): void
    {
        if (! MeiliClient::configured()) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach (array_chunk($ids, 500) as $chunk) {
            $job = SyncSearchDocuments::dispatch($index, $chunk)->afterCommit();
            $at !== null && $job->delay($at);
        }
    }

    /**
     * Videos an item uses, trashed items included (video_usage leaves them out, but a trashed item's video may
     * have just stopped being public).
     *
     * @param  list<int>  $postIds
     * @return list<int>
     */
    public static function videosOfPosts(array $postIds): array
    {
        return DB::table('posts')->whereIn('id', $postIds)->whereNotNull('primary_video_id')->pluck('primary_video_id')
            ->merge(DB::table('post_body_videos')->whereIn('post_id', $postIds)->pluck('video_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return list<int> */
    public static function tagsOfPosts(array $postIds): array
    {
        return DB::table('post_tags')->whereIn('post_id', $postIds)->pluck('tag_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return list<int> the awareness cards of a character */
    public static function cardsOf(int $characterId): array
    {
        return DB::table('awareness_cards')->join('character_poses', 'character_poses.id', '=', 'awareness_cards.character_pose_id')
            ->where('character_poses.character_id', $characterId)->pluck('awareness_cards.post_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    public static function videosOfPlaylist(int $playlistId): array
    {
        return DB::table('playlist_videos')->where('playlist_id', $playlistId)->pluck('video_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private static function postsOfPerson(string $index, int $id): array
    {
        return $index === 'authors'
            ? DB::table('post_authors')->where('author_id', $id)->pluck('post_id')->all()
            : DB::table('post_guests')->where('guest_id', $id)->pluck('post_id')->all();
    }
}
