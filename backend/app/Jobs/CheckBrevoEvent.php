<?php

namespace App\Jobs;

use App\Newsletter\BrevoClient;
use App\Newsletter\Subscribers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A webhook from Brevo about one address, checked against Brevo's API before anything changes (decision 2026-10-23). */
class CheckBrevoEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public string $email, public string $event) {}

    public function handle(Subscribers $subscribers, BrevoClient $brevo): void
    {
        $subscribers->checkWithBrevo($this->email, $this->event, $brevo);
    }
}
