<?php

use App\Awareness\CharacterLibrary;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Characters\Pages\CreateCharacter;
use App\Filament\Resources\Playlists\Pages\CreatePlaylist;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\GenerateMediaVariants;
use App\Media\MediaInUse;
use App\Media\MediaProbe;
use App\Media\MediaTrash;
use App\Media\MediaUploader;
use App\Media\Mp4Cleaner;
use App\Models\Character;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Video;
use App\Video\VideoLibrary;
use App\Video\VideoRejected;
use App\Video\VideoView;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Storage::fake('media_local_public');
    Bus::fake([GenerateMediaVariants::class]);
    ensurePostKinds();
    $this->actingAs(User::factory()->staff()->create());
    config(['raqam.youtube.key' => null]);
});

// ---- free text has a size -----------------------------------------------------------------------

test('a video description cannot be megabytes long', function () {
    $video = app(VideoLibrary::class)->addYoutube('https://youtu.be/dQw4w9WgXcQ', ['title' => 'x']);

    Livewire::test(EditVideo::class, ['record' => $video->id])->fillForm(['display_description' => str_repeat('ب', 60_000)])->call('save')->assertHasFormErrors(['display_description']);
    expect(fn () => app(VideoLibrary::class)->updateDisplay($video, null, str_repeat('ب', 60_000), null))->toThrow(VideoRejected::class);
    expect($video->refresh()->display_description)->toBeNull();
});

test('playlist and character descriptions have a limit, too', function () {
    Livewire::test(CreatePlaylist::class)->fillForm(['name_ar' => 'قائمة', 'description' => str_repeat('ب', 60_000)])->call('create')->assertHasFormErrors(['description']);
    Livewire::test(CreateCharacter::class)->fillForm(['name_ar' => 'شخصية', 'role_ar' => 'x', 'accent_color' => '#0B5FA5', 'description' => str_repeat('ب', 60_000)])->call('create')->assertHasFormErrors(['description']);

    expect(DB::table('playlists')->count() + Character::count())->toBe(0);
});

test('an absurd order number is refused with a sentence, not an error page', function () {
    Livewire::test(CreatePlaylist::class)->fillForm(['name_ar' => 'قائمة', 'sort_order' => 99999999999])->call('create')->assertHasNoErrors();
    Livewire::test(CreateCharacter::class)->fillForm(['name_ar' => 'شخصية', 'role_ar' => 'x', 'accent_color' => '#0B5FA5', 'sort_order' => 99999999999])->call('create')->assertHasNoErrors();

    expect(DB::table('playlists')->count() + Character::count())->toBe(0);
});

// ---- files in use are not lost ----------------------------------------------------------------------

test('a picture used by a character pose, a Rive file used by a character and a clip used by a video cannot be trashed', function () {
    $pic = app(MediaUploader::class)->store(uploadFile(imageBytes(220, 200)), 'p.jpg', ['alt_text' => 'و'], null)->asset;
    $rive = app(MediaUploader::class)->store(uploadFile('RIVE'."\x07\x00\x01".random_bytes(300)), 'c.riv', [], null)->asset;
    $character = Character::create(['name_ar' => 'ش', 'slug' => 's', 'role_ar' => 'x', 'accent_color' => '#0B5FA5', 'rive_media_id' => $rive->id]);
    app(CharacterLibrary::class)->savePoses($character, ['explaining' => ['static_media_id' => $pic->id]]);
    $clip = insertUploadedVideo();
    $clipMedia = MediaAssetOf($clip);

    foreach ([$pic, $rive, $clipMedia] as $asset) {
        expect(fn () => app(MediaTrash::class)->trash($asset))->toThrow(MediaInUse::class);
        expect($asset->refresh()->trashed())->toBeFalse();
    }
});

function MediaAssetOf(int $videoId): MediaAsset
{
    return MediaAsset::findOrFail(DB::table('videos')->where('id', $videoId)->value('upload_media_id'));
}

// ---- the tools that open an uploaded video -----------------------------------------------------------

test('ffprobe and ffmpeg are told not to follow references to other files inside the MP4', function () {
    $probe = app(MediaProbe::class)->arguments('/tmp/x.mp4');
    $clean = app(Mp4Cleaner::class)->arguments('/tmp/in.mp4', '/tmp/out.mp4');

    foreach ([$probe, $clean] as $arguments) {
        $at = array_search('-enable_drefs', $arguments, true);
        expect($at)->not->toBeFalse()->and($arguments[$at + 1])->toBe('0')->and($at)->toBeLessThan(array_search('-i', $arguments, true));
    }
});

test('the real tools accept those arguments', function () {
    expect(realMediaToolsInstalled())->toBeTrue('ffmpeg and ffprobe are needed: sudo apt install ffmpeg');
    config(['media.ffprobe' => 'ffprobe', 'media.ffmpeg' => 'ffmpeg']);

    $asset = app(MediaUploader::class)->store(uploadFile(realMp4(2, gps: false)), 'a.mp4', [], null)->asset;

    expect($asset->mime_type)->toBe('video/mp4');
});

// ---- the YouTube key is nowhere ------------------------------------------------------------------------

test('the key appears in no table, no log line, no page of the panel and no answer of the API, whatever happens', function () {
    $key = 'zz-secret-youtube-key-0123456789abcdef';
    config(['raqam.youtube.key' => $key]);
    $ok = ['id' => 'dQw4w9WgXcQ', 'snippet' => ['title' => 'حلقة'], 'contentDetails' => ['duration' => 'PT5M'], 'status' => ['privacyStatus' => 'public']];

    // Success, a refusal that echoes the key, a server error that echoes it, and a dead connection that names it.
    $answers = [
        Http::response(['items' => [$ok]]),
        Http::response(['error' => ['message' => "key $key is not valid", 'errors' => [['reason' => 'keyInvalid']]]], 400),
        Http::response("upstream failure for $key", 500),
        fn () => throw new ConnectionException("cURL error for https://www.googleapis.com/youtube/v3/videos?key=$key"),
    ];
    foreach ($answers as $i => $answer) {
        Http::swap(new Factory);
        Http::fake(['www.googleapis.com/*' => $answer]);
        try {
            app(VideoLibrary::class)->addYoutube('https://youtu.be/'.($i === 0 ? 'dQw4w9WgXcQ' : str_pad("a$i", 11, 'a')));
        } catch (VideoRejected $e) {
            expect($e->getMessage())->not->toContain($key);
        }
    }

    $video = Video::first();
    $pages = [
        Livewire::test(EditVideo::class, ['record' => $video->id])->html(),
        Livewire::test(ListVideos::class)->html(),
        Livewire::test(Dashboard::class)->html(),
    ];
    $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public'"))->map(fn ($t) => json_encode(DB::table($t->tablename)->get()))->implode("\n");
    $log = is_file(storage_path('logs/testing.log')) ? file_get_contents(storage_path('logs/testing.log')) : '';

    foreach ([...$pages, $tables, $log] as $where => $text) {
        expect(str_contains($text, $key))->toBeFalse("The key was found in item $where.");
    }
    foreach (['/videos', '/videos/'.$video->id, '/playlists', '/sitemaps/videos'] as $path) {
        expect(api($path)->getContent())->not->toContain($key);
    }
});

test('the key is only read from the environment, and the repository holds none', function () {
    expect(file_get_contents(base_path('.env.example')))->toMatch('/^YOUTUBE_API_KEY=$/m');
    $leaks = [];
    foreach (['app', 'config', 'routes', 'database', 'tests', 'resources'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir))) as $file) {
            if ($file->isFile() && preg_match('/AIza[0-9A-Za-z_\-]{35}/', (string) file_get_contents($file->getPathname()))) {
                $leaks[] = $file->getPathname();
            }
        }
    }

    expect($leaks)->toBe([]);
});

// ---- outside links ---------------------------------------------------------------------------------------------

test('every outside link in a video the API builds opens safely, and nothing is script', function () {
    $v = DB::table('videos')->insertGetId(['source' => 'youtube', 'duration_seconds' => 600, 'display_title' => '"><script>alert(1)</script>']);
    DB::table('youtube_videos')->insert(['video_id' => $v, 'youtube_id' => 'dQw4w9WgXcQ', 'source_title' => 'x']);
    DB::table('video_chapters')->insert(['video_id' => $v, 'starts_at_seconds' => 5, 'title' => '<img src=x onerror=alert(1)>']);

    $html = app(VideoView::class)->html(Video::with('youtube')->find($v));

    expect($html)->not->toContain('<script')->not->toContain('<img src=x');   // escaped text is harmless; a real tag is not
    preg_match_all('/<a\b[^>]*>/', $html, $links);
    expect($links[0])->not->toBeEmpty();
    foreach ($links[0] as $link) {
        expect($link)->toContain('rel="noopener noreferrer"')->toMatch('~href="https://www\.youtube\.com/watch\?v=dQw4w9WgXcQ~');
    }
});
