<?php

use App\Content\PostPreview;
use App\Models\Post;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Filament\Facades\Filament;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

beforeEach(fn () => Filament::setCurrentPanel('admin'));

const EVERY_RESPONSE = [
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'Cross-Origin-Opener-Policy' => 'same-origin',
    // No page of another origin may load an answer of the back end as a picture, a script or a frame source (QA-8):
    // the site's pages never do (their media are files the web server sends, outside PHP).
    'Cross-Origin-Resource-Policy' => 'same-origin',
];

function hasSecurityHeaders($response, string $what): void
{
    foreach (EVERY_RESPONSE as $name => $value) {
        expect($response->headers->get($name))->toBe($value, "{$what}: {$name}");
    }
    expect($response->headers->get('Referrer-Policy'))->not->toBeNull("{$what}: Referrer-Policy");
    expect(str_contains((string) $response->headers->get('Permissions-Policy'), 'camera=()'))->toBeTrue("{$what}: Permissions-Policy");

    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect(str_contains($csp, "frame-ancestors 'none'"))->toBeTrue("{$what}: framing is refused");
}

test('the panel cannot be put in somebody else\'s frame, and every page carries the basic headers', function () {
    $panel = '/'.config('raqam.admin_path');

    hasSecurityHeaders($this->get($panel.'/login'), 'sign-in page');
    hasSecurityHeaders($this->actingAs(User::factory()->staff()->create())->get($panel), 'dashboard');
    hasSecurityHeaders($this->get('/nothing-here'), '404 page');
    hasSecurityHeaders($this->getJson('/api/v1/posts'), 'API list');
    hasSecurityHeaders($this->getJson('/api/v1/posts/1'), 'API error');
});

test('panel pages are never stored by the browser, so Back after signing out shows nothing', function () {
    $panel = '/'.config('raqam.admin_path');

    expect($this->get($panel.'/login')->headers->get('Cache-Control'))->toContain('no-store');
    expect($this->actingAs(User::factory()->staff()->create())->get($panel)->headers->get('Cache-Control'))->toContain('no-store');
});

test('HTTPS requests are told to stay on HTTPS', function () {
    $secure = (string) $this->get('https://localhost/'.config('raqam.admin_path').'/login')->headers->get('Strict-Transport-Security');

    expect($secure)->toContain('max-age=')->toContain('includeSubDomains');
    expect($this->get('http://localhost/'.config('raqam.admin_path').'/login')->headers->get('Strict-Transport-Security'))->toBeNull();
});

test('a header an endpoint chose for itself is kept (the preview page says no-referrer)', function () {
    $post = Post::query()->forceCreate(['kind_code' => 'news', 'title' => 'x', 'slug' => 'x', 'body' => '']);
    $url = app(PostPreview::class)->url($post, 24);

    $response = $this->get($url)->assertOk();

    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")->toContain("frame-ancestors 'none'");
});

test('in production every address the back end writes starts with APP_URL over https, whatever Host a request carried', function () {
    config(['app.url' => 'https://api.raqam.example']);
    AppServiceProvider::pinUrls();

    $this->withHeaders(['Host' => 'evil.example'])->get('/livez');
    expect(route('ads.click', ['campaign' => 1, 'placement' => 'top_banner', 'signature' => str_repeat('a', 32)]))->toStartWith('https://api.raqam.example/api/v1/ads/click/')
        ->and(url('/x'))->toBe('https://api.raqam.example/x');
});

test('a file chosen in a panel form is never previewed as SVG (security review)', function () {
    expect(config('livewire.temporary_file_upload.preview_mimes'))->not->toContain('svg')->toContain('png');
});

/*
 * The answers made before the application's own code runs carry the headers too (QA-8, the outside scan): a body
 * larger than PHP takes (413), the maintenance page (503) and a server error (500). The headers' middleware is the
 * outermost one, so whatever answers inside it, it adds them.
 */
test('a body too large to take is refused with every security header', function () {
    $response = $this->call('POST', '/api/v1/contact', server: ['CONTENT_LENGTH' => (string) (PHP_INT_MAX >> 1), 'HTTP_ACCEPT' => 'application/json']);

    expect($response->getStatusCode())->toBe(413);
    hasSecurityHeaders($response, '413');
});

test('the maintenance page carries every security header', function () {
    // Kept in this process's memory, not in the shared storage folder, where the other test processes would see it.
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->forgetInstance(MaintenanceModeManager::class);
    app()->maintenanceMode()->activate([]);
    try {
        $response = $this->get('/'.config('raqam.admin_path').'/login');
        $api = $this->getJson('/api/v1/posts');
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect($response->getStatusCode())->toBe(503)->and($api->getStatusCode())->toBe(503);
    hasSecurityHeaders($response, 'maintenance page');
    hasSecurityHeaders($api, 'maintenance answer of the API');
});

test('a server error carries every security header and shows no trace', function () {
    config(['app.debug' => false]);
    Route::get('/qa-security-boom', fn () => throw new RuntimeException('qa boom in '.__FILE__));
    Route::get('/api/v1/qa-security-boom', fn () => throw new RuntimeException('qa boom in '.__FILE__));

    foreach ([$this->get('/qa-security-boom'), $this->getJson('/api/v1/qa-security-boom')] as $response) {
        expect($response->getStatusCode())->toBe(500)
            ->and($response->getContent())->not->toContain('qa boom')->not->toContain('.php')->not->toContain('Stack trace');
        hasSecurityHeaders($response, '500');
    }
});

/*
 * Every PHP answer says Cross-Origin-Resource-Policy: same-origin (QA-8), so nothing another origin loads (a picture in
 * a mail program or on the website, a feed's enclosure, a social card) may be served through PHP. The media are files
 * the web server (or R2) sends; the one file route PHP has is private-media, for booking attachments seen in the panel
 * (same origin) and nothing linked from outside but by a click (a navigation, which the header does not touch).
 */
test('no route of the back end serves the public media: they are files the web server sends, outside PHP', function () {
    foreach (['/storage/media/2026/11/picture.jpg', '/storage/media/2026/11/picture.webp', '/storage/x.png'] as $path) {
        expect(fn () => Route::getRoutes()->match(Request::create($path)))->toThrow(NotFoundHttpException::class);
    }
    foreach (['public', 'media_local_public'] as $disk) {
        expect(config("filesystems.disks.{$disk}.serve"))->not->toBeTrue("{$disk} is served through PHP");
    }

    $fileRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), '{path}'))->map->uri()->values()->all();
    expect($fileRoutes)->toBe(['private-media/{path}', 'private-media/{path}']);
});
