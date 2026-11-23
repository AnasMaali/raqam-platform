<?php

use App\Content\PostSaver;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Panel\PanelAlerts;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * The panel's home, the editor's first (panel phase 3): the drafts in progress, what goes live next and the messages
 * waiting come before the day's numbers; the system's state (the outside connections and the names of their keys) is
 * last, closed, and only for whoever holds «settings.manage».
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    ensurePostKinds();
    $this->admin = User::factory()->staff()->create();
});

/** A member of the team who writes and publishes, without the settings (multi_admin on). */
function dashboardEditor(): User
{
    Artisan::call('permissions:install');
    DB::table('feature_flags')->updateOrInsert(['key' => 'multi_admin'], ['description_ar' => 'أكثر من مستخدم وأدوار لفريق الإدارة', 'is_enabled' => true]);
    $role = DB::table('roles')->insertGetId(['code' => 'writer', 'name_ar' => 'محرر']);
    foreach (['posts.manage', 'posts.publish', 'messages.manage'] as $code) {
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
    }
    $editor = User::factory()->bareStaff()->create();
    DB::table('user_roles')->insert(['user_id' => $editor->id, 'role_id' => $role]);

    return $editor;
}

test('the home starts with the editor\'s work: the drafts in progress, what goes live next, the new messages, each a link', function () {
    $this->actingAs($this->admin);
    $draft = app(PostSaver::class)->create('news', 'مسودة الموازنة', $this->admin->id);
    $soon = app(PostSaver::class)->create('news', 'تقرير الغد', $this->admin->id);
    DB::table('posts')->where('id', $soon->id)->update(['status' => 'scheduled', 'published_at' => '2030-01-14 10:00:00+00', 'category_id' => insertCategory('اقتصاد'),
        'cover_media_id' => insertMedia(), 'body' => '<p>نص</p>']);
    $message = DB::table('contact_messages')->insertGetId(['name' => 'سامي', 'email' => 'sami@example.com', 'subject' => 'استفسار عن الإعلان', 'message' => 'نص الرسالة']);

    $html = Livewire::test(Dashboard::class)->assertOk()->html();

    expect($html)->toContain('مسودات قيد العمل')->toContain('مسودة الموازنة')->toContain("posts/{$draft->id}/edit")
        ->toContain('يُنشر قريباً')->toContain('تقرير الغد')->toContain("يُنشر \u{2066}2030\u{2011}01\u{2011}14\u{00A0}12:00\u{2069}")   // Palestine time, kept in order inside the Arabic line
        ->toContain('استفسار عن الإعلان')->toContain("contact-messages/{$message}");
    // The work comes before the day's numbers, and both before the system.
    expect(strpos($html, 'مسودات قيد العمل'))->toBeLessThan(strpos($html, 'زيارات اليوم'))
        ->and(strpos($html, 'زيارات اليوم'))->toBeLessThan(strpos($html, 'حالة النظام'));
});

test('a new item is the one primary button of the home; refreshing the site is quiet', function () {
    $this->actingAs($this->admin);
    $page = Livewire::test(Dashboard::class)->instance();

    expect($page->getAction('newPost')->getUrl())->toEndWith('/posts/create')
        ->and($page->getAction('newPost')->getColor())->toBe('primary')
        ->and($page->getAction('refreshSite')->getColor())->toBe('gray');
});

test('the system\'s state is last and closed, with the outside connections and the names of their keys', function () {
    $this->actingAs($this->admin);

    $html = Livewire::test(Dashboard::class)->html();

    expect($html)->toContain('حالة النظام')->toContain('data-system')->toContain('OPENWEATHER_API_KEY')
        ->toMatch('~data-system[^>]*>.*?isCollapsed: true~s');
});

test('someone without «settings.manage» sees the work and the day, never the system or a key\'s name', function () {
    $editor = dashboardEditor();
    $this->actingAs($editor);
    app(PostSaver::class)->create('news', 'مسودة المحرر', $editor->id);

    $html = Livewire::test(Dashboard::class)->assertOk()->html();

    expect($html)->toContain('مسودة المحرر')->toContain('زيارات اليوم')
        ->not->toContain('حالة النظام')->not->toContain('data-system')->not->toContain('OPENWEATHER_API_KEY')->not->toContain('_API_KEY');
});

test('the system\'s warnings go to the system section; what the editors act on stays at the top for everyone', function () {
    Cache::put('youtube:units:'.now('America/Los_Angeles')->format('Y-m-d'), 7500);   // near the day's quota

    expect(PanelAlerts::system())->toHaveCount(1)->and(PanelAlerts::system()[0])->toContain('حصة يوتيوب')
        ->and(PanelAlerts::editorial())->toBe([]);

    $this->actingAs(dashboardEditor());
    expect(Livewire::test(Dashboard::class)->html())->not->toContain('حصة يوتيوب');
});
