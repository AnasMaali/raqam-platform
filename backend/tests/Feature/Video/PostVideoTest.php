<?php

use App\Content\Blocks\BlockRegistry;
use App\Content\Blocks\VideoBlock;
use App\Content\PostRuleViolation;
use App\Content\PostSaver;
use App\Models\Post;
use App\Models\User;
use App\Video\VideoLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    ensurePostKinds();
    config(['raqam.youtube.key' => null]);
    $this->user = User::factory()->staff()->create();
    $this->saver = app(PostSaver::class);
    $this->video = app(VideoLibrary::class)->addYoutube('https://youtu.be/dQw4w9WgXcQ', ['title' => 'فيديو']);
});

function draftPost(string $kind = 'news'): Post
{
    return app(PostSaver::class)->create($kind, 'عنوان '.random_int(1, 99999), test()->user->id);
}

test('a post keeps the main video it was given and shows it in the usage view (8.5)', function () {
    $post = draftPost();

    $this->saver->save($post, ['title' => $post->title, 'primary_video_id' => $this->video->id], $this->user->id);

    expect($post->refresh()->primary_video_id)->toBe($this->video->id)
        ->and(DB::table('video_usage')->where('video_id', $this->video->id)->where('post_id', $post->id)->where('usage', 'primary')->exists())->toBeTrue();
});

test('the same video can be the main video of several posts', function () {
    foreach ([draftPost(), draftPost()] as $post) {
        $this->saver->save($post, ['title' => $post->title, 'primary_video_id' => $this->video->id], $this->user->id);
    }

    expect(Post::where('primary_video_id', $this->video->id)->count())->toBe(2);
});

test('a video that is not in the library is refused, whatever was sent', function (mixed $id) {
    $post = draftPost();

    expect(fn () => $this->saver->save($post, ['title' => $post->title, 'primary_video_id' => $id], $this->user->id))->toThrow(PostRuleViolation::class);
})->with([[987654], ['abc'], ['1; drop table videos'], [-5], [[1]]]);

test('a video in the trash cannot be chosen', function () {
    $this->video->delete();
    $post = draftPost();

    expect(fn () => $this->saver->save($post, ['title' => $post->title, 'primary_video_id' => $this->video->id], $this->user->id))->toThrow(PostRuleViolation::class);
});

test('saving without mentioning the video leaves it, and sending nothing removes it', function () {
    $post = draftPost();
    $this->saver->save($post, ['title' => $post->title, 'primary_video_id' => $this->video->id], $this->user->id);

    $this->saver->save($post->refresh(), ['title' => 'عنوان جديد'], $this->user->id);
    expect($post->refresh()->primary_video_id)->toBe($this->video->id);

    $this->saver->save($post, ['title' => 'عنوان جديد', 'primary_video_id' => null], $this->user->id);
    expect($post->refresh()->primary_video_id)->toBeNull();
});

test('restoring an old revision does not lose the video', function () {
    $post = draftPost();
    $this->saver->save($post, ['title' => 'أولى', 'body' => '<p>أول</p>'], $this->user->id);
    $this->saver->save($post->refresh(), ['title' => 'ثانية', 'body' => '<p>ثان</p>', 'primary_video_id' => $this->video->id], $this->user->id);

    $this->saver->restoreRevision($post->refresh(), 1, $this->user->id);

    expect($post->refresh()->primary_video_id)->toBe($this->video->id);
});

test('the video block is offered in the editor and saves only a reference to a library video (21.6)', function () {
    expect(app(BlockRegistry::class)->forEditor())->toContain(VideoBlock::class);

    expect(VideoBlock::normalize(['video_id' => $this->video->id, 'extra' => '<script>']))->toBe(['video_id' => $this->video->id])
        ->and(VideoBlock::normalize(['video_id' => 987654]))->toBeNull()
        ->and(VideoBlock::normalize(['video_id' => 'x']))->toBeNull()
        ->and(VideoBlock::normalize([]))->toBeNull();
});

test('a video in the text is recorded as used by that post', function () {
    $post = draftPost();
    $body = '<p>x</p><div data-type="customBlock" data-id="video" data-config=\'{"video_id":'.$this->video->id.'}\'></div>';

    $this->saver->save($post, ['title' => $post->title, 'body' => $body], $this->user->id);

    expect(DB::table('video_usage')->where('video_id', $this->video->id)->where('usage', 'body')->pluck('post_id')->all())->toBe([$post->id]);
});

test('an episode with a video joins its season playlist, once, at the end (5.1, 8.9)', function () {
    $other = app(VideoLibrary::class)->addYoutube('https://youtu.be/aaaaaaaaaaa', ['title' => 'آخر']);
    $playlist = DB::table('playlists')->insertGetId(['name_ar' => 'الموسم 1', 'slug' => 'season-1']);
    DB::table('playlist_videos')->insert(['playlist_id' => $playlist, 'video_id' => $other->id, 'sort_order' => 1]);
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 1, 'playlist_id' => $playlist]);

    $episode = draftPost('episode');
    $data = ['title' => $episode->title, 'primary_video_id' => $this->video->id, 'episode' => ['season_id' => $season, 'episode_number' => 1]];
    $this->saver->save($episode, $data, $this->user->id);
    $this->saver->save($episode->refresh(), $data, $this->user->id);

    expect(app(VideoLibrary::class)->playlistVideoIds($playlist))->toBe([$other->id, $this->video->id]);
});

test('an episode of a season with no playlist, or a news item, adds nothing to any playlist', function () {
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 2]);
    $episode = draftPost('episode');
    $this->saver->save($episode, ['title' => $episode->title, 'primary_video_id' => $this->video->id, 'episode' => ['season_id' => $season, 'episode_number' => 1]], $this->user->id);
    $news = draftPost();
    $this->saver->save($news, ['title' => $news->title, 'primary_video_id' => $this->video->id], $this->user->id);

    expect(DB::table('playlist_videos')->count())->toBe(0);
});
