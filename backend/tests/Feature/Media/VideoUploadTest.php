<?php

use App\Jobs\GenerateMediaVariants;
use App\Media\MediaProbe;
use App\Media\MediaRejected;
use App\Media\MediaUploader;
use App\Media\Mp4Cleaner;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Storage::fake('media_local_private');
    Bus::fake([GenerateMediaVariants::class]);
    $this->user = User::factory()->staff()->create();
});

function storeClip(string $bytes, string $name = 'clip.mp4')
{
    return app(MediaUploader::class)->store(uploadFile($bytes), $name, [], User::first()->id);
}

function clipRefusal(string $bytes, string $name = 'clip.mp4'): MediaRejected
{
    try {
        storeClip($bytes, $name);
    } catch (MediaRejected $e) {
        return $e;
    }
    test()->fail('The clip was accepted.');
}

test('a real short clip is stored with the duration and size ffprobe measured, under a random name', function () {
    fakeFfprobe(ffprobeAnswer(42.5, 1280, 720));

    $asset = storeClip(mp4Bytes(), 'holiday.mp4')->asset;

    expect($asset->mime_type)->toBe('video/mp4')
        ->and($asset->duration_seconds)->toBe(42.5)
        ->and([$asset->width, $asset->height])->toBe([1280, 720])
        ->and($asset->path)->toMatch('~^\d{4}/\d{2}/[0-9a-f]{40}\.mp4$~')
        ->and(Storage::disk('media_local_public')->exists($asset->path))->toBeTrue();
    Bus::assertNotDispatched(GenerateMediaVariants::class);   // a video has no picture renditions
});

test('a clip longer than 90 seconds is refused and nothing is stored', function () {
    fakeFfprobe(ffprobeAnswer(91));

    expect(clipRefusal(mp4Bytes())->getMessage())->toContain('90')
        ->and(MediaAsset::count())->toBe(0)->and(Storage::disk('media_local_public')->allFiles())->toBe([]);
});

test('exactly 90 seconds is accepted', function () {
    fakeFfprobe(ffprobeAnswer(90));

    expect(storeClip(mp4Bytes())->asset->duration_seconds)->toBe(90.0);
});

test('a clip over 30 MB is refused before it is measured', function () {
    fakeFfprobe(ffprobeAnswer(10));

    expect(clipRefusal(mp4Bytes(31 * 1048576))->getMessage())->toContain('حجم الملف');
});

test('a text or script file named .mp4 is refused', function () {
    fakeFfprobe(ffprobeAnswer());

    expect(clipRefusal("<?php system(\$_GET['c']); ?>", 'clip.mp4')->getMessage())->toContain('نوع الملف غير مسموح')
        ->and(clipRefusal('<html><script>alert(1)</script></html>', 'clip.mp4')->getMessage())->toContain('نوع الملف غير مسموح');
});

test('an MP4 header on something ffprobe does not call a video is refused', function (array $answer, string $expected) {
    fakeFfprobe($answer);

    expect(clipRefusal(mp4Bytes())->getMessage())->toContain($expected);
})->with([
    'other container' => [fn () => ffprobeAnswer(10, 640, 480, 'h264', 'matroska,webm'), 'ليس MP4'],
    'audio only' => [fn () => ['format' => ['format_name' => 'mov,mp4', 'duration' => '10'], 'streams' => [['codec_type' => 'audio', 'codec_name' => 'aac']]], 'مسار فيديو'],
    'codec no browser plays' => [fn () => ffprobeAnswer(10, 640, 480, 'hevc'), 'H.264'],
    'no duration' => [fn () => ['format' => ['format_name' => 'mp4'], 'streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 10, 'height' => 10]]], 'مدة'],
    'bigger than 4K' => [fn () => ffprobeAnswer(10, 7680, 4320), '4K'],
]);

test('a damaged file that ffprobe cannot read is refused', function () {
    fakeFfprobe(['error' => 'x'], exit: 1);

    expect(clipRefusal(mp4Bytes())->getMessage())->toContain('تعذّرت قراءة');
});

test('a QuickTime file is not an MP4', function () {
    fakeFfprobe(ffprobeAnswer());

    expect(clipRefusal("\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00qt  ".str_repeat("\x01", 200), 'clip.mp4')->getMessage())->toContain('نوع الملف غير مسموح');
});

test('a phone clip recorded sideways is measured as upright', function () {
    fakeFfprobe(['format' => ['format_name' => 'mov,mp4', 'duration' => '5'],
        'streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080, 'side_data_list' => [['rotation' => -90]]]]]);

    $asset = storeClip(mp4Bytes())->asset;

    expect([$asset->width, $asset->height])->toBe([1080, 1920]);
});

test('without ffprobe the upload is refused and the message says what to install', function () {
    config(['media.ffprobe' => '/nonexistent/ffprobe']);

    expect(clipRefusal(mp4Bytes())->getMessage())->toContain('ffprobe')->toContain('sudo apt install ffmpeg')
        ->and(MediaAsset::count())->toBe(0);
});

test('the same clip is not stored twice', function () {
    fakeFfprobe(ffprobeAnswer());
    $bytes = mp4Bytes();

    $first = storeClip($bytes);
    $second = storeClip($bytes);

    expect($second->duplicate)->toBeTrue()->and($second->asset->id)->toBe($first->asset->id);
});

function riveBytes(int $extra = 500): string
{
    return 'RIVE'."\x07\x00\x01".random_bytes($extra);
}

test('a Rive file is accepted by its header and stored as such', function () {
    $asset = storeClip(riveBytes(), 'character.riv')->asset;

    expect($asset->mime_type)->toBe('application/x-rive')->and($asset->path)->toEndWith('.riv')
        ->and($asset->width)->toBeNull();
});

test('a Rive file over 5 MB is refused', function () {
    expect(clipRefusal(riveBytes(6 * 1048576), 'big.riv')->getMessage())->toContain('حجم الملف');
});

test('a script behind a Rive header is refused', function () {
    expect(clipRefusal('RIVE'."\x07\x00\x01".'<?php system($_GET["c"]); ?>', 'a.riv')->getMessage())->toContain('محتوى الملف');
});

// ---- with the real ffmpeg and ffprobe ------------------------------------------------------------
// These do not skip: on a machine without the tools they FAIL, saying what to install.

function requireRealTools(): void
{
    expect(realMediaToolsInstalled())->toBeTrue('ffmpeg and ffprobe are needed: sudo apt install ffmpeg');
    config(['media.ffprobe' => 'ffprobe', 'media.ffmpeg' => 'ffmpeg']);
}

function probeJson(string $path): string
{
    return (string) shell_exec('ffprobe -v error -show_format -show_streams -print_format json '.escapeshellarg($path));
}

test('a real clip is stored with its real duration and size', function () {
    requireRealTools();

    $asset = storeClip(realMp4(3, gps: false, size: '80x60'), 'real.mp4')->asset;

    expect($asset->mime_type)->toBe('video/mp4')->and([$asset->width, $asset->height])->toBe([80, 60])
        ->and($asset->duration_seconds)->toBeGreaterThan(2.5)->toBeLessThan(3.6);
});

test('the GPS position, device and time are gone from the stored file (9.8)', function () {
    requireRealTools();
    $original = realMp4(2, gps: true);

    // The test file really carries them, or the check below would prove nothing.
    $copy = uploadFile($original);
    expect($original)->toContain('+32.2211')->and(probeJson($copy))->toContain('location');

    $asset = storeClip($original, 'phone.mp4')->asset;
    $stored = Storage::disk('media_local_public')->get($asset->path);

    expect($stored)->not->toContain('+32.2211')->not->toContain('035.2544')->not->toContain('ISO6709')->not->toContain('2026-01-02');
    $json = probeJson(Storage::disk('media_local_public')->path($asset->path));
    $data = json_decode($json, true);
    $tags = array_merge(array_keys($data['format']['tags'] ?? []), ...array_map(fn ($s) => array_keys($s['tags'] ?? []), $data['streams']));
    $values = array_merge(array_values($data['format']['tags'] ?? []), ...array_map(fn ($s) => array_values($s['tags'] ?? []), $data['streams']));

    expect(implode(' ', $tags))->not->toMatch('/location|creation|gps|model|make/i')
        ->and(implode(' ', $values))->not->toMatch('/32\.2211|GPS|2026-01-02/')
        ->and($json)->toContain('h264');   // the picture itself is untouched
});

test('the picture and the sound survive the cleaning without being re-encoded', function () {
    requireRealTools();

    $asset = storeClip(realMp4(2, gps: true), 'phone.mp4')->asset;
    $json = json_decode(probeJson(Storage::disk('media_local_public')->path($asset->path)), true);

    expect(collect($json['streams'])->pluck('codec_type')->sort()->values()->all())->toBe(['audio', 'video'])
        ->and(collect($json['streams'])->pluck('codec_name')->sort()->values()->all())->toBe(['aac', 'h264']);
});

test('a real clip over 90 seconds is refused', function () {
    requireRealTools();

    expect(clipRefusal(realMp4(92, gps: false, size: '32x32'))->getMessage())->toContain('90');
});

test('a file with an MP4 header and nothing real behind it is refused', function () {
    requireRealTools();

    expect(clipRefusal(mp4Bytes(5000))->getMessage())->toContain('تعذّرت');
    expect(MediaAsset::count())->toBe(0);
});

test('a playlist dressed as an MP4 cannot make the tools open other files or reach the network', function () {
    requireRealTools();
    $secret = tempnam(sys_get_temp_dir(), 'raqam-secret-');
    file_put_contents($secret, "TOP-SECRET-CONTENT\n");
    // A concat script and an HLS playlist, both pointing at a local file and at the network, behind a valid MP4 start.
    $evil = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\n#EXTM3U\n#EXTINF:1,\nfile:$secret\nhttp://127.0.0.1:9/never\n#EXT-X-ENDLIST\nffconcat version 1.0\nfile '$secret'\n";

    $message = clipRefusal($evil)->getMessage();

    expect($message)->not->toContain('TOP-SECRET')->and(MediaAsset::count())->toBe(0);
    unlink($secret);
});

test('both programs are told to open plain files only and to read the input as MP4', function () {
    $probe = app(MediaProbe::class)->arguments('/tmp/x.mp4');
    $clean = app(Mp4Cleaner::class)->arguments('/tmp/in.mp4', '/tmp/out.mp4');

    foreach ([$probe, $clean] as $arguments) {
        $at = array_search('-protocol_whitelist', $arguments, true);
        expect($at)->not->toBeFalse()->and($arguments[$at + 1])->toBe('file');
        $f = array_search('-f', $arguments, true);
        expect($arguments[$f + 1])->toBe('mov');
        // The input options come before the file they apply to.
        expect($at)->toBeLessThan(array_search('-i', $arguments, true));
    }
    // Only the first picture track and the sound: the position is also recorded in separate data tracks.
    $maps = array_values(array_filter(array_map(fn ($i) => $clean[$i + 1], array_keys($clean, '-map', true))));
    expect($maps)->toBe(['0:v:0', '0:a?']);
    expect($clean)->toContain('-map_metadata')->and($clean[array_search('-map_metadata', $clean, true) + 1])->toBe('-1')
        ->and($clean)->toContain('copy');
});

test('without ffmpeg the clip is refused rather than stored with its metadata', function () {
    requireRealTools();
    config(['media.ffmpeg' => '/nonexistent/ffmpeg']);

    expect(clipRefusal(realMp4(2, gps: true))->getMessage())->toContain('ffmpeg')->toContain('sudo apt install ffmpeg')
        ->and(MediaAsset::count())->toBe(0)->and(Storage::disk('media_local_public')->allFiles())->toBe([]);
});
