<?php

use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Integrations\DataProviders;
use App\Integrations\DecimalJson;
use App\Models\User;
use App\Models\WeatherLocation;
use App\Weather\ForecastSlot;
use App\Weather\Observation;
use App\Weather\WeatherCities;
use App\Weather\WeatherFetcher;
use App\Weather\WeatherSource;
use App\Weather\WeatherSources;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/* The weather source is a setting (12.13); the admin adds and removes weather cities (12.1). */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin = User::factory()->staff()->create());
    config(['raqam.weather.key' => 'ow-test-key']);
    $this->nablus = (int) DB::table('cities')->where('slug', 'نابلس')->value('id');
    $this->jenin = (int) DB::table('cities')->where('slug', 'جنين')->value('id');
});

/** A second source the code knows, answering from memory, with a data_providers row of its own. */
function standInWeatherSource(): void
{
    DB::table('data_providers')->insertOrIgnore(['code' => 'stand_in', 'service' => 'weather', 'name' => 'Stand-in', 'is_active' => false, 'is_backup' => true]);
    DB::table('provider_health')->insertOrIgnore(['provider_code' => 'stand_in']);
    app()->instance('weather.stand_in', new class implements WeatherSource
    {
        public function code(): string
        {
            return 'stand_in';
        }

        public function configured(): bool
        {
            return true;
        }

        public function current(float|string $lat, float|string $lon): Observation
        {
            return Observation::read(DecimalJson::decode(owCurrent()));
        }

        public function forecast(float|string $lat, float|string $lon): array
        {
            return array_values(array_filter(array_map(fn ($i) => ForecastSlot::read($i), DecimalJson::decode(owForecast())['list'])));
        }
    });
    config(['raqam.weather.sources' => config('raqam.weather.sources') + ['stand_in' => 'weather.stand_in']]);
}

test('switching the source is a setting: the next fetch uses the new one and its readings carry its code', function () {
    standInWeatherSource();
    fakeOpenWeather(owCurrent(), owForecast());

    DataProviders::switchTo('weather', 'stand_in');

    expect(app(WeatherFetcher::class)->city($this->nablus))->toBe('stored');
    Http::assertNothingSent();   // the old source was not called
    expect(DB::table('weather_observations')->where('city_id', $this->nablus)->value('provider_code'))->toBe('stand_in')
        ->and(DB::table('weather_forecasts')->where('city_id', $this->nablus)->distinct()->pluck('provider_code')->all())->toBe(['stand_in'])
        ->and(DB::table('provider_health')->where('provider_code', 'stand_in')->value('last_success_at'))->not->toBeNull()
        ->and(DB::table('audit_log_changes')->where('field', 'provider.weather')->value('new_value'))->toBe('stand_in');
});

test('a source the settings name but the code does not know fetches nothing at all', function () {
    DB::table('data_providers')->insertOrIgnore(['code' => 'unknown_source', 'service' => 'weather', 'name' => 'Not built', 'is_active' => false, 'is_backup' => true]);
    fakeOpenWeather(owCurrent(), owForecast());

    DataProviders::switchTo('weather', 'unknown_source');

    expect(app(WeatherFetcher::class)->city($this->nablus))->toBe('skipped')->and(WeatherSources::ready())->toBeFalse();
    Http::assertNothingSent();
});

test('the weather screen switches the source; the switch is audited and an unconfigured source cannot be chosen', function () {
    DB::table('data_providers')->insertOrIgnore(['code' => 'unknown_source', 'service' => 'weather', 'name' => 'Not built', 'is_active' => false, 'is_backup' => true]);

    Livewire::test(ManageCities::class)->callAction(TestAction::make('source')->table(), ['provider' => 'unknown_source'])->assertHasFormErrors(['provider']);
    expect(DataProviders::active('weather'))->toBe('openweathermap');

    config(['raqam.weather.key' => '']);
    Livewire::test(ManageCities::class)->callAction(TestAction::make('source')->table(), ['provider' => 'openweathermap'])->assertHasFormErrors(['provider']);
    config(['raqam.weather.key' => 'ow-test-key']);
    Livewire::test(ManageCities::class)->callAction(TestAction::make('source')->table(), ['provider' => 'openweathermap'])->assertHasNoFormErrors();
    expect(WeatherSources::ready())->toBeTrue();
});

test('removing a city drops its weather and readings, its page answers 410, and cities:install does not bring it back', function () {
    DB::table('weather_observations')->insert(['city_id' => $this->jenin, 'provider_code' => 'openweathermap', 'observed_at' => now(), 'temperature_c' => 20, 'condition_code' => 800, 'is_day' => true]);
    $location = WeatherLocation::query()->findOrFail($this->jenin);

    Livewire::test(ManageCities::class)->callTableAction('remove', $location)->assertHasNoTableActionErrors();

    expect(DB::table('weather_locations')->where('city_id', $this->jenin)->exists())->toBeFalse()
        ->and(DB::table('weather_observations')->where('city_id', $this->jenin)->exists())->toBeFalse()
        ->and(DB::table('cities')->where('id', $this->jenin)->exists())->toBeFalse()
        ->and(DB::table('redirects')->where('from_path', '/weather/جنين')->value('status_code'))->toBe(410)
        ->and(api('/weather/جنين')->status())->toBe(404);

    $this->artisan('cities:install')->expectsOutputToContain('Added 0 weather cities.')->assertSuccessful();
    expect(DB::table('cities')->where('slug', 'جنين')->exists())->toBeFalse();
});

test('a city a company still names keeps its row; adding it again brings it back at its old address, without the 410', function () {
    DB::table('organizations')->insert(['name' => 'شركة جنين', 'slug' => 'jenin-co', 'city_id' => $this->jenin]);
    app(WeatherCities::class)->remove(WeatherLocation::query()->findOrFail($this->jenin), $this->admin->id);
    expect(DB::table('cities')->where('id', $this->jenin)->exists())->toBeTrue();

    Livewire::test(ManageCities::class)->callAction(TestAction::make('add')->table(), ['name_ar' => 'جنين', 'latitude' => '32.464600', 'longitude' => '35.293900', 'is_active' => true])
        ->assertHasNoFormErrors();

    expect(DB::table('weather_locations')->where('city_id', $this->jenin)->exists())->toBeTrue()
        ->and(DB::table('redirects')->where('from_path', '/weather/جنين')->exists())->toBeFalse()
        ->and(DB::table('cities')->where('name_ar', 'جنين')->count())->toBe(1);
});

test('the default city cannot be removed', function () {
    $nablus = WeatherLocation::query()->findOrFail($this->nablus);

    Livewire::test(ManageCities::class)->assertTableActionHidden('remove', $nablus);
    expect(fn () => app(WeatherCities::class)->remove($nablus, null))->toThrow(InvalidArgumentException::class);
});
