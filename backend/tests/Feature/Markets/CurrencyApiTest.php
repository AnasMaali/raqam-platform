<?php

use App\Jobs\SendSiteWebhook;
use App\Markets\RateFetcher;
use App\Markets\RateOverrides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeBoi(boiBody(['USD' => '3.000', 'JOD' => '4.3', 'EUR' => '3.4', 'GBP' => '4.1', 'EGP' => '0.056'], '2026-10-19T12:40:00Z'));
    app(RateFetcher::class)->run();
    fakeBoi(boiBody(['USD' => '3.030', 'JOD' => '4.25', 'EUR' => '3.4', 'GBP' => '4.1', 'EGP' => '0.0571'], '2026-10-20T12:42:27.6546389Z'));
    app(RateFetcher::class)->run();
    Http::assertSentCount(1);
});

test('the board lists the active currencies in order, as decimal strings rounded to their decimals, with the note', function () {
    $res = api('/currencies')->assertOk();
    matchesContract($res->json('data.0'), 'CurrencyRate');
    $body = $res->json();
    $usd = $body['data'][0];

    expect(array_column($body['data'], 'code'))->toBe(['USD', 'JOD', 'EUR', 'GBP', 'EGP', 'AED', 'SAR'])
        ->and($usd)->toMatchArray(['code' => 'USD', 'mid' => '3.030', 'buy' => '3.015', 'sell' => '3.045', 'previous' => '3.000',
            'change_pct' => '1.00', 'direction' => 'up', 'is_manual' => false, 'is_pegged' => false, 'rate_date' => '2026-10-20',
            'published_at' => '2026-10-20T12:42:27Z'])
        ->and($body['data'][1]['direction'])->toBe('down')
        ->and($body['data'][2]['direction'])->toBe('same')
        ->and($body['data'][4])->toMatchArray(['code' => 'EGP', 'mid' => '0.0571', 'decimals' => 4])
        ->and($body['data'][5])->toMatchArray(['code' => 'AED', 'is_pegged' => true, 'mid' => '0.825'])
        ->and($body['meta'])->toMatchArray(['rate_date' => '2026-10-20', 'source' => 'bank_of_israel',
            'note' => 'الأسعار استرشادية — حسب السعر التمثيلي لبنك إسرائيل', 'published_at' => '2026-10-20T12:42:27Z'])
        ->and($body['meta']['checked_at'])->not->toBeNull();
    // Strings, never JSON numbers: a float would round the rate on its way.
    expect($res->getContent())->toContain('"mid":"3.030"')->not->toMatch('/"(mid|buy|sell|previous|change_pct)":\d/');
});

test('every currency endpoint fits the contract', function () {
    foreach (['/currencies' => 'board', '/currencies/ticker' => 'ticker', '/currencies/converter' => 'converter', '/currencies/usd/history' => 'history'] as $path => $name) {
        $res = api($path)->assertOk()->assertHeader('ETag');
        validateAgainstPath($res->json(), preg_replace('#/currencies/[a-z]{3}/history#', '/currencies/{code}/history', $path));
    }
});

test('the converter says which rate it uses, and the ticker is short', function () {
    $converter = api('/currencies/converter')->json('data');
    $ticker = api('/currencies/ticker')->json('data.0');

    expect($converter['base'])->toBe('ILS')
        ->and($converter['rules']['to_base']['rate'])->toBe('buy')
        ->and($converter['rules']['from_base']['rate'])->toBe('sell')
        ->and($converter['currencies'][0])->toBe(['code' => 'USD', 'name' => 'دولار أمريكي', 'symbol' => '$', 'decimals' => 3, 'buy' => '3.015', 'sell' => '3.045', 'is_manual' => false])
        ->and(array_keys($ticker))->toBe(['code', 'name', 'symbol', 'mid', 'change_pct', 'direction']);
});

test('history has one point per publication day for 30 days, oldest first; unknown or hidden currencies are 404', function () {
    $points = api('/currencies/USD/history')->json('data.points');

    expect($points)->toBe([['date' => '2026-10-19', 'mid' => '3.000'], ['date' => '2026-10-20', 'mid' => '3.030']]);
    api('/currencies/XYZ/history')->assertNotFound();
    api('/currencies/USD/history?days=3')->assertStatus(422);
    api('/currencies/USD/history?days=999')->assertStatus(422);

    DB::table('rate_board_currencies')->where('currency_code', 'USD')->update(['is_active' => false]);
    api('/currencies/SAR/history')->assertOk();
    Cache::flush();
    api('/currencies/USD/history')->assertNotFound();
    expect(array_column(api('/currencies')->json('data'), 'code'))->not->toContain('USD');
});

test('history leaves out days older than asked', function () {
    DB::table('exchange_rates')->insert(['currency_code' => 'USD', 'provider_code' => 'bank_of_israel', 'mid_rate' => '2.9', 'effective_at' => now()->subDays(40), 'fetched_at' => now()]);
    Cache::flush();

    expect(collect(api('/currencies/USD/history')->json('data.points'))->pluck('mid')->all())->not->toContain('2.900')
        ->and(collect(api('/currencies/USD/history?days=60')->json('data.points'))->pluck('mid')->all())->toContain('2.900');
});

test('answering never calls the bank, and a new publication or manual rate shows at once (the cache is dropped)', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake();

    expect(api('/currencies')->json('data.0.mid'))->toBe('3.030');
    Http::assertNothingSent();

    app(RateOverrides::class)->set('USD', '3.1', '3.2', null);
    expect(api('/currencies')->json('data.0'))->toMatchArray(['buy' => '3.100', 'sell' => '3.200', 'is_manual' => true]);

    fakeBoi(boiBody(['USD' => '3.05', 'JOD' => '4.25', 'EUR' => '3.4', 'GBP' => '4.1', 'EGP' => '0.0571'], now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z')));
    app(RateFetcher::class)->run();
    expect(api('/currencies')->json('data.0.mid'))->toBe('3.050');
});

test('a manual rate that runs out leaves the cached board at that moment', function () {
    app(RateOverrides::class)->set('USD', '3.1', '3.2', now()->addMinutes(2));
    expect(api('/currencies')->json('data.0.is_manual'))->toBeTrue();

    // Two minutes pass (the database clock cannot be moved, so the override is moved back instead).
    DB::table('rate_overrides')->update(['valid_from' => DB::raw("valid_from - interval '1 hour'"), 'valid_until' => DB::raw("now() - interval '1 second'")]);
    $this->travel(3)->minutes();

    expect(api('/currencies')->json('data.0'))->toMatchArray(['is_manual' => false, 'buy' => '3.015']);
});

test('the site is told when the board changes', function () {
    config(['raqam.site_webhook.url' => 'https://site.test/hook', 'raqam.site_webhook.secret' => 'shh']);
    Bus::fake([SendSiteWebhook::class]);

    app(RateOverrides::class)->set('USD', '3.1', '3.2', null);

    Bus::assertDispatched(SendSiteWebhook::class, fn ($job) => $job->payload['type'] === 'currencies' && $job->payload['change'] === 'manual_rate' && $job->payload['paths'] === ['/currencies']);
});
