<?php

namespace App\Markets;

use App\Audit\AuditLogger;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Manual buy/sell rates for one currency, from now until a chosen time or until removed (decision 11.5).
 *
 * The database refuses two overrides that overlap in time (EXCLUDE), so a new one first ends the current one at
 * the same instant it starts. When `valid_until` passes, the board goes back to the official rate and spread by
 * itself. Every change is in the audit log.
 */
final class RateOverrides
{
    /** A manual rate must lie between half and double the official one: a guard against a slipped digit. */
    public const MAX_RATIO = 2;

    /** @throws RateRejected */
    public function set(string $code, string $buy, string $sell, ?CarbonInterface $until): int
    {
        [$buyRate, $sellRate] = [self::number($buy, 'سعر الشراء'), self::number($sell, 'سعر البيع')];
        if ($sellRate->isLessThan($buyRate)) {
            throw new RateRejected('سعر البيع يجب ألا يقل عن سعر الشراء.');
        }

        $id = DB::transaction(function () use ($code, $buyRate, $sellRate, $until) {
            $row = DB::table('rate_board_currencies')->where('currency_code', $code)->lockForUpdate()->first();
            if ($row === null) {
                throw new RateRejected('هذه العملة ليست على لوحة الأسعار.');
            }

            $mid = DB::table('effective_exchange_rates')->where('currency_code', $code)->orderByDesc('effective_at')->value('mid_rate');
            if ($mid !== null) {
                $official = BigDecimal::of($mid);
                foreach (['الشراء' => $buyRate, 'البيع' => $sellRate] as $label => $rate) {
                    if ($rate->isGreaterThan($official->multipliedBy(self::MAX_RATIO)) || $rate->multipliedBy(self::MAX_RATIO)->isLessThan($official)) {
                        throw new RateRejected("سعر {$label} بعيد جداً عن السعر الرسمي ({$official->stripTrailingZeros()}): تأكد من الرقم.");
                    }
                }
            }

            if ($until !== null && ! DB::selectOne('SELECT ?::timestamptz > now() + interval \'1 minute\' AS ok', [$until->format('Y-m-d H:i:sP')])->ok) {
                throw new RateRejected('وقت الانتهاء يجب أن يكون في المستقبل.');
            }

            $startsAt = $this->endCurrent($code)['at'];

            $id = DB::selectOne(
                'INSERT INTO rate_overrides (currency_code, buy_rate, sell_rate, valid_from, valid_until, created_by)
                 VALUES (?, ?, ?, ?::timestamptz, ?, ?) RETURNING id',
                [$code, (string) $buyRate, (string) $sellRate, $startsAt, $until?->format('Y-m-d H:i:sP'), auth()->id()],
            )->id;

            AuditLogger::record('create', 'rate_overrides', $id, [
                'currency_code' => [null, $code], 'buy_rate' => [null, (string) $buyRate], 'sell_rate' => [null, (string) $sellRate],
                'valid_until' => [null, $until?->utc()->format('Y-m-d H:i:s\Z')],
            ]);

            return $id;
        });

        RateBoard::changed('manual_rate');

        return $id;
    }

    /** Ends the manual rate now; the board returns to the official rate. False when there was none. */
    public function end(string $code): bool
    {
        $ended = DB::transaction(function () use ($code) {
            DB::table('rate_board_currencies')->where('currency_code', $code)->lockForUpdate()->first();

            return $this->endCurrent($code)['ended'];
        });

        if ($ended) {
            RateBoard::changed('manual_rate');
        }

        return $ended;
    }

    /**
     * Closes every override not yet over (the one in force, and one whose start looks ahead because the clock stepped
     * back) and returns the instant the last one closed: a new override starts there, so the two never overlap.
     * valid_until must stay after valid_from (a CHECK): the database clock, never before valid_from.
     *
     * @return array{at: string, ended: bool}
     */
    private function endCurrent(string $code): array
    {
        $open = DB::select(
            'SELECT id, valid_until::text AS valid_until FROM rate_overrides
             WHERE currency_code = ? AND (valid_until IS NULL OR valid_until > now()) ORDER BY id FOR UPDATE',
            [$code],
        );
        $at = DB::selectOne('SELECT now()::text AS t')->t;

        foreach ($open as $override) {
            $closed = DB::selectOne(
                "UPDATE rate_overrides SET valid_until = greatest(now(), valid_from + interval '1 microsecond') WHERE id = ?
                 RETURNING valid_until::text AS t, valid_until > ?::timestamptz AS later",
                [$override->id, $at],
            );
            $at = $closed->later ? $closed->t : $at;
            AuditLogger::record('update', 'rate_overrides', $override->id, ['valid_until' => [$override->valid_until, $closed->t]]);
        }

        return ['at' => $at, 'ended' => $open !== []];
    }

    private static function number(string $text, string $label): BigDecimal
    {
        $text = trim($text);
        if (preg_match('/^\d{1,10}(\.\d{1,6})?$/', $text) !== 1) {
            throw new RateRejected("{$label}: أدخل رقماً موجباً بستة منازل عشرية على الأكثر.");
        }
        try {
            $value = BigDecimal::of($text);
        } catch (MathException) {
            throw new RateRejected("{$label}: رقم غير صالح.");
        }
        if ($value->isLessThanOrEqualTo(0)) {
            throw new RateRejected("{$label} يجب أن يكون أكبر من صفر.");
        }

        return $value;
    }
}
