<?php

namespace App\Settings;

/**
 * The settings the admin edits (decision 21.13): key => Arabic label, type and a hint. The type is one
 * of those the settings table allows; the database CHECKs stay the last word, this list gives the form
 * and the server-side validation the same rules in friendly Arabic.
 * Not here on purpose: the time zone, retention periods and anything secret.
 */
final class SettingsCatalog
{
    /** @return array<string, array{group: string, label: string, type: string, hint?: string}> */
    public static function fields(): array
    {
        return [
            'general.site_name' => ['group' => 'عام', 'label' => 'اسم المنصة', 'type' => 'string'],
            'general.logo' => ['group' => 'عام', 'label' => 'الشعار', 'type' => 'media', 'hint' => 'اختر صورة عامة من مكتبة الوسائط'],
            'contact.phone' => ['group' => 'التواصل', 'label' => 'الهاتف', 'type' => 'phone', 'hint' => 'بالصيغة الدولية، مثال: +970599123456'],
            'contact.whatsapp' => ['group' => 'التواصل', 'label' => 'رقم الواتساب', 'type' => 'phone', 'hint' => 'بالصيغة الدولية، مثال: +970599123456'],
            'contact.whatsapp_message' => ['group' => 'التواصل', 'label' => 'رسالة الواتساب الجاهزة', 'type' => 'string', 'hint' => 'يُضاف إليها اسم الخدمة تلقائياً'],
            'contact.email' => ['group' => 'التواصل', 'label' => 'البريد الإلكتروني للتواصل', 'type' => 'email'],
            'contact.email_subject' => ['group' => 'التواصل', 'label' => 'عنوان رسالة البريد الجاهز', 'type' => 'string', 'hint' => 'يُضاف إليه اسم الخدمة تلقائياً، مثل: استفسار عن خدمة: حلقة بودكاست'],
            'contact.address' => ['group' => 'التواصل', 'label' => 'العنوان', 'type' => 'string'],
            'mail.no_reply' => ['group' => 'البريد', 'label' => 'عنوان رسائل النظام (no-reply@)', 'type' => 'email', 'hint' => 'رموز الدخول والتنبيهات. فارغ = عنوان MAIL_FROM_ADDRESS'],
            'mail.info' => ['group' => 'البريد', 'label' => 'عنوان التواصل (info@)', 'type' => 'email', 'hint' => 'الرد التلقائي على «اتصل بنا»'],
            'mail.ads' => ['group' => 'البريد', 'label' => 'عنوان الإعلانات (ads@)', 'type' => 'email'],
            'mail.booking' => ['group' => 'البريد', 'label' => 'عنوان الحجوزات (booking@)', 'type' => 'email', 'hint' => 'جاهز لنظام الحجز (مطفأ)'],
            'seo.title_suffix' => ['group' => 'محركات البحث', 'label' => 'لاحقة عنوان الصفحة', 'type' => 'string', 'hint' => 'تُضاف بعد عنوان كل صفحة'],
            'seo.default_image' => ['group' => 'محركات البحث', 'label' => 'صورة المشاركة الافتراضية', 'type' => 'media', 'hint' => 'تظهر عند مشاركة صفحة ليس لها صورة'],
            'seo.default_description' => ['group' => 'محركات البحث', 'label' => 'الوصف الافتراضي', 'type' => 'text', 'hint' => 'يُستعمل عندما لا يكون للصفحة وصف خاص'],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::fields());
    }

    /** @return list<string> Laravel validation rules for a value of this type (empty means "no value"). */
    public static function rules(string $type): array
    {
        return match ($type) {
            'string' => ['nullable', 'string', 'max:255'],
            'text' => ['nullable', 'string', 'max:2000'],
            'integer' => ['nullable', 'integer'],
            'boolean' => ['nullable', 'in:true,false'],
            'url' => ['nullable', 'url:http,https', 'max:2048'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'regex:/^\+[1-9][0-9]{6,14}$/'],
            'time' => ['nullable', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'media' => ['nullable', 'regex:/^[0-9]+$/'],
        };
    }

    /** @return array<string, string> */
    public static function messages(string $attribute): array
    {
        return [
            "{$attribute}.max" => 'النص أطول من المسموح.',
            "{$attribute}.url" => 'الرابط غير صحيح، ويجب أن يبدأ بـ https://',
            "{$attribute}.email" => 'البريد الإلكتروني غير صحيح.',
            "{$attribute}.regex" => 'الصيغة غير صحيحة.',
            "{$attribute}.integer" => 'يجب أن يكون رقماً صحيحاً.',
            "{$attribute}.in" => 'القيمة غير صحيحة.',
        ];
    }
}
