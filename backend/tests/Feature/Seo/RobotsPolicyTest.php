<?php

use App\Filament\Pages\GeneralSettings;
use App\Jobs\SendSiteWebhook;
use App\Models\User;
use App\Seo\CrawlerAgents;
use App\Settings\SettingsStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 | The crawler policy and llms.txt (phase 10b, GEO). This file and the data file it tests are the only places the crawlers
 | are named (decision 2026-10-25).
 */

beforeEach(function () {
    $this->admin = User::factory()->staff()->create();
});

function robotsGroups(): array
{
    return collect(api('/robots')->assertOk()->json('data.groups'))->keyBy('purpose')->all();
}

test('by default search engines and answer engines may crawl, training crawlers may not, in the contract shape', function () {
    $body = api('/robots')->assertOk()->assertHeader('ETag')->json();
    validateAgainstPath($body, '/robots');
    $groups = robotsGroups();

    expect($groups['search'])->toBe(['purpose' => 'search', 'agents' => ['*'], 'allow' => true])
        ->and($groups['answer']['allow'])->toBeTrue()->and($groups['training']['allow'])->toBeFalse()
        ->and($groups['answer']['agents'])->toContain('OAI-SearchBot', 'PerplexityBot', 'Claude-SearchBot')
        ->and($groups['training']['agents'])->toContain('GPTBot', 'ClaudeBot', 'Google-Extended', 'CCBot')
        ->and($body['data']['disallow'])->toBe(['/search', '/newsletter', '/bookings', '/account', '/preview'])
        ->and($body['data']['sitemaps'])->toBe(['/sitemap.xml']);
});

test('no crawler is in both lists, and the search engines are never named (they come under the star)', function () {
    expect(array_intersect(CrawlerAgents::ANSWER, CrawlerAgents::TRAINING))->toBe([]);
    foreach (['Googlebot', 'Bingbot', '*'] as $search) {
        expect(CrawlerAgents::ANSWER)->not->toContain($search)->and(CrawlerAgents::TRAINING)->not->toContain($search);
    }
});

test('the admin turns answer engines off and training crawlers on, each on its own', function () {
    $store = app(SettingsStore::class);

    $store->save(['seo.answer_bots' => false], [], $this->admin->id);
    expect(robotsGroups()['answer']['allow'])->toBeFalse()->and(robotsGroups()['training']['allow'])->toBeFalse();

    $store->save(['seo.training_bots' => true], [], $this->admin->id);
    expect(robotsGroups()['answer']['allow'])->toBeFalse()->and(robotsGroups()['training']['allow'])->toBeTrue();
    expect(DB::table('settings')->whereIn('key', ['seo.answer_bots', 'seo.training_bots'])->orderBy('key')->pluck('value')->all())->toBe(['false', 'true']);
});

test('the settings screen shows both switches with their defaults and saves them as the table expects', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    Livewire::test(GeneralSettings::class)
        ->assertFormSet(['seo__answer_bots' => true, 'seo__training_bots' => false])
        ->fillForm(['seo__answer_bots' => false, 'seo__training_bots' => true, 'seo__llms_summary' => 'منصة اقتصادية من نابلس.'])
        ->call('save')->assertHasNoFormErrors();

    expect(DB::table('settings')->whereIn('key', ['seo.answer_bots', 'seo.training_bots', 'seo.llms_summary'])->orderBy('key')->pluck('value', 'key')->all())
        ->toBe(['seo.answer_bots' => 'false', 'seo.llms_summary' => 'منصة اقتصادية من نابلس.', 'seo.training_bots' => 'true']);
    Livewire::test(GeneralSettings::class)->assertFormSet(['seo__answer_bots' => false, 'seo__training_bots' => true]);
});

test('a change of the policy or the llms text sends a robots webhook; nothing else does', function () {
    config(['raqam.site_webhook.url' => 'https://site.example/hooks/raqam', 'raqam.site_webhook.secret' => 'test-secret-0123456789']);
    Bus::fake([SendSiteWebhook::class]);
    $store = app(SettingsStore::class);

    $store->save(['seo.training_bots' => false], [], $this->admin->id);   // the default, written for the first time
    $store->save(['seo.training_bots' => true], [], $this->admin->id);
    $store->save(['seo.training_bots' => true], [], $this->admin->id);    // no change
    $store->save(['seo.llms_summary' => 'وصف'], [], $this->admin->id);

    $changes = [];
    Bus::assertDispatched(SendSiteWebhook::class, function ($job) use (&$changes) {
        $changes[] = $job->payload['type'].':'.$job->payload['change'];

        return true;
    });
    expect($changes)->toBe(['site:robots', 'site:robots', 'site:robots']);
});

// ---- llms.txt ---------------------------------------------------------------------------------------------------------

test('llms holds the name, the summary and the links a visitor can open, in the contract shape', function () {
    DB::table('settings')->updateOrInsert(['key' => 'seo.default_description'], ['value' => 'الوصف الافتراضي', 'value_type' => 'text']);
    DB::table('sections')->where('key', 'round_table')->update(['is_visible' => false]);
    $energy = insertCategory('طاقة-llms');
    $hidden = insertCategory('مخفي-llms');
    DB::table('categories')->where('id', $hidden)->update(['is_active' => false]);
    insertCategory('فرعي-llms', $energy);
    DB::table('pages')->insert([
        ['title' => 'من نحن', 'slug' => 'about', 'system_key' => 'about', 'is_published' => true],
        ['title' => 'السياسة التحريرية', 'slug' => 'editorial-policy', 'system_key' => 'editorial_policy', 'is_published' => true],
        ['title' => 'الخصوصية', 'slug' => 'privacy', 'system_key' => 'privacy', 'is_published' => true],
        ['title' => 'فريق العمل', 'slug' => 'team', 'system_key' => 'team', 'is_published' => false],
    ]);

    $body = api('/llms')->assertOk()->json();
    validateAgainstPath($body, '/llms');
    $groups = collect($body['data']['groups'])->mapWithKeys(fn ($g) => [$g['title'] => array_column($g['links'], 'path')]);

    expect($body['data']['summary'])->toBe('الوصف الافتراضي')
        ->and($groups['الأقسام الخاصة'])->toContain('/podcast')->not->toContain('/round-table')
        ->and($groups['التصنيفات الإخبارية'])->toContain('/category/طاقة-llms')
        ->and($groups['عن المنصة'])->toBe(['/about', '/editorial-policy'])
        ->and($groups['بيانات حيّة'])->toBe(['/currencies', '/weather'])
        ->and($body['data'])->toMatchArray(['feed' => '/feeds/posts', 'sitemap' => '/sitemap.xml']);
    foreach (['/category/مخفي-llms', '/category/فرعي-llms'] as $left) {
        expect($groups['التصنيفات الإخبارية'])->not->toContain($left);
    }

    app(SettingsStore::class)->save(['seo.llms_summary' => 'منصة اقتصادية من نابلس.'], [], $this->admin->id);
    expect(api('/llms')->json('data.summary'))->toBe('منصة اقتصادية من نابلس.');
});
