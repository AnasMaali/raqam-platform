<?php

use App\Ads\AdCampaigns;
use App\Ads\AdRejected;
use App\Ads\AdTargetUrl;
use App\Jobs\SendSiteWebhook;
use App\Models\AdCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** The field and sentence a refused save gives, or "saved". */
function adRefusal(array $overrides, ?AdCampaign $campaign = null): string
{
    try {
        app(AdCampaigns::class)->save($campaign, adCampaignData($overrides), null);
    } catch (AdRejected $e) {
        return $e->field.': '.$e->getMessage();
    }

    return 'saved';
}

function adAuditedFields(AdCampaign $c): array
{
    return DB::table('audit_log_changes')->join('audit_log', 'audit_log.id', '=', 'audit_log_changes.audit_id')
        ->where('audit_log.subject_table', 'ad_campaigns')->where('audit_log.subject_id', $c->id)
        ->orderBy('audit_log.id')->orderBy('audit_log_changes.field')->get(['field', 'old_value', 'new_value'])->map(fn ($r) => [$r->field, $r->old_value, $r->new_value])->all();
}

// ---- saving ----------------------------------------------------------------------------------------------

test('a campaign is saved with its slots and pictures, and slot and picture changes are audited from and to', function () {
    $d = adImage(1940, 180);   // the header's shape at twice its size
    $m = adImage(320, 100);
    $c = adCampaign(['placements' => ['header_leaderboard'], 'desktop_media_id' => $d, 'mobile_media_id' => $m]);

    expect($c->placementCodes())->toBe(['header_leaderboard'])->and($c->creativeIds())->toBe(['desktop' => $d, 'mobile' => $m])
        ->and(adAuditedFields($c))->toContain(['placements', null, 'header_leaderboard'], ['creative_desktop', null, (string) $d], ['name', null, 'حملة الخريف']);

    // between_sections has the header's mobile shape (320×100) but its own desktop shape (970×250).
    $wide = adImage(970, 250);
    expect(adRefusal(['advertiser_id' => $c->advertiser_id, 'placements' => ['header_leaderboard', 'between_sections'], 'desktop_media_id' => $d, 'mobile_media_id' => $m], $c))
        ->toContain('desktop_media_id')->toContain('«بين الأقسام»');

    $c = app(AdCampaigns::class)->save($c, adCampaignData(['advertiser_id' => $c->advertiser_id, 'placements' => ['between_sections'], 'desktop_media_id' => $wide, 'mobile_media_id' => $m]), null);
    expect($c->placementCodes())->toBe(['between_sections'])
        ->and(adAuditedFields($c))->toContain(['placements', 'header_leaderboard', 'between_sections'], ['creative_desktop', (string) $d, (string) $wide]);
});

test('a save that changes nothing about slots or pictures leaves no slot or picture row in the audit log', function () {
    $c = adCampaign();
    $before = count(adAuditedFields($c));
    app(AdCampaigns::class)->save($c, adCampaignData(['advertiser_id' => $c->advertiser_id, 'desktop_media_id' => $c->creativeIds()['desktop'], 'mobile_media_id' => $c->creativeIds()['mobile'],
        'starts_at' => $c->starts_at->format('Y-m-d H:i:s'), 'ends_at' => $c->ends_at->format('Y-m-d H:i:s')]), null);

    expect(count(adAuditedFields($c)))->toBe($before);
});

test('the site is told once per save, after the commit', function () {
    config(['raqam.site_webhook.url' => 'https://site.test/hook', 'raqam.site_webhook.secret' => 'secret']);
    Queue::fake([SendSiteWebhook::class]);

    adCampaign();
    Queue::assertPushed(SendSiteWebhook::class, 1);
    Queue::assertPushed(SendSiteWebhook::class, fn ($job) => $job->payload['type'] === 'ads' && $job->payload['change'] === 'campaign');
});

// ---- the advertiser's address ---------------------------------------------------------------------------

test('only an https address of a real host is accepted', function (string $url, bool $ok) {
    expect(AdTargetUrl::isValid($url))->toBe($ok);
    expect(adRefusal(['target_url' => $url]))->{$ok ? 'toBe' : 'toStartWith'}($ok ? 'saved' : 'target_url: ');
})->with([
    ['https://bank.example.ps/offer?utm=raqam#top', true],
    ['https://xn--mgbh0fb.xn--ygbi2ammx/', true],
    ['HTTPS://Bank.Example.PS', true],
    ['https://bank.example.ps:443/x', true],
    ['http://bank.example.ps', false],
    ['javascript:alert(1)', false],
    ['data:text/html,<script>alert(1)</script>', false],
    ['//evil.example', false],
    ['/advertise', false],
    ['https://bank.ps@evil.example/', false],
    ['https://user:pass@bank.example.ps/', false],
    ['https://bank.example.ps:8080/', false],
    ['https://localhost/', false],
    ['https://192.168.1.1/', false],
    ['https://bank example.ps/', false],
    ["https://bank.example.ps/\nSet-Cookie:x", false],
    ['https://bank.example.ps\\@evil.example', false],
    ['ftp://bank.example.ps', false],
    ['https://', false],
]);

test('an address longer than 2048 characters is refused', function () {
    expect(adRefusal(['target_url' => 'https://bank.example.ps/'.str_repeat('a', 2030)]))->toContain('target_url');
});

// ---- slots and pictures ----------------------------------------------------------------------------------

test('a campaign needs a slot, and a picture for every device its slots have a size for', function () {
    expect(adRefusal(['placements' => []]))->toStartWith('placements:')
        ->and(adRefusal(['placements' => ['nowhere']]))->toStartWith('placements:')
        ->and(adRefusal(['desktop_media_id' => null]))->toBe('desktop_media_id: صورة الكمبيوتر مطلوبة لمكان «بانر أعلى الصفحة».')
        ->and(adRefusal(['mobile_media_id' => null]))->toBe('mobile_media_id: صورة الجوال مطلوبة لمكان «بانر أعلى الصفحة».');

    // The mobile bar exists on phones only: no desktop picture is needed.
    expect(adRefusal(['placements' => ['mobile_sticky'], 'desktop_media_id' => null, 'mobile_media_id' => adImage(640, 100)]))->toBe('saved');
});

test('a picture must be a public raster image of the library, of the slot shape and at least its size', function () {
    expect(adRefusal(['desktop_media_id' => adImage(728, 90)]))->toContain('لا تناسب شكل مكان')          // 8.1 vs 10.8
        ->and(adRefusal(['desktop_media_id' => adImage(485, 45)]))->toContain('أصغر من مكان')            // right shape, half the size
        ->and(adRefusal(['desktop_media_id' => adImage(1000, 90)]))->toBe('saved')                       // within 5% of the shape
        ->and(adRefusal(['desktop_media_id' => adImage(970, 90, ['visibility' => 'private'])]))->toContain('اختر صورة عامة')
        ->and(adRefusal(['desktop_media_id' => adImage(970, 90, ['mime_type' => 'image/svg+xml'])]))->toContain('اختر صورة عامة')
        ->and(adRefusal(['desktop_media_id' => insertMedia(['mime_type' => 'application/pdf', 'width' => null, 'height' => null, 'alt_text' => null])]))->toContain('اختر صورة عامة')
        ->and(adRefusal(['desktop_media_id' => '1 OR 1=1']))->toContain('اختر صورة عامة')
        ->and(adRefusal(['desktop_media_id' => '99999999999999999999999']))->toContain('اختر صورة عامة')
        ->and(adRefusal(['desktop_media_id' => 987654321]))->toContain('اختر صورة عامة');

    $trashed = adImage(970, 90);
    DB::table('media_assets')->where('id', $trashed)->update(['deleted_at' => now()]);
    expect(adRefusal(['desktop_media_id' => $trashed]))->toContain('اختر صورة عامة');
});

// ---- times, priority, status -----------------------------------------------------------------------------

test('the end comes after the start and, when it is set, lies ahead', function () {
    expect(adRefusal(['starts_at' => '2030-01-02 10:00:00', 'ends_at' => '2030-01-02 10:00:00']))->toStartWith('ends_at: وقت النهاية يجب أن يكون بعد البداية')
        ->and(adRefusal(['starts_at' => now()->subDays(9)->format('Y-m-d H:i:s'), 'ends_at' => now()->subDay()->format('Y-m-d H:i:s')]))->toStartWith('ends_at: وقت النهاية مضى')
        ->and(adRefusal(['starts_at' => 'yesterday-ish']))->toStartWith('starts_at:')
        ->and(adRefusal(['ends_at' => '']))->toStartWith('ends_at:');

    // A campaign that has ended keeps its end when edited for something else.
    $c = adCampaign();
    DB::table('ad_campaigns')->where('id', $c->id)->update(['starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(2)]);
    $c->refresh();
    expect(adRefusal(['advertiser_id' => $c->advertiser_id, 'name' => 'اسم جديد', 'starts_at' => $c->starts_at->format('Y-m-d H:i:s.u'), 'ends_at' => $c->ends_at->format('Y-m-d H:i:s.u')], $c))->toBe('saved');
});

test('the priority is a whole number from 1 to 100, and the status one of three', function (mixed $priority, bool $ok) {
    expect(adRefusal(['priority' => $priority]))->toBe($ok ? 'saved' : 'priority: الأولوية رقم صحيح من 1 إلى 100.');
})->with([[1, true], [100, true], ['75', true], [0, false], [101, false], [-5, false], ['1.5', false], ['abc', false], ['', false], [null, false], ['1e2', false]]);

test('an unknown status or advertiser is refused, and a name is required', function () {
    expect(adRefusal(['status' => 'ended']))->toStartWith('status:')
        ->and(adRefusal(['advertiser_id' => 999999]))->toStartWith('advertiser_id:')
        ->and(adRefusal(['advertiser_id' => 'x']))->toStartWith('advertiser_id:')
        ->and(adRefusal(['name' => '   ']))->toStartWith('name:')
        ->and(adRefusal(['name' => str_repeat('ح', 161)]))->toStartWith('name:');
});

// ---- deleting --------------------------------------------------------------------------------------------

test('a campaign with counted views or clicks keeps its report: it cannot be deleted', function () {
    $c = adCampaign();
    DB::table('ad_stats_daily')->insert(['campaign_id' => $c->id, 'placement_code' => 'header_leaderboard', 'stat_date' => now()->toDateString(), 'impressions' => 1]);

    expect(fn () => app(AdCampaigns::class)->delete($c))->toThrow(AdRejected::class, 'أوقفها بدلاً من ذلك');
    expect(AdCampaign::query()->find($c->id))->not->toBeNull();

    $empty = adCampaign();
    app(AdCampaigns::class)->delete($empty);
    expect(AdCampaign::query()->find($empty->id))->toBeNull()->and(DB::table('ad_campaign_placements')->where('campaign_id', $empty->id)->count())->toBe(0);
});

test('the state the panel shows follows the clock and the status', function () {
    $c = adCampaign();
    expect($c->state())->toBe('running')
        ->and($c->state(now()->subHour()))->toBe('scheduled')
        ->and($c->state(now()->addWeeks(2)))->toBe('ended');
    $c->status = 'paused';
    expect($c->state())->toBe('paused')->and($c->state(now()->addWeeks(2)))->toBe('ended');
    $c->status = 'draft';
    expect($c->state(now()->addWeeks(2)))->toBe('draft');
});
