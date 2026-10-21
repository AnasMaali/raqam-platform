<?php

namespace App\Markets;

use Carbon\CarbonImmutable;

/** One official rate: shekels for one unit of the currency (already divided by the source's "unit"), and when it was published. */
final readonly class PublishedRate
{
    public function __construct(
        public string $code,
        public string $mid,
        public CarbonImmutable $publishedAt,
    ) {}
}
