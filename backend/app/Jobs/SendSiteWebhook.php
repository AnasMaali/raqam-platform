<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Tells the website that content changed so it refreshes within seconds (decision 21.16). The body is signed
 * with HMAC-SHA256 over "{timestamp}.{body}" so the site can check who sent it and refuse old copies.
 */
class SendSiteWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /** @param  array<string, mixed>  $payload */
    public function __construct(public array $payload) {}

    /** Whether a webhook is configured at all. If not, nothing is queued and nothing happens. */
    public static function configured(): bool
    {
        $url = (string) config('raqam.site_webhook.url');

        return $url !== '' && (string) config('raqam.site_webhook.secret') !== ''
            && (str_starts_with($url, 'https://') || (! app()->isProduction() && str_starts_with($url, 'http://')));
    }

    /** @param  array<string, mixed>  $payload */
    public static function notify(array $payload): void
    {
        if (self::configured()) {
            self::dispatch($payload + ['at' => now()->utc()->format('Y-m-d\TH:i:s\Z')])->afterCommit();
        }
    }

    public function handle(): void
    {
        $body = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $time = (string) time();
        $signature = hash_hmac('sha256', $time.'.'.$body, (string) config('raqam.site_webhook.secret'));

        Http::timeout(5)->withBody($body, 'application/json')
            ->withHeaders(['X-Raqam-Timestamp' => $time, 'X-Raqam-Signature' => 'sha256='.$signature])
            ->post((string) config('raqam.site_webhook.url'))->throw();
    }
}
