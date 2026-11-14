<?php

namespace App\Audit;

use App\Mail\EmailCatalog;
use App\Settings\SettingsCatalog;
use Illuminate\Support\Facades\DB;

/**
 * The Arabic name of a field the audit trail records, as the audit screen shows it. A field is named once here
 * whichever table it belongs to (a slug is a slug everywhere). A field the trail names itself is read from where its
 * name lives: a setting from the settings catalog, a switch from its description, a link from its platform, a
 * message's field from the mail catalog. A field with no name here comes back null, and the screen shows it as stored.
 */
final class AuditFieldNames
{
    /** @var array<string, string> */
    public const COLUMNS = [
        // What every record has.
        'id' => 'الرقم',
        'key' => 'المفتاح',
        'code' => 'الرمز',
        'name' => 'الاسم',
        'name_ar' => 'الاسم',
        'search_name' => 'الاسم كما يُبحث به',
        'slug' => 'الرابط المختصر',
        'title' => 'العنوان',
        'subtitle' => 'العنوان الفرعي',
        'description' => 'الوصف',
        'description_ar' => 'الوصف',
        'summary' => 'الملخص',
        'status' => 'الحالة',
        'sort_order' => 'الترتيب',
        'is_active' => 'مفعّل',
        'is_enabled' => 'مفعّل',
        'is_visible' => 'ظاهر',
        'is_published' => 'منشور',
        'created_by' => 'أنشأه',
        'updated_by' => 'آخر من عدّله',
        'user_id' => 'حساب اللوحة',
        'seo_meta_id' => 'بيانات SEO',
        'cover_media_id' => 'صورة الغلاف',
        'notes' => 'ملاحظات',

        // Posts.
        'kind_code' => 'النوع',
        'excerpt' => 'المقتطف',
        'body' => 'النص',
        'body_length' => 'طول النص (حروف)',
        'category_id' => 'القسم',
        'section_id' => 'القسم الخاص',
        'published_at' => 'وقت النشر',
        'is_featured' => 'مميّز',
        'breaking_until' => 'عاجل حتى',
        'corrected_at' => 'وقت التصحيح',
        'correction_note' => 'ملاحظة التصحيح',
        'primary_video_id' => 'الفيديو الرئيسي',
        'sponsor_organization_id' => 'الجهة الراعية',
        'authors' => 'الكتّاب',
        'tags' => 'الوسوم',
        'guests' => 'الضيوف',
        'gallery' => 'صور المعرض',

        // The facts of each kind of post.
        'story.organization_id' => 'الشركة / الجهة',
        'story.person_name' => 'اسم الشخص',
        'story.figures' => 'أرقام بارزة',
        'episode.episode_number' => 'رقم الحلقة',
        'episode.season_id' => 'الموسم',
        'product.organization_id' => 'الجهة المنتجة',
        'product.product_name' => 'اسم المنتج',
        'product.contact_phone' => 'هاتف التواصل',
        'product.contact_url' => 'رابط التواصل',
        'history.era_id' => 'الحقبة',
        'history.period_label' => 'وصف الفترة',
        'history.year_from' => 'من سنة',
        'history.year_to' => 'إلى سنة',
        'initiative.initiative_name' => 'اسم المبادرة',
        'initiative.organizer_organization_id' => 'الجهة المنظِّمة',
        'initiative.status' => 'حالة المبادرة',
        'initiative.url' => 'رابط المبادرة',
        'roundtable.held_on' => 'تاريخ الجلسة',
        'awareness.awareness_type_code' => 'نوع التوعية',
        'awareness.character_pose_id' => 'وضعية الشخصية',
        'infographic.download_media_id' => 'ملف التحميل',

        // People, organisations and places.
        'bio' => 'النبذة',
        'job_title' => 'المسمّى الوظيفي',
        'photo_media_id' => 'الصورة',
        'team_sort_order' => 'الترتيب في صفحة الفريق',
        'organization_id' => 'الجهة',
        'sector_id' => 'القطاع',
        'city_id' => 'المدينة',
        'founded_year' => 'سنة التأسيس',
        'website' => 'الموقع الإلكتروني',
        'logo_media_id' => 'الشعار',
        'parent_id' => 'القسم الأب',
        'is_highlighted' => 'بارز',
        'year_from' => 'من سنة',
        'year_to' => 'إلى سنة',
        'latitude' => 'خط العرض',
        'longitude' => 'خط الطول',
        'is_default' => 'الافتراضية',

        // Awareness characters.
        'role_ar' => 'الدور',
        'accent_color' => 'اللون المميِّز',
        'rive_media_id' => 'ملف الحركة',

        // Media and videos.
        'disk' => 'مكان التخزين',
        'path' => 'مسار الملف',
        'original_name' => 'اسم الملف الأصلي',
        'mime_type' => 'نوع الملف',
        'size_bytes' => 'الحجم (بايت)',
        'checksum_sha256' => 'بصمة الملف',
        'width' => 'العرض',
        'height' => 'الارتفاع',
        'duration_seconds' => 'المدة (ثوانٍ)',
        'alt_text' => 'النص البديل',
        'caption' => 'التعليق',
        'credit' => 'المصدر',
        'focal_x' => 'نقطة التركيز (أفقياً)',
        'focal_y' => 'نقطة التركيز (عمودياً)',
        'visibility' => 'الظهور',
        'uploaded_by' => 'رفعه',
        'source' => 'المصدر',
        'display_title' => 'العنوان المعروض',
        'display_description' => 'الوصف المعروض',
        'poster_media_id' => 'صورة الغلاف',
        'upload_media_id' => 'الملف المرفوع',

        // Pages, SEO, addresses.
        'system_key' => 'مفتاح الصفحة',
        'meta_title' => 'عنوان SEO',
        'meta_description' => 'وصف SEO',
        'canonical_url' => 'الرابط الأساسي',
        'robots_noindex' => 'إخفاء عن محركات البحث',
        'share_media_id' => 'صورة المشاركة',
        'from_path' => 'من العنوان',
        'to_path' => 'إلى العنوان',
        'status_code' => 'نوع التحويل',
        'hits' => 'عدد الزيارات',
        'last_hit_at' => 'آخر زيارة',
        'resolved_path' => 'عنوان عولج',
        'reopened_path' => 'عنوان أعيد فتحه',
        'items' => 'العناصر',
        'home.layout' => 'ترتيب الرئيسية',
        'platform_code' => 'المنصة',
        'url' => 'الرابط',

        // Ads.
        'advertiser_id' => 'المعلن',
        'contact_name' => 'اسم المسؤول',
        'contact_email' => 'البريد الإلكتروني',
        'contact_phone' => 'الهاتف',
        'booking_id' => 'الحجز',
        'starts_at' => 'يبدأ',
        'ends_at' => 'ينتهي',
        'priority' => 'الأولوية',
        'target_url' => 'رابط الوجهة',
        'ending_alert_sent_at' => 'وقت تنبيه قرب الانتهاء',
        'placements' => 'أماكن الظهور',
        'creative_desktop' => 'تصميم الكمبيوتر',
        'creative_mobile' => 'تصميم الجوال',
        'placement' => 'مكان الإعلان',
        'placement_code' => 'مكان الإعلان',
        'house_ad' => 'إعلان المنصة',
        'desktop_width' => 'العرض على الكمبيوتر',
        'desktop_height' => 'الارتفاع على الكمبيوتر',
        'mobile_width' => 'العرض على الجوال',
        'mobile_height' => 'الارتفاع على الجوال',
        'empty_behavior' => 'عند عدم وجود إعلان',
        'frequency_cap_hours' => 'مرة كل (ساعات)',
        'lazy_load' => 'تحميل عند الوصول',
        'desktop_media_id' => 'صورة الكمبيوتر',
        'mobile_media_id' => 'صورة الجوال',
        'target_path' => 'عنوان الوجهة',

        // Services and prices.
        'group_id' => 'المجموعة',
        'price' => 'السعر',
        'price_mode' => 'طريقة التسعير',
        'price_amount' => 'المبلغ',
        'price_note' => 'ملاحظة السعر',
        'currency_code' => 'العملة',
        'sale_price_amount' => 'سعر العرض',
        'sale_ends_at' => 'ينتهي العرض',
        'deliverables' => 'ماذا تشمل',
        'faqs' => 'أسئلة شائعة',
        'service_id' => 'الخدمة',

        // Currencies and weather.
        'symbol' => 'الرمز المعروض',
        'minor_units' => 'الخانات العشرية',
        'buy_spread_bp' => 'هامش الشراء (نقاط أساس)',
        'sell_spread_bp' => 'هامش البيع (نقاط أساس)',
        'display_decimals' => 'الخانات المعروضة',
        'buy_rate' => 'سعر الشراء',
        'sell_rate' => 'سعر البيع',
        'valid_until' => 'ساري حتى',

        // The chatbot, frequently asked questions, search.
        'question' => 'السؤال',
        'answer' => 'الجواب',
        'variants' => 'صيغ أخرى للسؤال',
        'keywords' => 'كلمات مفتاحية',
        'action' => 'الإجراء',
        'action_type' => 'نوع الإجراء',
        'action_url' => 'رابط الإجراء',
        'action_page_id' => 'صفحة الإجراء',
        'action_service_id' => 'خدمة الإجراء',
        'faq_id' => 'السؤال الشائع',
        'label_ar' => 'نص الزر',
        'is_root' => 'في البداية',
        'target_entry_id' => 'الجواب المرتبط',
        'target_page_id' => 'الصفحة المرتبطة',
        'target_service_id' => 'الخدمة المرتبطة',
        'terms' => 'الكلمات',

        // Messages, mail and the newsletter.
        'handled_at' => 'وقت المعالجة',
        'handled_by' => 'عالجه',
        'audience' => 'المستلم',
        'subject' => 'الموضوع',
        'body_html' => 'نص الرسالة',
        'intro' => 'المقدمة',
        'posts' => 'المواد',
        'subscribers.rows' => 'عدد المشتركين المصدَّرين',
        'statistics.period' => 'فترة الإحصاءات المصدَّرة',

        // Settings, switches and bookings.
        'value' => 'القيمة',
        'value_type' => 'نوع القيمة',
        'depends_on' => 'تتطلب',
        'general.timezone' => 'المنطقة الزمنية',
        'analytics.raw_retention_days' => 'مدة حفظ الإحصاءات التفصيلية (أيام)',
        'audit.retention_days' => 'مدة حفظ سجل التدقيق (أيام)',
        'scheduled_for' => 'الموعد',
        'published_post_id' => 'المادة المنشورة',
        'admin_notes' => 'ملاحظات الإدارة',
    ];

    /** The outside services a data source serves, as the switch between sources names them. */
    public const SERVICES = [
        'currency' => 'أسعار العملات', 'weather' => 'الطقس', 'video' => 'الفيديو', 'email' => 'البريد', 'newsletter' => 'النشرة البريدية',
    ];

    public static function label(string $field): ?string
    {
        if (isset(self::COLUMNS[$field])) {
            return self::COLUMNS[$field];
        }

        $setting = SettingsCatalog::fields()[$field]['label'] ?? null;
        if ($setting !== null) {
            return $setting;
        }

        [$head, $rest] = array_pad(explode('.', $field, 2), 2, null);
        if ($rest === null || $rest === '') {
            return null;
        }

        return match (true) {
            $head === 'feature' => ($name = self::names('feature_flags', 'key', 'description_ar')[$rest] ?? null) ? "الميزة «{$name}»" : null,
            $head === 'social', $head === 'link' => ($name = self::names('social_platforms', 'code', 'name')[$rest] ?? null) ? "رابط {$name}" : null,
            $head === 'provider' => isset(self::SERVICES[$rest]) ? 'مصدر '.self::SERVICES[$rest] : null,
            default => self::template($head, $rest),
        };
    }

    /** A message's subject, text or switch, named with the message (`admin_login_code.subject`). */
    private static function template(string $key, string $field): ?string
    {
        $name = EmailCatalog::templates()[$key]['name'] ?? null;
        $part = ['subject' => 'الموضوع', 'body_html' => 'النص', 'is_active' => 'مفعّلة'][$field] ?? null;

        return $name !== null && $part !== null ? "{$name}: {$part}" : null;
    }

    /** @return array<string, string> read once per request (a screen names many fields) */
    private static function names(string $table, string $key, string $name): array
    {
        return once(fn () => DB::table($table)->pluck($name, $key)->map(fn ($v) => (string) $v)->all());
    }

    /** The fields whose Arabic name holds this text, so the audit screen's search finds a field by the name it shows. */
    public static function matching(string $text): array
    {
        $names = self::COLUMNS + array_map(fn (array $setting) => $setting['label'], SettingsCatalog::fields());

        return array_keys(array_filter($names, fn (string $name) => mb_stripos($name, $text) !== false));
    }
}
