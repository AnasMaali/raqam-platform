<?php

namespace App\Services;

use App\Support\BotAgent;
use App\Support\TrackingHash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Counts the contact buttons of each service (14.9) into service_contact_clicks_daily, by channel and Palestine day.
 * People only (22.3); the same visitor pressing the same button of the same service again within 30 seconds counts once;
 * at most 30 counted presses an hour per visitor. The visitor is an HMAC of the address, never the address.
 */
final class ContactClicks
{
    public const GAP = 30;

    public const PER_HOUR = 30;

    public static function record(int $serviceId, string $channel, ?string $visitorIp, ?string $agent): bool
    {
        if (BotAgent::isBot($agent) || ! in_array($channel, ServiceContact::CHANNELS, true)) {
            return false;
        }
        $visitor = TrackingHash::visitor($visitorIp);
        if (! Cache::add('services:press:'.hash('sha256', "{$visitor}|{$serviceId}|{$channel}"), 1, self::GAP)) {
            return false;
        }
        $key = 'services:presses:'.$visitor;
        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            return false;
        }
        RateLimiter::hit($key, 3600);

        DB::statement(
            'INSERT INTO service_contact_clicks_daily (service_id, channel, stat_date, clicks) VALUES (?, ?, ?, 1)
             ON CONFLICT (service_id, channel, stat_date) DO UPDATE SET clicks = service_contact_clicks_daily.clicks + 1',
            [$serviceId, $channel, now('Asia/Hebron')->toDateString()],
        );

        return true;
    }
}
