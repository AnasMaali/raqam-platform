<?php

use App\Jobs\GenerateMediaVariants;
use App\Media\MediaUploader;
use App\Media\VariantGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 | The share picture of every item (9.4, 23.8): the 1200×630 rendition is made for every picture; an item's share image is
 | its SEO picture when the admin chose one, otherwise its cover; with neither, the site uses /site's default image.
 */

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake([GenerateMediaVariants::class]);
});

function sharePicture(string $alt): int
{
    $asset = app(MediaUploader::class)->store(uploadFile(imageBytes(1600, 900, noise: true)), 'p.jpg', ['alt_text' => $alt], null)->asset;
    app(VariantGenerator::class)->generate($asset);

    return $asset->id;
}

test('a post without an SEO picture is shared with its cover, in its 1200 by 630 rendition', function () {
    $cover = sharePicture('غلاف');
    $id = insertPost(publishedPostValues(['cover_media_id' => $cover]));

    $share = api("/posts/{$id}")->assertOk()->json('data.seo.share_image');

    expect($share['id'])->toBe($cover)
        ->and($share['variants']['og']['jpeg'])->toMatchArray(['width' => 1200, 'height' => 630]);
});

test('an SEO picture chosen by the admin wins over the cover', function () {
    $cover = sharePicture('غلاف');
    $chosen = sharePicture('صورة مشاركة');
    $seo = DB::table('seo_meta')->insertGetId(['share_media_id' => $chosen]);
    $id = insertPost(publishedPostValues(['cover_media_id' => $cover, 'seo_meta_id' => $seo]));

    expect(api("/posts/{$id}")->json('data.seo.share_image.id'))->toBe($chosen);
});

test('a page with no picture of its own has none, and the site falls back to the default share image of /site', function () {
    $default = sharePicture('صورة رَقَم');
    DB::table('settings')->updateOrInsert(['key' => 'seo.default_image'], ['value' => (string) $default, 'value_type' => 'media']);
    DB::table('pages')->insert(['title' => 'من نحن', 'slug' => 'about', 'is_published' => true]);

    expect(api('/pages/about')->json('data.seo.share_image'))->toBeNull()
        ->and(api('/site')->json('data.seo.default_image.id'))->toBe($default)
        ->and(api('/site')->json('data.seo.default_image.variants.og.jpeg.width'))->toBe(1200);
});
