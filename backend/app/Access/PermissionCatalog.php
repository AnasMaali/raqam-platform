<?php

namespace App\Access;

/**
 * The permissions of the admin team (decision 2.7), one per part of the panel, written `module.action`. They are founded
 * now and used later: in V1 the only role in use is «admin», which holds every permission by rule (a permission added
 * here later is the admin's at once, with no row to forget). `permissions:install` writes the missing ones.
 */
final class PermissionCatalog
{
    public const PERMISSIONS = [
        'posts.manage' => 'إدارة المواد والصفحات',
        'posts.publish' => 'نشر المواد',
        'media.manage' => 'إدارة مكتبة الوسائط',
        'taxonomy.manage' => 'إدارة التصنيفات والكتّاب والضيوف',
        'videos.manage' => 'إدارة الفيديو',
        'markets.manage' => 'إدارة العملات والطقس',
        'ads.manage' => 'إدارة الإعلانات',
        'services.manage' => 'إدارة الخدمات والأسعار',
        'bookings.manage' => 'إدارة الحجوزات',
        'payments.refund' => 'استرجاع الدفعات',
        'messages.manage' => 'رسائل «اتصل بنا» والنشرة والشات بوت',
        'stats.view' => 'عرض الإحصائيات',
        'audit.view' => 'عرض سجل العمليات',
        'settings.manage' => 'الإعدادات ومفاتيح التشغيل',
        'users.manage' => 'إدارة فريق الإدارة وأدواره',
    ];

    public static function exists(string $code): bool
    {
        return isset(self::PERMISSIONS[$code]);
    }
}
