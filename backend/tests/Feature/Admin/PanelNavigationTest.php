<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The sidebar, ordered by how often an editor needs each screen (decision after the panel audit, 2026-11-22): the daily
 * screens on top with no group, the weekly groups next, the rare groups at the bottom and closed until opened.
 */

const PANEL_NAVIGATION = [
    '' => ['الرئيسية', 'المواد', 'مكتبة الوسائط', 'ترتيب الرئيسية', 'رسائل «اتصل بنا»'],
    'النشرة البريدية' => ['النشرات الأسبوعية', 'مشتركو النشرة'],
    'الفيديو' => ['مكتبة الفيديو', 'قوائم التشغيل'],
    'الإعلانات' => ['الحملات', 'الأماكن', 'المعلنون'],
    'الخدمات' => ['الخدمات والأسعار'],
    'الإحصائيات' => ['الإحصائيات'],
    'التصنيفات والأشخاص' => ['الكتّاب', 'الضيوف', 'الوسوم', 'التصنيفات', 'الأقسام الخاصة', 'شخصيات التوعية', 'الحقب التاريخية', 'الجهات والشركات', 'القطاعات الاقتصادية'],
    'الموقع' => ['الصفحات', 'قوائم الموقع', 'التحويلات', 'صفحات غير موجودة (404)'],
    'الأسعار والطقس' => ['العملات', 'الطقس'],
    'الشات بوت' => ['قاعدة المعرفة', 'الأسئلة الشائعة', 'الأزرار', 'أسئلة بدون جواب'],
    'البحث' => ['المترادفات', 'إحصائيات البحث'],
    'الإعدادات والنظام' => ['الإعدادات العامة', 'مفاتيح التشغيل', 'الإشعارات', 'قوالب الرسائل', 'سجل الإيميلات', 'سجل العمليات', 'محاولات الدخول'],
];

const PANEL_RARE_GROUPS = ['التصنيفات والأشخاص', 'الموقع', 'الأسعار والطقس', 'الشات بوت', 'البحث', 'الإعدادات والنظام'];

/** @return array<string, NavigationGroup> the admin's sidebar, as the panel builds it for a request. */
function panelNavigation(): array
{
    test()->actingAs(User::factory()->staff()->create())->followingRedirects()->get('/'.config('raqam.admin_path'))->assertOk();
    Filament::setCurrentPanel('admin');

    return Filament::getNavigation();
}

test('the sidebar holds every screen in the order of use: daily, weekly, then the rare groups', function () {
    $navigation = [];
    foreach (panelNavigation() as $group) {
        $navigation[(string) $group->getLabel()] = array_map(fn ($item) => (string) $item->getLabel(), $group->getItems()->all());
    }

    expect($navigation)->toBe(PANEL_NAVIGATION);
});

test('only the rare groups start closed', function () {
    $closed = [];
    foreach (panelNavigation() as $group) {
        if ($group->isCollapsed()) {
            $closed[] = (string) $group->getLabel();
        }
    }

    expect($closed)->toBe(PANEL_RARE_GROUPS);
});
