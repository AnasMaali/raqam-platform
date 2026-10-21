<?php

namespace App\Markets;

use App\Integrations\ProviderUnavailable;

/** Where official rates come from (decision 11); one per data_providers row of the "currency" service. */
interface CurrencySource
{
    /** The data_providers code. */
    public function code(): string;

    /** Whether it can be used at all: built and configured. A source that is not never opens a connection. */
    public function available(): bool;

    /** @return list<string> the ISO codes it publishes */
    public function supports(): array;

    /**
     * @return array<string, PublishedRate|string> by code: the rate, or why the entry it sent is unusable
     *
     * @throws ProviderUnavailable
     */
    public function fetch(): array;

    /** The sentence shown under every rate. */
    public function note(): string;
}
