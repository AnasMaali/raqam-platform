<?php

namespace App\Payments;

/** A notification whose signature checked out. Its content is archived, never believed: the order is asked about again. */
final readonly class GatewayNotification
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(public string $eventId, public string $type, public ?string $orderId, public array $payload) {}
}
