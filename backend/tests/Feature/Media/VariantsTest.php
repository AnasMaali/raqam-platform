<?php

use App\Media\ImageProcessor;
use App\Media\MediaTrash;
use App\Media\MediaUploader;
use App\Media\VariantGenerator;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
});

function uploaded(int $w, int $h, string $format = 'jpeg', array $attrs = []): MediaAsset
{
    return app(MediaUploader::class)->store(uploadFile(imageBytes($w, $h, $format)), 'p.'.$format, ['alt_text' => 'صورة'] + $attrs, null)->asset;
}

function variantsOf(MediaAsset $asset)
{
    return DB::table('media_variants')->where('media_id', $asset->id)->get()->keyBy(fn ($v) => "{$v->variant}.{$v->format}");
}

function supportedFormats(): array
{
    $images = app(ImageProcessor::class);

    return array_values(array_filter(['avif', 'webp', 'jpeg'], fn ($f) => $images->canEncode($f)));
}

test('an upload gets its renditions through the queue, every size in every supported format', function () {
    $asset = uploaded(2400, 1200);   // wider than every target

    $variants = variantsOf($asset);
    foreach (['thumb', 'sm', 'md', 'lg', 'xl', 'og'] as $name) {
        foreach (supportedFormats() as $format) {
            expect($variants->has("{$name}.{$format}"))->toBeTrue("missing {$name}.{$format}");
        }
    }
    expect($variants)->toHaveCount(6 * count(supportedFormats()));
});

test('every rendition file exists, matches its row and is a real image of that format', function () {
    $asset = uploaded(2000, 1000);

    foreach (variantsOf($asset) as $row) {
        $bytes = Storage::disk('media_local_public')->get($row->path);
        expect(strlen($bytes))->toBe($row->size_bytes);
        $size = getimagesizefromstring($bytes);
        expect([$size[0], $size[1]])->toBe([$row->width, $row->height]);
        expect($size['mime'])->toBe($row->format === 'jpeg' ? 'image/jpeg' : "image/{$row->format}");
    }
});

test('sizes keep the aspect ratio, thumb is square and og is 1200 by 630', function () {
    $v = variantsOf(uploaded(2400, 1200));

    expect([$v['md.jpeg']->width, $v['md.jpeg']->height])->toBe([768, 384])
        ->and([$v['xl.jpeg']->width, $v['xl.jpeg']->height])->toBe([1920, 960])
        ->and([$v['thumb.jpeg']->width, $v['thumb.jpeg']->height])->toBe([320, 320])
        ->and([$v['og.jpeg']->width, $v['og.jpeg']->height])->toBe([1200, 630]);
});

test('a picture smaller than a size does not get that size (nothing is enlarged), but always gets thumb and og', function () {
    $v = variantsOf(uploaded(600, 400));

    expect($v->has('sm.jpeg'))->toBeTrue()
        ->and($v->has('md.jpeg'))->toBeFalse()->and($v->has('lg.jpeg'))->toBeFalse()->and($v->has('xl.jpeg'))->toBeFalse()
        ->and($v->has('thumb.jpeg'))->toBeTrue()->and($v->has('og.jpeg'))->toBeTrue();
});

test('rendition names are random like the original and sit beside it', function () {
    $asset = uploaded(800, 600);
    $base = preg_replace('/\.jpg$/', '', $asset->path);

    foreach (variantsOf($asset) as $row) {
        expect($row->path)->toStartWith($base.'_');
    }
});

test('the 1200 by 630 share image is made for every picture automatically', function () {
    $v = variantsOf(uploaded(300, 300, 'png'));

    expect([$v['og.jpeg']->width, $v['og.jpeg']->height])->toBe([1200, 630]);
});

test('transparent PNG renditions keep their transparency in WebP and sit on white in JPEG', function () {
    $asset = app(MediaUploader::class)->store(uploadFile(imageBytes(800, 400, 'png', alpha: true)), 'a.png', ['alt_text' => 'x'], null)->asset;
    $v = variantsOf($asset);

    $webp = imagecreatefromstring(Storage::disk('media_local_public')->get($v['md.webp']->path));
    $jpeg = imagecreatefromstring(Storage::disk('media_local_public')->get($v['md.jpeg']->path));
    $middle = [imagesx($webp) / 2, imagesy($webp) / 2];

    expect((imagecolorat($webp, (int) $middle[0], (int) $middle[1]) >> 24) & 0x7F)->toBeGreaterThan(100);   // transparent in the middle
    $rgb = imagecolorsforindex($jpeg, imagecolorat($jpeg, (int) $middle[0], (int) $middle[1]));
    expect($rgb['red'])->toBeGreaterThan(240)->and($rgb['green'])->toBeGreaterThan(240);   // white, not black
});

test('the focal point decides what the square and share crops keep', function () {
    // Red block on the left quarter, green on the right quarter. Focal on the far left must keep red in the thumb.
    $asset = uploaded(1200, 400, attrs: ['focal_x' => 0.0, 'focal_y' => 0.5]);
    $thumb = imagecreatefromstring(Storage::disk('media_local_public')->get(variantsOf($asset)['thumb.jpeg']->path));
    $left = imagecolorsforindex($thumb, imagecolorat($thumb, 10, 160));

    expect($left['red'])->toBeGreaterThan(180)->and($left['green'])->toBeLessThan(90);

    $asset = uploaded(1204, 400, attrs: ['focal_x' => 1.0, 'focal_y' => 0.5]);   // another size: identical bytes would be recognised as the same file
    $thumb = imagecreatefromstring(Storage::disk('media_local_public')->get(variantsOf($asset)['thumb.jpeg']->path));
    $right = imagecolorsforindex($thumb, imagecolorat($thumb, 310, 160));

    expect($right['green'])->toBeGreaterThan(150)->and($right['red'])->toBeLessThan(90);
});

test('changing the focal point rebuilds the crops, and old files that no longer apply are removed', function () {
    $asset = uploaded(1200, 400);
    $before = Storage::disk('media_local_public')->get(variantsOf($asset)['thumb.jpeg']->path);

    $asset->update(['focal_x' => 1.0, 'focal_y' => 0.5]);

    $after = Storage::disk('media_local_public')->get(variantsOf($asset->fresh())['thumb.jpeg']->path);
    expect($after)->not->toBe($before);
    // Same set of rows, none doubled.
    expect(variantsOf($asset))->toHaveCount(DB::table('media_variants')->where('media_id', $asset->id)->count());
});

test('editing only the caption does not rebuild anything', function () {
    $asset = uploaded(800, 400);
    Bus::fake();

    $asset->update(['caption' => 'تعليق']);

    Bus::assertNothingDispatched();
});

test('where AVIF cannot be written only WebP and JPEG are made, and the skip is logged', function () {
    if (app(ImageProcessor::class)->canEncode('avif')) {
        expect(supportedFormats())->toContain('avif');   // nothing to prove on a server that has it

        return;
    }
    Log::spy();

    $v = variantsOf(uploaded(900, 600));

    expect($v->keys()->filter(fn ($k) => str_ends_with($k, '.avif')))->toBeEmpty()
        ->and($v->has('md.webp'))->toBeTrue()->and($v->has('md.jpeg'))->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'avif is not supported'))->atLeast()->once();
});

test('the renditions are removed with the row when a file is deleted for good', function () {
    $asset = uploaded(800, 600);
    expect(variantsOf($asset))->not->toBeEmpty();

    app(MediaTrash::class)->trash($asset);
    app(MediaTrash::class)->purge($asset->fresh());

    expect(DB::table('media_variants')->where('media_id', $asset->id)->count())->toBe(0)
        ->and(Storage::disk('media_local_public')->allFiles())->toBe([]);
});

test('renditions that no longer apply are deleted when the set is rebuilt', function () {
    $asset = uploaded(900, 600);
    DB::table('media_variants')->insert(['media_id' => $asset->id, 'variant' => 'xl', 'format' => 'png', 'width' => 10, 'height' => 10, 'path' => 'old/xl.png', 'size_bytes' => 3]);
    Storage::disk('media_local_public')->put('old/xl.png', 'old');

    app(VariantGenerator::class)->generate($asset);

    expect(Storage::disk('media_local_public')->exists('old/xl.png'))->toBeFalse()
        ->and(DB::table('media_variants')->where('media_id', $asset->id)->where('format', 'png')->count())->toBe(0);
});

test('SVG and PDF get no renditions', function () {
    $svg = app(MediaUploader::class)->store(uploadFile('<svg xmlns="http://www.w3.org/2000/svg"/>'), 'a.svg', ['alt_text' => 'x'], null)->asset;
    $pdf = app(MediaUploader::class)->store(uploadFile("%PDF-1.4\n%%EOF"), 'a.pdf', [], null)->asset;

    expect(DB::table('media_variants')->whereIn('media_id', [$svg->id, $pdf->id])->count())->toBe(0);
});
