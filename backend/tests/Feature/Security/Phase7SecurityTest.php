<?php

use App\Ads\AdClickLink;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AdCampaigns\Pages\ListAdCampaigns;
use App\Media\MediaInUse;
use App\Media\MediaTrash;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\ServiceCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// Phase 7 security review (private/phase7-security-report.md): each finding has its test.

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

// F1: the click link's flood limit answered 429 after 60. Behind a proxy that hides the visitors' addresses (before
// TrustProxies is set for Cloudflare) every visitor shares one address, so real visitors would have been stopped on the way
// to the advertiser. Over the click counter's limit the visitor still goes on; the click is simply not counted. The request
// limit is now 120 a minute per address (decision 2026-10-25, next test).
test('F1: over the click limit the visitor still reaches the advertiser, and nothing more is counted', function () {
    $c = adCampaign();
    $path = parse_url(AdClickLink::url($c->id, 'header_leaderboard'), PHP_URL_PATH);
    // Seventy people behind one shared address within the same minute.
    foreach (range(1, 70) as $i) {
        $this->withHeaders(['User-Agent' => PERSON_AGENT])->get($path)->assertStatus(302)->assertHeader('Location', 'https://bank.example.ps/offer?utm=raqam');
    }
    // Counted once (the same address, campaign and slot within 30 seconds), and every one of them reached the advertiser.
    expect((int) DB::table('ad_stats_daily')->sum('clicks'))->toBe(1);
});

test('the click link allows 120 requests a minute per address: each reaches the advertiser, the next is refused, another address goes on', function () {
    $c = adCampaign();
    $path = parse_url(AdClickLink::url($c->id, 'header_leaderboard'), PHP_URL_PATH);

    foreach (range(1, 120) as $i) {
        $this->withHeaders(['User-Agent' => PERSON_AGENT])->get($path)->assertStatus(302)->assertHeader('Location', 'https://bank.example.ps/offer?utm=raqam');
    }
    $this->withHeaders(['User-Agent' => PERSON_AGENT])->get($path)->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])->withHeaders(['User-Agent' => PERSON_AGENT])->get($path)->assertStatus(302);
});

test('made-up service and click addresses are a 404, never a server error, whatever they hold', function (string $slug) {
    app(ServiceCatalog::class)->save(null, ['group_id' => DB::table('service_groups')->value('id'), 'name_ar' => 'خدمة', 'summary' => 'وصف.', 'price_mode' => 'on_request', 'is_active' => true], null);
    api('/services/'.rawurlencode($slug))->assertNotFound();
    $this->withHeaders(['User-Agent' => PERSON_AGENT])->postJson('/api/v1/services/'.rawurlencode($slug).'/contact', ['channel' => 'phone'])->assertNotFound();
})->with([str_repeat('ب', 5000), "a\0b", '../../etc/passwd', "' OR 1=1 --", '%', '٪٪']);

test('a click address with a huge or odd campaign number is a 404', function () {
    $this->get('/api/v1/ads/click/999999999999999999/header_leaderboard/'.str_repeat('a', 32))->assertNotFound();
    $this->get('/api/v1/ads/click/9999999999999999999999/header_leaderboard/'.str_repeat('a', 32))->assertNotFound();
    $this->get('/api/v1/ads/click/1/HEADER/'.str_repeat('a', 32))->assertNotFound();
});

test('names typed into ads are shown as text on the panel, never as markup', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->staff()->create());
    adCampaign(['name' => '<script>alert(1)</script><img src=x onerror=alert(2)>', 'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
        'advertiser_id' => adAdvertiser('<b>معلن</b>')->id]);

    foreach ([Dashboard::class, ListAdCampaigns::class] as $screen) {
        Livewire::test($screen)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)->assertDontSee('<b>معلن</b>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
});

test('a picture used by a campaign or a house banner cannot be moved to the trash', function () {
    $c = adCampaign();
    $asset = MediaAsset::query()->findOrFail($c->creativeIds()['desktop']);

    expect(fn () => app(MediaTrash::class)->trash($asset))->toThrow(MediaInUse::class);
    expect($asset->refresh()->deleted_at)->toBeNull();
});
