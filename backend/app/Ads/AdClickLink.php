<?php

namespace App\Ads;

/**
 * The tracking address of an ad (decision 13.7): /api/v1/ads/click/{campaign}/{slot}/{signature}. It names a campaign
 * and a slot, never a destination: the redirect goes to the address stored with the campaign, so the link cannot be
 * turned into a way to send people anywhere else (no open redirect). The signature (an HMAC under the application key)
 * stops anyone from making links for other campaigns or slots, e.g. to read the address of a campaign not yet shown.
 */
final class AdClickLink
{
    public static function signature(int $campaignId, string $placement): string
    {
        return substr(hash_hmac('sha256', "ad-click|{$campaignId}|{$placement}", (string) config('app.key')), 0, 32);
    }

    public static function url(int $campaignId, string $placement): string
    {
        return route('ads.click', ['campaign' => $campaignId, 'placement' => $placement, 'signature' => self::signature($campaignId, $placement)]);
    }

    public static function valid(int $campaignId, string $placement, string $signature): bool
    {
        return hash_equals(self::signature($campaignId, $placement), $signature);
    }
}
