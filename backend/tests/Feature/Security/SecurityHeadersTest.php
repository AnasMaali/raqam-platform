<?php

use App\Content\PostPreview;
use App\Models\Post;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Filament::setCurrentPanel('admin'));

const EVERY_RESPONSE = [
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'Cross-Origin-Opener-Policy' => 'same-origin',
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
