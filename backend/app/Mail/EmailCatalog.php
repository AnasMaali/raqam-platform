<?php

namespace App\Mail;

/**
 * Every message the platform sends (decision 20), with the text it starts with. The admin edits the subject and the
 * body of each one from the panel (20.7); the row in email_templates is made from here the first time it is needed,
 * and never overwritten afterwards.
 *
 * A variable is written {name} in the subject or the body. Its kind says how its value goes in:
 *  - text: escaped, line breaks kept (anything a visitor typed is text);
 *  - code: a one-time code, shown large;
 *  - url:  a link we made ourselves, as an <a>;
 *  - html: a block the platform builds itself, already escaped where it holds outside text (the weekly summary).
 * `required` variables cannot be removed (a sign-in code mail without the code would lock the admin out);
 * `locked` templates cannot be switched off; `direct` ones are not queued (the sign-in messages, decision 2026-10-23).
 */
final class EmailCatalog
{
    /** Who a message is from: the setting that holds each address (20.1). */
    public const SENDERS = [
        'no_reply' => 'mail.no_reply',
        'info' => 'mail.info',
        'ads' => 'mail.ads',
        'booking' => 'mail.booking',
    ];

    public const AUDIENCES = ['visitor' => 'زائر', 'admin' => 'الإدارة', 'customer' => 'عميل (غير مفعّل)'];

    /**
     * @return array<string, array{name: string, audience: string, sender: string, subject: string, body: string,
     *     vars: array<string, string>, required?: list<string>, locked?: bool, direct?: bool, active?: bool}>
     */
    public static function templates(): array
    {
        $device = ['الوقت' => 'text', 'المتصفح' => 'text', 'النظام' => 'text'];

        return [
            // Sign-in (decisions 2.4, 2026-10-23): straight away, three tries, never queued.
            'admin_login_code' => [
                'name' => 'رمز الدخول إلى اللوحة', 'audience' => 'admin', 'sender' => 'no_reply', 'locked' => true, 'direct' => true,
                'subject' => 'رمز الدخول إلى لوحة رَقَم',
                'body' => '<p>رمز الدخول إلى لوحة التحكم:</p><p>{الرمز}</p><p>صالح لمدة {الدقائق} دقائق ولمرة واحدة.</p><p>إن لم تحاول الدخول فتجاهل هذه الرسالة، ولا تشارك الرمز مع أحد.</p>',
                'vars' => ['الرمز' => 'code', 'الدقائق' => 'text'], 'required' => ['الرمز'],
            ],
            'admin_new_device' => [
                'name' => 'تنبيه دخول من جهاز جديد', 'audience' => 'admin', 'sender' => 'no_reply', 'locked' => true, 'direct' => true,
                'subject' => 'دخول إلى لوحة رَقَم من جهاز جديد',
                'body' => '<p>تم الدخول إلى لوحة التحكم من جهاز جديد:</p><ul><li>الوقت: {الوقت} (توقيت القدس)</li><li>المتصفح: {المتصفح}</li><li>النظام: {النظام}</li></ul><p><strong>إذا لم تكن أنت فغيّر كلمة المرور فوراً.</strong></p>',
                'vars' => $device,
            ],
            'admin_password_changed' => [
                'name' => 'تنبيه تغيير كلمة المرور', 'audience' => 'admin', 'sender' => 'no_reply', 'locked' => true, 'direct' => true,
                'subject' => 'تم تغيير كلمة مرور لوحة رَقَم',
                'body' => '<p>تم تغيير كلمة المرور الخاصة بحسابك في لوحة التحكم، وتم تسجيل الخروج من باقي الأجهزة.</p><ul><li>الوقت: {الوقت} (توقيت القدس)</li><li>المتصفح: {المتصفح}</li><li>النظام: {النظام}</li></ul><p><strong>إذا لم تكن أنت من غيّرها فتواصل مع الدعم الفني فوراً.</strong></p>',
                'vars' => $device,
            ],

            // Contact form (20.13).
            'admin_contact_message' => [
                'name' => 'رسالة «اتصل بنا» جديدة (للإدارة)', 'audience' => 'admin', 'sender' => 'no_reply',
                'subject' => 'رسالة جديدة: {الموضوع}',
                'body' => '<p>وصلت رسالة جديدة من نموذج «اتصل بنا».</p><ul><li>الاسم: {الاسم}</li><li>البريد: {البريد}</li><li>الهاتف: {الهاتف}</li><li>الموضوع: {الموضوع}</li></ul><p>{الرسالة}</p><p>للرد اضغط «رد» في بريدك، أو افتح الرسالة في اللوحة: {الرابط}</p>',
                'vars' => ['الاسم' => 'text', 'البريد' => 'text', 'الهاتف' => 'text', 'الموضوع' => 'text', 'الرسالة' => 'text', 'الرابط' => 'url'],
            ],
            'visitor_contact_reply' => [
                'name' => 'رد تلقائي على «اتصل بنا»', 'audience' => 'visitor', 'sender' => 'info',
                'subject' => 'وصلتنا رسالتك',
                'body' => '<p>مرحباً {الاسم}،</p><p>شكراً لتواصلك مع منصة رَقَم. وصلتنا رسالتك وسنرد عليك في أقرب وقت.</p><p>هذه رسالة تلقائية، فلا حاجة للرد عليها.</p>',
                'vars' => ['الاسم' => 'text'],
            ],

            // Alerts for the admins (20.3, 20.4) and the weekly summary (20.12).
            'admin_provider_failure' => [
                'name' => 'تنبيه: مصدر بيانات لا يستجيب', 'audience' => 'admin', 'sender' => 'no_reply',
                'subject' => 'تنبيه: {المصدر} لا يستجيب',
                'body' => '<p>{المصدر} فشل {المرات} مرات متتالية.</p><p>آخر خطأ: {الخطأ}</p><p>الموقع يعرض آخر بيانات صحيحة حتى يعود المصدر.</p>',
                'vars' => ['المصدر' => 'text', 'المرات' => 'text', 'الخطأ' => 'text'],
            ],
            'admin_video_unavailable' => [
                'name' => 'تنبيه: فيديو يوتيوب لم يعد متاحاً', 'audience' => 'admin', 'sender' => 'no_reply',
                'subject' => 'تنبيه: فيديو يوتيوب {الحالة}',
                'body' => '<p>الفيديو «{الفيديو}» {الحالة} على يوتيوب.</p><p>المواد التي تستعمله: {المواد}</p><p>بدّله أو أزله من المواد.</p>',
                'vars' => ['الفيديو' => 'text', 'الحالة' => 'text', 'المواد' => 'text'],
            ],
            'admin_job_failed' => [
                'name' => 'تنبيه: فشل مهمة من مهام النظام', 'audience' => 'admin', 'sender' => 'no_reply',
                'subject' => 'تنبيه: فشلت مهمة «{المهمة}»',
                'body' => '<p>فشلت مهمة «{المهمة}» في {الوقت} (توقيت القدس).</p><p>الخطأ: {الخطأ}</p>',
                'vars' => ['المهمة' => 'text', 'الوقت' => 'text', 'الخطأ' => 'text'],
            ],
            'admin_disk_space' => [
                'name' => 'تنبيه: مساحة السيرفر قاربت على النفاد', 'audience' => 'admin', 'sender' => 'no_reply',
                'subject' => 'تنبيه: مساحة السيرفر قاربت على النفاد',
                'body' => '<p>المساحة الحرة على السيرفر {المساحة} ({النسبة} من المجموع).</p><p>احذف ملفات لا حاجة لها أو وسّع القرص قبل أن تتوقف الكتابة.</p>',
                'vars' => ['المساحة' => 'text', 'النسبة' => 'text'],
            ],
            'admin_ad_ending' => [
                'name' => 'تنبيه: حملة إعلانية قاربت على الانتهاء', 'audience' => 'admin', 'sender' => 'no_reply',
                'subject' => 'حملة «{الحملة}» تنتهي قريباً',
                'body' => '<p>حملة «{الحملة}» للمعلن {المعلن} تنتهي في {النهاية} (توقيت القدس).</p><p>تواصل مع المعلن إن كان يريد التمديد.</p>',
                'vars' => ['الحملة' => 'text', 'المعلن' => 'text', 'النهاية' => 'text'],
            ],
            'admin_weekly_digest' => [
                'name' => 'الملخص الأسبوعي للإدارة', 'audience' => 'admin', 'sender' => 'no_reply',
                'subject' => 'ملخص رَقَم الأسبوعي: {الفترة}',
                'body' => '<p>ملخص الأسبوع {الفترة}:</p>{الملخص}',
                'vars' => ['الفترة' => 'text', 'الملخص' => 'html'], 'required' => ['الملخص'],
            ],

            // Ready and switched off until their systems are (20.5).
            'booking_received' => [
                'name' => 'تأكيد استلام الحجز (غير مفعّل)', 'audience' => 'customer', 'sender' => 'booking', 'active' => false,
                'subject' => 'وصلنا طلب حجزك {المرجع}',
                'body' => '<p>مرحباً {الاسم}،</p><p>وصلنا طلب حجزك لخدمة «{الخدمة}» برقم مرجعي {المرجع}. سنتواصل معك قريباً.</p><p>تابع طلبك من هنا: {الرابط}</p>',
                'vars' => ['الاسم' => 'text', 'الخدمة' => 'text', 'المرجع' => 'text', 'الرابط' => 'url'],
            ],
            'booking_status_changed' => [
                'name' => 'تحديث حالة الحجز (غير مفعّل)', 'audience' => 'customer', 'sender' => 'booking', 'active' => false,
                'subject' => 'تحديث على حجزك {المرجع}',
                'body' => '<p>مرحباً {الاسم}،</p><p>صارت حالة حجزك {المرجع}: {الحالة}.</p><p>{الرابط}</p>',
                'vars' => ['الاسم' => 'text', 'المرجع' => 'text', 'الحالة' => 'text', 'الرابط' => 'url'],
            ],
            'payment_receipt' => [
                'name' => 'إيصال الدفع والفاتورة (غير مفعّل)', 'audience' => 'customer', 'sender' => 'booking', 'active' => false,
                'subject' => 'إيصال دفع الحجز {المرجع}',
                'body' => '<p>مرحباً {الاسم}،</p><p>استلمنا دفعتك للحجز {المرجع}. الفاتورة رقم {الفاتورة} مرفقة.</p>',
                'vars' => ['الاسم' => 'text', 'المرجع' => 'text', 'الفاتورة' => 'text'],
            ],
            'account_verify_code' => [
                'name' => 'رمز تأكيد حساب العميل (غير مفعّل)', 'audience' => 'customer', 'sender' => 'no_reply', 'active' => false,
                'subject' => 'رمز تأكيد حسابك في رَقَم',
                'body' => '<p>رمز تأكيد حسابك:</p><p>{الرمز}</p><p>صالح لمدة {الدقائق} دقيقة.</p>',
                'vars' => ['الرمز' => 'code', 'الدقائق' => 'text'], 'required' => ['الرمز'],
            ],
            'password_reset_code' => [
                'name' => 'رمز استعادة كلمة المرور (غير مفعّل)', 'audience' => 'customer', 'sender' => 'no_reply', 'active' => false,
                'subject' => 'رمز استعادة كلمة المرور',
                'body' => '<p>رمز استعادة كلمة المرور:</p><p>{الرمز}</p><p>صالح لمدة {الدقائق} دقيقة ولمرة واحدة. إن لم تطلبه فتجاهل الرسالة.</p>',
                'vars' => ['الرمز' => 'code', 'الدقائق' => 'text'], 'required' => ['الرمز'],
            ],
        ];
    }

    /** @return array{name: string, audience: string, sender: string, subject: string, body: string, vars: array<string, string>, required?: list<string>, locked?: bool, direct?: bool, active?: bool} */
    public static function get(string $key): array
    {
        return self::templates()[$key] ?? throw new \InvalidArgumentException("Unknown e-mail template [{$key}].");
    }

    /** @return array<string, string> variable => kind */
    public static function variables(string $key): array
    {
        return self::get($key)['vars'];
    }

    /** @return list<string> */
    public static function required(string $key): array
    {
        return self::get($key)['required'] ?? [];
    }

    public static function isLocked(string $key): bool
    {
        return (bool) (self::get($key)['locked'] ?? false);
    }

    public static function isDirect(string $key): bool
    {
        return (bool) (self::get($key)['direct'] ?? false);
    }
}
