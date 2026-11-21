<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(fn () => Filament::setCurrentPanel('admin'));

/*
 * The panel's content security policy (decision after QA-8): enforced on every HTML page of the back end, so a script
 * that got into a page can neither load another script nor send anything anywhere but the panel. Filament's Alpine
 * evaluates the expressions written in the page (Livewire 3 bundles Alpine's standard build, which runs them through
 * `new Function`), hence 'unsafe-eval'; Filament writes inline scripts with no nonce, hence 'unsafe-inline'.
 */
function panelPolicy(string $media = ''): string
{
    return implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: blob:{$media} https://i.ytimg.com",
        "media-src 'self' blob:{$media}",
        "font-src 'self'",
        "connect-src 'self' blob:",
        "worker-src 'self' blob:",
        "frame-src 'none'",
        "object-src 'none'",
        "base-uri 'none'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        'report-uri /csp-report',
    ]);
}

test('every page of the panel carries the policy, the media\'s origins in it', function () {
    config(['raqam.csp.media_origins' => 'https://media.raqam.example, https://cdn.raqam.example/']);
    $panel = '/'.config('raqam.admin_path');

    foreach ([$this->get($panel.'/login'), $this->actingAs(User::factory()->staff()->create())->get($panel), $this->get('/nothing-here')] as $response) {
        expect($response->headers->get('Content-Security-Policy'))->toBe(panelPolicy(' https://media.raqam.example https://cdn.raqam.example'));
    }
});

test('without MEDIA_ORIGINS the media come from the back end itself, and YouTube\'s pictures from where they are fetched', function () {
    config(['raqam.csp.media_origins' => '', 'raqam.youtube.image_base' => null]);

    expect($this->get('/'.config('raqam.admin_path').'/login')->headers->get('Content-Security-Policy'))->toBe(panelPolicy());

    config(['raqam.youtube.image_base' => 'http://127.0.0.1:9911/vi']);
    expect($this->get('/'.config('raqam.admin_path').'/login')->headers->get('Content-Security-Policy'))
        ->toContain("img-src 'self' data: blob: http://127.0.0.1:9911;");
});

test('a value of MEDIA_ORIGINS that is no origin is left out', function () {
    config(['raqam.csp.media_origins' => "https://ok.example javascript:alert(1) https://ok.example/path *.evil 'unsafe-inline' ftp://x.example"]);

    $policy = (string) $this->get('/'.config('raqam.admin_path').'/login')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("img-src 'self' data: blob: https://ok.example https://i.ytimg.com;")
        ->not->toContain('javascript')->not->toContain('evil')->not->toContain('ftp');
});

test('answers that are not pages keep the short policy, and the preview its own stricter one', function () {
    $json = $this->getJson('/api/v1/posts');

    expect($json->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
});

// ---- the reports ---------------------------------------------------------------------------------------------------

function cspReport(array $report, string $type = 'application/csp-report')
{
    return test()->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => $type, 'REMOTE_ADDR' => '198.51.100.7'], json_encode($report));
}

test('a report is one line of the log, without the query, and with the panel\'s address hidden', function () {
    Log::spy();
    $panel = '/'.config('raqam.admin_path');

    cspReport(['csp-report' => [
        'effective-directive' => 'script-src-elem', 'blocked-uri' => 'https://evil.example/x.js?token=secret#f',
        'document-uri' => "http://localhost{$panel}/posts/5/edit?tab=seo",
    ]])->assertNoContent();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($line) => $line === '[csp] script-src-elem blocked https://evil.example/x.js on http://localhost/<panel>/posts/5/edit');
});

test('the browsers\' newer report format is read too', function () {
    Log::spy();

    cspReport([[
        'type' => 'csp-violation',
        'body' => ['effectiveDirective' => 'connect-src', 'blockedURL' => 'https://evil.example/collect', 'documentURL' => 'http://localhost/x'],
    ]], 'application/reports+json')->assertNoContent();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($line) => $line === '[csp] connect-src blocked https://evil.example/collect on http://localhost/x');
});

test('anything else is refused and nothing is written', function () {
    Log::spy();

    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(415);
    cspReport(['csp-report' => ['effective-directive' => 'not a directive!', 'blocked-uri' => 'x', 'document-uri' => 'http://localhost/']])->assertStatus(400);
    cspReport(['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => 'x', 'document-uri' => 'javascript:alert(1)']])->assertStatus(400);
    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], 'not json')->assertStatus(400);
    cspReport(['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => str_repeat('a', 9000), 'document-uri' => 'http://localhost/']])->assertStatus(413);
    $this->get('/csp-report')->assertStatus(405);

    Log::shouldNotHaveReceived('warning');
});

test('one address sends at most 60 reports a minute; each writes a line, never a session', function () {
    Log::spy();
    RateLimiter::clear('csp-report');
    $report = ['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => 'https://x.example/a.png', 'document-uri' => 'http://localhost/']];

    for ($i = 0; $i < 60; $i++) {
        $response = cspReport($report)->assertNoContent();
    }
    cspReport($report)->assertStatus(429);

    expect($response->headers->getCookies())->toBe([]);
    Log::shouldHaveReceived('warning')->times(60);
});

// ---- nothing from outside --------------------------------------------------------------------------------------------

test('an admin\'s picture is drawn by the panel itself, never fetched from an outside service', function () {
    $user = User::factory()->staff()->create(['name' => 'سامي خليل']);

    $html = $this->actingAs($user)->get('/'.config('raqam.admin_path'))->assertOk()->getContent();

    expect($html)->not->toContain('ui-avatars.com')
        ->and(Filament::getUserAvatarUrl($user))->toStartWith('data:image/svg+xml;base64,');
    expect(base64_decode(substr(Filament::getUserAvatarUrl($user), strlen('data:image/svg+xml;base64,'))))->toContain('>س<');
});
