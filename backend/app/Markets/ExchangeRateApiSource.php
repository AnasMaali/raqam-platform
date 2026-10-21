<?php

namespace App\Markets;

use App\Integrations\ProviderUnavailable;

/**
 * ExchangeRate-API Pro, registered as the backup source (decision 11, revised 2026-10-12). It is a paid service that
 * has not been subscribed to, so there is no key and no connection: it cannot be chosen and never calls out.
 * Building it for real means a subscription, a key in .env and a fetch() here.
 */
final class ExchangeRateApiSource implements CurrencySource
{
    public function code(): string
    {
        return 'exchangerate_api';
    }

    public function available(): bool
    {
        return false;
    }

    public function supports(): array
    {
        return [];
    }

    public function fetch(): array
    {
        throw new ProviderUnavailable('ExchangeRate-API غير مفعّل: يحتاج اشتراكاً مدفوعاً ومفتاحاً، ولا ربط له بعد.');
    }

    public function note(): string
    {
        return 'الأسعار استرشادية.';
    }
}
