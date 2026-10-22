<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The contact buttons of a service (14.8), from the settings: WhatsApp with a ready message naming the service, a call,
 * and an e-mail with a ready subject. A button whose setting is empty is null (the site hides it).
 */
final class ServiceContact
{
    public const CHANNELS = ['whatsapp', 'phone', 'email'];

    /** @return array{whatsapp: ?string, phone: ?string, email: ?string} */
    public static function links(string $topic): array
    {
        $s = DB::table('settings')->whereIn('key', ['contact.whatsapp', 'contact.whatsapp_message', 'contact.phone', 'contact.email', 'contact.email_subject'])->pluck('value', 'key');
        $whatsapp = $s['contact.whatsapp'] ?? null;
        $phone = $s['contact.phone'] ?? null;
        $email = $s['contact.email'] ?? null;
        $message = trim(($s['contact.whatsapp_message'] ?? null) ?: 'مرحباً، أود الاستفسار عن').' «'.$topic.'»';
        $subject = trim(($s['contact.email_subject'] ?? null) ?: 'استفسار عن خدمة').': '.$topic;

        return [
            'whatsapp' => filled($whatsapp) ? 'https://wa.me/'.ltrim($whatsapp, '+').'?text='.rawurlencode($message) : null,
            'phone' => filled($phone) ? 'tel:'.$phone : null,
            // The address is encoded too: one set in the settings with ? or & could otherwise add recipients to the link.
            'email' => filled($email) ? 'mailto:'.str_replace('%40', '@', rawurlencode($email)).'?subject='.rawurlencode($subject) : null,
        ];
    }
}
