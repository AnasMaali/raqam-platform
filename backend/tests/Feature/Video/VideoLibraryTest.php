<?php

use App\Models\Video;
use App\Video\VideoLibrary;
use App\Video\VideoRejected;
use App\Video\YouTubeQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const TEST_KEY = 'test-key-never-to-be-seen-0123456789';

/** Replaces earlier stand-ins (Http::fake alone stacks them). */
function httpFake(array $stubs = []): void
{
    Http::swap(new Factory);
    Http::fake($stubs);
}

function youtubeItem(string $id = 'dQw4w9WgXcQ', array $over = []): array
{
    return array_replace_recursive([
        'id' => $id,
        'snippet' => ['title' => 'حلقة عن الاقتصاد', 'description' => 'وصف الحلقة', 'publishedAt' => '2026-09-01T10:00:00Z',
            'thumbnails' => ['default' => ['url' => 'https://i.ytimg.com/vi/'.$id.'/default.jpg'], 'high' => ['url' => 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg']]],
        'contentDetails' => ['duration' => 'PT1H2M3S'],
        'status' => ['privacyStatus' => 'public'],
    ], $over);
}

function youtubeReplies(array $items): void
{
    httpFake(['www.googleapis.com/*' => Http::response(['items' => $items])]);
}

beforeEach(function () {
    config(['raqam.youtube.key' => TEST_KEY]);
    Cache::flush();
    $this->library = app(VideoLibrary::class);
});

function rejection(Closure $call): VideoRejected
{
    try {
        $call();
    } catch (VideoRejected $e) {
        return $e;
    }
    test()->fail('Nothing was refused.');
}

test('a pasted link brings in the title, description, thumbnail, length and date from the API (8.2, 8.3)', function () {
    youtubeReplies([youtubeItem()]);

    $video = $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ');

    expect($video->source)->toBe('youtube')
        ->and($video->duration_seconds)->toBe(3723)
        ->and($video->youtube->youtube_id)->toBe('dQw4w9WgXcQ')
        ->and($video->youtube->source_title)->toBe('حلقة عن الاقتصاد')
        ->and($video->youtube->source_description)->toBe('وصف الحلقة')
        ->and($video->youtube->thumbnail_url)->toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg')
        ->and($video->youtube->published_on_youtube_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-01 10:00')
        ->and($video->title())->toBe('حلقة عن الاقتصاد');
});

test('the same video in the three address shapes is one video (8.5)', function () {
    youtubeReplies([youtubeItem('aB3-_xYz09Q')]);

    $a = $this->library->addYoutube('https://www.youtube.com/watch?v=aB3-_xYz09Q');
    $b = $this->library->addYoutube('https://youtu.be/aB3-_xYz09Q');
    $c = $this->library->addYoutube('https://www.youtube.com/shorts/aB3-_xYz09Q');

    expect([$b->id, $c->id])->toBe([$a->id, $a->id])->and(Video::count())->toBe(1);
    Http::assertSentCount(1);
});

test('a Shorts address marks the video as a short', function () {
    youtubeReplies([youtubeItem('aB3-_xYz09Q')]);

    expect($this->library->addYoutube('https://www.youtube.com/shorts/aB3-_xYz09Q')->youtube->is_short)->toBeTrue();
});

test('a video in the trash comes back instead of being added again', function () {
    youtubeReplies([youtubeItem()]);
    $video = $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ');
    $video->delete();

    $again = $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ');

    expect($again->id)->toBe($video->id)->and($again->trashed())->toBeFalse();
});

test('a bad address is refused with a reason and YouTube is not asked', function () {
    httpFake();

    expect(rejection(fn () => $this->library->addYoutube('https://vimeo.com/123'))->getMessage())->toContain('ليس رابط فيديو يوتيوب');
    Http::assertNothingSent();
});

test('a video YouTube does not know, or that is private, is refused', function () {
    youtubeReplies([]);
    expect(rejection(fn () => $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ'))->getMessage())->toContain('لم يجد يوتيوب');

    youtubeReplies([youtubeItem('dQw4w9WgXcQ', ['status' => ['privacyStatus' => 'private']])]);
    expect(rejection(fn () => $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ'))->getMessage())->toContain('خاص')
        ->and(Video::count())->toBe(0);
});

test('without a key the title is typed by hand and nothing goes out (8.3)', function () {
    config(['raqam.youtube.key' => null]);
    httpFake();

    $e = rejection(fn () => $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ'));
    expect($e->manualEntry)->toBeTrue();

    $video = $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ', ['title' => 'عنوان يدوي', 'duration' => '12:30']);

    expect($video->youtube->source_title)->toBe('عنوان يدوي')->and($video->duration_seconds)->toBe(750)
        ->and($video->youtube->thumbnail_url)->toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
    Http::assertNothingSent();
});

test('a wrong typed duration is refused', function () {
    config(['raqam.youtube.key' => null]);

    expect(rejection(fn () => $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ', ['title' => 'x', 'duration' => 'abc']))->field)->toBe('duration');
});

test('the key travels in a header and never in the address', function () {
    youtubeReplies([youtubeItem()]);

    $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ');

    Http::assertSent(fn (Request $r) => $r->header('X-Goog-Api-Key') === [TEST_KEY]
        && ! str_contains($r->url(), TEST_KEY) && ! str_contains($r->url(), 'key='));
});

test('each call is counted against the daily quota and calls stop at the budget (8.14)', function () {
    config(['raqam.youtube.quota_budget' => 2]);
    httpFake(['www.googleapis.com/*' => Http::response(['items' => [youtubeItem()]])]);

    $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ');
    expect(app(YouTubeQuota::class)->used())->toBe(1);

    httpFake(['www.googleapis.com/*' => Http::response(['items' => [youtubeItem('aB3-_xYz09Q')]])]);
    $this->library->addYoutube('https://youtu.be/aB3-_xYz09Q');
    $e = rejection(fn () => $this->library->addYoutube('https://youtu.be/bbbbbbbbbbb'));

    expect($e->getMessage())->toContain('الحد اليومي')->and($e->manualEntry)->toBeTrue();
    Http::assertSentCount(1);   // the third call never left
});

test('the provider health row records success and failures, with no key in it (8.14)', function () {
    youtubeReplies([youtubeItem()]);
    $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ');
    $row = DB::table('provider_health')->where('provider_code', 'youtube')->first();
    expect($row->last_success_at)->not->toBeNull()->and($row->consecutive_failures)->toBe(0);

    // The error body of the real API sometimes repeats the request; the stored reason must not.
    httpFake(['www.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'message' => 'bad key '.TEST_KEY, 'errors' => [['reason' => 'quotaExceeded']]]], 403)]);
    $e = rejection(fn () => $this->library->addYoutube('https://youtu.be/aB3-_xYz09Q'));
    httpFake(['www.googleapis.com/*' => Http::response('boom '.TEST_KEY, 500)]);
    rejection(fn () => $this->library->addYoutube('https://youtu.be/ccccccccccc'));

    $row = DB::table('provider_health')->where('provider_code', 'youtube')->first();
    expect($row->consecutive_failures)->toBe(2)->and($row->last_failure_at)->not->toBeNull()
        ->and($row->last_error)->not->toContain(TEST_KEY)->and($e->getMessage())->not->toContain(TEST_KEY)->and($e->manualEntry)->toBeTrue();
    expect(json_encode(DB::table('provider_health')->get()))->not->toContain(TEST_KEY);
});

test('a network failure asks for manual entry and is recorded', function () {
    httpFake(['www.googleapis.com/*' => fn () => throw new ConnectionException('cURL error for '.TEST_KEY)]);

    $e = rejection(fn () => $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ'));

    expect($e->manualEntry)->toBeTrue()->and($e->getMessage())->not->toContain(TEST_KEY)
        ->and(DB::table('provider_health')->where('provider_code', 'youtube')->value('consecutive_failures'))->toBe(1);
});

test('the display title and description change without YouTube being asked or its own text touched (8.4)', function () {
    youtubeReplies([youtubeItem()]);
    $video = $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ');
    httpFake();

    $this->library->updateDisplay($video, '  عنوان للموقع  ', 'وصف للموقع', null);
    $video->refresh();

    expect($video->title())->toBe('عنوان للموقع')->and($video->description())->toBe('وصف للموقع')
        ->and($video->youtube->source_title)->toBe('حلقة عن الاقتصاد')->and($video->youtube->source_description)->toBe('وصف الحلقة');
    Http::assertNothingSent();

    $this->library->updateDisplay($video, '', '', null);
    expect($video->refresh()->title())->toBe('حلقة عن الاقتصاد');
});

test('a cover must be a public picture of the library', function () {
    youtubeReplies([youtubeItem()]);
    $video = $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ');

    expect(rejection(fn () => $this->library->updateDisplay($video, null, null, 999999))->field)->toBe('poster_media_id');

    $private = insertMedia(['visibility' => 'private', 'alt_text' => null]);
    expect(rejection(fn () => $this->library->updateDisplay($video, null, null, $private))->field)->toBe('poster_media_id');

    $public = insertMedia();
    $this->library->updateDisplay($video, null, null, $public);
    expect($video->refresh()->poster_media_id)->toBe($public);
});

test('chapters are saved in time order and read back (8.8)', function () {
    youtubeReplies([youtubeItem()]);   // 1:02:03 long
    $video = $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ');

    $this->library->syncChapters($video, [['time' => '10:00', 'title' => 'الثاني'], ['time' => '0:00', 'title' => 'المقدمة'], ['time' => '1:01:00', 'title' => 'الخاتمة']]);

    expect($this->library->chapters($video))->toBe([['time' => '0:00', 'title' => 'المقدمة'], ['time' => '10:00', 'title' => 'الثاني'], ['time' => '1:01:00', 'title' => 'الخاتمة']]);

    $this->library->syncChapters($video, []);
    expect($this->library->chapters($video))->toBe([]);
});

test('a chapter at or after the end of the video is refused and nothing changes (8.8)', function () {
    youtubeReplies([youtubeItem()]);   // 3723 seconds
    $video = $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ');
    $this->library->syncChapters($video, [['time' => '1:00', 'title' => 'قديم']]);

    expect(rejection(fn () => $this->library->syncChapters($video, [['time' => '1:02:03', 'title' => 'عند النهاية']]))->getMessage())->toContain('يتجاوز مدة الفيديو')
        ->and(rejection(fn () => $this->library->syncChapters($video, [['time' => '2:00:00', 'title' => 'بعد النهاية']]))->field)->toBe('chapters')
        ->and($this->library->chapters($video))->toBe([['time' => '1:00', 'title' => 'قديم']]);

    $this->library->syncChapters($video, [['time' => '1:02:02', 'title' => 'آخر ثانية']]);
    expect($this->library->chapters($video))->toHaveCount(1);
});

test('chapters need a title, a valid time, and no repeated time', function (array $chapters, string $expected) {
    youtubeReplies([youtubeItem()]);
    $video = $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ');

    expect(rejection(fn () => $this->library->syncChapters($video, $chapters))->getMessage())->toContain($expected);
})->with([
    'no title' => [[['time' => '1:00', 'title' => ' ']], 'العنوان إجباري'],
    'bad time' => [[['time' => 'soon', 'title' => 'x']], 'الوقت غير صحيح'],
    'repeated time' => [[['time' => '1:00', 'title' => 'a'], ['time' => '60', 'title' => 'b']], 'مكرر'],
]);

test('a video of unknown length accepts any chapter time', function () {
    config(['raqam.youtube.key' => null]);
    $video = $this->library->addYoutube('https://youtu.be/dQw4w9WgXcQ', ['title' => 'x']);

    $this->library->syncChapters($video, [['time' => '5:00:00', 'title' => 'بعيد']]);

    expect($this->library->chapters($video))->toHaveCount(1);
});

test('a playlist keeps its videos in the order they were put in, and re-ordering is a rewrite (8.9)', function () {
    config(['raqam.youtube.key' => null]);
    $ids = collect(['aaaaaaaaaaa', 'bbbbbbbbbbb', 'ccccccccccc'])->map(fn ($y) => $this->library->addYoutube("https://youtu.be/$y", ['title' => $y])->id)->all();
    $playlist = DB::table('playlists')->insertGetId(['name_ar' => 'الموسم الأول', 'slug' => 'season-1']);

    $this->library->setPlaylistVideos($playlist, [$ids[2], $ids[0], $ids[1]]);
    expect($this->library->playlistVideoIds($playlist))->toBe([$ids[2], $ids[0], $ids[1]]);

    $this->library->setPlaylistVideos($playlist, [$ids[1], $ids[2]]);
    expect($this->library->playlistVideoIds($playlist))->toBe([$ids[1], $ids[2]]);
});

test('a playlist ignores repeated, unknown, trashed and malformed ids', function () {
    config(['raqam.youtube.key' => null]);
    $a = $this->library->addYoutube('https://youtu.be/aaaaaaaaaaa', ['title' => 'a']);
    $gone = $this->library->addYoutube('https://youtu.be/bbbbbbbbbbb', ['title' => 'b']);
    $gone->delete();
    $playlist = DB::table('playlists')->insertGetId(['name_ar' => 'قائمة', 'slug' => 'list']);

    $this->library->setPlaylistVideos($playlist, [$a->id, (string) $a->id, 999999, $gone->id, 'x', '1; drop', 99999999999999999999, null, [3]]);

    expect($this->library->playlistVideoIds($playlist))->toBe([$a->id]);
});

test('one video can sit in several playlists (8.5)', function () {
    config(['raqam.youtube.key' => null]);
    $v = $this->library->addYoutube('https://youtu.be/aaaaaaaaaaa', ['title' => 'a']);
    $p1 = DB::table('playlists')->insertGetId(['name_ar' => 'ا', 'slug' => 'one']);
    $p2 = DB::table('playlists')->insertGetId(['name_ar' => 'ب', 'slug' => 'two']);

    $this->library->setPlaylistVideos($p1, [$v->id]);
    $this->library->setPlaylistVideos($p2, [$v->id]);

    expect(DB::table('playlist_videos')->where('video_id', $v->id)->count())->toBe(2);
});
