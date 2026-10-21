<?php

use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Jobs\FetchCityWeather;
use App\Markets\RateFetcher;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
