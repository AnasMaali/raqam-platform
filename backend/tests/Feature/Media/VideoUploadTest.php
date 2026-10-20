<?php

use App\Jobs\GenerateMediaVariants;
use App\Media\MediaRejected;
use App\Media\MediaUploader;
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
