<?php

use App\Jobs\GenerateMediaVariants;
use App\Media\ImageProcessor;
use App\Media\MediaRejected;
use App\Media\MediaUploader;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Storage::fake('media_local_private');
    Bus::fake([GenerateMediaVariants::class]);
    $this->user = User::factory()->staff()->create();
    $this->uploader = app(MediaUploader::class);
});

function upload(string $bytes, string $name = 'photo.jpg', array $attrs = ['alt_text' => 'وصف'], ?MediaUploader $uploader = null)
{
    return ($uploader ?? app(MediaUploader::class))->store(uploadFile($bytes), $name, $attrs, auth()->id() ?? User::first()?->id);
}

function refusal(string $bytes, string $name = 'photo.jpg', array $attrs = ['alt_text' => 'وصف']): MediaRejected
{
    try {
        upload($bytes, $name, $attrs);
    } catch (MediaRejected $e) {
        return $e;
    }
    test()->fail('The upload was accepted.');
}

// ---- the real type, not the name --------------------------------------------------------------

test('a PHP script named like an image is refused', function () {
    $e = refusal("<?php system(\$_GET['c']); ?>", 'cute.jpg');

    expect($e->getMessage())->toContain('نوع الملف غير مسموح');
    expect(MediaAsset::count())->toBe(0)->and(Storage::disk('media_local_public')->allFiles())->toBe([]);
});

test('a PHP script behind a GIF header is refused', function () {
    refusal("GIF89a;<?php echo 'x'; ?>", 'logo.png');

    expect(MediaAsset::count())->toBe(0);
});

test('HTML, text and executables are refused whatever their name', function (string $content, string $name) {
    expect(refusal($content, $name)->getMessage())->toContain('نوع الملف غير مسموح');
})->with([
    'html as jpg' => ['<html><script>alert(1)</script></html>', 'a.jpg'],
    'text as png' => ["just some text\n", 'a.png'],
    'elf as pdf' => ["\x7FELF\x02\x01\x01", 'a.pdf'],
    'zip as webp' => ["PK\x03\x04\x14\x00", 'a.webp'],
]);

test('a fake PDF is refused, a real one is accepted', function () {
    refusal('<html>not a pdf</html>', 'invoice.pdf');

    $upload = upload("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'file.pdf', []);

    expect($upload->asset->mime_type)->toBe('application/pdf');
});

test('video and Rive files are not accepted yet, with a clear message', function () {
    $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

    expect(refusal($mp4, 'clip.mp4')->getMessage())->toContain('غير مدعوم')
        ->and(refusal('RIVE'.str_repeat("\0", 20), 'a.riv')->getMessage())->toContain('غير مدعوم');
});

test('a valid image with the wrong extension is stored under its real type with a random name', function () {
    $asset = upload(imageBytes(100, 80, 'png'), 'evil.php')->asset;

    expect($asset->mime_type)->toBe('image/png')
        ->and($asset->path)->toMatch('~^\d{4}/\d{2}/[0-9a-f]{40}\.png$~')
        ->and($asset->path)->not->toContain('evil')
        ->and(Storage::disk('media_local_public')->exists($asset->path))->toBeTrue();
});

test('an image that is secretly a script is neutralised by re-encoding: nothing but pixels is stored', function () {
    $poisoned = imageBytes(120, 80).'<?php system($_GET["c"]); ?>';
    $withComment = substr($poisoned, 0, 2)."\xFF\xFE".pack('n', 30).'<?php echo "pwn"; ?>'.substr($poisoned, 2);

    $asset = upload($withComment, 'photo.jpg')->asset;
    $stored = Storage::disk('media_local_public')->get($asset->path);

    expect($stored)->not->toContain('<?php')->and($stored)->not->toContain('system(');
    expect(@getimagesizefromstring($stored))->not->toBeFalse();
});

test('the original name is only a label: directories and tricks are dropped', function (string $given, string $shown) {
    $asset = upload(imageBytes(50, 50), $given)->asset;

    expect($asset->original_name)->toBe($shown)->and($asset->path)->not->toContain('..');
})->with([
    ['../../etc/passwd.jpg', 'passwd.jpg'],
    ['C:\\Users\\x\\a.jpg', 'a.jpg'],
    ["name\0with\x07control.jpg", 'namewithcontrol.jpg'],
]);

// ---- size ---------------------------------------------------------------------------------------

test('a file above the limit is refused, per type', function (string $bytes, string $limitKey) {
    config(["media.limits.{$limitKey}" => 500]);
    config(['media.limits.image_upload' => $limitKey === 'image_upload' ? 500 : 25 * 1024 * 1024]);

    expect(refusal($bytes, 'big')->getMessage())->toContain('أكبر من الحد المسموح');
})->with([
    'image' => [fn () => imageBytes(400, 400, 'png'), 'image_upload'],
    'pdf' => [fn () => "%PDF-1.4\n".str_repeat('x', 2000), 'pdf'],
    'svg' => [fn () => '<svg xmlns="http://www.w3.org/2000/svg"><!-- '.str_repeat('x', 2000).' --></svg>', 'svg'],
]);

test('an empty file is refused', function () {
    expect(refusal('')->getMessage())->toContain('فارغ');
});

test('a picture that claims enormous dimensions is refused before it is decoded', function () {
    // A 1x1 PNG whose header says 20000 x 20000 = 400 million pixels.
    $png = imageBytes(1, 1, 'png');
    $bomb = substr($png, 0, 16).pack('N', 20000).pack('N', 20000).substr($png, 24);

    expect(refusal($bomb)->getMessage())->toContain('كبيرة جداً');
});

test('an oversized picture is shrunk instead of refused', function () {
    config(['media.limits.max_side' => 300]);

    $asset = upload(imageBytes(900, 600))->asset;

    expect($asset->width)->toBe(300)->and($asset->height)->toBe(200);
});

test('a stored image that stays above the stored limit after compression is refused', function () {
    config(['media.limits.image_stored' => 200]);

    expect(refusal(imageBytes(800, 600))->getMessage())->toContain('كبيرة جداً');
});

test('a large PNG without transparency is saved as a JPEG to fit, one with transparency is refused', function () {
    $opaque = imageBytes(800, 600, 'png', noise: true);
    config(['media.limits.image_stored' => 500_000]);
    expect(strlen($opaque))->toBeGreaterThan(500_000);

    expect(upload($opaque)->asset->mime_type)->toBe('image/jpeg');
    expect(refusal(imageBytes(800, 600, 'png', alpha: true, noise: true), 'a.png')->getMessage())->toContain('كبيرة جداً');
});

// ---- EXIF ---------------------------------------------------------------------------------------

test('the test image really carries a GPS position before upload', function () {
    expect(hasGps(jpegWithExif(imageBytes(), gps: true)))->toBeTrue()->and(hasGps(imageBytes()))->toBeFalse();
});

test('the GPS position and every other EXIF tag are gone from the stored file', function () {
    $asset = upload(jpegWithExif(imageBytes(300, 200), gps: true), 'IMG_0001.jpg')->asset;
    $stored = Storage::disk('media_local_public')->get($asset->path);

    expect(hasGps($stored))->toBeFalse()
        ->and(strpos($stored, 'Exif'))->toBeFalse()
        ->and(strpos($stored, "\xFF\xE1"))->toBeFalse();
});

test('a photo the camera held sideways is turned upright before its orientation tag is dropped', function () {
    // 300x100 with the tag "rotate 90 clockwise to view": upright it is 100 wide and 300 tall.
    $asset = upload(jpegWithExif(imageBytes(300, 100), orientation: 6))->asset;

    expect($asset->width)->toBe(100)->and($asset->height)->toBe(300);
});

// ---- alt text, visibility, duplicates -----------------------------------------------------------

test('a public image needs alt text, a private one or a PDF does not', function () {
    expect(refusal(imageBytes(), 'a.jpg', [])->field)->toBe('alt_text');
    expect(refusal(imageBytes(), 'a.jpg', ['alt_text' => '   '])->field)->toBe('alt_text');

    expect(upload(imageBytes(60, 60), 'a.jpg', ['visibility' => 'private'])->asset->alt_text)->toBeNull();
    expect(upload("%PDF-1.4\n%%EOF", 'a.pdf', [])->asset->mime_type)->toBe('application/pdf');
});

test('private files go to the private disk, public ones to the public disk', function () {
    $private = upload(imageBytes(70, 70), 'p.jpg', ['visibility' => 'private'])->asset;
    $public = upload(imageBytes(71, 71), 'q.jpg')->asset;

    expect(Storage::disk('media_local_private')->exists($private->path))->toBeTrue()
        ->and(Storage::disk('media_local_public')->exists($private->path))->toBeFalse()
        ->and(Storage::disk('media_local_public')->exists($public->path))->toBeTrue();
});

test('the same file twice is stored once', function () {
    $bytes = imageBytes(90, 90);
    $first = upload($bytes);
    $second = upload($bytes);

    expect($first->duplicate)->toBeFalse()->and($second->duplicate)->toBeTrue()
        ->and($second->asset->id)->toBe($first->asset->id)->and(MediaAsset::count())->toBe(1)
        ->and(Storage::disk('media_local_public')->allFiles())->toHaveCount(1);
});

test('a rejected row leaves no file behind', function () {
    // alt_text over the column's limit is truncated, so break a CHECK another way: a bad visibility is refused first.
    expect(fn () => upload(imageBytes(), 'a.jpg', ['alt_text' => 'x', 'visibility' => 'secret']))->toThrow(MediaRejected::class);

    expect(Storage::disk('media_local_public')->allFiles())->toBe([]);
});

test('when the database refuses the row after the file was written, the file is removed', function () {
    // An uploader that does not exist: the foreign key refuses the insert, which happens after the file is stored.
    expect(fn () => app(MediaUploader::class)->store(uploadFile(imageBytes()), 'a.jpg', ['alt_text' => 'x'], 987654321))
        ->toThrow(QueryException::class);

    expect(Storage::disk('media_local_public')->allFiles())->toBe([])->and(MediaAsset::count())->toBe(0);
});

test('only description fields can be mass assigned: the file, its type, place and checksum cannot', function () {
    $asset = upload(imageBytes(88, 66))->asset;
    $before = $asset->only(['path', 'mime_type', 'disk', 'visibility', 'checksum_sha256', 'size_bytes', 'uploaded_by']);

    $asset->update(['path' => '../../x.php', 'mime_type' => 'text/html', 'disk' => 'r2', 'visibility' => 'private', 'checksum_sha256' => str_repeat('a', 64), 'size_bytes' => 1, 'uploaded_by' => 1, 'caption' => 'ok']);

    expect($asset->fresh()->only(array_keys($before)))->toBe($before)->and($asset->fresh()->caption)->toBe('ok');
});

test('the uploader and the time of upload are recorded, and the upload is audited', function () {
    $this->actingAs($this->user);
    $asset = upload(imageBytes())->asset;

    expect($asset->uploaded_by)->toBe($this->user->id);
    expect(DB::table('audit_log')->where('subject_table', 'media_assets')->where('action', 'create')->exists())->toBeTrue();
});

test('renditions are queued for pictures only', function () {
    upload(imageBytes(50, 50));
    Bus::assertDispatchedTimes(GenerateMediaVariants::class, 1);

    upload('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>', 'a.svg');
    upload("%PDF-1.4\n%%EOF", 'a.pdf', []);
    Bus::assertDispatchedTimes(GenerateMediaVariants::class, 1);
});

test('a WebP picture stays a WebP', function () {
    $asset = upload(imageBytes(80, 60, 'webp'))->asset;

    expect($asset->mime_type)->toBe('image/webp')->and($asset->width)->toBe(80);
});

test('an AVIF picture stays AVIF where the server can write it, and becomes WebP or JPEG where it cannot', function () {
    // Where the server cannot write AVIF there is no AVIF to feed in; the fallback path is the one under test in the variants file.
    if (! app(ImageProcessor::class)->canEncode('avif')) {
        expect(app(ImageProcessor::class)->canEncode('webp'))->toBeTrue();

        return;
    }

    expect(upload(imageBytes(80, 60, 'avif'))->asset->mime_type)->toBe('image/avif');
});
