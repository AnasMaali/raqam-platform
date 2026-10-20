<?php

use App\Awareness\CharacterLibrary;
use App\Content\PostPublisher;
use App\Content\PostSaver;
use App\Jobs\GenerateMediaVariants;
use App\Jobs\SyncSearchDocuments;
use App\Media\MediaUploader;
use App\Models\Author;
use App\Models\Category;
use App\Models\Character;
use App\Models\Guest;
use App\Models\Playlist;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Search\SearchIndexer;
use App\Search\SearchUnavailable;
use App\Video\VideoLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake([GenerateMediaVariants::class]);
    ensurePostKinds();
    $this->user = User::factory()->staff()->create();
    $this->actingAs($this->user);
    $this->saver = app(PostSaver::class);
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'غلاف'], null)->asset;
    $this->data = ['title' => 'ارتفاع أسعار الذهب', 'body' => '<p>قفزت أسعار الذهب في نابلس</p>', 'category_id' => Category::value('id'), 'cover_media_id' => $this->cover->id];
    useSearchEngine();
});

afterEach(fn () => dropSearchIndexes());

function savedPost(string $status, array $extra = [], string $kind = 'news'): Post
{
    $post = app(PostSaver::class)->create($kind, test()->data['title'], auth()->id());

    return app(PostSaver::class)->save($post, [...test()->data, ...$extra, 'status' => $status], auth()->id());
}

// ---- what belongs (10.8) ---------------------------------------------------------------------------

test('a published item is indexed after the save, every text field in the search form', function () {
    $post = savedPost('published', ['subtitle' => 'الأسواق المحلية', 'excerpt' => 'تقرير عن الذهب', 'tags' => ['الذهب']]);

    $doc = indexedDocument('posts', $post->id);

    expect($doc['id'])->toBe($post->id)
        ->and($doc['title_n'])->toBe('ارتفاع اسعار ذهب')
        ->and($doc['subtitle_n'])->toBe('اسواق محليه')
        ->and($doc['body_n'])->toBe('قفزت اسعار ذهب في نابلس')
        ->and($doc['tags_n'])->toBe('ذهب')
        ->and($doc['kind'])->toBe('news')
        ->and($doc['categories'])->toContain($post->category_id)
        ->and($doc['published_at'])->toBe($post->published_at->getTimestamp());
});

test('drafts and scheduled items never enter the index', function () {
    $draft = savedPost('draft');
    $scheduled = savedPost('scheduled', ['published_at' => now()->addHour()]);

    expect(indexedIds('posts'))->not->toContain($draft->id)->not->toContain($scheduled->id);
});

test('an item leaves the index at once when it goes back to draft, is archived or trashed, and returns when restored', function () {
    $a = savedPost('published');
    $b = savedPost('published');
    $c = savedPost('published');
    expect(indexedIds('posts'))->toBe([$a->id, $b->id, $c->id]);

    $this->saver->save($a, [...$this->data, 'status' => 'draft'], $this->user->id);
    $this->saver->save($b, [...$this->data, 'status' => 'archived'], $this->user->id);
    $this->saver->trash($c);
    expect(indexedIds('posts'))->toBe([]);

    $this->saver->restore($c->fresh());
    expect(indexedIds('posts'))->toBe([$c->id]);
});

test('a purged item is removed even if its trash job never ran', function () {
    $post = savedPost('published');
    // As if the item had been trashed while the engine was down.
    DB::table('posts')->where('id', $post->id)->update(['deleted_at' => now()]);
    expect(indexedIds('posts'))->toBe([$post->id]);

    $this->saver->purge(Post::withTrashed()->find($post->id));

    expect(indexedIds('posts'))->toBe([]);
});

test('an edit of a published item reaches the index', function () {
    $post = savedPost('published');
    $this->saver->save($post, [...$this->data, 'title' => 'تراجع سعر الدولار', 'status' => 'published'], $this->user->id);

    expect(indexedDocument('posts', $post->id)['title_n'])->toBe('تراجع سعر دولار');
});

// ---- scheduled items: delayed job + every-minute net (10.8) -----------------------------------------

test('saving a scheduled item queues an index job for one second after its publishing time', function () {
    Queue::fake([SyncSearchDocuments::class]);
    $at = now()->addHours(2)->startOfMinute();

    $post = savedPost('scheduled', ['published_at' => $at]);

    Queue::assertPushed(SyncSearchDocuments::class, fn ($job) => $job->index === 'posts' && $job->ids === [$post->id]
        && $job->delay !== null && abs($at->copy()->addSecond()->diffInSeconds($job->delay, false)) < 1);
});

test('the delayed job re-checks the item: not live yet, moved back to draft or trashed = nothing indexed', function () {
    $post = savedPost('scheduled', ['published_at' => now()->addHour()]);

    (new SyncSearchDocuments('posts', [$post->id]))->handle();
    expect(indexedIds('posts'))->toBe([]);

    $this->travel(61)->minutes();
    $this->saver->save($post->fresh(), [...$this->data, 'status' => 'draft'], $this->user->id);
    (new SyncSearchDocuments('posts', [$post->id]))->handle();
    expect(indexedIds('posts'))->toBe([]);
});

test('a scheduled item is in the index as soon as it is published', function () {
    $post = savedPost('scheduled', ['published_at' => now()->addMinutes(5)]);
    $this->travel(6)->minutes();

    app(PostPublisher::class)->publishIfDue($post->id);

    expect(indexedIds('posts'))->toBe([$post->id]);
});

test('search:sync-due indexes an item that went live without any job (the safety net)', function () {
    // Written behind the application's back: no event, no job.
    $id = insertPost(publishedPostValues(['title' => 'خبر الدقيقة']));
    expect(indexedIds('posts'))->toBe([]);

    $this->artisan('search:sync-due')->assertSuccessful();

    expect(indexedIds('posts'))->toBe([$id]);
});

test('search:sync-due indexes an item whose publishing time just passed, even if nothing touched it for long', function () {
    // Written long ago with a publishing time that has just come: nothing has touched the row since.
    $id = insertPost(publishedPostValues(['title' => 'خبر قديم الحفظ',
        'published_at' => DB::raw("now() - interval '2 minutes'"), 'updated_at' => DB::raw("now() - interval '1 hour'")]));

    $this->artisan('search:sync-due')->assertSuccessful();

    expect(indexedIds('posts'))->toBe([$id]);
});

test('search:sync-due also removes an item that left the index behind its back', function () {
    $post = savedPost('published', ['published_at' => now()->subDay()]);
    DB::table('posts')->where('id', $post->id)->update(['status' => 'draft', 'updated_at' => DB::raw('now()')]);

    $this->artisan('search:sync-due')->assertSuccessful();

    expect(indexedIds('posts'))->toBe([]);
});

test('search:reindex writes what is missing and removes what no longer belongs', function () {
    $kept = savedPost('published');
    $missing = insertPost(publishedPostValues(['title' => 'غائب عن الفهرس']));
    $stray = savedPost('published');
    DB::table('posts')->where('id', $stray->id)->update(['status' => 'draft']);
    expect(indexedIds('posts'))->toBe([$kept->id, $stray->id]);

    $this->artisan('search:reindex', ['index' => 'posts'])->expectsOutputToContain('posts: 2 indexed, 1 removed')->assertSuccessful();

    expect(indexedIds('posts'))->toBe([$kept->id, $missing]);
});

test('search:reindex refuses an unknown index', function () {
    $this->artisan('search:reindex', ['index' => 'users'])->assertExitCode(2);
});

// ---- hidden characters (decision after phase 4) ----------------------------------------------------

test('the cards of a hidden character leave the index, and come back when it is shown again', function () {
    $character = Character::create(['name_ar' => 'المستشار', 'slug' => 'advisor', 'role_ar' => 'ينصح', 'accent_color' => '#1F6F5B']);
    app(CharacterLibrary::class)->savePoses($character, ['explaining' => ['static_media_id' => $this->cover->id]]);
    $pose = DB::table('character_poses')->where('character_id', $character->id)->value('id');
    $facts = ['awareness' => ['character_id' => $character->id, 'character_pose_id' => $pose, 'awareness_type_code' => 'advice']];
    $card = savedPost('published', $facts, 'awareness');
    $news = savedPost('published');
    expect(indexedIds('posts'))->toBe([$card->id, $news->id]);

    $character->update(['is_active' => false]);
    expect(indexedIds('posts'))->toBe([$news->id]);

    // While hidden, a save of the card does not bring it back.
    $this->saver->save($card->fresh(), [...$this->data, ...$facts, 'status' => 'published'], $this->user->id);
    expect(indexedIds('posts'))->toBe([$news->id]);

    $character->update(['is_active' => true]);
    expect(indexedIds('posts'))->toBe([$card->id, $news->id]);
});

test('search:reindex never puts back the card of a hidden character', function () {
    $character = Character::create(['name_ar' => 'انتبه!', 'slug' => 'watch-out', 'role_ar' => 'ينبه', 'accent_color' => '#B3261E', 'is_active' => false]);
    app(CharacterLibrary::class)->savePoses($character, ['explaining' => ['static_media_id' => $this->cover->id]]);
    $pose = DB::table('character_poses')->where('character_id', $character->id)->value('id');
    $card = insertPost(publishedPostValues(['kind_code' => 'awareness', 'category_id' => null]));
    DB::table('awareness_cards')->insert(['post_id' => $card, 'kind_code' => 'awareness', 'character_pose_id' => $pose, 'awareness_type_code' => 'advice']);

    $this->artisan('search:reindex', ['index' => 'posts'])->assertSuccessful();

    expect(indexedIds('posts'))->toBe([]);
});

// ---- videos, writers, guests, tags (10.4) ------------------------------------------------------------

test('a video is indexed while a live item uses it, and leaves when the item is unpublished', function () {
    $video = insertUploadedVideo();
    DB::table('videos')->where('id', $video)->update(['display_title' => 'جولة في سوق الذهب']);
    $post = savedPost('published', ['primary_video_id' => $video]);
    expect(indexedIds('videos'))->toBe([$video])
        ->and(indexedDocument('videos', $video)['title_n'])->toBe('جوله في سوق ذهب');

    $this->saver->save($post, [...$this->data, 'primary_video_id' => $video, 'status' => 'draft'], $this->user->id);

    expect(indexedIds('videos'))->toBe([]);
});

test('a video in a visible playlist is indexed, and leaves with the playlist rewrite that drops it', function () {
    $video = insertUploadedVideo();
    $playlist = DB::table('playlists')->insertGetId(['name_ar' => 'مواسم', 'slug' => 'seasons', 'is_visible' => true]);
    app(VideoLibrary::class)->setPlaylistVideos($playlist, [$video]);
    expect(indexedIds('videos'))->toBe([$video]);

    app(VideoLibrary::class)->setPlaylistVideos($playlist, []);
    expect(indexedIds('videos'))->toBe([]);
});

test('hiding a playlist removes the videos it alone made public', function () {
    $video = insertUploadedVideo();
    $playlist = Playlist::create(['name_ar' => 'مواسم', 'slug' => 'seasons', 'is_visible' => true]);
    app(VideoLibrary::class)->setPlaylistVideos($playlist->id, [$video]);
    expect(indexedIds('videos'))->toBe([$video]);

    $playlist->update(['is_visible' => false]);
    expect(indexedIds('videos'))->toBe([]);
});

test('a YouTube video found private or removed by the weekly check leaves the index', function () {
    config(['raqam.youtube.key' => 'test-key-1234567890']);
    $video = insertYoutubeVideo('aaaaaaaaaaa');
    savedPost('published', ['primary_video_id' => $video]);
    expect(indexedIds('videos'))->toBe([$video]);

    Http::fake(['www.googleapis.com/*' => Http::response(['items' => []])]);
    $this->artisan('videos:check-availability');

    expect(DB::table('youtube_videos')->where('video_id', $video)->value('availability'))->toBe('removed')
        ->and(indexedIds('videos'))->toBe([]);
});

test('a tag is a result only while a live item carries it', function () {
    $post = savedPost('published', ['tags' => ['الذهب']]);
    $tag = Tag::where('name_ar', 'الذهب')->value('id');
    expect(indexedIds('tags'))->toBe([$tag]);

    $this->saver->trash($post);

    expect(indexedIds('tags'))->toBe([]);
});

test('writers: an inactive or trashed writer leaves the index; a rename reaches the items they wrote', function () {
    $author = Author::create(['name' => 'سامر يوسف', 'slug' => 'samer']);
    $post = savedPost('published', ['authors' => [$author->id]]);
    expect(indexedIds('authors'))->toBe([$author->id])
        ->and(indexedDocument('posts', $post->id)['people_n'])->toBe('سامر يوسف');

    $author->update(['name' => 'سامر الخليلي']);
    expect(indexedDocument('posts', $post->id)['people_n'])->toBe('سامر خليلي')
        ->and(indexedDocument('authors', $author->id)['name_n'])->toBe('سامر خليلي');

    $author->update(['is_active' => false]);
    expect(indexedIds('authors'))->toBe([]);
});

test('guests are indexed with their organisation', function () {
    $org = DB::table('organizations')->insertGetId(['name' => 'غرفة تجارة نابلس', 'slug' => 'chamber']);
    $guest = Guest::create(['name' => 'ليلى حسن', 'slug' => 'layla', 'job_title' => 'مديرة', 'organization_id' => $org]);

    expect(indexedIds('guests'))->toBe([$guest->id])
        ->and(indexedDocument('guests', $guest->id)['organization_n'])->toBe('غرفه تجاره نابلس');

    $guest->delete();
    expect(indexedIds('guests'))->toBe([]);
});

// ---- the engine itself -------------------------------------------------------------------------------

test('every index pins its searchable fields to Arabic and carries the synonyms', function () {
    $group = DB::table('synonym_groups')->insertGetId([]);
    DB::table('synonym_terms')->insert([['term' => 'الشيكل', 'group_id' => $group], ['term' => 'شيقل', 'group_id' => $group]]);
    SearchIndexer::make()->setup();

    foreach (array_keys(SearchIndexer::indexes()) as $key) {
        $settings = SearchIndexer::make()->meili()->settings(SearchIndexer::uid($key));
        ksort($settings['synonyms']);
        expect($settings['localizedAttributes'])->toBe([['attributePatterns' => ['*_n'], 'locales' => ['ara']]], $key)
            ->and($settings['searchableAttributes'])->each->toEndWith('_n')
            ->and($settings['synonyms'])->toBe(['شيقل' => ['شيكل'], 'شيكل' => ['شيقل']]);
    }
});

test('a save does not fail and does not wait when the engine is down', function () {
    config(['raqam.search.host' => 'http://127.0.0.1:9']);   // nothing listens there

    $post = savedPost('published');

    expect($post->fresh()->status)->toBe('published');
});

test('on a real queue the job fails when the engine is down, so it is retried', function () {
    config(['raqam.search.host' => 'http://127.0.0.1:9']);
    $job = new SyncSearchDocuments('posts', [1]);
    $job->setJob(new class extends Job implements Illuminate\Contracts\Queue\Job
    {
        public function getJobId()
        {
            return '1';
        }

        public function getRawBody()
        {
            return '';
        }

        public function attempts()
        {
            return 1;
        }

        public function getConnectionName()
        {
            return 'redis';
        }
    });

    expect(fn () => $job->handle())->toThrow(SearchUnavailable::class)
        ->and($job->tries)->toBeGreaterThan(1);
});
