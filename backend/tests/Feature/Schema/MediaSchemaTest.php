<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Rules enforced by database/schema/02-media.sql, ported from database/tests/schema_tests.sql.

test('a public image needs alt text', function () {
    expect(rejected(fn () => insertMedia(['alt_text' => null])))
        ->toThrow(QueryException::class, '"media_assets_check3"');
});

test('a private image may skip alt text', function () {
    expect(insertMedia(['alt_text' => null, 'visibility' => 'private']))->toBeInt();
});

test('an uploaded clip is at most 90 seconds', function () {
    expect(rejected(fn () => insertMedia([
        'path' => 'v/long.mp4', 'mime_type' => 'video/mp4', 'alt_text' => null,
        'duration_seconds' => 120, 'width' => 1920, 'height' => 1080,
    ])))->toThrow(QueryException::class, '"media_assets_check6"');
});

test('an image is at most 10 MB', function () {
    expect(rejected(fn () => insertMedia(['size_bytes' => 11 * 1024 * 1024])))
        ->toThrow(QueryException::class, '"media_assets_check4"');
});

test('a raster image needs its dimensions', function (string $mime) {
    expect(rejected(fn () => insertMedia(['mime_type' => $mime, 'width' => null, 'height' => null])))
        ->toThrow(QueryException::class, '"media_assets_check2"');
})->with(['image/jpeg', 'image/png', 'image/webp', 'image/avif']);

test('a video needs its dimensions', function () {
    expect(rejected(fn () => insertMedia([
        'path' => 'v/clip.mp4', 'mime_type' => 'video/mp4', 'alt_text' => null,
        'duration_seconds' => 30, 'width' => null, 'height' => null,
    ])))->toThrow(QueryException::class, '"media_assets_check2"');
});

test('width and height come together', function () {
    expect(rejected(fn () => insertMedia(['mime_type' => 'image/svg+xml', 'height' => null])))
        ->toThrow(QueryException::class, '"media_assets_check1"');
});

test('SVG and Rive need no dimensions', function (string $mime, ?string $alt) {
    expect(insertMedia(['mime_type' => $mime, 'alt_text' => $alt, 'width' => null, 'height' => null]))->toBeInt();
})->with([
    'svg' => ['image/svg+xml', 'شعار'],
    'rive' => ['application/x-rive', null],
]);

test('a focal point has both coordinates', function () {
    expect(rejected(fn () => insertMedia(['focal_x' => 0.5])))
        ->toThrow(QueryException::class, '"media_assets_check"');
});

test('deleting the original deletes its renditions', function () {
    $media = insertMedia();
    DB::table('media_variants')->insert([
        'media_id' => $media, 'variant' => 'md', 'format' => 'avif',
        'width' => 400, 'height' => 300, 'path' => 'test/md.avif', 'size_bytes' => 100,
    ]);

    DB::table('media_assets')->delete($media);

    expect(DB::table('media_variants')->where('media_id', $media)->exists())->toBeFalse();
});
