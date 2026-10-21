<?php

use App\Filament\Pages\Dashboard;
use App\Integrations\DecimalJson;
use App\Integrations\ProviderAlerts;
use App\Integrations\ProviderHealth;
use App\Markets\BankOfIsraelSource;
use App\Markets\RateFetcher;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function rates(string $code): array
{
    return DB::table('exchange_rates')->where('currency_code', $code)->orderBy('effective_at')->pluck('mid_rate')->all();
}

/** A publication a few minutes ago: newer than boiBody()'s, never in the future. */
function freshTime(): string
{
    return now()->subMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z');
}

/** The five fetched board currencies at believable values, with some changed (null = left out). */
function freshRates(array $over): array
{
    return array_filter([...['USD' => '3.11', 'JOD' => '4.38', 'EUR' => '3.48', 'GBP' => '4.14', 'EGP' => '0.057'], ...$over], fn ($v) => $v !== null);
}

function freshBoi(array $over): string
{
    return boiBody(freshRates($over), freshTime());
}

function boiHealth(): object
{
    return DB::table('provider_health')->where('provider_code', 'bank_of_israel')->first();
}

// ---- reading the bank's answer ---------------------------------------------------------------------

test('numbers in an answer are kept as their exact text, never a float', function () {
    $data = DecimalJson::decode('{"a": 0.1000000000000000055511151231257827, "b": [-3.5e2, 12], "c": "7.10", "d": true, "e": null, "f": "say \\"9.9\\""}');

    expect($data)->toBe(['a' => '0.1000000000000000055511151231257827', 'b' => ['-3.5e2', '12'], 'c' => '7.10', 'd' => true, 'e' => null, 'f' => 'say "9.9"']);
});

test('each rate is divided by its unit, exactly, and its publication time is kept to the microsecond', function () {
    $rates = BankOfIsraelSource::read(boiBody());

    expect($rates['USD']->mid)->toBe('3.106')
        ->and($rates['JPY']->mid)->toBe('0.019632')          // 1.9632 per 100 yen
        ->and($rates['LBP']->mid)->toBe('0.00003')           // 0.0003 per 10 pounds
        ->and($rates['EGP']->mid)->toBe('0.057')
        ->and($rates['USD']->publishedAt->format('Y-m-d\TH:i:s.uP'))->toBe('2026-10-20T12:42:27.654638+00:00');
});

test('the half-hourly check stores a rate only when the bank published a new one, and keeps every day', function () {
    fakeBoi(boiBody());
    app(RateFetcher::class)->run();
    app(RateFetcher::class)->run();                          // same publication: nothing new

    expect(rates('USD'))->toBe(['3.1060000000'])
        ->and(rates('EGP'))->toBe(['0.0570000000'])
        ->and(DB::table('exchange_rates')->where('currency_code', 'JPY')->exists())->toBeFalse();   // not on the board

    fakeBoi(boiBody(['USD' => '3.12', 'JOD' => '4.4', 'EUR' => '3.5', 'GBP' => '4.2', 'EGP' => '0.0575'], freshTime()));
    $report = app(RateFetcher::class)->run();

    expect(rates('USD'))->toBe(['3.1060000000', '3.1200000000'])
        ->and($report['stored'])->toBe(['EGP', 'EUR', 'GBP', 'JOD', 'USD'])
        ->and(boiHealth()->consecutive_failures)->toBe(0)
        ->and(boiHealth()->last_success_at)->not->toBeNull();
});

test('an older publication than the one kept is ignored', function () {
    fakeBoi(boiBody());
    app(RateFetcher::class)->run();
    fakeBoi(boiBody(['USD' => '3.0', 'JOD' => '4.3', 'EUR' => '3.4', 'GBP' => '4.1', 'EGP' => '0.056'], '2026-10-19T12:40:00Z'));
    $report = app(RateFetcher::class)->run();

    expect(rates('USD'))->toBe(['3.1060000000'])->and($report['unchanged'])->toContain('USD');
});

test('pegged currencies are never fetched: the board derives AED and SAR from the dollar', function () {
    fakeBoi(boiBody(['USD' => '3.6725', 'JOD' => '4.38', 'EUR' => '3.48', 'GBP' => '4.14', 'EGP' => '0.057', 'AED' => '9.99']));
    app(RateFetcher::class)->run();

    $board = DB::table('rate_board')->whereIn('currency_code', ['AED', 'SAR'])->pluck('mid_rate', 'currency_code');

    expect(DB::table('exchange_rates')->whereIn('currency_code', ['AED', 'SAR'])->exists())->toBeFalse()
        ->and($board['AED'])->toBe('1.0000000000')
        ->and($board['SAR'])->toBe('0.9793333333');
});

test('the change compares with the previous publication day', function () {
    fakeBoi(boiBody(['USD' => '3.000', 'JOD' => '4.3', 'EUR' => '3.4', 'GBP' => '4.1', 'EGP' => '0.056'], '2026-10-19T12:40:00Z'));
    app(RateFetcher::class)->run();
    fakeBoi(boiBody(['USD' => '3.030', 'JOD' => '4.3', 'EUR' => '3.4', 'GBP' => '4.1', 'EGP' => '0.056'], '2026-10-20T12:40:00Z'));
    app(RateFetcher::class)->run();

    expect(DB::table('rate_board')->where('currency_code', 'USD')->value('change_pct'))->toBe('1.00');
});

// ---- answers that must not be believed ---------------------------------------------------------------

test('a broken or strange answer stores nothing, and the last good rate stays', function (Closure|string $answer, string $why, int $status = 200) {
    fakeBoi(boiBody());
    app(RateFetcher::class)->run();

    fakeBoi($answer, $status);
    app(RateFetcher::class)->run();

    expect(rates('USD'))->toBe(['3.1060000000'])
        ->and(boiHealth()->consecutive_failures)->toBe(1)
        ->and(boiHealth()->last_error)->toContain($why)
        ->and(DB::table('rate_board')->where('currency_code', 'USD')->value('mid_rate'))->toBe('3.1060000000');
})->with([
    'not JSON' => ['<html>maintenance</html>', 'ليس JSON'],
    'cut short' => ['{"exchangeRates":[{"key":"USD","currentExchangeRate":3.2', 'ليس JSON'],
    'a list, not an object' => ['[1,2,3]', 'قائمة أسعار'],
    'no rates' => ['{"exchangeRates":[]}', 'قائمة أسعار'],
    'rates not a list' => ['{"exchangeRates":{"USD":3.2}}', 'قائمة أسعار'],
    'too many entries' => ['{"exchangeRates":['.implode(',', array_fill(0, 101, '{"key":"XXX"}')).']}', 'قائمة أسعار'],
    'nested too deep' => ['{"exchangeRates":'.str_repeat('[', 40).str_repeat(']', 40).'}', 'ليس JSON'],
    'negative' => [fn () => fn () => Http::response(freshBoi(['USD' => '-3.2'])), 'USD: سعر غير صالح'],
    'zero' => [fn () => fn () => Http::response(freshBoi(['USD' => '0'])), 'USD: سعر صفر'],
    'below the smallest step' => [fn () => fn () => Http::response(freshBoi(['USD' => '0.00000000001'])), 'USD: سعر صفر'],
    'huge' => [fn () => fn () => Http::response(freshBoi(['USD' => '31060000000'])), 'USD: سعر غير صالح'],
    'ten times yesterday' => [fn () => fn () => Http::response(freshBoi(['USD' => '31.06'])), 'USD: قيمة غير معقولة'],
    'a third of yesterday' => [fn () => fn () => Http::response(freshBoi(['USD' => '1.0'])), 'USD: قيمة غير معقولة'],
    'exponent' => [fn () => fn () => Http::response(freshBoi(['USD' => '3.2e0'])), 'USD: سعر غير صالح'],
    'not a number' => [fn () => fn () => Http::response(str_replace('"currentExchangeRate":3.2,', '"currentExchangeRate":"abc",', freshBoi(['USD' => '3.2']))), 'USD: سعر غير صالح'],
    'unit zero' => [fn () => fn () => Http::response(freshBoi(['USD' => ['3.2', '0']])), 'USD: وحدة غير صالحة'],
    'unit fraction' => [fn () => fn () => Http::response(freshBoi(['USD' => ['3.2', '1.5']])), 'USD: وحدة غير صالحة'],
    'published tomorrow' => [fn () => fn () => Http::response(boiBody(freshRates(['USD' => '3.2']), now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'))), 'USD: تاريخ النشر في المستقبل'],
    'impossible date' => [fn () => fn () => Http::response(boiBody(freshRates(['USD' => '3.2']), '2026-13-40T12:40:00Z')), 'USD: تاريخ نشر غير صالح'],
    'dollar missing' => [fn () => fn () => Http::response(freshBoi(['USD' => null])), 'USD: غير موجود'],
    'dollar twice' => [fn () => fn () => Http::response(str_replace('{"exchangeRates":[', '{"exchangeRates":[{"key":"USD","currentExchangeRate":3.3,"unit":1,"lastUpdate":"'.freshTime().'"},', freshBoi(['USD' => '3.2']))), 'USD: ورد مرتين'],
    'server error' => ['{"error":"x"}', 'بخطأ (500)', 500],
    'redirect elsewhere' => [fn () => fn () => Http::response('', 302, ['Location' => 'https://evil.example/rates']), 'بخطأ (302)'],
    'too large' => ['{"exchangeRates":[], "pad":"'.str_repeat('x', 70000).'"}', 'أكبر من المسموح'],
    'no connection' => [fn () => fn () => throw new ConnectionException('Connection refused'), 'تعذّر الاتصال'],
]);

test('good entries of a partly bad answer are kept, and the bad one is reported', function () {
    fakeBoi(boiBody(['USD' => '-1', 'JOD' => '4.38', 'EUR' => '3.48', 'GBP' => '4.14', 'EGP' => '0.057']));
    $report = app(RateFetcher::class)->run();

    expect($report['stored'])->toBe(['EGP', 'EUR', 'GBP', 'JOD'])
        ->and($report['problems'])->toHaveKey('USD')
        ->and(rates('USD'))->toBe([])
        ->and(boiHealth()->last_error)->toContain('USD');
});

test('a redirect is never followed', function () {
    fakeBoi(fn () => Http::response('', 301, ['Location' => 'https://evil.example/']));
    app(RateFetcher::class)->run();

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
});

// ---- failing three times in a row --------------------------------------------------------------------

test('the third failure in a row alerts every active admin, at most once an hour', function () {
    $admin = User::factory()->staff()->create();
    $other = User::factory()->staff()->create();
    $off = User::factory()->staff()->create(['is_active' => false]);
    $customer = insertUser('customer', 'c@example.com');
    fakeBoi('nope', 503);

    app(RateFetcher::class)->run();
    app(RateFetcher::class)->run();
    expect(DB::table('admin_notifications')->count())->toBe(0)->and(ProviderAlerts::current())->toBe([]);

    app(RateFetcher::class)->run();
    $rows = DB::table('admin_notifications')->get();
    expect($rows->pluck('user_id')->sort()->values()->all())->toBe(collect([$admin->id, $other->id])->sort()->values()->all())
        ->and($rows->first()->category)->toBe('provider')
        ->and($rows->first()->dedupe_key)->toBe('provider:bank_of_israel')
        ->and($rows->first()->title)->toContain('بنك إسرائيل')
        ->and(ProviderAlerts::current()[0])->toContain('فشل 3 مرات متتالية')->toContain('آخر أسعار صحيحة');

    DB::table('admin_notifications')->update(['created_at' => DB::raw("now() - interval '59 minutes'")]);
    app(RateFetcher::class)->run();                          // fourth, within the hour: nothing new
    expect(DB::table('admin_notifications')->count())->toBe(2);

    DB::table('admin_notifications')->update(['created_at' => DB::raw("now() - interval '61 minutes'")]);
    app(RateFetcher::class)->run();                          // an hour later: again
    expect(DB::table('admin_notifications')->count())->toBe(4);

    $this->actingAs($admin)->get(Dashboard::getUrl(panel: 'admin'))->assertOk()->assertSee('مصدر العملات (بنك إسرائيل) فشل 5 مرات متتالية');

    fakeBoi(boiBody());
    app(RateFetcher::class)->run();                          // back: the alert on the home goes away
    expect(ProviderAlerts::current())->toBe([])->and(boiHealth()->consecutive_failures)->toBe(0);
});

test('provider_health never keeps a key, even if a message carries one', function () {
    config(['raqam.youtube.key' => 'yt-SECRET-123456', 'raqam.weather.key' => 'ow-SECRET-abcdef']);

    ProviderHealth::failure('bank_of_israel', 'GET https://x.test/?appid=ow-SECRET-abcdef&q=1 failed; header yt-SECRET-123456; api_key=zzz');

    expect(boiHealth()->last_error)->not->toContain('SECRET')->not->toContain('zzz')->toContain('[hidden]');
});

// ---- when it runs --------------------------------------------------------------------------------------

test('the check runs every 30 minutes from 12:00 to 16:00 Palestine time, Monday to Friday', function () {
    // The scheduler builds its list every minute, so it is built again at each moment tried.
    $due = function (string $local) {
        Carbon::setTestNow(Carbon::parse($local, 'Asia/Hebron'));
        app()->forgetInstance(Schedule::class);
        Facade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'currency:fetch'));

        return $event->isDue(app()) && $event->filtersPass(app());
    };

    expect($due('2026-10-21 12:00'))->toBeTrue()             // Wednesday
        ->and($due('2026-10-21 12:30'))->toBeTrue()
        ->and($due('2026-10-21 15:30'))->toBeTrue()
        ->and($due('2026-10-21 16:00'))->toBeTrue()
        ->and($due('2026-10-23 13:00'))->toBeTrue()          // Friday: the bank publishes
        ->and($due('2026-10-21 11:30'))->toBeFalse()
        ->and($due('2026-10-21 16:30'))->toBeFalse()
        ->and($due('2026-10-21 12:15'))->toBeFalse()
        ->and($due('2026-10-24 13:00'))->toBeFalse()         // Saturday
        ->and($due('2026-10-25 13:00'))->toBeFalse();        // Sunday
    Carbon::setTestNow();
});

test('the fetch command reports what it stored and fails loudly on a problem', function () {
    fakeBoi(boiBody());
    $this->artisan('currency:fetch')->expectsOutputToContain('Stored: EGP, EUR, GBP, JOD, USD')->assertSuccessful();

    fakeBoi('broken', 200);
    $this->artisan('currency:fetch')->assertFailed();
});
