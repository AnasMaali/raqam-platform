<?php

use App\Http\Controllers\Api\AccountApiController;
use App\Http\Controllers\Api\AdApiController;
use App\Http\Controllers\Api\AwarenessApiController;
use App\Http\Controllers\Api\BookingApiController;
use App\Http\Controllers\Api\ChatApiController;
use App\Http\Controllers\Api\ContactApiController;
use App\Http\Controllers\Api\CurrencyApiController;
use App\Http\Controllers\Api\HomeApiController;
use App\Http\Controllers\Api\NewsletterApiController;
use App\Http\Controllers\Api\PageApiController;
use App\Http\Controllers\Api\PaymentApiController;
use App\Http\Controllers\Api\PeopleApiController;
use App\Http\Controllers\Api\PostApiController;
use App\Http\Controllers\Api\RedirectLookupController;
use App\Http\Controllers\Api\SearchApiController;
use App\Http\Controllers\Api\ServiceApiController;
use App\Http\Controllers\Api\SiteApiController;
use App\Http\Controllers\Api\StatsApiController;
use App\Http\Controllers\Api\TaxonomyApiController;
use App\Http\Controllers\Api\VideoApiController;
use App\Http\Controllers\Api\WeatherApiController;
use App\Http\Middleware\ApiResponseCache;
use App\Http\Middleware\RequireCustomer;
use Illuminate\Support\Facades\Route;

// Read-only content API, /api/v1 (contract: docs/api/openapi.yaml). Published content only.
Route::middleware([ApiResponseCache::class, 'throttle:api-public'])->group(function () {
    Route::get('/home', [HomeApiController::class, 'show']);
    Route::get('/site', [SiteApiController::class, 'site']);
    Route::get('/menus', [SiteApiController::class, 'menus']);
    Route::get('/robots', [SiteApiController::class, 'robots']);
    Route::get('/llms', [SiteApiController::class, 'llms']);
    Route::get('/posts', [PostApiController::class, 'index']);
    Route::get('/posts/most-read', [StatsApiController::class, 'mostRead']);
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
    Route::get('/team', [PeopleApiController::class, 'team']);
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
    Route::get('/sitemaps', [SiteApiController::class, 'sitemaps']);
    Route::get('/sitemaps/pages', [SiteApiController::class, 'sitemapPages']);
    Route::get('/sitemaps/posts', [SiteApiController::class, 'sitemapPosts']);
    Route::get('/sitemaps/news', [SiteApiController::class, 'sitemapNews']);
    Route::get('/sitemaps/videos', [VideoApiController::class, 'sitemap']);
    Route::get('/feeds/posts', [SiteApiController::class, 'feed']);
    Route::get('/feeds/categories/{slug}', [SiteApiController::class, 'categoryFeed']);
    Route::get('/feeds/sections/{slug}', [SiteApiController::class, 'sectionFeed']);

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

    Route::get('/chat', [ChatApiController::class, 'config']);
    Route::get('/faqs', [ChatApiController::class, 'faqs']);
});

// Ads (decision 13): the click link a browser follows (counts, then 302 to the stored address) and the view reports
// of the site. Never cached. The click link allows 120 requests a minute per address (decision 2026-10-25): under it a
// person always reaches the advertiser; what counts as a click is limited by the counter itself.
Route::middleware('throttle:ads-click')->get('/ads/click/{campaign}/{placement}/{signature}', [AdApiController::class, 'click'])
    ->where(['campaign' => '[0-9]{1,18}', 'placement' => '[a-z_]{1,20}', 'signature' => '[0-9a-f]{32}'])->name('ads.click');
Route::middleware('throttle:ads-impressions')->post('/ads/impressions', [AdApiController::class, 'impressions']);

// Search (decision 10): its own limit, and no response cache (results are personal to a query and never cached).
Route::middleware('throttle:api-search')->get('/search', [SearchApiController::class, 'index']);

// Services (14.9): the contact buttons a visitor presses, reported by the site; never cached, a per-visitor limit.
Route::middleware('throttle:service-contact')->post('/services/{slug}/contact', [ServiceApiController::class, 'contact']);

// The contact form (20.13): from the site's server only (its token and the visitor's address), JSON only.
Route::middleware('throttle:contact-form')->post('/contact', [ContactApiController::class, 'store']);

// The newsletter (decision 19): from the site's server only, JSON only. The Brevo webhook is Brevo's own call.
Route::middleware('throttle:newsletter')->group(function () {
    Route::post('/newsletter/subscribe', [NewsletterApiController::class, 'subscribe']);
    Route::post('/newsletter/resend', [NewsletterApiController::class, 'resend']);
    Route::post('/newsletter/confirm', [NewsletterApiController::class, 'confirm']);
    Route::post('/newsletter/unsubscribe', [NewsletterApiController::class, 'unsubscribe']);
});
Route::middleware('throttle:brevo-webhook')->post('/newsletter/brevo-webhook', [NewsletterApiController::class, 'brevoWebhook']);

// The chatbot (decision 18): from the site's server only, JSON only.
Route::middleware('throttle:chat')->post('/chat/messages', [ChatApiController::class, 'message']);

// Statistics (decision 22): what the site's server reports for its visitors. Never cached, JSON only, no cookie, a
// limit per visitor.
Route::middleware('throttle:stats')->post('/stats/hits', [StatsApiController::class, 'hit']);

// Customer accounts (decision 17): built and switched off. While `customer_accounts` is off every route here is a 404.
// From the site's server only; signed-in routes carry the customer's session as a bearer token.
Route::middleware(['feature:customer_accounts', 'throttle:accounts'])->prefix('account')->group(function () {
    Route::post('/register', [AccountApiController::class, 'register']);
    Route::post('/register/resend', [AccountApiController::class, 'resend']);
    Route::post('/register/confirm', [AccountApiController::class, 'confirm']);
    Route::post('/login', [AccountApiController::class, 'login']);
    Route::post('/password/forgot', [AccountApiController::class, 'forgot']);
    Route::post('/password/reset', [AccountApiController::class, 'reset']);

    Route::middleware(RequireCustomer::class)->group(function () {
        Route::post('/logout', [AccountApiController::class, 'logout']);
        Route::get('/profile', [AccountApiController::class, 'profile']);
        Route::patch('/profile', [AccountApiController::class, 'updateProfile']);
        Route::post('/password', [AccountApiController::class, 'password']);
        Route::delete('/', [AccountApiController::class, 'destroy']);
        Route::get('/saved', [AccountApiController::class, 'saved']);
        Route::put('/saved/{post}', [AccountApiController::class, 'save'])->where('post', '[0-9]{1,18}');
        Route::delete('/saved/{post}', [AccountApiController::class, 'unsave'])->where('post', '[0-9]{1,18}');
        Route::get('/follows', [AccountApiController::class, 'follows']);
        Route::put('/follows/{kind}/{id}', [AccountApiController::class, 'follow'])->where(['kind' => 'category|section', 'id' => '[0-9]{1,9}']);
        Route::delete('/follows/{kind}/{id}', [AccountApiController::class, 'unfollow'])->where(['kind' => 'category|section', 'id' => '[0-9]{1,9}']);
        Route::get('/bookings', [AccountApiController::class, 'bookings'])->middleware('feature:booking');
    });
});

// Online booking (decision 15): built and switched off. While `booking` is off every route here is a 404, before the
// request limit or anything else looks at the request. From the site's server only.
Route::middleware('feature:booking')->group(function () {
    Route::middleware('throttle:booking')->post('/bookings', [BookingApiController::class, 'store']);
    Route::middleware('throttle:booking-track')->post('/bookings/track', [BookingApiController::class, 'track']);
});

// Online payment (decision 16): built and switched off. While `booking` or `online_payments` is off every route here is a
// 404. Starting and the customer's return come from the site's server; the notification is the bank's own call.
Route::middleware('feature:booking,online_payments')->group(function () {
    Route::middleware('throttle:payments')->post('/bookings/pay', [PaymentApiController::class, 'start']);
    Route::middleware('throttle:payments')->post('/payments/return', [PaymentApiController::class, 'return']);
    Route::middleware('throttle:payment-webhooks')->post('/payments/webhooks/{gateway}', [PaymentApiController::class, 'webhook'])->where('gateway', '[a-z0-9_]{1,32}');
});
