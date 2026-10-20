<?php

use App\Content\PostPath;
use App\Content\PostSaver;
use App\Jobs\GenerateMediaVariants;
use App\Jobs\PublishScheduledPost;
use App\Media\MediaUploader;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake([GenerateMediaVariants::class, PublishScheduledPost::class]);
    ensurePostKinds();
    $this->user = User::factory()->staff()->create();
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'غلاف'], null)->asset;
    $this->energy = Category::where('slug', 'طاقة')->first();
});

function ytVideo(string $id, array $over = [], array $facts = []): int
{
    $video = DB::table('videos')->insertGetId(array_merge(['source' => 'youtube', 'duration_seconds' => 600, 'created_at' => '2026-09-01 10:00:00+00'], $over));
    DB::table('youtube_videos')->insert(['video_id' => $video, 'youtube_id' => $id, 'source_title' => 'عنوان يوتيوب '.$id, 'source_description' => 'وصف يوتيوب',
        'thumbnail_url' => "https://i.ytimg.com/vi/$id/hqdefault.jpg", 'published_on_youtube_at' => '2026-08-30 08:00:00+00'] + $facts);

    return $video;
}

function videoPost(array $extra = []): int
{
    $t = test();
    $post = app(PostSaver::class)->create($extra['kind'] ?? 'news', 'خبر '.random_int(1, 999999), $t->user->id);

    return app(PostSaver::class)->save($post, array_merge(['title' => $post->title, 'body' => '<p>نص</p>', 'category_id' => $t->energy->id, 'cover_media_id' => $t->cover->id, 'status' => 'published'], $extra), $t->user->id)->id;
}

function playlistWith(string $slug, array $videoIds, array $over = []): int
{
    $id = DB::table('playlists')->insertGetId(['name_ar' => 'قائمة '.$slug, 'slug' => $slug] + $over);
    foreach ($videoIds as $i => $v) {
        DB::table('playlist_videos')->insert(['playlist_id' => $id, 'video_id' => $v, 'sort_order' => $i + 1]);
    }

    return $id;
}

test('the list shows only videos a visitor can play that are in use, and each answer fits the contract (8.10)', function () {
    $inPost = ytVideo('aaaaaaaaaaa');
    $inList = ytVideo('bbbbbbbbbbb');
    ytVideo('ccccccccccc');                                       // nobody uses it
    $draftOnly = ytVideo('ddddddddddd');                         // only in a draft
    $private = ytVideo('eeeeeeeeeee', [], ['availability' => 'private']);
    $trashed = ytVideo('fffffffffff');
    $hiddenList = ytVideo('ggggggggggg');
    videoPost(['primary_video_id' => $inPost]);
    videoPost(['primary_video_id' => $private]);
    videoPost(['primary_video_id' => $trashed]);
    $draft = app(PostSaver::class)->create('news', 'مسودة', $this->user->id);
    app(PostSaver::class)->save($draft, ['title' => 'مسودة', 'primary_video_id' => $draftOnly], $this->user->id);
    playlistWith('visible', [$inList]);
    playlistWith('hidden', [$hiddenList], ['is_visible' => false]);
    DB::table('videos')->where('id', $trashed)->update(['deleted_at' => now()]);

    $response = api('/videos')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect([$inPost, $inList])->sort()->values()->all());
    foreach ($response->json('data') as $video) {
        matchesContract($video, 'Video');
    }
    matchesContract($response->json('meta'), 'PageMeta');
});

test('a video has what the site needs: the safe player address, the poster, the chapters and the structured data (8.7, 8.13)', function () {
    $v = ytVideo('dQw4w9WgXcQ', ['duration_seconds' => 600, 'display_title' => 'عنواننا'], ['is_short' => false]);
    videoPost(['primary_video_id' => $v]);
    DB::table('video_chapters')->insert([['video_id' => $v, 'starts_at_seconds' => 0, 'title' => 'المقدمة'], ['video_id' => $v, 'starts_at_seconds' => 120, 'title' => 'الموضوع'], ['video_id' => $v, 'starts_at_seconds' => 300, 'title' => 'الخاتمة']]);

    $data = api("/videos/$v")->assertOk()->json('data');

    matchesContract($data, 'Video');
    expect($data['title'])->toBe('عنواننا')->and($data['description'])->toBe('وصف يوتيوب')
        ->and($data['youtube']['embed_url'])->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and($data['youtube']['watch_url'])->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->and($data['published_at'])->toBe('2026-08-30T08:00:00Z')
        ->and($data['chapters'])->toBe([['start' => 0, 'time' => '0:00', 'title' => 'المقدمة'], ['start' => 120, 'time' => '2:00', 'title' => 'الموضوع'], ['start' => 300, 'time' => '5:00', 'title' => 'الخاتمة']]);

    $ld = $data['structured_data'];
    expect($ld['@type'])->toBe('VideoObject')->and($ld['name'])->toBe('عنواننا')->and($ld['duration'])->toBe('PT10M')
        ->and($ld['uploadDate'])->toBe('2026-08-30T08:00:00Z')->and($ld['embedUrl'])->toBe($data['youtube']['embed_url'])
        ->and($ld['thumbnailUrl'])->toBe(['https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg']);
    expect(collect($ld['hasPart'])->map(fn ($c) => [$c['name'], $c['startOffset'], $c['endOffset']])->all())
        ->toBe([['المقدمة', 0, 120], ['الموضوع', 120, 300], ['الخاتمة', 300, 600]])
        ->and($ld['hasPart'][1]['url'])->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=120s')
        ->and($ld['hasPart'][0]['@type'])->toBe('Clip');
});

test('without a video length the last chapter has no end and is left out of the clips', function () {
    $v = ytVideo('dQw4w9WgXcQ', ['duration_seconds' => null]);
    videoPost(['primary_video_id' => $v]);
    DB::table('video_chapters')->insert([['video_id' => $v, 'starts_at_seconds' => 0, 'title' => 'أ'], ['video_id' => $v, 'starts_at_seconds' => 60, 'title' => 'ب']]);

    $ld = api("/videos/$v")->json('data.structured_data');

    expect($ld)->not->toHaveKey('duration')->and(collect($ld['hasPart'])->pluck('name')->all())->toBe(['أ']);
});

test('an uploaded clip carries its file address and not a YouTube player', function () {
    $v = insertUploadedVideo();
    videoPost(['primary_video_id' => $v]);

    $data = api("/videos/$v")->assertOk()->json('data');

    matchesContract($data, 'Video');
    expect($data['source'])->toBe('upload')->and($data['youtube'])->toBeNull()->and($data['upload']['mime_type'])->toBe('video/mp4')
        ->and($data['upload']['width'])->toBe(1080)->and($data['structured_data']['contentUrl'])->toBe($data['upload']['url'])
        ->and($data['structured_data'])->not->toHaveKey('embedUrl');
});

test('a video nobody published, a private one and a trashed one are not found', function () {
    $unused = ytVideo('aaaaaaaaaaa');
    $private = ytVideo('bbbbbbbbbbb', [], ['availability' => 'private']);
    $trashed = ytVideo('ccccccccccc');
    videoPost(['primary_video_id' => $private]);
    videoPost(['primary_video_id' => $trashed]);
    DB::table('videos')->where('id', $trashed)->update(['deleted_at' => now()]);

    foreach ([$unused, $private, $trashed, 999999] as $id) {
        api("/videos/$id")->assertNotFound();
    }
    api('/videos/99999999999999999999')->assertNotFound();
});

test('the library filters by playlist and by section (8.10)', function () {
    $a = ytVideo('aaaaaaaaaaa');
    $b = ytVideo('bbbbbbbbbbb');
    $podcast = DB::table('sections')->where('key', 'podcast')->first();
    playlistWith('season-one', [$a], ['section_id' => $podcast->id]);
    playlistWith('other', [$b]);

    expect(collect(api('/videos?playlist=season-one')->json('data'))->pluck('id')->all())->toBe([$a])
        ->and(collect(api('/videos?section='.rawurlencode($podcast->slug))->json('data'))->pluck('id')->all())->toBe([$a])
        ->and(api('/videos?playlist=nope')->json('data'))->toBe([])
        ->and(api('/videos?section=nope')->json('data'))->toBe([]);

    // An item of a section's kind puts its video in that section, too.
    $episodeVideo = ytVideo('eeeeeeeeeee');
    videoPost(['kind' => 'episode', 'primary_video_id' => $episodeVideo, 'episode' => ['season_id' => DB::table('podcast_seasons')->insertGetId(['season_number' => 1]), 'episode_number' => 1]]);
    expect(collect(api('/videos?section='.rawurlencode($podcast->slug))->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect([$a, $episodeVideo])->sort()->values()->all());
});

test('bad filters are answered with 422', function () {
    api('/videos?section[]=x')->assertStatus(422);
    api('/videos?page=0')->assertStatus(422);
});

test('a playlist lists its videos in the order set, hides the ones nobody can play, and hidden playlists are not found (8.9)', function () {
    $a = ytVideo('aaaaaaaaaaa');
    $b = ytVideo('bbbbbbbbbbb');
    $gone = ytVideo('ccccccccccc', [], ['availability' => 'removed']);
    playlistWith('list', [$b, $gone, $a]);
    playlistWith('secret', [$a], ['is_visible' => false]);

    $data = api('/playlists/list')->assertOk()->json('data');

    matchesContract($data, 'PlaylistDetail');
    expect(collect($data['videos'])->pluck('id')->all())->toBe([$b, $a])->and($data['videos_count'])->toBe(2);
    api('/playlists/secret')->assertNotFound();
    api('/playlists/none')->assertNotFound();
    expect(collect(api('/playlists')->json('data'))->pluck('slug')->all())->toBe(['list']);
    matchesContract(api('/playlists')->json('data.0'), 'Playlist');
});

test('a podcast season playlist says which season it is', function () {
    $a = ytVideo('aaaaaaaaaaa');
    $playlist = playlistWith('s2', [$a]);
    DB::table('podcast_seasons')->insert(['season_number' => 2, 'playlist_id' => $playlist]);

    expect(api('/playlists/s2')->json('data.season_number'))->toBe(2);
});

test('the video sitemap lists the video page and every published item showing the video (8.13)', function () {
    $v = ytVideo('aaaaaaaaaaa');
    $p1 = videoPost(['primary_video_id' => $v]);
    $draft = app(PostSaver::class)->create('news', 'مسودة', $this->user->id);
    app(PostSaver::class)->save($draft, ['title' => 'مسودة', 'primary_video_id' => $v], $this->user->id);

    $data = api('/sitemaps/videos')->assertOk()->json('data');

    foreach ($data as $entry) {
        matchesContract($entry, 'VideoSitemapEntry');
    }
    $pages = collect($data)->pluck('page_path')->all();
    expect($pages)->toContain("/video/$v")->and($pages)->toContain(PostPath::for('news', $p1, DB::table('posts')->where('id', $p1)->value('slug')))
        ->and($pages)->toHaveCount(2)
        ->and($data[0]['video']['player_url'])->toBe('https://www.youtube-nocookie.com/embed/aaaaaaaaaaa')->and($data[0]['video']['content_url'])->toBeNull();
});

test('a post carries its main video, null when it cannot be played any more', function () {
    $v = ytVideo('aaaaaaaaaaa');
    $post = videoPost(['primary_video_id' => $v]);
    $none = videoPost();

    $data = api("/posts/$post")->json('data');
    matchesContract($data, 'PostDetail');
    expect($data['video']['id'])->toBe($v)->and(api("/posts/$none")->json('data.video'))->toBeNull();

    DB::table('youtube_videos')->where('video_id', $v)->update(['availability' => 'private']);
    expect(api("/posts/$post")->json('data.video'))->toBeNull();
});

test('a video block in the text becomes the light player, and disappears when the video does (8.6)', function () {
    $v = ytVideo('aaaaaaaaaaa', ['display_title' => 'فيديو "خطير" <b>x</b>']);
    DB::table('video_chapters')->insert(['video_id' => $v, 'starts_at_seconds' => 90, 'title' => 'محور <script>']);
    $post = videoPost(['body' => '<p>قبل</p><div data-type="customBlock" data-id="video" data-config="'.e(json_encode(['video_id' => $v])).'"></div><p>بعد</p>']);

    $html = api("/posts/$post")->json('data.body_html');

    expect($html)->toContain('class="rq-video"')->toContain('data-youtube-id="aaaaaaaaaaa"')
        ->toContain('<img src="https://i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg"')->toContain('referrerpolicy="no-referrer"')
        ->toContain('data-start="90"')->toContain('rel="noopener noreferrer"')
        ->not->toContain('<iframe')->not->toContain('<script')->not->toContain('<b>x</b>')
        ->and(DB::table('post_body_videos')->where('post_id', $post)->pluck('video_id')->all())->toBe([$v]);

    DB::table('youtube_videos')->where('video_id', $v)->update(['availability' => 'removed']);
    expect(api("/posts/$post")->json('data.body_html'))->not->toContain('rq-video');
});

test('no API answer ever carries the YouTube key', function () {
    config(['raqam.youtube.key' => 'fake-secret-key-for-the-test-0123456789']);
    $v = ytVideo('aaaaaaaaaaa');
    $post = videoPost(['primary_video_id' => $v]);
    playlistWith('list', [$v]);

    foreach (['/videos', "/videos/$v", '/playlists', '/playlists/list', '/sitemaps/videos', "/posts/$post"] as $path) {
        expect(api($path)->assertOk()->getContent())->not->toContain('fake-secret-key');
    }
});
