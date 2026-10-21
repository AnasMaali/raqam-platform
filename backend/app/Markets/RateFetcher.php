<?php

namespace App\Markets;

use App\Integrations\ProviderHealth;
use App\Integrations\ProviderUnavailable;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Asks the active source for today's rates and keeps them (decisions 11.2, 11.3, 11.13).
 *
 * A rate is stored only when its publication time is newer than the last one kept for that currency, so the
 * half-hourly checks of a business day add one row per currency and day. Every row is kept forever.
 * A pegged currency (AED, SAR) is never fetched: the view derives it from its anchor.
 *
 * Nothing is deleted on failure, so the last good rate stays on the board. Any problem (no answer, a broken
 * answer, a missing currency or an unbelievable value) counts as a failure in provider_health; the third in a
 * row alerts the admins. Good entries of a partly bad answer are still stored.
 */
final class RateFetcher
{
    /** A new rate must lie between half and double the last one; outside that it is a broken answer, not a market. */
    public const MAX_RATIO = 2;

    /** A publication time may run ahead of our clock by this much (clocks differ), not more. */
    private const FUTURE_MINUTES = 15;

    /** @return array{stored: list<string>, unchanged: list<string>, problems: array<string, string>} */
    public function run(): array
    {
        $report = ['stored' => [], 'unchanged' => [], 'problems' => []];
        $source = CurrencySources::active();
        if ($source === null || ! $source->available()) {
            $report['problems']['*'] = 'لا يوجد مصدر عملات مفعّل.';

            return $report;
        }

        try {
            $rates = $source->fetch();
        } catch (ProviderUnavailable $e) {
            ProviderHealth::failure($source->code(), $e->getMessage());
            $report['problems']['*'] = $e->getMessage();

            return $report;
        }

        foreach ($this->wanted() as $code) {
            $rate = $rates[$code] ?? 'غير موجود في الرد.';
            if (is_string($rate)) {
                $report['problems'][$code] = $rate;

                continue;
            }

            $outcome = $this->keep($source->code(), $rate);
            if ($outcome === true) {
                $report['stored'][] = $code;
            } elseif ($outcome === false) {
                $report['unchanged'][] = $code;
            } else {
                $report['problems'][$code] = $outcome;
            }
        }

        if ($report['problems'] === []) {
            ProviderHealth::success($source->code());
        } else {
            ProviderHealth::failure($source->code(), collect($report['problems'])->map(fn ($why, $code) => "{$code}: {$why}")->implode(' '));
        }

        if ($report['stored'] !== []) {
            RateBoard::changed('rates_published');
        }

        return $report;
    }

    /** @return list<string> every currency on the board that is fetched, plus the anchors of the pegged ones */
    public function wanted(): array
    {
        $board = DB::table('rate_board_currencies')->pluck('currency_code')->map(fn ($c) => trim($c));
        $pegs = DB::table('currency_pegs')->get(['currency_code', 'anchor_code']);
        $pegged = $pegs->pluck('currency_code')->map(fn ($c) => trim($c))->all();
        $anchors = $pegs->filter(fn ($p) => $board->contains(trim($p->currency_code)))->pluck('anchor_code')->map(fn ($c) => trim($c));

        return $board->reject(fn ($c) => in_array($c, $pegged, true))->merge($anchors)->unique()->sort()->values()->all();
    }

    /** true = stored, false = nothing new, string = why it was refused */
    private function keep(string $provider, PublishedRate $rate): bool|string
    {
        return DB::transaction(function () use ($provider, $rate) {
            // One fetch at a time per currency, so two overlapping runs cannot both compare against the same "last".
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['rates:'.$rate->code]);

            $now = DB::selectOne('SELECT now() AS t')->t;
            if ($rate->publishedAt->greaterThan(CarbonImmutable::parse($now)->addMinutes(self::FUTURE_MINUTES))) {
                return 'تاريخ النشر في المستقبل.';
            }

            $last = DB::table('exchange_rates')->where('currency_code', $rate->code)->orderByDesc('effective_at')->first(['mid_rate', 'effective_at']);
            if ($last !== null && ! $rate->publishedAt->greaterThan(CarbonImmutable::parse($last->effective_at))) {
                return false;
            }

            if ($last !== null) {
                $new = BigDecimal::of($rate->mid);
                $old = BigDecimal::of($last->mid_rate);
                if ($new->isGreaterThan($old->multipliedBy(self::MAX_RATIO)) || $new->multipliedBy(self::MAX_RATIO)->isLessThan($old)) {
                    return "قيمة غير معقولة ({$rate->mid} مقابل {$old->stripTrailingZeros()} سابقاً).";
                }
            }

            // fetched_at is checked against effective_at (no more than a day earlier): the database clock, never behind it.
            $inserted = DB::affectingStatement(
                "INSERT INTO exchange_rates (currency_code, provider_code, mid_rate, effective_at, fetched_at)
                 VALUES (?, ?, ?, ?, greatest(now(), ?::timestamptz - interval '1 day'))
                 ON CONFLICT (currency_code, effective_at) DO NOTHING",
                [$rate->code, $provider, $rate->mid, $rate->publishedAt->format('Y-m-d H:i:s.uP'), $rate->publishedAt->format('Y-m-d H:i:s.uP')],
            );

            return $inserted > 0;
        });
    }
}
