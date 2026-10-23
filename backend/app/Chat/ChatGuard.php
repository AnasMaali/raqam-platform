<?php

namespace App\Chat;

use App\Contact\ContactInbox;

/**
 * What a visitor may send the chatbot (18.12) and what of it is kept (18.9). At most 500 characters; no links and no
 * code (refused, and nothing is kept). E-mail addresses and phone numbers are taken out before anything is stored or
 * matched: the conversation log holds no personal data.
 */
final class ChatGuard
{
    public const MAX = 500;

    /** @return string|null why the message is refused, or null when it is fine */
    public static function refusal(string $message): ?string
    {
        if (mb_strlen($message) > self::MAX) {
            return 'الرسالة أطول من '.self::MAX.' حرف.';
        }
        if (self::looksLikeCode($message)) {
            return 'لا يمكن إرسال أكواد في المحادثة.';
        }
        if (ContactInbox::looksLikeLink(self::redact($message, withEmails: true))) {
            return 'لا يمكن إرسال روابط في المحادثة.';
        }

        return null;
    }

    public static function looksLikeCode(string $text): bool
    {
        return preg_match('~(<\s*[a-z!/?]|<\?|\?>|\{\{|\}\}|`|javascript\s*:|data\s*:\s*text|\bon[a-z]+\s*=|\$\(|\b(select|insert|update|delete|drop|union)\b.+\b(from|into|table|set|select)\b)~iu', $text) === 1;
    }

    /** E-mail addresses and phone numbers (or any long run of digits) become a mark. */
    public static function redact(string $text, bool $withEmails = true): string
    {
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        if ($withEmails) {
            $text = (string) preg_replace('/[^\s@]+@[^\s@]+/u', '[بريد]', $text);
        }
        $text = (string) preg_replace_callback('/[+(]?\d[\d\s\-.()\/]{4,}\d/u', fn ($m) => preg_match_all('/\d/', $m[0]) >= 7 ? '[رقم]' : $m[0], $text);

        return (string) preg_replace('/\d{6,}/', '[رقم]', $text);
    }

    /** Spaces and control characters tidied; the visitor's words otherwise kept. */
    public static function tidy(string $text): string
    {
        return trim((string) preg_replace(['/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '/[ \t]+/u', "/\n{3,}/u"], ['', ' ', "\n\n"], $text));
    }
}
