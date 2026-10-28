<?php

namespace App\Markets;

use App\Integrations\DataProviders;
use App\Jobs\SendSiteWebhook;
use App\Seo\SitePaths;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The currency board as the site shows it (decisions 11.4–11.11), read only from our own tables (the rate_board view):
 * a visitor's page never waits for the bank. Answers are cached and dropped whenever a rate, a spread, an override
 * or the list changes; a cached board also expires at the next moment a manual override starts or ends.
 *
 * Every number leaves as a decimal string rounded to the currency's display decimals; nothing is a float.
 */
final class RateBoard
{
    private const TTL = 300;

    public const HISTORY_DAYS = 30;

    public const HISTORY_MAX_DAYS = 365;

    /** @return list<array<string, mixed>> the active currencies that have a rate, in the admin's order */
    public function rows(): array
    {
        return $this->remember('board', fn () => DB::table('rate_board')->orderBy('sort_order')->orderBy('currency_code')->get()
            ->filter(fn (object $r) => $r->buy_rate !== null && $r->sell_rate !== null)
            ->map(fn (object $r) => $this->row($r))->values()->all());
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return $this->remember('meta', function () {
            $source = CurrencySources::active();
            $code = $source?->code() ?? DataProviders::active('currency');
            $published = DB::table('rate_board')->where('is_pegged', false)->max('effective_at');

            return [
                'published_at' => self::iso($published),
                'rate_date' => $published === null ? null : DB::selectOne("SELECT (?::timestamptz AT TIME ZONE 'Asia/Hebron')::date AS d", [$published])->d,
                'checked_at' => self::iso($code === null ? null : DB::table('provider_health')->where('provider_code', $code)->value('last_success_at')),
                'source' => $code,
                'note' => $source?->note() ?? 'الأسعار استرشادية.',
            ];
        });
    }

    /**
     * One point per publication day (the day's last rate, Palestine dates), oldest first; null when the currency is not on the board.
     *
     * @return list<array{date: string, mid: string}>|null
     */
    public function history(string $code, int $days = self::HISTORY_DAYS): ?array
    {
        // Only a currency on the board gets entries of its own in the cache: codes made up by a visitor leave nothing behind.
        $shown = $this->remember('codes', fn () => DB::table('rate_board_currencies')->where('is_active', true)->pluck('currency_code')->map(fn ($c) => trim($c))->all());
        if (! in_array($code, $shown, true)) {
            return null;
        }

        return $this->remember("history:{$code}:{$days}", function () use ($code, $days) {
            $decimals = DB::table('rate_board_currencies')->where('currency_code', $code)->where('is_active', true)->value('display_decimals');
            if ($decimals === null) {
                return null;
            }

            $points = DB::select(
                "SELECT DISTINCT ON (d) d, mid_rate FROM (
                    SELECT (effective_at AT TIME ZONE 'Asia/Hebron')::date AS d, mid_rate, effective_at
                    FROM effective_exchange_rates WHERE currency_code = ?
                 ) e
                 WHERE d > (now() AT TIME ZONE 'Asia/Hebron')::date - ?::int
                 ORDER BY d, effective_at DESC",
                [$code, $days],
            );

            return array_map(fn (object $p) => ['date' => $p->d, 'mid' => self::decimal($p->mid_rate, (int) $decimals)], $points);
        });
    }

    /** Something the board shows changed: drop every cached answer and tell the site. */
    public static function changed(string $change): void
    {
        // After the commit, or a reader could cache the old board again before the change is visible.
        DB::afterCommit(fn () => Cache::increment('markets:version'));   // atomic: two changes at once both count
        SendSiteWebhook::notify(['type' => 'currencies', 'change' => $change, 'paths' => ['/currencies']]);
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        $decimals = (int) $r->display_decimals;
        $change = $r->change_pct === null ? null : BigDecimal::of($r->change_pct);

        return [
            'code' => trim($r->currency_code),
            'path' => SitePaths::currency($r->currency_code),
            'name' => $r->name_ar,
            'symbol' => $r->symbol,
            'decimals' => $decimals,
            'mid' => self::decimal($r->mid_rate, $decimals),
            'buy' => self::decimal($r->buy_rate, $decimals),
            'sell' => self::decimal($r->sell_rate, $decimals),
            'previous' => self::decimal($r->previous_rate, $decimals),
            'change_pct' => $change === null ? null : (string) $change->toScale(2, RoundingMode::HALF_UP),
            'direction' => $change === null ? null : match ($change->getSign()) {
                1 => 'up', -1 => 'down', default => 'same'
            },
            'is_manual' => (bool) $r->is_manual,
            'is_pegged' => (bool) $r->is_pegged,
            'rate_date' => $r->rate_date,
            'published_at' => self::iso($r->effective_at),
        ];
    }

    public static function decimal(?string $value, int $decimals): ?string
    {
        return $value === null ? null : (string) BigDecimal::of($value)->toScale($decimals, RoundingMode::HALF_UP);
    }

    private static function iso(?string $time): ?string
    {
        return $time === null ? null : Carbon::parse($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function remember(string $key, \Closure $build): mixed
    {
        $version = (int) Cache::get('markets:version', 0);

        return Cache::remember("markets:v{$version}:{$key}", $this->ttl(), $build);
    }

    /** Seconds until the board could change by itself: the next start or end of a manual override, at most TTL. */
    private function ttl(): int
    {
        $next = DB::selectOne('SELECT extract(epoch FROM min(t) - now()) AS s FROM (
            SELECT valid_from AS t FROM rate_overrides WHERE valid_from > now()
            UNION ALL SELECT valid_until FROM rate_overrides WHERE valid_until > now()) x')->s;

        return $next === null ? self::TTL : max(1, min(self::TTL, (int) ceil((float) $next)));
    }
}
