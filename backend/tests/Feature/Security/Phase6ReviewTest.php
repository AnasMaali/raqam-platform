<?php

use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Jobs\FetchCityWeather;
use App\Jobs\FetchCurrencyRates;
use App\Markets\BankOfIsraelSource;
use App\Markets\CurrencyBoard;
use App\Markets\CurrencyCatalog;
use App\Markets\CurrencySource;
use App\Markets\PublishedRate;
use App\Markets\RateBoard;
use App\Markets\RateFetcher;
use App\Models\RateBoardCurrency;
use App\Models\User;
use App\Models\WeatherLocation;
use App\Weather\WeatherBoard;
use App\Weather\WeatherFetcher;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Findings of the phase-6 security review (private/phase6-security-report.md). Each test failed before its fix.

test('F1: an unknown city or currency in the address leaves nothing in the cache', function () {
    fakeBoi(boiBody());
    app(RateFetcher::class)->run();
    $before = count(cacheKeys());

    foreach (range(1, 20) as $i) {
        api('/weather/'.rawurlencode("مدينة-$i"))->assertNotFound();
        api('/currencies/'.chr(65 + $i % 26).'QQ/history?days='.(7 + $i))->assertNotFound();
    }

    expect(count(cacheKeys()))->toBeLessThanOrEqual($before + 2);   // at most the shared city list and board list
});

test('F2: "fetch all weather" from the panel queues one job per city instead of holding the page for minutes', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->staff()->create());
    config(['raqam.weather.key' => 'ow-key']);
    Bus::fake([FetchCityWeather::class]);
    Http::preventStrayRequests();
    Http::fake();

    Livewire::test(ManageCities::class)->callAction(TestAction::make('fetchAll')->table())->assertNotified();

    Bus::assertDispatchedTimes(FetchCityWeather::class, 12);
    Http::assertNothingSent();
});

test('F2: a run of the fetch command stops calling a source that is down after two cities', function () {
    config(['raqam.weather.key' => 'ow-key']);
    $calls = 0;
    fakeOpenWeather(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('down');
    });

    $this->artisan('weather:fetch')->expectsOutputToContain('stopped')->assertFailed();

    expect($calls)->toBe(4)                                           // two cities × two attempts, then stop
        ->and(DB::table('provider_health')->where('provider_code', 'openweathermap')->value('consecutive_failures'))->toBe(2);
});

test('the new endpoints share the API limit', function () {
    config(['raqam.api.limit' => 3]);

    foreach (['/currencies', '/weather/widget'] as $path) {
        Cache::flush();
        foreach (range(1, 3) as $i) {
            api($path)->assertOk();
        }
        api($path)->assertStatus(429);
    }
});

/** Every key the cache store holds (the test store is in memory). */
function cacheKeys(): array
{
    $store = Cache::store()->getStore();
    $storage = (fn () => $this->storage)->call($store);

    // Ours only: the rate limiter keeps its own (constant) keys in the same store.
    return array_values(array_filter(array_keys($storage), fn ($k) => str_contains($k, 'markets:') || str_contains($k, 'weather:')));
}

// ---- second review round ---------------------------------------------------------------------------------

test('F3: a forecast slot whose low is above its high is dropped; the other days are kept', function () {
    $this->travelTo(now('Asia/Hebron')->setTime(10, 0));
    config(['raqam.weather.key' => 'ow-key']);
    $nablus = (int) DB::table('cities')->where('slug', 'نابلس')->value('id');
    $tomorrow = now('Asia/Hebron')->addDay()->toDateString();
    fakeOpenWeather(owCurrent(), owForecast(fn (array $item) => Carbon::createFromTimestamp($item['dt'], 'Asia/Hebron')->toDateString() === $tomorrow
        ? array_replace_recursive($item, ['main' => ['temp_min' => 40, 'temp_max' => 5]]) : $item));

    expect(app(WeatherFetcher::class)->city($nablus))->toBe('stored');

    $days = DB::table('weather_forecasts')->where('city_id', $nablus)->pluck('forecast_date')->all();
    expect($days)->not->toContain($tomorrow)
        ->toContain(now('Asia/Hebron')->toDateString(), now('Asia/Hebron')->addDays(2)->toDateString());
});

test('F4: a database error on one city is recorded without its details, and the other cities are still fetched', function () {
    config(['raqam.weather.key' => 'ow-key']);
    fakeOpenWeather(owCurrent());
    $refused = DB::table('weather_locations')->where('is_active', true)->orderBy('sort_order')->limit(2)->pluck('city_id')->all();
    DB::unprepared("
        CREATE FUNCTION test_refuse_weather() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'secret detail of the failure'; END \$\$;
        CREATE TRIGGER test_refuse_weather BEFORE INSERT ON weather_observations FOR EACH ROW
            WHEN (NEW.city_id IN (".implode(',', $refused).')) EXECUTE FUNCTION test_refuse_weather();
    ');

    $result = app(WeatherFetcher::class)->city($refused[0]);
    $health = DB::table('provider_health')->where('provider_code', 'openweathermap')->first();
    expect($result)->toContain('P0001')->not->toContain('secret detail')
        ->and($health->consecutive_failures)->toBe(1)
        ->and($health->last_error)->toBe($result);

    // Two failing cities in a row are not the source being down: the run goes on to the other ten.
    $this->artisan('weather:fetch')->doesntExpectOutputToContain('stopped')->assertFailed();
    expect(DB::table('weather_observations')->distinct()->count('city_id'))->toBe(10);
});

test('F5: a currency\'s first rate must be within fixed bounds; later ones only within half to double of the last', function () {
    fakeBoi(boiBody(['USD' => '31.06', 'EUR' => '0.34822', 'GBP' => '4.1444', 'JOD' => '4.3812', 'EGP' => '0.057']));
    $report = app(RateFetcher::class)->run();

    expect($report['stored'])->toBe(['EGP', 'GBP', 'JOD'])
        ->and($report['problems'])->toBe([
            'EUR' => 'أول سعر خارج الحدود المعقولة (0.34822، المسموح 2–8).',
            'USD' => 'أول سعر خارج الحدود المعقولة (31.06، المسموح 2–6).',
        ])
        ->and(DB::table('provider_health')->where('provider_code', 'bank_of_israel')->value('last_error'))
        ->toContain('USD: أول سعر خارج الحدود المعقولة (31.06، المسموح 2–6).');

    fakeBoi(boiBody(['USD' => '5.9', 'EUR' => '3.4822', 'GBP' => '4.1444', 'JOD' => '4.3812', 'EGP' => '0.057']));
    expect(app(RateFetcher::class)->run()['stored'])->toBe(['EUR', 'USD']);

    // Past the first, the bounds are not applied (a long drift is the market's), only the jump check.
    fakeBoi(boiBody(['USD' => '6.5', 'EUR' => '3.4822', 'GBP' => '4.1444', 'JOD' => '4.3812', 'EGP' => '0.057'], now()->subMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z')));
    expect(app(RateFetcher::class)->run()['stored'])->toContain('USD');
});

test('F5: every currency the bank publishes has bounds; a currency without them can be neither added nor stored', function () {
    foreach (BankOfIsraelSource::CODES as $code) {
        $bounds = CurrencyCatalog::bounds($code);
        expect($bounds)->not->toBeNull("{$code} has no bounds")
            ->and(BigDecimal::of($bounds[0])->isPositive() && BigDecimal::of($bounds[0])->isLessThan($bounds[1]))->toBeTrue();
    }

    $source = new class implements CurrencySource
    {
        public function code(): string
        {
            return 'bank_of_israel';
        }

        public function available(): bool
        {
            return true;
        }

        public function supports(): array
        {
            return ['CHF', 'XAU'];
        }

        public function fetch(): array
        {
            return ['XAU' => new PublishedRate('XAU', '12000', CarbonImmutable::now()->subHour())];
        }

        public function note(): string
        {
            return '';
        }
    };
    app()->instance(BankOfIsraelSource::class, $source);
    expect(CurrencyBoard::addable())->toHaveKey('CHF')->not->toHaveKey('XAU');

    DB::table('currencies')->insert(['code' => 'XAU', 'name_ar' => 'أونصة ذهب', 'symbol' => 'XAU']);
    DB::table('rate_board_currencies')->insert(['currency_code' => 'XAU']);
    expect(app(RateFetcher::class)->run()['problems']['XAU'])->toBe('لا توجد حدود معقولة معروفة لأول سعر لهذه العملة.')
        ->and(DB::table('exchange_rates')->where('currency_code', 'XAU')->exists())->toBeFalse();
});

test('F6: a currency name already used is a message on the form, not a server error', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->staff()->create());

    Livewire::test(ManageCurrencies::class)
        ->callAction(TestAction::make('edit')->table(RateBoardCurrency::find('EUR')), ['name_ar' => 'دولار أمريكي', 'symbol' => '€', 'buy_percent' => '0.5', 'sell_percent' => '0.5', 'display_decimals' => 3, 'is_active' => true])
        ->assertHasFormErrors(['name_ar' => 'unique']);
    Livewire::test(ManageCurrencies::class)
        ->callAction(TestAction::make('add')->table(), ['code' => 'CHF', 'name_ar' => 'يورو', 'symbol' => 'CHF', 'buy_percent' => '0.5', 'sell_percent' => '0.5', 'display_decimals' => 3, 'is_active' => true])
        ->assertHasFormErrors(['name_ar' => 'unique']);
    expect(DB::table('currencies')->where('code', 'EUR')->value('name_ar'))->toBe('يورو');

    // A currency keeps its own name: saving it unchanged, or adding back one removed from the board, is fine.
    Livewire::test(ManageCurrencies::class)
        ->callAction(TestAction::make('edit')->table(RateBoardCurrency::find('EUR')), ['name_ar' => 'يورو', 'symbol' => '€', 'buy_percent' => '1', 'sell_percent' => '0.5', 'display_decimals' => 3, 'is_active' => true])
        ->assertHasNoFormErrors();
    RateBoardCurrency::find('GBP')->delete();
    Livewire::test(ManageCurrencies::class)
        ->callAction(TestAction::make('add')->table(), ['code' => 'GBP', 'name_ar' => 'جنيه إسترليني', 'symbol' => '£', 'buy_percent' => '0.5', 'sell_percent' => '0.5', 'display_decimals' => 3, 'is_active' => true])
        ->assertHasNoFormErrors();
    expect(RateBoardCurrency::find('GBP'))->not->toBeNull();
});

test('F7: "fetch now" for the currencies and for one city queue a job; the page never waits on the source', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->staff()->create());
    config(['raqam.weather.key' => 'ow-key']);
    Bus::fake([FetchCurrencyRates::class, FetchCityWeather::class]);
    Http::preventStrayRequests();
    Http::fake();
    $jenin = (int) DB::table('cities')->where('slug', 'جنين')->value('id');

    Livewire::test(ManageCurrencies::class)->callAction(TestAction::make('fetch')->table())->assertNotified('أُرسل الطلب.');
    Livewire::test(ManageCities::class)->callAction(TestAction::make('fetch')->table(WeatherLocation::find($jenin)))->assertNotified('أُرسل الطلب.');

    Bus::assertDispatchedTimes(FetchCurrencyRates::class, 1);
    Bus::assertDispatched(FetchCityWeather::class, fn (FetchCityWeather $job) => $job->cityId === $jenin);
    Bus::assertDispatchedTimes(FetchCityWeather::class, 1);
    Bus::assertNotDispatchedSync(FetchCurrencyRates::class);
    Bus::assertNotDispatchedSync(FetchCityWeather::class);
    Http::assertNothingSent();
});

test('F7: the currency job fetches the rates', function () {
    fakeBoi(boiBody());
    FetchCurrencyRates::dispatch();

    expect(DB::table('exchange_rates')->where('currency_code', 'USD')->value('mid_rate'))->toBe('3.1060000000');
});

test('F8: no change of the board is lost when two meet: the cache version goes up once for each', function () {
    foreach (['markets:version' => fn () => RateBoard::changed('board'), 'weather:version' => fn () => WeatherBoard::changed()] as $key => $change) {
        Cache::forever($key, 5);
        // Another process changes the board too, just after this one has read the version (if it reads it at all).
        $others = 0;
        $listening = true;
        Event::listen(CacheHit::class, function (CacheHit $e) use ($key, &$others, &$listening) {
            if ($listening && $e->key === $key && $others === 0) {
                $others++;
                Cache::store()->getStore()->increment($key);
            }
        });

        $change();
        $listening = false;

        expect((int) Cache::get($key))->toBe(5 + 1 + $others);
    }
});
