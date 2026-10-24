<?php

namespace App\Audit;

/** The Arabic names of the tables the audit trail speaks about (22.14); an unlisted table shows its own name. */
final class AuditSubjects
{
    public const NAMES = [
        'posts' => 'المواد', 'categories' => 'التصنيفات', 'tags' => 'الوسوم', 'authors' => 'الكتّاب', 'guests' => 'الضيوف',
        'sections' => 'الأقسام الخاصة', 'pages' => 'الصفحات', 'characters' => 'الشخصيات', 'videos' => 'الفيديوهات',
        'playlists' => 'قوائم التشغيل', 'media_assets' => 'مكتبة الوسائط', 'users' => 'المستخدمون', 'settings' => 'الإعدادات',
        'feature_flags' => 'مفاتيح التشغيل', 'menus' => 'قوائم الموقع', 'home_blocks' => 'ترتيب الرئيسية', 'redirects' => 'التحويلات',
        'ad_campaigns' => 'الحملات الإعلانية', 'advertisers' => 'المعلنون', 'organizations' => 'الجهات', 'services' => 'الخدمات',
        'subscribers' => 'المشتركون', 'newsletter_issues' => 'النشرات', 'email_templates' => 'قوالب البريد',
        'contact_messages' => 'رسائل «اتصل بنا»', 'bot_entries' => 'مداخل الشات بوت', 'faqs' => 'الأسئلة الشائعة',
        'synonym_groups' => 'المرادفات', 'currencies' => 'العملات', 'rate_overrides' => 'الأسعار اليدوية',
        'rate_board_currencies' => 'لوحة العملات', 'cities' => 'المدن', 'weather_locations' => 'مواقع الطقس', 'eras' => 'الحقب',
        'seo_meta' => 'بيانات محركات البحث', 'site_social_links' => 'روابط التواصل الاجتماعي', 'data_providers' => 'مصادر البيانات',
    ];

    public static function name(?string $table): string
    {
        return $table === null ? '—' : (self::NAMES[$table] ?? $table);
    }
}
