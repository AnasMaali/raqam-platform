<?php

use App\Ads\AdRejected;
use App\Ads\AdSlots;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AdCampaigns\Pages\CampaignReport;
use App\Filament\Resources\AdCampaigns\Pages\CreateAdCampaign;
use App\Filament\Resources\AdCampaigns\Pages\EditAdCampaign;
use App\Filament\Resources\AdCampaigns\Pages\ListAdCampaigns;
use App\Filament\Resources\AdPlacements\Pages\ManageAdPlacements;
use App\Filament\Resources\Advertisers\Pages\ManageAdvertisers;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Models\Advertiser;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
});

/** A form's times are typed in Palestine time. */
function hebron(DateTimeInterface $t): string
{
    return CarbonImmutable::instance($t)->setTimezone('Asia/Hebron')->format('Y-m-d H:i:s');
}

test('a campaign is created from the screen and lands with its slots and pictures', function () {
    $advertiser = adAdvertiser();
    Livewire::test(CreateAdCampaign::class)
        ->fillForm([
            'name' => 'حملة الشاشة', 'advertiser_id' => $advertiser->id, 'target_url' => 'https://bank.example.ps/', 'status' => 'active', 'priority' => 60,
            'starts_at' => hebron(now()->addHour()), 'ends_at' => hebron(now()->addDays(10)),
            'placements' => ['header_leaderboard'], 'desktop_media_id' => $d = adImage(970, 90), 'mobile_media_id' => $m = adImage(320, 100),
        ])
        ->call('create')->assertHasNoFormErrors();

    $c = AdCampaign::query()->where('name', 'حملة الشاشة')->firstOrFail();
    expect($c->placementCodes())->toBe(['header_leaderboard'])->and($c->creativeIds())->toBe(['desktop' => $d, 'mobile' => $m])
        ->and($c->created_by)->toBe($this->admin->id)
        // Typed in Palestine time, stored in UTC.
        ->and($c->starts_at->setTimezone('Asia/Hebron')->format('Y-m-d H:i'))->toBe(now('Asia/Hebron')->addHour()->format('Y-m-d H:i'));
});

test('a refused campaign shows the reason under its field and saves nothing', function () {
    Livewire::test(CreateAdCampaign::class)
        ->fillForm([
            'name' => 'حملة', 'advertiser_id' => adAdvertiser()->id, 'target_url' => 'http://bank.example.ps/', 'status' => 'active', 'priority' => 50,
            'starts_at' => hebron(now()), 'ends_at' => hebron(now()->addDay()), 'placements' => ['header_leaderboard'],
            'desktop_media_id' => adImage(970, 90), 'mobile_media_id' => adImage(320, 100),
        ])
        ->call('create')->assertHasFormErrors(['target_url']);

    Livewire::test(CreateAdCampaign::class)
        ->fillForm([
            'name' => 'حملة', 'advertiser_id' => adAdvertiser()->id, 'target_url' => 'https://bank.example.ps/', 'status' => 'active', 'priority' => 50,
            'starts_at' => hebron(now()), 'ends_at' => hebron(now()->addDay()), 'placements' => ['header_leaderboard'],
            'desktop_media_id' => adImage(300, 250), 'mobile_media_id' => adImage(320, 100),
        ])
        ->call('create')->assertHasFormErrors(['desktop_media_id']);

    expect(AdCampaign::query()->count())->toBe(0);
});

test('editing a campaign shows its slots and pictures, and the list shows its state and numbers', function () {
    $c = adCampaign(['name' => 'تعمل الآن']);
    DB::table('ad_stats_daily')->insert(['campaign_id' => $c->id, 'placement_code' => 'header_leaderboard', 'stat_date' => now()->toDateString(), 'impressions' => 400, 'clicks' => 10]);

    Livewire::test(EditAdCampaign::class, ['record' => $c->id])
        ->assertFormSet(['placements' => ['header_leaderboard'], 'desktop_media_id' => $c->creativeIds()['desktop'], 'mobile_media_id' => $c->creativeIds()['mobile']])
        ->fillForm(['priority' => 90])->call('save')->assertHasNoFormErrors();
    expect($c->refresh()->priority)->toBe(90);

    Livewire::test(ListAdCampaigns::class)->assertSee('تعمل الآن')->assertSee('2.50%')->assertSee('400');
});

test('a campaign with numbers is not deleted from the list; the admin is told why', function () {
    $c = adCampaign();
    DB::table('ad_stats_daily')->insert(['campaign_id' => $c->id, 'placement_code' => 'header_leaderboard', 'stat_date' => now()->toDateString(), 'clicks' => 1]);

    Livewire::test(ListAdCampaigns::class)->callAction(TestAction::make('delete')->table($c))->assertNotified('لم تُحذف الحملة');
    expect(AdCampaign::query()->find($c->id))->not->toBeNull();
});

test('the report page shows the numbers and gives Excel and PDF files', function () {
    $c = adCampaign();
    DB::table('ad_stats_daily')->insert(['campaign_id' => $c->id, 'placement_code' => 'header_leaderboard', 'stat_date' => now('Asia/Hebron')->toDateString(), 'impressions' => 300, 'clicks' => 3]);

    $page = Livewire::test(CampaignReport::class, ['record' => $c->id])->assertSee('1.00%')->assertSee('بانر أعلى الصفحة');
    $page->callAction('xlsx')->assertFileDownloaded("ad-report-{$c->id}-".now('Asia/Hebron')->toDateString().'-'.now('Asia/Hebron')->toDateString().'.xlsx');
    $page->callAction('pdf')->assertFileDownloaded();

    $page->set('data.from', '2020-01-01')->set('data.to', '2026-10-01')->assertSee('سنتين');
});

test('a slot is edited with its house banner; a bad size or link is refused with a message', function () {
    $slot = AdPlacement::query()->find('sidebar_mpu');
    Livewire::test(ManageAdPlacements::class)
        ->callAction(TestAction::make('edit')->table($slot), [
            'name_ar' => 'المربع الجانبي', 'desktop_width' => 300, 'desktop_height' => 600, 'mobile_width' => 300, 'mobile_height' => 250,
            'is_enabled' => true, 'lazy_load' => true, 'empty_behavior' => 'house',
            'house_desktop_media_id' => $d = adImage(300, 600), 'house_mobile_media_id' => null, 'house_target_path' => '/advertise', 'house_is_active' => true,
        ])->assertNotified('حُفظ المكان.');
    expect($slot->refresh()->only(['name_ar', 'desktop_height']))->toBe(['name_ar' => 'المربع الجانبي', 'desktop_height' => 600])
        ->and(DB::table('house_ads')->where('placement_code', 'sidebar_mpu')->value('desktop_media_id'))->toBe($d);

    // Sizes alone (no banner, so its picture cannot be what refuses them), then the banner's link, picture and the choice.
    $plain = ['house_desktop_media_id' => null, 'house_mobile_media_id' => null, 'empty_behavior' => 'hide'];
    foreach ([['desktop_width' => 5] + $plain, ['desktop_height' => 2001] + $plain, ['mobile_width' => 0] + $plain,
        ['house_target_path' => 'javascript:alert(1)'], ['house_target_path' => '//evil.example'], ['house_target_path' => 'http://evil.example'],
        ['house_desktop_media_id' => adImage(728, 90)], ['empty_behavior' => 'nothing']] as $bad) {
        $x = Livewire::test(ManageAdPlacements::class)
            ->callAction(TestAction::make('edit')->table($slot->refresh()), $bad + [
                'name_ar' => 'المربع الجانبي', 'desktop_width' => 300, 'desktop_height' => 600, 'mobile_width' => 300, 'mobile_height' => 250,
                'is_enabled' => true, 'lazy_load' => true, 'empty_behavior' => 'house',
                'house_desktop_media_id' => $d, 'house_mobile_media_id' => null, 'house_target_path' => '/advertise', 'house_is_active' => true,
            ]);
        // The form itself drops a choice it does not offer (checked below: nothing changes); the rest is refused by the save, with a message.
        if (! isset($bad['empty_behavior'])) {
            $x->assertNotified('لم يُحفظ المكان');
        }
        expect($slot->refresh()->only(['desktop_width', 'desktop_height', 'mobile_width', 'empty_behavior']))
            ->toBe(['desktop_width' => 300, 'desktop_height' => 600, 'mobile_width' => 300, 'empty_behavior' => 'house'], json_encode($bad));
    }
    // The same choice, sent past the form, is refused by the save too.
    expect(fn () => app(AdSlots::class)->save($slot->refresh(), ['empty_behavior' => 'nothing'] + $slot->only(['name_ar', 'desktop_width', 'desktop_height', 'mobile_width', 'mobile_height'])))
        ->toThrow(AdRejected::class, 'المكان الفارغ');
    expect(DB::table('house_ads')->where('placement_code', 'sidebar_mpu')->first(['target_path', 'desktop_media_id']))->toEqual((object) ['target_path' => '/advertise', 'desktop_media_id' => $d]);
});

test('the popup frequency is 1 to 720 hours, and the slot screen says the popup also needs its feature switch', function () {
    $popup = AdPlacement::query()->find('popup');
    $data = ['name_ar' => 'نافذة', 'desktop_width' => 600, 'desktop_height' => 500, 'mobile_width' => 320, 'mobile_height' => 400, 'is_enabled' => true, 'lazy_load' => true,
        'empty_behavior' => 'hide', 'house_desktop_media_id' => null, 'house_mobile_media_id' => null, 'house_target_path' => '', 'house_is_active' => false];

    Livewire::test(ManageAdPlacements::class)->callAction(TestAction::make('edit')->table($popup), $data + ['frequency_cap_hours' => 721])->assertNotified('لم يُحفظ المكان');
    Livewire::test(ManageAdPlacements::class)->callAction(TestAction::make('edit')->table($popup), $data + ['frequency_cap_hours' => 12])->assertNotified('حُفظ المكان.');
    expect($popup->refresh()->frequency_cap_hours)->toBe(12);

    Livewire::test(ManageAdPlacements::class)->assertSee('مطفأ بمفتاح التشغيل');
});

test('the home lists the running campaigns and warns three days before one ends', function () {
    adCampaign(['name' => 'تنتهي قريباً', 'ends_at' => now()->addDays(2)->format('Y-m-d H:i:s')]);
    adCampaign(['name' => 'باقية طويلاً', 'ends_at' => now()->addDays(4)->format('Y-m-d H:i:s')]);
    adCampaign(['name' => 'موقوفة تنتهي', 'status' => 'paused', 'ends_at' => now()->addDay()->format('Y-m-d H:i:s')]);

    Livewire::test(Dashboard::class)
        ->assertSee('حملات إعلانية فعّالة (2)')
        ->assertSee('الحملة «تنتهي قريباً» (بنك القدس) تنتهي')
        ->assertDontSee('الحملة «باقية طويلاً»')
        ->assertDontSee('الحملة «موقوفة تنتهي»');
});

test('an advertiser is added once per company; one with campaigns cannot be deleted', function () {
    $org = Organization::query()->create(['name' => 'شركة المعلن', 'slug' => 'adv-co']);
    Livewire::test(ManageAdvertisers::class)->callAction('create', ['organization_id' => $org->id, 'contact_name' => 'سامي', 'contact_phone' => '+970599123456', 'contact_email' => 'sami@example.ps']);
    // Choosing a company that already advertises picks that advertiser: no error, no second row.
    Livewire::test(ManageAdvertisers::class)->callAction('create', ['organization_id' => $org->id, 'contact_name' => 'آخر'])->assertHasNoActionErrors();
    expect(Advertiser::query()->where('organization_id', $org->id)->count())->toBe(1);

    Livewire::test(ManageAdvertisers::class)->callAction('create', ['organization_id' => $org->id, 'contact_phone' => '0599123456'])->assertHasActionErrors(['contact_phone']);

    $c = adCampaign();
    Livewire::test(ManageAdvertisers::class)->assertActionHidden(TestAction::make('delete')->table($c->advertiser));
});
