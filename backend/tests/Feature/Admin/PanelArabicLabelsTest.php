<?php

use App\Demo\DemoImages;
use App\Demo\DemoManifest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * The panel speaks Arabic to screen readers too. A field whose label is hidden (->hiddenLabel()) still has one for
 * them: without an Arabic label of its own, Filament makes it from the field's name ("Seo assistant", "Deliverables").
 * Found by the QA crawl of the panel (e2e/qa); the crawl itself runs only on the QA database, so these screens are
 * checked here as well.
 */

function arabicPanelUrl(string $path = ''): string
{
    return '/'.config('raqam.admin_path').$path;
}

beforeEach(function () {
    Storage::fake('media_local_public');
    $this->app->instance(DemoImages::class, new DemoImages(scale: 0.12));
    $this->app->instance(DemoManifest::class, new DemoManifest(storage_path('framework/testing/demo-manifest-'.bin2hex(random_bytes(4)).'.json')));
    $this->artisan('raqam:install', ['--no-migrate' => true])->assertSuccessful();
    $this->admin = User::factory()->staff()->create();
    $this->artisan('raqam:demo', ['--posts' => 11, '--seed' => 3])->assertSuccessful();
    DB::table('not_found_paths')->insertOrIgnore(['path' => '/old-address', 'hits' => 3]);
});

test('no screen reads a label made from a field name, or a translation key', function () {
    $first = fn (string $table, string $key = 'id') => DB::table($table)->orderBy($key)->value($key);
    $episode = DB::table('posts')->where('kind_code', 'episode')->value('id');
    $guest = DB::table('post_guests')->where('post_id', $episode)->value('guest_id') ?? $first('guests');
    $screens = [
        "/posts/{$episode}/edit" => ['Seo assistant', 'Guests'],
        "/guests/{$guest}/edit" => ['Appearances'],
        '/videos/'.$first('videos').'/edit' => ['Usage'],
        '/media-assets/'.$first('media_assets').'/edit' => ['Usage', 'Focal'],
        '/playlists/'.$first('playlists').'/edit' => ['Videos', 'Video id'],
        '/services/'.$first('services').'/edit' => ['Deliverables', 'Faqs', 'Label'],
        '/home-layout' => ['Blocks'],
        '/menus' => ['Header', 'Footer main', 'Footer legal'],
        '/not-found-paths' => [],
    ];

    foreach ($screens as $path => $english) {
        $html = $this->actingAs($this->admin)->get(arabicPanelUrl($path))->assertOk()->getContent();
        foreach ($english as $label) {
            expect($html)->not->toMatch('/>\s*'.preg_quote($label, '/').'\s*</', "{$path} reads «{$label}»");
        }
        expect($html)->not->toContain('filament::', "{$path} shows a translation key");
    }
});

test('the media screen names the post that uses a picture by its title, never by its row\'s columns', function () {
    $media = DB::table('posts')->whereNotNull('cover_media_id')->orderBy('id')->first(['cover_media_id', 'title']);

    $html = $this->actingAs($this->admin)->get(arabicPanelUrl("/media-assets/{$media->cover_media_id}/edit"))->assertOk()->getContent();

    expect($html)->toContain(e("«{$media->title}»"))->not->toMatch('/\b(post_id|media_id|id)=\d/');
});

test('the copy button reads in Arabic (Filament\'s own Arabic file leaves its label out)', function () {
    // The table that shows it loads its rows after the page, so the label is checked where it comes from.
    expect(trans('filament::components/copyable.label', ['label' => 'العنوان'], 'ar'))->toBe('نسخ العنوان')
        ->and(trans('filament::components/copyable.messages.copied', [], 'ar'))->toBe('تم النسخ');
});
