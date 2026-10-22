<?php

use App\Ads\AdBoard;
use App\Ads\AdClickLink;
use App\Ads\AdSlots;
use App\Models\AdPlacement;
use App\Settings\FeatureFlags;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();   // the array store of the tests: one test's board never leaks into the next
    config(['raqam.api.site_token' => 'site-token-123']);
});

function adSlot(array $body, string $code): array
{
    return collect($body['data'])->firstWhere('code', $code);
}

function adsNow(): array
{
    Cache::increment('ads:version');   // what the save services do; tests that write rows directly call it too

    return api('/ads')->assertOk()->json();
}

/** A view report as the site's server sends it: its token, the visitor's address and browser; $site false = someone else. */
function adViews(array $items, string $viewId = 'view-0001', array $headers = [], ?string $agent = PERSON_AGENT, bool $site = true)
{
    $sent = $site ? $headers + ['X-Raqam-Site-Token' => 'site-token-123', 'X-Raqam-Visitor-Ip' => '203.0.113.1', 'X-Raqam-Visitor-Ua' => $agent, 'User-Agent' => 'node']
        : $headers + ['User-Agent' => $agent];

    // withHeaders() stays on the test case: each report starts from no headers so one request never carries another's.
    return test()->flushHeaders()->withHeaders(array_filter($sent, fn ($v) => $v !== null))->postJson('/api/v1/ads/impressions', ['view_id' => $viewId, 'items' => $items]);
}

function adStat(int $campaign, string $placement = 'header_leaderboard', string $column = 'impressions'): int
{
    return (int) DB::table('ad_stats_daily')->where('campaign_id', $campaign)->where('placement_code', $placement)->sum($column);
}

function clickPath(int $campaign, string $placement = 'header_leaderboard'): string
{
    return parse_url(AdClickLink::url($campaign, $placement), PHP_URL_PATH);
}

// ---- the slots -------------------------------------------------------------------------------------------

test('every slot comes in the site order with its reserved sizes, in the contract shape', function () {
    $c = adCampaign(['advertiser_id' => adAdvertiser('شركة الاختبار')->id]);
    $body = adsNow();
    validateAgainstPath($body, '/ads');

    expect(collect($body['data'])->pluck('code')->all())->toBe(['header_leaderboard', 'sidebar_mpu', 'in_article', 'between_sections', 'popup', 'mobile_sticky'])
        ->and(adSlot($body, 'header_leaderboard')['sizes'])->toBe(['desktop' => ['width' => 970, 'height' => 90], 'mobile' => ['width' => 320, 'height' => 100]])
        ->and(adSlot($body, 'mobile_sticky')['sizes']['desktop'])->toBeNull()
        ->and(adSlot($body, 'header_leaderboard')['lazy'])->toBeFalse()
        ->and(adSlot($body, 'sidebar_mpu')['lazy'])->toBeTrue()
        ->and($body['meta'])->toMatchArray(['label' => 'إعلان', 'rotation' => 'weighted', 'adsense' => ['enabled' => false, 'client' => null]]);

    $ad = adSlot($body, 'header_leaderboard')['ads'][0];
    expect($ad['id'])->toBe($c->id)->and($ad['advertiser'])->toBe('شركة الاختبار')->and($ad['rel'])->toBe('sponsored noopener')
        ->and($ad['weight'])->toBe(50)->and($ad['images']['desktop']['id'])->toBe($c->creativeIds()['desktop'])
        ->and($ad['click_url'])->toEndWith(clickPath($c->id));

    // The advertiser's own address is never handed out: only our click link.
    expect(json_encode($body))->not->toContain('bank.example.ps');
});

test('a campaign shows only from its start until its end, only when active, and only in a slot that is on', function () {
    $live = adCampaign(['name' => 'تعمل']);
    $later = adCampaign(['name' => 'لاحقاً', 'starts_at' => now()->addHour()->format('Y-m-d H:i:s')]);
    $ended = adCampaign(['name' => 'انتهت']);
    DB::table('ad_campaigns')->where('id', $ended->id)->update(['starts_at' => now()->subDays(3), 'ends_at' => now()->subSecond()]);
    $paused = adCampaign(['name' => 'موقوفة', 'status' => 'paused']);
    $draft = adCampaign(['name' => 'مسودة', 'status' => 'draft']);

    expect(collect(adSlot(adsNow(), 'header_leaderboard')['ads'])->pluck('id')->all())->toBe([$live->id]);

    AdPlacement::query()->whereKey('header_leaderboard')->update(['is_enabled' => false]);
    $off = adSlot(adsNow(), 'header_leaderboard');
    expect($off['active'])->toBeFalse()->and($off['ads'])->toBe([])->and($off['house'])->toBeNull();
});

test('the popup and the mobile bar need both their own switch and their feature switch', function () {
    $c = adCampaign(['placements' => ['mobile_sticky'], 'desktop_media_id' => null, 'mobile_media_id' => adImage(320, 50)]);
    expect(adSlot(adsNow(), 'mobile_sticky')['active'])->toBeFalse();

    $slot = AdPlacement::query()->find('mobile_sticky');
    app(AdSlots::class)->save($slot, $slot->only(['name_ar', 'mobile_width', 'mobile_height', 'lazy_load']) + ['is_enabled' => true, 'empty_behavior' => 'house',
        'house_mobile_media_id' => adImage(320, 50), 'house_target_path' => '/advertise', 'house_is_active' => true]);
    // The slot is on, but its feature switch is still off: no campaign, no banner, nothing reserved.
    expect(adSlot(adsNow(), 'mobile_sticky'))->toMatchArray(['active' => false, 'ads' => [], 'house' => null]);

    app(FeatureFlags::class)->set('ad_mobile_sticky', true, null);
    $bar = adSlot(adsNow(), 'mobile_sticky');
    expect($bar['active'])->toBeTrue()->and($bar['ads'][0]['id'])->toBe($c->id)->and($bar['ads'][0]['images']['desktop'])->toBeNull()
        ->and($bar['house']['target'])->toBe('/advertise');

    AdPlacement::query()->whereKey('mobile_sticky')->update(['is_enabled' => false]);
    expect(adSlot(adsNow(), 'mobile_sticky')['active'])->toBeFalse();      // and the slot's own switch still counts
});

test('turning a feature switch drops the cached slots at once', function () {
    AdPlacement::query()->whereKey('popup')->update(['is_enabled' => true]);
    Cache::increment('ads:version');
    expect(adSlot(api('/ads')->json(), 'popup')['active'])->toBeFalse();

    app(FeatureFlags::class)->set('ad_popup', true, null);
    expect(adSlot(api('/ads')->json(), 'popup'))->toMatchArray(['active' => true, 'frequency_cap_hours' => 24]);
});

test('several campaigns in one slot rotate by priority: each carries its weight, highest first', function () {
    $a = adCampaign(['name' => 'أ', 'priority' => 20]);
    $b = adCampaign(['name' => 'ب', 'priority' => 80]);
    $ads = adSlot(adsNow(), 'header_leaderboard')['ads'];

    expect(collect($ads)->map(fn ($ad) => [$ad['id'], $ad['weight']])->all())->toBe([[$b->id, 80], [$a->id, 20]]);
});

test('a campaign whose picture went private no longer shows', function () {
    $c = adCampaign();
    DB::table('media_assets')->where('id', $c->creativeIds()['mobile'])->update(['visibility' => 'private']);

    expect(adSlot(adsNow(), 'header_leaderboard')['ads'])->toBe([]);
});

test('an empty slot offers the «أعلن معنا» banner or nothing, as the admin chose', function () {
    $slot = AdPlacement::query()->find('sidebar_mpu');
    app(AdSlots::class)->save($slot, $slot->only(['name_ar', 'desktop_width', 'desktop_height', 'mobile_width', 'mobile_height', 'is_enabled', 'lazy_load'])
        + ['empty_behavior' => 'house', 'house_desktop_media_id' => $d = adImage(600, 500), 'house_mobile_media_id' => adImage(300, 250), 'house_target_path' => '/advertise', 'house_is_active' => true]);

    $house = adSlot(adsNow(), 'sidebar_mpu')['house'];
    expect($house['target'])->toBe('/advertise')->and($house['images']['desktop']['id'])->toBe($d);

    app(AdSlots::class)->save($slot->refresh(), $slot->only(['name_ar', 'desktop_width', 'desktop_height', 'mobile_width', 'mobile_height', 'is_enabled', 'lazy_load'])
        + ['empty_behavior' => 'hide', 'house_desktop_media_id' => $d, 'house_mobile_media_id' => null, 'house_target_path' => '/advertise', 'house_is_active' => true]);
    expect(adSlot(api('/ads')->json(), 'sidebar_mpu')['house'])->toBeNull();
});

test('the cached slots last at most until the next start or end of a campaign', function () {
    adCampaign(['starts_at' => now()->addSeconds(40)->format('Y-m-d H:i:s')]);
    $ttl = (fn () => $this->ttl())->call(app(AdBoard::class));
    expect($ttl)->toBeLessThanOrEqual(41)->toBeGreaterThan(30);

    DB::table('ad_campaigns')->delete();
    expect((fn () => $this->ttl())->call(app(AdBoard::class)))->toBe(300);
});

// ---- views -----------------------------------------------------------------------------------------------

test('a view is counted once per page view, by Palestine day, and not again within seconds', function () {
    $c = adCampaign();
    adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']])->assertNoContent();
    adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']])->assertNoContent();     // the same view again
    adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']], 'view-0002')->assertNoContent(); // another view, 0 s later

    expect(adStat($c->id))->toBe(1);

    $this->travel(11)->seconds();
    adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']], 'view-0003')->assertNoContent();
    expect(adStat($c->id))->toBe(2);

    $this->travel(11)->seconds();
    adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']])->assertNoContent();             // the first view, sent again later
    expect(adStat($c->id))->toBe(2);
});

test('a view counts on the Palestine day: 22:30 UTC is already the next day', function () {
    $c = adCampaign(['starts_at' => now()->subDays(2)->format('Y-m-d H:i:s'), 'ends_at' => now()->addDays(5)->format('Y-m-d H:i:s')]);
    $this->travelTo(now()->utc()->setTime(22, 30));
    adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']], 'late-view-1')->assertNoContent();

    expect(DB::table('ad_stats_daily')->where('campaign_id', $c->id)->value('stat_date'))->toBe(now()->utc()->addDay()->toDateString())
        ->and(now('Asia/Hebron')->toDateString())->toBe(now()->utc()->addDay()->toDateString());
});

test('robots, campaigns not live and slots the campaign is not in are not counted, and the answer does not tell', function () {
    $c = adCampaign();
    $paused = adCampaign(['status' => 'paused']);
    foreach (['', 'Googlebot/2.1 (+http://www.google.com/bot.html)', 'WhatsApp/2.23.20.0', 'facebookexternalhit/1.1', 'curl/8.4.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/129.0 Safari/537.36', 'python-requests/2.31'] as $i => $agent) {
        adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']], 'bot-view-'.$i, [], $agent)->assertNoContent();
    }
    adViews([['campaign_id' => $paused->id, 'placement' => 'header_leaderboard'], ['campaign_id' => $c->id, 'placement' => 'sidebar_mpu'],
        ['campaign_id' => 999999, 'placement' => 'popup']], 'view-x123')->assertNoContent();

    expect(DB::table('ad_stats_daily')->count())->toBe(0);
});

test('a report that is not well formed is refused, and only JSON is accepted', function () {
    $c = adCampaign();
    $ok = ['campaign_id' => $c->id, 'placement' => 'header_leaderboard'];
    adViews([$ok], 'short')->assertStatus(422);
    adViews([$ok], 'has spaces in it')->assertStatus(422);
    adViews([$ok], str_repeat('a', 65))->assertStatus(422);
    adViews([])->assertStatus(422);
    adViews(array_fill(0, 9, $ok))->assertStatus(422);
    adViews([['campaign_id' => $c->id, 'placement' => 'nowhere']])->assertStatus(422);
    adViews([['campaign_id' => 'abc', 'placement' => 'header_leaderboard']])->assertStatus(422);
    adViews([['campaign_id' => '99999999999999999999999', 'placement' => 'header_leaderboard']])->assertStatus(422);
    adViews([$ok + ['extra' => 1]])->assertStatus(422);

    // A form post (what a page on another site could make a browser send without asking) is refused.
    $this->withHeaders(['User-Agent' => PERSON_AGENT])->post('/api/v1/ads/impressions', ['view_id' => 'view-form-1', 'items' => [$ok]])->assertStatus(415);
    $this->call('POST', '/api/v1/ads/impressions', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => PERSON_AGENT],
        json_encode(['view_id' => 'view-text-1', 'items' => [$ok]]))->assertStatus(415);
    expect(adStat($c->id))->toBe(0);
});

test('a flood of view reports is cut: per request a minute, per visitor an hour', function () {
    config(['raqam.ads.impressions_per_hour' => 3, 'raqam.ads.impression_gap' => 1]);
    $c = adCampaign();
    foreach (range(1, 5) as $i) {
        $this->travel(2)->seconds();
        adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']], "flood-{$i}-xx")->assertNoContent();
    }
    expect(adStat($c->id))->toBe(3);

    foreach (range(1, 60) as $i) {
        adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']], "many-{$i}-xxxx");
    }
    adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']], 'one-too-many')->assertStatus(429);
});

test('through the site the visitor is the address it names with its token, and the visitor\'s own browser decides', function () {
    $c = adCampaign();
    $item = [['campaign_id' => $c->id, 'placement' => 'header_leaderboard']];

    // Two visitors behind the same site server are two people.
    adViews($item, 'site-view-1', ['X-Raqam-Visitor-Ip' => '203.0.113.5'])->assertNoContent();
    adViews($item, 'site-view-2', ['X-Raqam-Visitor-Ip' => '203.0.113.6'])->assertNoContent();
    expect(adStat($c->id))->toBe(2);

    // The visitor's own agent decides, not the site server's: a robot behind the site is not counted.
    adViews($item, 'site-view-3', ['X-Raqam-Visitor-Ip' => '203.0.113.7', 'User-Agent' => PERSON_AGENT], 'Googlebot/2.1')->assertNoContent();
    expect(adStat($c->id))->toBe(2);
});

test('only the site, with its token and the visitor\'s address, is counted; anyone else gets the same 204 and counts nothing', function () {
    $c = adCampaign();
    $item = [['campaign_id' => $c->id, 'placement' => 'header_leaderboard']];
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9']);

    adViews($item, 'bare-view-1', [], PERSON_AGENT, site: false)->assertNoContent();
    adViews($item, 'fake-view-1', ['X-Raqam-Visitor-Ip' => '203.0.113.8'], PERSON_AGENT, site: false)->assertNoContent();
    adViews($item, 'fake-view-2', ['X-Raqam-Site-Token' => 'not-the-token'])->assertNoContent();
    adViews($item, 'fake-view-3', ['X-Raqam-Visitor-Ip' => null])->assertNoContent();             // the token without a visitor
    adViews($item, 'fake-view-4', ['X-Raqam-Visitor-Ip' => 'not-an-address'])->assertNoContent();
    expect(adStat($c->id))->toBe(0);

    adViews($item, 'real-view-1')->assertNoContent();
    expect(adStat($c->id))->toBe(1);
});

test('an IPv6 visitor is one visitor across the whole /64: new addresses from the same line neither repeat views nor escape the limit', function () {
    config(['raqam.ads.impressions_per_hour' => 2, 'raqam.ads.impression_gap' => 1]);
    $c = adCampaign();
    $item = [['campaign_id' => $c->id, 'placement' => 'header_leaderboard']];

    adViews($item, 'six-view-1', ['X-Raqam-Visitor-Ip' => '2001:db8:aa:1::1'])->assertNoContent();
    adViews($item, 'six-view-2', ['X-Raqam-Visitor-Ip' => '2001:db8:aa:1:ffff::2'])->assertNoContent();   // same /64, within the gap
    expect(adStat($c->id))->toBe(1);

    $this->travel(2)->seconds();
    adViews($item, 'six-view-3', ['X-Raqam-Visitor-Ip' => '2001:db8:aa:1:1234:5678:9abc:def0'])->assertNoContent();
    $this->travel(2)->seconds();
    adViews($item, 'six-view-4', ['X-Raqam-Visitor-Ip' => '2001:db8:aa:1::99'])->assertNoContent();     // over the hourly ceiling of the /64
    expect(adStat($c->id))->toBe(2);

    adViews($item, 'six-view-5', ['X-Raqam-Visitor-Ip' => '2001:db8:aa:2::1'])->assertNoContent();     // the next /64 is someone else
    expect(adStat($c->id))->toBe(3);
});

// ---- clicks ----------------------------------------------------------------------------------------------

test('a click is counted and the visitor is sent to the stored address only', function () {
    $c = adCampaign();
    $this->withHeaders(['User-Agent' => PERSON_AGENT])->get(clickPath($c->id).'?url=https://evil.example&next=//evil.example')
        ->assertStatus(302)->assertHeader('Location', 'https://bank.example.ps/offer?utm=raqam')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect(adStat($c->id, column: 'clicks'))->toBe(1);

    // A double click within seconds is one click; the visitor still goes through.
    $this->withHeaders(['User-Agent' => PERSON_AGENT])->get(clickPath($c->id))->assertStatus(302);
    expect(adStat($c->id, column: 'clicks'))->toBe(1);
    $this->travel(31)->seconds();
    $this->withHeaders(['User-Agent' => PERSON_AGENT])->get(clickPath($c->id))->assertStatus(302);
    expect(adStat($c->id, column: 'clicks'))->toBe(2);
});

test('a click link that was not issued is a 404: wrong signature, other slot, other campaign, a draft', function () {
    $c = adCampaign();
    $draft = adCampaign(['status' => 'draft']);
    $sig = AdClickLink::signature($c->id, 'header_leaderboard');

    $this->get("/api/v1/ads/click/{$c->id}/header_leaderboard/".str_repeat('0', 32))->assertNotFound();
    $this->get("/api/v1/ads/click/{$c->id}/sidebar_mpu/{$sig}")->assertNotFound();
    $this->get('/api/v1/ads/click/'.($c->id + 1)."/header_leaderboard/{$sig}")->assertNotFound();
    $this->get(clickPath($c->id, 'sidebar_mpu'))->assertNotFound();          // signed, but the campaign is not in that slot
    $this->get(clickPath($draft->id))->assertNotFound();                      // never shown: its address stays unknown
    $this->get(clickPath(99999999))->assertNotFound();
    $this->get("/api/v1/ads/click/{$c->id}/header_leaderboard/".strtoupper($sig))->assertNotFound();
    expect(DB::table('ad_stats_daily')->count())->toBe(0);
});

test('an address that is not https is never followed, even if it reached the database some other way', function () {
    $c = adCampaign();
    foreach (['http://bank.example.ps/', 'https://bank.ps@evil.example/', 'javascript://bank.example.ps/%0aalert(1)'] as $bad) {
        DB::table('ad_campaigns')->where('id', $c->id)->update(['target_url' => str_starts_with($bad, 'javascript') ? 'https://x' : $bad]);
        if (str_starts_with($bad, 'javascript')) {
            continue;   // the column itself refuses anything but http(s)
        }
        $this->get(clickPath($c->id))->assertNotFound();
    }
});

test('an ended or paused campaign still takes the visitor on, without counting; robots are not counted', function () {
    $c = adCampaign();
    $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])->get(clickPath($c->id))->assertStatus(302);
    DB::table('ad_campaigns')->where('id', $c->id)->update(['status' => 'paused']);
    Cache::increment('ads:version');
    $this->withHeaders(['User-Agent' => PERSON_AGENT])->get(clickPath($c->id))->assertStatus(302)->assertHeader('Location', 'https://bank.example.ps/offer?utm=raqam');

    expect(adStat($c->id, column: 'clicks'))->toBe(0);
});

test('clicks have an hourly ceiling per visitor, and the visitor always goes on', function () {
    config(['raqam.ads.clicks_per_hour' => 2]);
    $a = adCampaign();
    $b = adCampaign();
    $c = adCampaign();
    foreach ([$a, $b, $c] as $campaign) {
        $this->withHeaders(['User-Agent' => PERSON_AGENT])->get(clickPath($campaign->id))->assertStatus(302);
    }
    expect((int) DB::table('ad_stats_daily')->sum('clicks'))->toBe(2);

    foreach (range(1, 80) as $i) {
        $this->withHeaders(['User-Agent' => PERSON_AGENT])->get(clickPath($a->id))->assertStatus(302);
    }
    expect((int) DB::table('ad_stats_daily')->sum('clicks'))->toBe(2);
});

// ---- privacy ---------------------------------------------------------------------------------------------

test('the counters in Redis hold no raw address and no view id', function () {
    config(['raqam.api.site_token' => 'site-token-123', 'cache.default' => 'redis', 'cache.prefix' => 'raqam_ads_test_'.bin2hex(random_bytes(3))]);
    app('cache')->forgetDriver('redis');
    $store = app('cache')->store('redis');
    (fn () => $this->cache = $store)->call(app(RateLimiter::class));
    $connection = Redis::connection((string) config('cache.stores.redis.connection'));

    try {
        $c = adCampaign();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23']);
        adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']], 'secret-view-77', ['X-Raqam-Visitor-Ip' => '198.51.100.23'])->assertNoContent();
        adViews([['campaign_id' => $c->id, 'placement' => 'header_leaderboard']], 'secret-view-88', ['X-Raqam-Visitor-Ip' => '203.0.113.77'])->assertNoContent();
        $this->withHeaders(['User-Agent' => PERSON_AGENT])->get(clickPath($c->id))->assertStatus(302);
        expect(adStat($c->id))->toBe(2);

        $keys = array_map(fn ($k) => (string) $k, $connection->keys('*'.$store->getPrefix().'*'));
        expect($keys)->not->toBeEmpty();
        $dump = implode("\n", $keys)."\n".implode("\n", array_map(fn ($k) => (string) $connection->get(str_replace((string) config('database.redis.options.prefix'), '', $k)), $keys));
        foreach (['198.51.100.23', '203.0.113.77', 'secret-view-77', 'secret-view-88'] as $raw) {
            foreach ([$raw, md5($raw), sha1($raw), hash('sha256', $raw)] as $form) {
                expect($dump)->not->toContain($form);
            }
        }
    } finally {
        foreach ($connection->keys('*'.$store->getPrefix().'*') as $key) {
            $connection->del(str_replace((string) config('database.redis.options.prefix'), '', (string) $key));
        }
    }
});
