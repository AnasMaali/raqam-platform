<?php

use App\Jobs\SendSiteWebhook;
use App\Models\Category;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use App\Navigation\MenuStore;
use App\Settings\FeatureFlags;
use App\Settings\SettingsStore;
use App\Settings\SiteProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->staff()->create();
    config(['raqam.site_webhook.url' => 'https://site.example/hooks/raqam', 'raqam.site_webhook.secret' => 'test-secret-0123456789']);
    Bus::fake([SendSiteWebhook::class]);
});

/** @return list<array<string, mixed>> the payloads of the webhooks queued so far */
function siteHooks(): array
{
    $sent = [];
    Bus::assertDispatched(SendSiteWebhook::class, function (SendSiteWebhook $job) use (&$sent) {
        $sent[] = $job->payload;

        return true;
    });

    return $sent;
}

function siteHookTypes(): array
{
    return array_map(fn ($p) => $p['type'].':'.$p['change'], siteHooks());
}

// ---- /site (21.13) ----------------------------------------------------------------------------------------------------

test('the site settings carry the name, logo, contact, social accounts, default SEO and the switches, in the contract shape', function () {
    $logo = insertMedia(['alt_text' => 'شعار رَقَم']);
    app(SettingsStore::class)->save([
        'general.site_name' => 'رَقَم', 'general.logo' => (string) $logo, 'contact.phone' => '+970599123456', 'contact.whatsapp' => '+970599000111',
        'contact.whatsapp_message' => 'مرحباً رَقَم', 'contact.email' => 'info@raqam.example', 'contact.address' => 'نابلس',
        'seo.title_suffix' => ' | رَقَم', 'seo.default_description' => 'منصة اقتصادية', 'seo.default_image' => (string) $logo,
    ], ['facebook' => 'https://facebook.com/raqam', 'x' => 'https://x.com/raqam'], $this->admin->id);
    DB::table('site_social_links')->insert(['platform_code' => 'tiktok', 'url' => 'https://tiktok.com/@raqam', 'is_visible' => false]);

    $data = api('/site')->assertOk()->assertHeader('ETag')->json('data');
    validateAgainstPath(['data' => $data], '/site');

    expect($data['name'])->toBe('رَقَم')
        ->and($data['logo']['id'])->toBe($logo)
        ->and($data['contact'])->toMatchArray(['phone' => '+970599123456', 'email' => 'info@raqam.example', 'address' => 'نابلس'])
        ->and($data['contact']['whatsapp_url'])->toBe('https://wa.me/970599000111?text='.rawurlencode('مرحباً رَقَم'))
        ->and(array_column($data['social'], 'platform'))->toBe(['facebook', 'x'])   // the hidden one is left out, platform order kept
        ->and($data['seo'])->toMatchArray(['title_suffix' => '| رَقَم', 'default_description' => 'منصة اقتصادية'])
        ->and($data['features'])->toBe(['ad_mobile_sticky' => false, 'ad_popup' => false, 'booking' => false, 'chatbot' => true,
            'customer_accounts' => false, 'online_payments' => false, 'public_view_counts' => false]);
});

test('nothing internal or secret is in the site settings', function () {
    app(SettingsStore::class)->save(['mail.no_reply' => 'no-reply@raqam.example', 'mail.booking' => 'booking@raqam.example',
        'newsletter.send_time' => '09:00', 'chatbot.welcome' => 'أهلاً'], [], $this->admin->id);
    config(['raqam.api.site_token' => 'site-token-abcdef123456', 'raqam.indexnow.key' => 'indexnowkey12345678']);

    $body = api('/site')->assertOk()->getContent();

    foreach (['no-reply@raqam.example', 'booking@raqam.example', '09:00', 'site-token-abcdef123456', 'indexnowkey12345678', 'multi_admin', 'chatbot_ai'] as $hidden) {
        expect($body)->not->toContain($hidden);
    }
});

test('with nothing set the name falls back to the platform name and every field is still there', function () {
    DB::table('settings')->whereIn('key', SiteProfile::SETTINGS)->delete();

    $data = api('/site')->assertOk()->json('data');
    validateAgainstPath(['data' => $data], '/site');

    expect($data['name'])->toBe('رَقَم')->and($data['logo'])->toBeNull()->and($data['contact']['whatsapp_url'])->toBeNull()->and($data['social'])->toBe([]);
});

test('a change of what every page shows sends one site webhook; other settings send none', function () {
    Bus::fake([SendSiteWebhook::class]);
    $store = app(SettingsStore::class);

    $store->save(['newsletter.send_time' => '10:00', 'chatbot.welcome' => 'مرحباً'], [], $this->admin->id);
    Bus::assertNotDispatched(SendSiteWebhook::class);

    $store->save(['general.site_name' => 'رقم'], [], $this->admin->id);
    expect(siteHookTypes())->toBe(['site:settings']);

    $store->save(['general.site_name' => 'رقم'], [], $this->admin->id);   // the same value: no change, no webhook
    expect(siteHookTypes())->toBe(['site:settings']);

    $store->save([], ['instagram' => 'https://instagram.com/raqam'], $this->admin->id);
    $store->save([], ['instagram' => null], $this->admin->id);
    expect(siteHookTypes())->toBe(['site:settings', 'site:settings', 'site:settings']);
});

test('a switch the site acts on sends a site webhook; a panel-only switch does not', function () {
    Bus::fake([SendSiteWebhook::class]);

    app(FeatureFlags::class)->set('chatbot', false, $this->admin->id);
    expect(siteHookTypes())->toBe(['site:feature']);

    app(FeatureFlags::class)->set('multi_admin', true, $this->admin->id, fromServer: true);
    expect(siteHookTypes())->toBe(['site:feature']);
});

// ---- /menus (21.12) ---------------------------------------------------------------------------------------------------

function menuTree(): array
{
    $page = Page::query()->create(['title' => 'من نحن', 'slug' => 'about-us', 'is_published' => true]);
    $draft = Page::query()->create(['title' => 'مسودة', 'slug' => 'draft-page', 'is_published' => false]);
    $parent = Category::query()->create(['name_ar' => 'أسواق', 'slug' => 'أسواق-x', 'is_active' => true]);
    $child = Category::query()->create(['name_ar' => 'بورصة', 'slug' => 'بورصة-x', 'parent_id' => $parent->id, 'is_active' => true]);
    $podcast = Section::query()->where('key', 'podcast')->first();

    return compact('page', 'draft', 'parent', 'child', 'podcast');
}

test('the menus come with their addresses made, in order, one level of children, in the contract shape', function () {
    ['page' => $page, 'parent' => $parent, 'child' => $child, 'podcast' => $podcast] = menuTree();
    app(MenuStore::class)->save('header', [
        ['label_ar' => 'الأسواق', 'type' => 'category', 'category_id' => $parent->id, 'children' => [
            ['label_ar' => 'البورصة', 'type' => 'category', 'category_id' => $child->id],
            ['label_ar' => 'مخفي', 'type' => 'url', 'url' => '/x', 'is_active' => false],
        ]],
        ['label_ar' => 'البودكاست', 'type' => 'section', 'section_id' => $podcast->id],
        ['label_ar' => 'شريك', 'type' => 'url', 'url' => 'https://partner.example/', 'opens_new_tab' => true],
    ], $this->admin->id);
    app(MenuStore::class)->save('footer_legal', [['label_ar' => 'من نحن', 'type' => 'page', 'page_id' => $page->id]], $this->admin->id);

    $data = api('/menus')->assertOk()->json('data');
    validateAgainstPath(['data' => $data], '/menus');

    expect($data['header'])->toBe([
        ['label' => 'الأسواق', 'href' => '/category/أسواق-x', 'external' => false, 'opens_new_tab' => false, 'children' => [
            ['label' => 'البورصة', 'href' => '/category/بورصة-x', 'external' => false, 'opens_new_tab' => false, 'children' => []],
        ]],
        ['label' => 'البودكاست', 'href' => '/podcast', 'external' => false, 'opens_new_tab' => false, 'children' => []],
        ['label' => 'شريك', 'href' => 'https://partner.example/', 'external' => true, 'opens_new_tab' => true, 'children' => []],
    ])->and($data['footer_main'])->toBe([])
        ->and($data['footer_legal'][0]['href'])->toBe('/about-us');
});

test('a link to something a visitor cannot open is left out, and a top-level link takes its children with it', function () {
    ['draft' => $draft, 'parent' => $parent, 'child' => $child, 'podcast' => $podcast] = menuTree();
    app(MenuStore::class)->save('header', [
        ['label_ar' => 'الأسواق', 'type' => 'category', 'category_id' => $parent->id, 'children' => [['label_ar' => 'البورصة', 'type' => 'category', 'category_id' => $child->id]]],
        ['label_ar' => 'مسودة', 'type' => 'page', 'page_id' => $draft->id],
        ['label_ar' => 'البودكاست', 'type' => 'section', 'section_id' => $podcast->id],
    ], $this->admin->id);

    $parent->update(['is_active' => false]);
    $podcast->update(['is_visible' => false]);

    expect(api('/menus')->json('data.header'))->toBe([]);

    $parent->update(['is_active' => true]);
    $child->update(['is_active' => false]);
    expect(api('/menus')->json('data.header'))->toHaveCount(1)->and(api('/menus')->json('data.header.0.children'))->toBe([]);
});

test('saving a menu sends a menus webhook only when it changed', function () {
    Bus::fake([SendSiteWebhook::class]);
    $tree = [['label_ar' => 'شريك', 'type' => 'url', 'url' => 'https://partner.example/']];

    app(MenuStore::class)->save('footer_main', $tree, $this->admin->id);
    app(MenuStore::class)->save('footer_main', $tree, $this->admin->id);

    expect(siteHookTypes())->toBe(['menus:footer_main']);
});

test('a linked page, category or section that changes its address or leaves the site redraws the menus; an unlinked one does not', function () {
    ['page' => $page, 'parent' => $parent, 'child' => $child, 'podcast' => $podcast] = menuTree();
    $other = Category::query()->create(['name_ar' => 'غير مربوط', 'slug' => 'غير-مربوط', 'is_active' => true]);
    app(MenuStore::class)->save('header', [
        ['label_ar' => 'الأسواق', 'type' => 'category', 'category_id' => $parent->id],
        ['label_ar' => 'من نحن', 'type' => 'page', 'page_id' => $page->id],
        ['label_ar' => 'البودكاست', 'type' => 'section', 'section_id' => $podcast->id],
    ], $this->admin->id);
    Bus::fake([SendSiteWebhook::class]);

    $other->update(['slug' => 'غير-مربوط-2', 'is_active' => false]);
    $parent->update(['description' => 'وصف']);               // not part of the link
    Bus::assertNotDispatched(SendSiteWebhook::class);

    $parent->update(['slug' => 'أسواق-y']);
    $podcast->update(['is_visible' => false]);
    $page->update(['is_published' => false]);
    $child->delete();                                         // not linked
    $parent->delete();

    expect(array_values(array_filter(siteHookTypes(), fn ($t) => str_starts_with($t, 'menus:'))))->toBe(['menus:target', 'menus:target', 'menus:target', 'menus:target']);
});
