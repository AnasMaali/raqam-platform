<?php

namespace App\Newsletter;

/**
 * The subscriber's own unsubscribe link (19.5): our page on the website, signed with the application key for this
 * subscriber and address, so it cannot be guessed for anybody else. Nothing is stored for it.
 */
final class NewsletterLinks
{
    public static function signature(int $subscriberId, string $email): string
    {
        return hash_hmac('sha256', 'newsletter-unsubscribe|'.$subscriberId.'|'.mb_strtolower($email), (string) config('app.key'));
    }

    public static function valid(int $subscriberId, string $email, string $signature): bool
    {
        return hash_equals(self::signature($subscriberId, $email), $signature);
    }

    public static function unsubscribeUrl(int $subscriberId, string $email): string
    {
        return rtrim((string) config('raqam.site_url'), '/').'/newsletter/unsubscribe?'.http_build_query(['s' => $subscriberId, 't' => self::signature($subscriberId, $email)]);
    }
}
