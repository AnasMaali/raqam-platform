<?php

use App\Models\Video;
use App\Panel\PanelAlerts;
use App\Video\VideoLibrary;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function apiAnswers(array $items): void
{
    Http::swap(new Factory);
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => $items])]);
}

function apiItem(string $id, string $privacy = 'public'): array
{
    return ['id' => $id, 'snippet' => ['title' => 't'], 'contentDetails' => ['duration' => 'PT1M'], 'status' => ['privacyStatus' => $privacy]];
}

beforeEach(function () {
    config(['raqam.youtube.key' => 'test-key-1234567890']);
    Cache::flush();
    config(['raqam.youtube.key' => null]);
    $this->ids = collect(['aaaaaaaaaaa', 'bbbbbbbbbbb', 'ccccccccccc'])
        ->mapWithKeys(fn ($y) => [$y => app(VideoLibrary::class)->addYoutube("https://youtu.be/$y", ['title' => "فيديو $y"])->id])->all();
    config(['raqam.youtube.key' => 'test-key-1234567890']);
});

test('the weekly check marks a private video, a deleted video, and keeps a healthy one (8.12)', function () {
    apiAnswers([apiItem('aaaaaaaaaaa'), apiItem('bbbbbbbbbbb', 'private')]);   // ccccccccccc is not in the answer: gone

    expect(Artisan::call('videos:check-availability'))->toBe(0);

    $state = DB::table('youtube_videos')->orderBy('youtube_id')->pluck('availability', 'youtube_id')->all();
    expect($state)->toBe(['aaaaaaaaaaa' => 'ok', 'bbbbbbbbbbb' => 'private', 'ccccccccccc' => 'removed'])
        ->and(DB::table('youtube_videos')->whereNull('last_checked_at')->count())->toBe(0);
});

test('a video that came back becomes available again', function () {
    DB::table('youtube_videos')->where('youtube_id', 'aaaaaaaaaaa')->update(['availability' => 'private']);
    apiAnswers([apiItem('aaaaaaaaaaa'), apiItem('bbbbbbbbbbb'), apiItem('ccccccccccc')]);

    Artisan::call('videos:check-availability');

    expect(DB::table('youtube_videos')->where('youtube_id', 'aaaaaaaaaaa')->value('availability'))->toBe('ok');
});

test('videos in the trash are not checked, and the ids go out fifty at a time', function () {
    Video::query()->whereKey($this->ids['ccccccccccc'])->delete();
    apiAnswers([apiItem('aaaaaaaaaaa'), apiItem('bbbbbbbbbbb')]);

    Artisan::call('videos:check-availability');

    Http::assertSent(fn ($r) => ! str_contains($r->url(), 'ccccccccccc') && str_contains($r->url(), 'aaaaaaaaaaa'));
    expect(DB::table('youtube_videos')->where('youtube_id', 'ccccccccccc')->value('availability'))->toBe('ok');   // untouched
});

test('without a key the check is skipped and changes nothing', function () {
    config(['raqam.youtube.key' => null]);
    Http::fake();

    expect(Artisan::call('videos:check-availability'))->toBe(1);
    Http::assertNothingSent();
    expect(DB::table('youtube_videos')->whereNotNull('last_checked_at')->count())->toBe(0);
});

test('when YouTube fails nothing is marked removed', function () {
    Http::swap(new Factory);
    Http::fake(['www.googleapis.com/*' => Http::response('boom', 500)]);

    expect(Artisan::call('videos:check-availability'))->toBe(1);
    expect(DB::table('youtube_videos')->where('availability', '<>', 'ok')->count())->toBe(0);
});

test('the panel home warns about a video that is gone and names the items that use it (8.12)', function () {
    ensurePostKinds();
    $post = insertPost(['title' => 'حلقة الاقتصاد', 'primary_video_id' => $this->ids['bbbbbbbbbbb']]);
    DB::table('youtube_videos')->where('youtube_id', 'bbbbbbbbbbb')->update(['availability' => 'private']);
    DB::table('youtube_videos')->where('youtube_id', 'ccccccccccc')->update(['availability' => 'removed']);

    $alerts = PanelAlerts::all();

    expect($alerts)->toHaveCount(2)
        ->and($alerts[0])->toContain('فيديو bbbbbbbbbbb')->toContain('صار خاصاً')->toContain('حلقة الاقتصاد')
        ->and($alerts[1])->toContain('فيديو ccccccccccc')->toContain('حُذف من يوتيوب');
});

test('no warning while every video is fine, and the quota warning shows near the limit (8.14)', function () {
    expect(PanelAlerts::all())->toBe([]);

    Cache::put('youtube:units:'.now('America/Los_Angeles')->format('Y-m-d'), 7500);

    expect(PanelAlerts::all())->toHaveCount(1)->and(PanelAlerts::all()[0])->toContain('حصة يوتيوب');
});

test('the check is scheduled weekly', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'videos:check-availability'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 4 * * 0');
});

test('more than fifty videos are all checked, none skipped', function () {
    config(['raqam.youtube.key' => null]);
    foreach (range(1, 120) as $n) {
        app(VideoLibrary::class)->addYoutube('https://youtu.be/'.str_pad("x$n", 11, '_'), ['title' => "v$n"]);
    }
    config(['raqam.youtube.key' => 'test-key-1234567890']);
    config(['raqam.youtube.quota_budget' => 100]);
    Http::swap(new Factory);
    Http::fake(['www.googleapis.com/*' => function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);

        return Http::response(['items' => array_map(fn ($id) => apiItem($id), explode(',', $q['id']))]);
    }]);

    Artisan::call('videos:check-availability');

    expect(DB::table('youtube_videos')->whereNull('last_checked_at')->count())->toBe(0);
    Http::assertSentCount(3);   // 123 videos, 50 a call
});
