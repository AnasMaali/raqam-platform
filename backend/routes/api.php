<?php

use App\Http\Controllers\Api\AdApiController;
use App\Http\Controllers\Api\AwarenessApiController;
use App\Http\Controllers\Api\CurrencyApiController;
use App\Http\Controllers\Api\PageApiController;
use App\Http\Controllers\Api\PeopleApiController;
use App\Http\Controllers\Api\PostApiController;
use App\Http\Controllers\Api\RedirectLookupController;
use App\Http\Controllers\Api\SearchApiController;
use App\Http\Controllers\Api\ServiceApiController;
use App\Http\Controllers\Api\TaxonomyApiController;
use App\Http\Controllers\Api\VideoApiController;
use App\Http\Controllers\Api\WeatherApiController;
use App\Http\Middleware\ApiResponseCache;
use Illuminate\Support\Facades\Route;

// Read-only content API, /api/v1 (contract: docs/api/openapi.yaml). Published content only.
Route::middleware([ApiResponseCache::class, 'throttle:api-public'])->group(function () {
    Route::get('/posts', [PostApiController::class, 'index']);
    // At most 18 digits: a longer number does not fit the database's bigint (and would be a server error, not a 404).
    Route::get('/posts/{id}', [PostApiController::class, 'show'])->where('id', '[0-9]{1,18}');
    Route::get('/posts/{id}/{slug}', [PostApiController::class, 'show'])->where('id', '[0-9]{1,18}');

    Route::get('/categories', [TaxonomyApiController::class, 'categories']);
    Route::get('/categories/{slug}', [TaxonomyApiController::class, 'category']);
    Route::get('/tags', [TaxonomyApiController::class, 'tags']);
    Route::get('/tags/{slug}', [TaxonomyApiController::class, 'tag']);
    Route::get('/sections', [TaxonomyApiController::class, 'sections']);
    Route::get('/sections/{slug}', [TaxonomyApiController::class, 'section']);

    Route::get('/authors', [PeopleApiController::class, 'authors']);
    Route::get('/authors/{slug}', [PeopleApiController::class, 'author']);
    Route::get('/guests', [PeopleApiController::class, 'guests']);
    Route::get('/guests/{slug}', [PeopleApiController::class, 'guest']);

    Route::get('/pages', [PageApiController::class, 'index']);
    Route::get('/pages/{slug}', [PageApiController::class, 'show']);

    Route::get('/awareness', [AwarenessApiController::class, 'overview']);
    Route::get('/characters', [AwarenessApiController::class, 'characters']);
    Route::get('/characters/{slug}', [AwarenessApiController::class, 'character']);
    Route::get('/characters/{slug}/cards', [AwarenessApiController::class, 'cards']);

    Route::get('/videos', [VideoApiController::class, 'index']);
    Route::get('/videos/{id}', [VideoApiController::class, 'show'])->where('id', '[0-9]{1,18}');
    Route::get('/playlists', [VideoApiController::class, 'playlists']);
    Route::get('/playlists/{slug}', [VideoApiController::class, 'playlistShow']);
    Route::get('/sitemaps/videos', [VideoApiController::class, 'sitemap']);

    Route::get('/redirects/lookup', RedirectLookupController::class);

    Route::get('/currencies', [CurrencyApiController::class, 'board']);
    Route::get('/currencies/ticker', [CurrencyApiController::class, 'ticker']);
    Route::get('/currencies/converter', [CurrencyApiController::class, 'converter']);
    Route::get('/currencies/{code}/history', [CurrencyApiController::class, 'history'])->where('code', '[A-Za-z]{3}');

    Route::get('/weather/cities', [WeatherApiController::class, 'cities']);
    Route::get('/weather/widget', [WeatherApiController::class, 'widget']);
    Route::get('/weather/{slug}', [WeatherApiController::class, 'show']);

    Route::get('/ads', [AdApiController::class, 'slots']);

    Route::get('/services', [ServiceApiController::class, 'index']);
    Route::get('/services/{slug}', [ServiceApiController::class, 'show']);
    Route::get('/advertise', [ServiceApiController::class, 'advertise']);
});

// Ads (decision 13): the click link a browser follows (counts, then 302 to the stored address) and the view reports
// of the site. Never cached; each has its own per-visitor limit.
Route::middleware('throttle:ads-click')->get('/ads/click/{campaign}/{placement}/{signature}', [AdApiController::class, 'click'])
    ->where(['campaign' => '[0-9]{1,18}', 'placement' => '[a-z_]{1,20}', 'signature' => '[0-9a-f]{32}'])->name('ads.click');
Route::middleware('throttle:ads-impressions')->post('/ads/impressions', [AdApiController::class, 'impressions']);

// Search (decision 10): its own limit, and no response cache (results are personal to a query and never cached).
Route::middleware('throttle:api-search')->get('/search', [SearchApiController::class, 'index']);

// Services (14.9): the contact buttons a visitor presses, reported by the site; never cached, a per-visitor limit.
Route::middleware('throttle:service-contact')->post('/services/{slug}/contact', [ServiceApiController::class, 'contact']);
