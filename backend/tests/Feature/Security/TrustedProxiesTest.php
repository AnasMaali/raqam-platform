<?php

use App\Api\SiteToken;
use App\Models\User;
use App\Support\ProductionChecks;
use App\Support\ProxyAddresses;
use App\Support\TrackingHash;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
 * Who the visitor is (24.2). The real chain: visitor -> Cloudflare -> Caddy -> PHP. PHP sees Caddy (here 172.20.0.5,
 * TRUSTED_LOCAL_PROXIES 172.20.0.0/16); Caddy adds the Cloudflare edge it was reached from to X-Forwarded-For;
 * Cloudflare adds the visitor before it and sets CF-Connecting-IP. Whatever the visitor writes in those headers sits
 * further left and must never win. Addresses are from the documentation ranges; the edges are Cloudflare's.
 */

const PROXY_CADDY = '172.20.0.5';
const PROXY_EDGE_V4 = '162.158.1.1';
const PROXY_EDGE_V6 = '2606:4700::6810:1';

beforeEach(function () {
    config(['raqam.proxies.local' => '172.20.0.0/16', 'raqam.proxies.trust_cloudflare' => true]);
    Route::get('/__who', fn (Request $request) => response()->json([
        'ip' => $request->ip(), 'secure' => $request->isSecure(), 'cf' => $request->header('CF-Connecting-IP'), 'site' => SiteToken::visitorIp($request),
    ]));
});

/** One request as it reaches PHP: from `$remote`, with `$headers`. */
function proxiedFrom(string $remote, array $headers = []): array
{
    return test()->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $remote])->withHeaders($headers)->getJson('/__who')->assertOk()->json();
}

/** Through Cloudflare and Caddy, as Cloudflare and Caddy write it: what the visitor sent comes first. */
function throughCloudflare(string $visitor, string $edge = PROXY_EDGE_V4, array $sent = []): array
{
    $forwarded = trim(($sent['X-Forwarded-For'] ?? '').', '.$visitor.', '.$edge, ', ');

    return proxiedFrom(PROXY_CADDY, [...$sent, 'X-Forwarded-For' => $forwarded, 'CF-Connecting-IP' => $visitor, 'X-Forwarded-Proto' => 'https']);
}

// ---- the real chain ------------------------------------------------------------------------------------

test('through Cloudflare and Caddy the visitor is who Cloudflare names, over https', function () {
    expect(throughCloudflare('198.51.100.7'))->toMatchArray(['ip' => '198.51.100.7', 'secure' => true, 'cf' => '198.51.100.7'])
        ->and(throughCloudflare('2001:db8:1::5', PROXY_EDGE_V6)['ip'])->toBe('2001:db8:1::5');
});

test('a forged X-Forwarded-For or CF-Connecting-IP sent through Cloudflare does not win', function () {
    // The visitor writes both; Cloudflare keeps the forged X-Forwarded-For on the left and overwrites CF-Connecting-IP.
    $read = throughCloudflare('198.51.100.7', sent: ['X-Forwarded-For' => '203.0.113.200, 10.0.0.1']);
    expect($read['ip'])->toBe('198.51.100.7');

    // Even if a forged CF-Connecting-IP reached us beside the chain, the chain's own hop decides: here it names the
    // visitor Cloudflare added, and the forged left part is cut.
    $read = proxiedFrom(PROXY_CADDY, ['X-Forwarded-For' => '203.0.113.200, 198.51.100.7, '.PROXY_EDGE_V4, 'CF-Connecting-IP' => '198.51.100.7']);
    expect($read['ip'])->toBe('198.51.100.7');
});

test('the visitor read from the chain alone (no CF-Connecting-IP): the rightmost address that is not a trusted machine', function () {
    expect(proxiedFrom(PROXY_CADDY, ['X-Forwarded-For' => '203.0.113.200, 198.51.100.7, '.PROXY_EDGE_V4])['ip'])->toBe('198.51.100.7');
});

test('a CF-Connecting-IP not sent by Cloudflare is dropped: straight to Caddy, or straight to PHP', function () {
    // An attacker reaches Caddy without Cloudflare (the origin's address found): Caddy adds the attacker's address.
    $read = proxiedFrom(PROXY_CADDY, ['X-Forwarded-For' => '203.0.113.66', 'CF-Connecting-IP' => '198.51.100.7']);
    expect($read)->toMatchArray(['ip' => '203.0.113.66', 'cf' => null]);

    // Or reaches PHP itself: nothing it says about the chain is read.
    $read = proxiedFrom('203.0.113.66', ['X-Forwarded-For' => '198.51.100.7', 'CF-Connecting-IP' => '198.51.100.7', 'X-Forwarded-Proto' => 'https']);
    expect($read)->toMatchArray(['ip' => '203.0.113.66', 'cf' => null, 'secure' => false]);

    // Or reaches PHP itself writing a chain that ends in a Cloudflare edge: the edge did not send it.
    $read = proxiedFrom('203.0.113.66', ['X-Forwarded-For' => '198.51.100.7, '.PROXY_EDGE_V4, 'CF-Connecting-IP' => '198.51.100.7']);
    expect($read)->toMatchArray(['ip' => '203.0.113.66', 'cf' => null]);
});

test('with TRUST_CLOUDFLARE off, an edge is a visitor like any other and its CF-Connecting-IP is dropped', function () {
    config(['raqam.proxies.trust_cloudflare' => false]);

    expect(throughCloudflare('198.51.100.7'))->toMatchArray(['ip' => PROXY_EDGE_V4, 'cf' => null])
        ->and(proxiedFrom(PROXY_CADDY, ['X-Forwarded-For' => '198.51.100.7'])['ip'])->toBe('198.51.100.7');   // Caddy alone is still believed
});

test('straight from a Cloudflare edge (no local proxy): its CF-Connecting-IP is the visitor', function () {
    expect(proxiedFrom(PROXY_EDGE_V4, ['X-Forwarded-For' => '203.0.113.200, 198.51.100.7', 'CF-Connecting-IP' => '198.51.100.7']))
        ->toMatchArray(['ip' => '198.51.100.7', 'cf' => '198.51.100.7']);
});

// ---- IPv6 and IPv4 written as IPv6 ---------------------------------------------------------------------

test('IPv4 written as IPv6 (::ffff:) is IPv4 everywhere in the chain', function () {
    // Caddy reached over a dual-stack socket, the edge and the visitor written as mapped addresses.
    $read = proxiedFrom('::ffff:'.PROXY_CADDY, ['X-Forwarded-For' => '::ffff:198.51.100.7, ::ffff:'.PROXY_EDGE_V4, 'CF-Connecting-IP' => '::ffff:198.51.100.7']);
    expect($read)->toMatchArray(['ip' => '198.51.100.7', 'cf' => '198.51.100.7']);

    // The chain alone, mapped (no CF-Connecting-IP): the visitor comes out as IPv4.
    expect(proxiedFrom(PROXY_CADDY, ['X-Forwarded-For' => '::ffff:198.51.100.7, ::ffff:'.PROXY_EDGE_V4])['ip'])->toBe('198.51.100.7');

    // A mapped address straight to PHP is not mistaken for the local proxy's range nor left mapped.
    expect(proxiedFrom('::ffff:203.0.113.66', ['X-Forwarded-For' => '198.51.100.7'])['ip'])->toBe('203.0.113.66')
        ->and(ProxyAddresses::plain('::ffff:198.51.100.7'))->toBe('198.51.100.7')
        ->and(ProxyAddresses::plain('2001:db8::1'))->toBe('2001:db8::1')
        ->and(ProxyAddresses::plain('not an address'))->toBeNull();
});

test('IPv6 visitors and an IPv6 local proxy', function () {
    config(['raqam.proxies.local' => 'fd00:20::/64']);

    expect(proxiedFrom('fd00:20::5', ['X-Forwarded-For' => '2001:db8:1::5, '.PROXY_EDGE_V6, 'CF-Connecting-IP' => '2001:db8:1::5'])['ip'])->toBe('2001:db8:1::5')
        ->and(proxiedFrom('fd00:21::5', ['X-Forwarded-For' => '2001:db8:1::5', 'CF-Connecting-IP' => '2001:db8:1::5'])['ip'])->toBe('fd00:21::5');
});

// ---- limits count real visitors ------------------------------------------------------------------------

test('a request limit counts each visitor behind Cloudflare separately, and a forged header buys no new count', function () {
    config(['raqam.api.limit' => 2]);
    $get = fn (string $visitor, array $sent = []) => test()->flushHeaders()->withServerVariables(['REMOTE_ADDR' => PROXY_CADDY])
        ->withHeaders([...$sent, 'X-Forwarded-For' => trim(($sent['X-Forwarded-For'] ?? '').', '.$visitor.', '.PROXY_EDGE_V4, ', '), 'CF-Connecting-IP' => $visitor])
        ->getJson('/api/v1/site')->status();

    expect([$get('198.51.100.7'), $get('198.51.100.7'), $get('198.51.100.7')])->toBe([200, 200, 429])
        // Another visitor through the same edge and the same Caddy has its own count.
        ->and($get('198.51.100.8'))->toBe(200)
        ->and($get('2001:db8:1::5'))->toBe(200)
        // The first one writing someone else's address on the left is still the first one.
        ->and($get('198.51.100.7', ['X-Forwarded-For' => '203.0.113.1']))->toBe(429);
});

test('the sign-in\'s per-address count and the audit see the visitor, not Cloudflare', function () {
    Route::get('/__who-hash', fn () => TrackingHash::ip());
    $hash = fn (string $visitor) => test()->flushHeaders()->withServerVariables(['REMOTE_ADDR' => PROXY_CADDY])
        ->withHeaders(['X-Forwarded-For' => $visitor.', '.PROXY_EDGE_V4, 'CF-Connecting-IP' => $visitor])->get('/__who-hash')->getContent();

    expect($hash('198.51.100.7'))->toBe(TrackingHash::of('198.51.100.7'))
        ->and($hash('198.51.100.8'))->not->toBe($hash('198.51.100.7'));
});

// ---- the site's own server ------------------------------------------------------------------------------

test('the site\'s server calls the API directly: its visitor header counts with a valid token, never without', function () {
    config(['raqam.api.site_token' => str_repeat('s', 40)]);
    $site = ['X-Raqam-Site-Token' => str_repeat('s', 40), 'X-Raqam-Visitor-Ip' => '198.51.100.7'];

    // From the site's container through Caddy (or straight), no Cloudflare in between: the named visitor.
    expect(proxiedFrom(PROXY_CADDY, [...$site, 'X-Forwarded-For' => '172.20.0.9'])['site'])->toBe('198.51.100.7')
        ->and(proxiedFrom('172.20.0.9', $site)['site'])->toBe('198.51.100.7')
        ->and(proxiedFrom('172.20.0.9', [...$site, 'X-Raqam-Visitor-Ip' => '::ffff:198.51.100.7'])['site'])->toBe('198.51.100.7')
        ->and(proxiedFrom('172.20.0.9', [...$site, 'X-Raqam-Visitor-Ip' => '2001:db8:1::5'])['site'])->toBe('2001:db8:1::5');

    // A wrong token, or none, through Cloudflare: the header is ignored, the visitor is who Cloudflare names.
    $forged = ['X-Raqam-Site-Token' => 'guess', 'X-Raqam-Visitor-Ip' => '203.0.113.200'];
    expect(throughCloudflare('198.51.100.7', sent: $forged)['site'])->toBe('198.51.100.7')
        ->and(throughCloudflare('198.51.100.7', sent: ['X-Raqam-Visitor-Ip' => '203.0.113.200'])['site'])->toBe('198.51.100.7')
        ->and(proxiedFrom('172.20.0.9', [...$site, 'X-Raqam-Visitor-Ip' => 'nonsense'])['site'])->toBe('172.20.0.9');
});

// ---- the settings ---------------------------------------------------------------------------------------

test('never `*` or a range wider than a local network; Cloudflare\'s ranges present and sound', function () {
    productionConfig();
    config(['raqam.proxies.local' => '172.20.0.0/16,::1', 'raqam.proxies.trust_cloudflare' => true]);
    expect(ProductionChecks::failures())->toBe([]);

    foreach (['*', '172.20.0.5,*', '0.0.0.0/0', '::/0', '10.0.0.0/8', 'fd00::/16', 'proxy.local', '172.20.0.0/33'] as $bad) {
        config(['raqam.proxies.local' => $bad]);
        expect(collect(ProductionChecks::failures())->implode(' '))->toContain('TRUSTED_LOCAL_PROXIES');
    }

    config(['raqam.proxies.local' => '172.20.0.0/16', 'cloudflare.ipv4' => []]);
    expect(collect(ProductionChecks::failures())->implode(' '))->toContain('lists no ranges');
    config(['cloudflare.ipv4' => ['0.0.0.0/0']]);
    expect(collect(ProductionChecks::failures())->implode(' '))->toContain('config/cloudflare.php');
});

test('without TRUSTED_LOCAL_PROXIES only this machine is the local proxy', function () {
    expect((require config_path('raqam.php'))['proxies'])->toBe(['local' => '127.0.0.1,::1', 'trust_cloudflare' => false]);
});

test('the saved Cloudflare ranges are sound, and the trusted machines are never everyone', function () {
    $saved = require config_path('cloudflare.php');
    expect(ProxyAddresses::cloudflareProblems([...$saved['ipv4'], ...$saved['ipv6']]))->toBe([])
        ->and(count($saved['ipv4']))->toBeGreaterThan(5)->and(count($saved['ipv6']))->toBeGreaterThan(3)
        ->and(ProxyAddresses::trusted())->not->toContain('*')->not->toContain('0.0.0.0/0')->not->toContain('::/0');
});

// ---- the weekly check and the rewrite -------------------------------------------------------------------

function fakeCloudflareLists(string $v4, string $v6): void
{
    Http::swap(new Factory);   // a new fake, not one more stub behind the first
    Http::fake(['www.cloudflare.com/ips-v4' => Http::response($v4), 'www.cloudflare.com/ips-v6' => Http::response($v6)]);
}

test('the weekly check: the same lists change nothing; different lists raise one alert and still change nothing', function () {
    $saved = file_get_contents(config_path('cloudflare.php'));
    User::factory()->staff()->create();
    $v4 = implode("\n", config('cloudflare.ipv4'))."\n";
    $v6 = implode("\n", config('cloudflare.ipv6'))."\n";

    fakeCloudflareLists($v4, $v6);
    expect(Artisan::call('proxies:cloudflare-ranges'))->toBe(0)
        ->and(DB::table('admin_notifications')->count())->toBe(0);

    fakeCloudflareLists($v4."198.18.0.0/15\n", $v6);
    expect(Artisan::call('proxies:cloudflare-ranges'))->toBe(0)
        ->and(DB::table('admin_notifications')->where('category', 'system')->pluck('body')->implode(' '))->toContain('198.18.0.0/15')
        ->and(file_get_contents(config_path('cloudflare.php')))->toBe($saved);
});

test('a broken or empty list is refused whole, by the check and by the rewrite', function () {
    $saved = file_get_contents(config_path('cloudflare.php'));
    $v6 = implode("\n", config('cloudflare.ipv6'));
    foreach (['', '<html>error</html>', "173.245.48.0/20\n0.0.0.0/0", "173.245.48.0/20\n2400:cb00::/32", '173.245.48.0/40'] as $broken) {
        fakeCloudflareLists($broken, $v6);
        expect(Artisan::call('proxies:cloudflare-ranges'))->toBe(1, $broken)
            ->and(Artisan::call('proxies:cloudflare-ranges', ['--write' => true]))->toBe(1, $broken);
    }
    Http::swap(new Factory);
    Http::fake(['*' => Http::response('', 500)]);
    expect(Artisan::call('proxies:cloudflare-ranges'))->toBe(1)
        ->and(file_get_contents(config_path('cloudflare.php')))->toBe($saved)
        ->and(DB::table('admin_notifications')->count())->toBe(0);
});

test('--write rewrites config/cloudflare.php with the published lists, in a form the application reads back', function () {
    $path = config_path('cloudflare.php');
    $saved = file_get_contents($path);
    try {
        fakeCloudflareLists("173.245.48.0/20\n198.18.0.0/15\n", "2400:cb00::/32\n");
        expect(Artisan::call('proxies:cloudflare-ranges', ['--write' => true]))->toBe(0);
        $written = require $path;
        expect($written['ipv4'])->toBe(['173.245.48.0/20', '198.18.0.0/15'])->and($written['ipv6'])->toBe(['2400:cb00::/32'])
            ->and($written['checked'])->toBe(now('Asia/Hebron')->format('Y-m-d'));
    } finally {
        file_put_contents($path, $saved);
    }
});

test('the weekly check is scheduled', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'proxies:cloudflare-ranges'));
    expect($events)->toHaveCount(1)
        ->and($events->first()->command)->not->toContain('--write');
});
