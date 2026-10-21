<?php

use App\Filament\Resources\Cities\CityResource;
use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Models\User;
use App\Models\WeatherLocation;
use App\Weather\WeatherFetcher;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mid-morning in Palestine, so the forecast holds slots of today whenever the tests run.
    $this->travelTo(now('Asia/Hebron')->setTime(10, 0));
    config(['raqam.weather.key' => 'ow-test-key']);
    $this->nablus = (int) DB::table('cities')->where('slug', 'نابلس')->value('id');
    $this->jenin = (int) DB::table('cities')->where('slug', 'جنين')->value('id');
    fakeOpenWeather(owCurrent(['main' => ['temp' => 30.04, 'humidity' => 40], 'weather' => [['id' => 800, 'icon' => '01n']]]));
    app(WeatherFetcher::class)->city($this->nablus);
});

function cityPath(string $slug): string
{
    return '/weather/'.rawurlencode($slug);
}

// ---- API -----------------------------------------------------------------------------------------------

test('every weather endpoint fits the contract and carries the OpenWeather credit', function () {
    foreach (['/weather/cities' => '/weather/cities', '/weather/widget' => '/weather/widget', cityPath('نابلس') => '/weather/{slug}', cityPath('جنين') => '/weather/{slug}'] as $path => $template) {
        $res = api($path)->assertOk()->assertHeader('ETag');
        validateAgainstPath($res->json(), $template);
        expect($res->json('meta.attribution'))->toBe('Weather data © OpenWeather');
    }
});

test('a city page shows the weather now with today\'s low and high, and the next five days', function () {
    $data = api(cityPath('نابلس'))->json('data');

    expect($data)->toMatchArray(['slug' => 'نابلس', 'name' => 'نابلس', 'path' => '/weather/نابلس', 'is_default' => true])
        ->and($data['now'])->toMatchArray(['temperature' => 30, 'humidity' => 40, 'wind_kmh' => 13, 'is_day' => false, 'stale' => false])
        ->and($data['now']['condition'])->toBe(['code' => 800, 'text' => 'صافٍ', 'icon' => 'clear-night'])
        ->and($data['now']['today']['max'])->toEqual(30)             // the forecast's high stretched to what was measured
        ->and($data['forecast'])->toHaveCount(5)
        ->and($data['forecast'][0]['date'])->toBe(now('Asia/Hebron')->toDateString())
        ->and($data['forecast'][1]['date'])->toBe(now('Asia/Hebron')->addDay()->toDateString())
        ->and($data['forecast'][1]['condition']['icon'])->toBe('partly-cloudy-day');
});

test('a city never fetched has no weather yet; hidden and unknown cities are 404', function () {
    expect(api(cityPath('جنين'))->json('data.now'))->toBeNull();

    DB::table('weather_locations')->where('city_id', $this->jenin)->update(['is_active' => false]);
    Cache::flush();
    api(cityPath('جنين'))->assertNotFound();
    api(cityPath('مدينة-غير-موجودة'))->assertNotFound();
    api('/weather/'.str_repeat('ا', 200))->assertNotFound();
    expect(api('/weather/cities')->json('data.*.slug'))->not->toContain('جنين')->toHaveCount(11);
});

test('the widget lists every visible city in order with its weather now, and the coordinates for the nearest city', function () {
    $widget = api('/weather/widget')->json('data');

    expect($widget)->toHaveCount(12)
        ->and($widget[0])->toMatchArray(['slug' => 'نابلس', 'is_default' => true, 'latitude' => 32.2211, 'longitude' => 35.2544])
        ->and($widget[0]['now']['temperature'])->toEqual(30)
        ->and($widget[1]['now'])->toBeNull()
        ->and(array_keys($widget[0]['now']))->toBe(['temperature', 'condition', 'is_day', 'observed_at', 'stale']);
});

test('old weather is marked stale', function () {
    DB::table('weather_observations')->update(['observed_at' => now()->subHours(4)]);
    Cache::flush();

    expect(api(cityPath('نابلس'))->json('data.now.stale'))->toBeTrue();
});

test('answering never calls OpenWeather, and new weather shows at once', function () {
    fakeOpenWeather(owCurrent());
    expect(api(cityPath('نابلس'))->json('data.now.temperature'))->toEqual(30);
    Http::assertNothingSent();

    fakeOpenWeather(owCurrent(['dt' => now()->subMinute()->timestamp, 'main' => ['temp' => 18]]));
    app(WeatherFetcher::class)->city($this->nablus);
    expect(api(cityPath('نابلس'))->json('data.now.temperature'))->toEqual(18);
});

test('no endpoint takes a visitor\'s position', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/weather'));

    expect($routes->map->uri()->values()->all())->toBe(['api/v1/weather/cities', 'api/v1/weather/widget', 'api/v1/weather/{slug}'])
        ->and(api('/weather/widget?lat=32.1&lon=35.2')->json('data.0.slug'))->toBe('نابلس');
});

// ---- the cities screen -----------------------------------------------------------------------------------

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
});

test('the admin changes a city\'s name, coordinates and visibility; the address stays, and it is in the audit log', function () {
    $this->actingAs($this->admin);
    Livewire::test(ManageCities::class)
        ->callAction(TestAction::make('edit')->table(WeatherLocation::find($this->jenin)), ['name_ar' => 'جنين المدينة', 'latitude' => '32.4600', 'longitude' => '35.3000', 'is_active' => false])
        ->assertHasNoFormErrors()->assertNotified();

    $city = DB::table('cities')->find($this->jenin);
    expect($city)->name_ar->toBe('جنين المدينة')->slug->toBe('جنين')->latitude->toBe('32.460000')
        ->and(DB::table('weather_locations')->where('city_id', $this->jenin)->value('is_active'))->toBeFalse()
        ->and(DB::table('audit_log')->where('subject_table', 'cities')->where('subject_id', $this->jenin)->exists())->toBeTrue()
        ->and(DB::table('audit_log')->where('subject_table', 'weather_locations')->where('subject_id', $this->jenin)->exists())->toBeTrue()
        ->and(api('/weather/cities')->json('data.*.slug'))->not->toContain('جنين');
});

test('coordinates outside Palestine, swapped or malformed, are refused', function (string $lat, string $lon, array $errors) {
    $this->actingAs($this->admin);
    Livewire::test(ManageCities::class)
        ->callAction(TestAction::make('edit')->table(WeatherLocation::find($this->jenin)), ['name_ar' => 'جنين', 'latitude' => $lat, 'longitude' => $lon, 'is_active' => true])
        ->assertHasFormErrors($errors);

    expect(DB::table('cities')->where('id', $this->jenin)->value('latitude'))->toBe('32.464600');
})->with([
    'swapped' => ['35.29', '32.46', ['latitude', 'longitude']],
    'far away' => ['48.85', '2.35', ['latitude', 'longitude']],
    'words' => ['north', '35.2', ['latitude']],
    'too precise' => ['32.1234567', '35.2', ['latitude']],
]);

test('the default city stays visible', function () {
    $this->actingAs($this->admin);
    Livewire::test(ManageCities::class)
        ->callAction(TestAction::make('edit')->table(WeatherLocation::find($this->nablus)), ['name_ar' => 'نابلس', 'latitude' => '32.2211', 'longitude' => '35.2544', 'is_active' => false]);

    expect(DB::table('weather_locations')->where('city_id', $this->nablus)->value('is_active'))->toBeTrue();
});

test('a new city gets an Arabic address and the last place; the list is dragged into order', function () {
    $this->actingAs($this->admin);
    Livewire::test(ManageCities::class)
        ->callAction(TestAction::make('add')->table(), ['name_ar' => 'دير البلح', 'latitude' => '31.4183', 'longitude' => '34.3506', 'is_active' => true])
        ->assertHasNoFormErrors();

    $id = (int) DB::table('cities')->where('slug', 'دير-البلح')->value('id');
    expect(DB::table('weather_locations')->where('city_id', $id)->value('sort_order'))->toBe(13)
        ->and(api('/weather/cities')->json('data.12.slug'))->toBe('دير-البلح');

    api('/weather/cities');                                     // cached before the drag
    $order = DB::table('weather_locations')->orderByDesc('sort_order')->pluck('city_id')->map(fn ($v) => (string) $v)->all();
    Livewire::test(ManageCities::class)->call('reorderTable', $order);
    expect(api('/weather/cities')->json('data.0.slug'))->toBe('دير-البلح');
});

test('a name already used is refused', function () {
    $this->actingAs($this->admin);
    Livewire::test(ManageCities::class)
        ->callAction(TestAction::make('add')->table(), ['name_ar' => 'جنين', 'latitude' => '32.0', 'longitude' => '35.0', 'is_active' => true])
        ->assertHasFormErrors(['name_ar']);
});

test('without a key the screen says so and offers no fetch; with one, fetch now reports problems', function () {
    $this->actingAs($this->admin);
    config(['raqam.weather.key' => '']);
    $this->get(CityResource::getUrl())->assertOk()->assertSee('مفتاح OpenWeather غير مضبوط')->assertSee('نابلس');
    Livewire::test(ManageCities::class)->assertActionHidden(TestAction::make('fetchAll')->table());

    config(['raqam.weather.key' => 'ow-test-key']);
    fakeOpenWeather(fn () => Http::response('', 500));
    Livewire::test(ManageCities::class)->callAction(TestAction::make('fetch')->table(WeatherLocation::find($this->jenin)))->assertNotified('مشكلة في جلب الطقس');
});

test('the screen is for signed-in staff only', function () {
    $this->get(CityResource::getUrl())->assertRedirect();
});

// ---- cities:install --------------------------------------------------------------------------------------

test('cities:install adds a missing city, never changes one the admin edited, and runs twice safely', function () {
    DB::table('cities')->where('id', $this->jenin)->update(['name_ar' => 'جنين القسام', 'latitude' => 32.5]);
    $gaza = (int) DB::table('cities')->where('slug', 'غزة')->value('id');
    DB::table('cities')->where('id', $gaza)->delete();
    $tulkarm = (int) DB::table('cities')->where('slug', 'طولكرم')->value('id');
    DB::table('cities')->where('id', $tulkarm)->update(['slug' => 'tulkarm']);   // its address was changed: not a missing city

    $this->artisan('cities:install')->expectsOutputToContain('Added 1 weather city.')->assertSuccessful();
    $this->artisan('cities:install')->expectsOutputToContain('Added 0 weather cities.')->assertSuccessful();

    expect(DB::table('cities')->where('id', $this->jenin)->value('name_ar'))->toBe('جنين القسام')
        ->and(DB::table('cities')->where('slug', 'غزة')->exists())->toBeTrue()
        ->and(DB::table('cities')->where('name_ar', 'طولكرم')->pluck('slug')->all())->toBe(['tulkarm'])
        ->and(DB::table('weather_locations')->count())->toBe(12)
        ->and(DB::table('weather_locations')->where('is_default', true)->pluck('city_id')->all())->toBe([$this->nablus]);
});
