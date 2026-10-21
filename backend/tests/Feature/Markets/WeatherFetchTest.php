<?php

use App\Integrations\ProviderAlerts;
use App\Models\User;
use App\Support\ProductionChecks;
use App\Weather\WeatherFetcher;
use App\Weather\WeatherSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const OW_KEY = 'ow-test-KEY-7f3a9c';

beforeEach(function () {
    // Mid-morning in Palestine, so the forecast holds slots of today whenever the tests run.
    $this->travelTo(now('Asia/Hebron')->setTime(10, 0));
    config(['raqam.weather.key' => OW_KEY]);
    $this->nablus = (int) DB::table('cities')->where('slug', 'نابلس')->value('id');
});

function observations(int $city): Collection
{
    return DB::table('weather_observations')->where('city_id', $city)->orderBy('observed_at')->get();
}

function owHealth(): object
{
    return DB::table('provider_health')->where('provider_code', 'openweathermap')->first();
}

// ---- without a key ---------------------------------------------------------------------------------------

test('without a key nothing is called, nothing fails, and the last weather stays', function () {
    fakeOpenWeather(owCurrent());
    app(WeatherFetcher::class)->city($this->nablus);
    config(['raqam.weather.key' => '']);
    fakeOpenWeather(owCurrent());

    expect(app(WeatherFetcher::class)->city($this->nablus))->toBe('skipped')
        ->and(observations($this->nablus))->toHaveCount(1)
        ->and(owHealth()->consecutive_failures)->toBe(0);
    Http::assertNothingSent();
});

// ---- what is kept ------------------------------------------------------------------------------------------

test('the weather now is stored in Celsius and km/h, for the city\'s own coordinates', function () {
    fakeOpenWeather(owCurrent(['weather' => [['id' => 500, 'icon' => '10n']], 'wind' => ['speed' => 3.6, 'deg' => 290]]));

    expect(app(WeatherFetcher::class)->city($this->nablus))->toBe('stored');
    $o = observations($this->nablus)->first();

    expect($o)->temperature_c->toBe('24.3')->feels_like_c->toBe('24.0')->humidity_pct->toBe(48)
        ->wind_kmh->toBe('13.0')->wind_deg->toBe(290)->condition_code->toBe(500)->is_day->toBeFalse()
        ->and(owHealth()->last_success_at)->not->toBeNull();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/data/2.5/weather') && $r['lat'] === '32.221100' && $r['lon'] === '35.254400' && $r['units'] === 'metric' && $r['appid'] === OW_KEY);
});

test('the same observation twice is kept once; a newer one is added', function () {
    $dt = now()->subMinutes(40)->timestamp;
    fakeOpenWeather(owCurrent(['dt' => $dt]));
    app(WeatherFetcher::class)->city($this->nablus);
    expect(app(WeatherFetcher::class)->city($this->nablus))->toBe('unchanged');

    fakeOpenWeather(owCurrent(['dt' => $dt + 1800, 'main' => ['temp' => 25]]));
    app(WeatherFetcher::class)->city($this->nablus);

    expect(observations($this->nablus)->pluck('temperature_c')->all())->toBe(['24.3', '25.0']);
});

test('the forecast becomes one row a day (Palestine dates): low and high over the day, the picture at midday; it replaces the last one', function () {
    fakeOpenWeather(owCurrent());
    app(WeatherFetcher::class)->city($this->nablus);

    $rows = DB::table('weather_forecasts')->where('city_id', $this->nablus)->orderBy('forecast_date')->get();
    $today = now('Asia/Hebron')->toDateString();
    $tomorrow = now('Asia/Hebron')->addDay()->toDateString();

    expect($rows->first()->forecast_date)->toBe($today)
        ->and($rows->count())->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(6)
        ->and($rows->firstWhere('forecast_date', $tomorrow))->condition_code->toBe(801)
        ->temp_min_c->toBe('14.0')->temp_max_c->toBe('28.0');   // night 15 + (0..2) − 1, day 25 + (0..2) + 1

    DB::table('weather_forecasts')->insert(['city_id' => $this->nablus, 'forecast_date' => now('Asia/Hebron')->addDays(9)->toDateString(),
        'temp_min_c' => 1, 'temp_max_c' => 2, 'condition_code' => 800, 'provider_code' => 'openweathermap']);
    app(WeatherFetcher::class)->city($this->nablus);
    expect(DB::table('weather_forecasts')->where('city_id', $this->nablus)->max('forecast_date'))->toBeLessThan(now('Asia/Hebron')->addDays(9)->toDateString());
});

test('today\'s low and high gather over the day, and stay when no slot of today is left', function () {
    $this->travelTo(now('Asia/Hebron')->setTime(9, 0));
    fakeOpenWeather(owCurrent(), owForecast(fn ($s, $i, $hour) => $i === 0 ? array_replace_recursive($s, ['main' => ['temp_min' => 3, 'temp_max' => 40]]) : $s));
    app(WeatherFetcher::class)->city($this->nablus);

    $this->travelTo(now('Asia/Hebron')->setTime(23, 30));
    fakeOpenWeather(owCurrent(), owForecast());                 // starts after midnight: nothing of today
    app(WeatherFetcher::class)->city($this->nablus);

    $today = DB::table('weather_forecasts')->where('city_id', $this->nablus)->where('forecast_date', now('Asia/Hebron')->toDateString())->first();
    expect($today)->not->toBeNull()->temp_min_c->toBe('3.0')->temp_max_c->toBe('40.0');
});

test('a day at the far end with too few slots is left out instead of showing a night-only low and high', function () {
    fakeOpenWeather(owCurrent(), owForecast(count: 14));        // the rest of today, tomorrow, and two slots of the day after
    app(WeatherFetcher::class)->city($this->nablus);

    $dates = DB::table('weather_forecasts')->where('city_id', $this->nablus)->pluck('forecast_date')->all();
    expect($dates)->toContain(now('Asia/Hebron')->addDay()->toDateString())->not->toContain(now('Asia/Hebron')->addDays(2)->toDateString());
    $counts = collect(json_decode(owForecast(count: 14), true)['list'])
        ->groupBy(fn ($s) => Carbon::createFromTimestamp($s['dt'])->timezone('Asia/Hebron')->toDateString())->map->count();

    foreach ($counts as $date => $n) {
        expect(in_array($date, $dates, true))->toBe($date === now('Asia/Hebron')->toDateString() || $n >= 4);
    }
});

test('an unknown condition code takes its group\'s general one', function () {
    fakeOpenWeather(owCurrent(['weather' => [['id' => 899, 'icon' => '04d']]]));
    app(WeatherFetcher::class)->city($this->nablus);

    expect(observations($this->nablus)->first()->condition_code)->toBe(804);
});

// ---- answers that must not be believed -------------------------------------------------------------------

test('a strange answer stores nothing new, the last weather stays, and the failure is recorded', function (Closure|string $current, ?string $forecast, string $why) {
    fakeOpenWeather(owCurrent(['dt' => now()->subHours(2)->timestamp]));
    app(WeatherFetcher::class)->city($this->nablus);
    $forecastBefore = DB::table('weather_forecasts')->where('city_id', $this->nablus)->count();

    fakeOpenWeather($current, $forecast);
    app(WeatherFetcher::class)->city($this->nablus);

    expect(observations($this->nablus))->toHaveCount(1)
        ->and(DB::table('weather_forecasts')->where('city_id', $this->nablus)->count())->toBe($forecastBefore)
        ->and(owHealth()->consecutive_failures)->toBe(1)
        ->and(owHealth()->last_error)->toContain($why);
})->with([
    'not JSON' => ['<html>oops</html>', null, 'ليس JSON'],
    'too hot' => [fn () => owCurrent(['main' => ['temp' => 500]]), null, 'للطقس الحالي غير صالح'],
    'too cold' => [fn () => owCurrent(['main' => ['temp' => -80]]), null, 'للطقس الحالي غير صالح'],
    'temperature as words' => [fn () => owCurrent(['main' => ['temp' => 'hot']]), null, 'للطقس الحالي غير صالح'],
    'no condition' => [fn () => owCurrent(['weather' => null]), null, 'للطقس الحالي غير صالح'],
    'a bad icon' => [fn () => owCurrent(['weather' => [['id' => 800, 'icon' => '<svg>']]]), null, 'للطقس الحالي غير صالح'],
    'a code outside every group' => [fn () => owCurrent(['weather' => [['id' => 150, 'icon' => '01d']]]), null, 'رمز حالة طقس غير معروف'],
    'measured tomorrow' => [fn () => owCurrent(['dt' => now()->addDay()->timestamp]), null, 'للطقس الحالي غير صالح'],
    'two days old' => [fn () => owCurrent(['dt' => now()->subDays(2)->timestamp]), null, 'للطقس الحالي غير صالح'],
    'forecast not a list' => [fn () => owCurrent(), '{"list": {"a": 1}}', 'للتوقعات غير صالح'],
    'forecast too long' => [fn () => owCurrent(), fn () => owForecast(count: 61), 'للتوقعات غير صالح'],
    'forecast all broken' => [fn () => owCurrent(), fn () => owForecast(fn ($s) => ['dt' => 'x'] + $s), 'للتوقعات غير صالح'],
    'bad key' => [fn () => fn () => Http::response('{"cod":401,"message":"Invalid API key."}', 401), null, 'بخطأ (401)'],
    'server error' => [fn () => fn () => Http::response('', 502), null, 'بخطأ (502)'],
    'redirect' => [fn () => fn () => Http::response('', 302, ['Location' => 'https://evil.example/']), null, 'بخطأ (302)'],
    'too large' => [fn () => owCurrent(['pad' => str_repeat('x', 300000)]), null, 'أكبر من المسموح'],
    'no connection' => [fn () => fn () => throw new ConnectionException('cURL error 7: Failed to connect to api.openweathermap.org for https://api.openweathermap.org/data/2.5/weather?lat=1&appid='.OW_KEY), null, 'تعذّر الاتصال'],
]);

test('humidity, wind and feels-like outside their range are dropped, the rest is kept', function () {
    fakeOpenWeather(owCurrent(['main' => ['humidity' => 150, 'feels_like' => 99], 'wind' => ['speed' => -2, 'deg' => 400]]));
    app(WeatherFetcher::class)->city($this->nablus);

    expect(observations($this->nablus)->first())->temperature_c->toBe('24.3')->humidity_pct->toBeNull()->wind_kmh->toBeNull()->wind_deg->toBeNull()->feels_like_c->toBeNull();
});

// ---- the key ------------------------------------------------------------------------------------------------

test('the key, which travels in the address, never reaches provider_health, an alert, a log line or a message', function () {
    User::factory()->staff()->create();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.json_encode($e->context);
    });
    fakeOpenWeather(fn () => throw new ConnectionException('cURL error 28 for https://api.openweathermap.org/data/2.5/weather?lat=32.2&lon=35.2&units=metric&appid='.OW_KEY));

    $results = [];
    foreach (range(1, 3) as $i) {
        $results[] = app(WeatherFetcher::class)->city($this->nablus);
    }
    $this->artisan('weather:fetch', ['city' => 'نابلس'])->doesntExpectOutputToContain(OW_KEY)->assertFailed();

    $everything = json_encode([$results, $logged, DB::table('provider_health')->get(), DB::table('admin_notifications')->get(),
        DB::table('failed_jobs')->get(), ProviderAlerts::current()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($everything)->not->toContain(OW_KEY)->not->toContain('appid')
        ->and(DB::table('admin_notifications')->count())->toBe(1)
        ->and(file_exists(storage_path('logs/testing.log')) ? file_get_contents(storage_path('logs/testing.log')) : '')->not->toContain(OW_KEY);
});

test('the key is not in any answer of the API', function () {
    fakeOpenWeather(owCurrent());
    app(WeatherFetcher::class)->city($this->nablus);

    foreach (['/weather/cities', '/weather/widget', '/weather/'.rawurlencode('نابلس')] as $path) {
        expect(api($path)->assertOk()->getContent())->not->toContain(OW_KEY)->not->toContain('appid');
    }
});

// ---- when it runs ----------------------------------------------------------------------------------------------

test('every visible city is due once an hour, spread over the hour', function () {
    $minutes = collect(range(0, 59))->flatMap(fn ($m) => collect(WeatherSchedule::due($m))->map(fn ($id) => [$id, $m]));

    expect($minutes)->toHaveCount(12)
        ->and($minutes->pluck(0)->unique())->toHaveCount(12)
        ->and($minutes->pluck(1)->all())->toBe([0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55]);

    DB::table('weather_locations')->where('city_id', $this->nablus)->update(['is_active' => false]);
    $hidden = collect(range(0, 59))->flatMap(fn ($m) => WeatherSchedule::due($m));
    expect($hidden)->toHaveCount(11)->not->toContain($this->nablus);
});

test('the scheduler asks every minute for the cities due, and the command fetches only those', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'weather:fetch --due'));
    expect($event->expression)->toBe('* * * * *');

    $this->travelTo(now()->startOfHour()->addMinutes(5));
    fakeOpenWeather(owCurrent());
    $this->artisan('weather:fetch --due')->assertSuccessful();

    expect(DB::table('weather_observations')->distinct()->pluck('city_id')->all())->toBe(WeatherSchedule::due(5));
    Http::assertSentCount(2);                                   // one city: now and forecast
});

test('production refuses to start with the test stand-in for OpenWeather set', function () {
    productionConfig(['raqam.weather.api_base' => 'http://127.0.0.1:9999']);
    expect(ProductionChecks::failures())->toBe(['OPENWEATHER_API_BASE is for browser tests only and must not be set.']);
});
