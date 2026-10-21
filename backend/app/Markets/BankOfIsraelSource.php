<?php

namespace App\Markets;

use App\Integrations\DecimalJson;
use App\Integrations\OutboundHttp;
use App\Integrations\ProviderUnavailable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use JsonException;

/**
 * The Bank of Israel's official representative rates (decision 11, revised 2026-10-12): free, no key, one public
 * address that answers with every currency at once. Each entry is shekels for `unit` units of the currency, with
 * the time it was published (lastUpdate, UTC).
 *
 * Nothing in the answer is trusted: numbers are read as exact text (never a float), codes, units and times are
 * checked one by one, and an entry that fails a check is reported instead of stored.
 */
final class BankOfIsraelSource implements CurrencySource
{
    /** What the bank publishes (checked 2026-10-21). */
    public const CODES = ['USD', 'GBP', 'JPY', 'EUR', 'AUD', 'CAD', 'DKK', 'NOK', 'ZAR', 'SEK', 'CHF', 'JOD', 'LBP', 'EGP'];

    private const MAX_ENTRIES = 100;

    public function code(): string
    {
        return 'bank_of_israel';
    }

    public function available(): bool
    {
        return true;
    }

    public function supports(): array
    {
        return self::CODES;
    }

    public function note(): string
    {
        return 'الأسعار استرشادية — حسب السعر التمثيلي لبنك إسرائيل';
    }

    public function fetch(): array
    {
        $base = rtrim((string) (config('raqam.currency.boi_base') ?: 'https://boi.org.il'), '/');
        $response = OutboundHttp::get('بنك إسرائيل', $base.'/PublicApi/GetExchangeRates', maxBytes: 65536);

        return self::read($response->body());
    }

    /**
     * @return array<string, PublishedRate|string>
     *
     * @throws ProviderUnavailable
     */
    public static function read(string $body): array
    {
        try {
            $data = DecimalJson::decode($body);
        } catch (JsonException) {
            throw new ProviderUnavailable('ردّ بنك إسرائيل ليس JSON صالحاً.');
        }

        $entries = $data['exchangeRates'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries) || $entries === [] || count($entries) > self::MAX_ENTRIES) {
            throw new ProviderUnavailable('ردّ بنك إسرائيل لا يحوي قائمة أسعار صالحة.');
        }

        $rates = [];
        $seen = [];
        foreach ($entries as $entry) {
            $code = is_array($entry) && is_string($entry['key'] ?? null) && preg_match('/^[A-Z]{3}$/', $entry['key']) === 1 ? $entry['key'] : null;
            if ($code === null) {
                continue;   // nothing to report it under; the codes we need are checked for presence by the caller
            }
            if (isset($seen[$code])) {
                $rates[$code] = 'ورد مرتين في الرد.';

                continue;
            }
            $seen[$code] = true;
            $rates[$code] = self::entry($code, $entry);
        }

        return $rates;
    }

    private static function entry(string $code, array $entry): PublishedRate|string
    {
        $rate = $entry['currentExchangeRate'] ?? null;
        $unit = $entry['unit'] ?? null;
        $time = $entry['lastUpdate'] ?? null;

        if (! is_string($rate) || preg_match('/^\d{1,10}(\.\d{1,12})?$/', $rate) !== 1) {
            return 'سعر غير صالح.';
        }
        if (! is_string($unit) || preg_match('/^[1-9]\d{0,6}$/', $unit) !== 1) {
            return 'وحدة غير صالحة.';
        }
        $publishedAt = is_string($time) ? self::time($time) : null;
        if ($publishedAt === null) {
            return 'تاريخ نشر غير صالح.';
        }

        $mid = BigDecimal::of($rate)->dividedBy($unit, 10, RoundingMode::HALF_UP);
        if ($mid->isLessThanOrEqualTo(0)) {
            return 'سعر صفر أو أقل.';
        }

        return new PublishedRate($code, (string) $mid->stripTrailingZeros(), $publishedAt);
    }

    /** "2026-10-20T12:42:27.6546389Z" → a UTC time to the microsecond (the database keeps six digits). */
    public static function time(string $text): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})$/', $text, $m) !== 1) {
            return null;
        }
        $micro = str_pad(substr($m[2] ?? '', 0, 6), 6, '0');
        $zone = $m[3] === 'Z' ? '+00:00' : $m[3];

        try {
            $time = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.uP', "{$m[1]}.{$micro}{$zone}");
        } catch (\Throwable) {
            return null;
        }
        // createFromFormat rolls 2026-13-40 over into a real date; a real date reads back the same.
        if ($time === null || $time->format('Y-m-d\TH:i:s') !== $m[1]) {
            return null;
        }

        return $time->utc();
    }
}
