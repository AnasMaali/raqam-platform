<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;

/**
 * A period of the statistics screens (22.11): today, the last 7 or 30 days, or one picked by hand, in Palestine days.
 * A period picked backwards is turned round, and one longer than a year is cut to its last year; both say so.
 */
final class StatsPeriod
{
    public const MAX_DAYS = 366;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $problem = null,
    ) {}

    public static function of(string $period, mixed $from = null, mixed $to = null, ?CarbonImmutable $now = null): self
    {
        $today = ($now ?? CarbonImmutable::now('Asia/Hebron'))->setTimezone('Asia/Hebron')->startOfDay();
        $problem = null;

        if ($period === 'custom') {
            $start = self::day($from) ?? $today->subDays(6);
            $end = self::day($to) ?? $today;
            if ($start->greaterThan($end)) {
                [$start, $end, $problem] = [$end, $start, 'بداية الفترة بعد نهايتها، فبُدّلتا.'];
            }
            if ($start->diffInDays($end) >= self::MAX_DAYS) {
                [$start, $problem] = [$end->subDays(self::MAX_DAYS - 1), 'الفترة أطول من سنة، فعُرضت آخر سنة منها.'];
            }
        } else {
            $days = in_array($period, ['1', '7', '30'], true) ? (int) $period : 7;
            [$start, $end] = [$today->subDays($days - 1), $today];
        }

        return new self($start, $end, $problem);
    }

    /** @return array{0: string, 1: string} */
    public function range(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString()];
    }

    /** @return list<string> every date of the period, oldest first */
    public function dates(): array
    {
        $dates = [];
        for ($d = $this->from; $d->lessThanOrEqualTo($this->to); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }

    public function includes(string $date): bool
    {
        return $date >= $this->from->toDateString() && $date <= $this->to->toDateString();
    }

    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', substr($value, 0, 10), 'Asia/Hebron')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
