<?php

namespace App\Jobs;

use App\Seo\IndexNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One IndexNow submission (decision 23.11), queued after the save is committed so a slow or failing engine never holds
 * an editor up. An address sent in the last `gap_seconds` is not sent again. A refusal that will not change by trying
 * again (a wrong key, addresses of another host) fails at once, so the admins see it in the bell; a busy engine or a
 * broken connection is tried again later.
 */
class SubmitIndexNow implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    /** @param  list<string>  $paths */
    public function __construct(public array $paths) {}

    public function handle(): void
    {
        if (! IndexNow::configured()) {
            return;
        }
        $gap = (int) config('raqam.indexnow.gap_seconds');
        $fresh = array_values(array_filter($this->paths, fn (string $p) => Cache::add('indexnow:'.hash('sha256', $p), 1, $gap)));
        if ($fresh === []) {
            return;
        }

        $site = rtrim((string) config('raqam.site_url'), '/');
        $key = (string) config('raqam.indexnow.key');
        $endpoint = (string) (config('raqam.indexnow.endpoint_override') ?: config('raqam.indexnow.endpoint'));
        $forget = fn () => array_map(fn (string $p) => Cache::forget('indexnow:'.hash('sha256', $p)), $fresh);

        try {
            $response = Http::timeout(10)->acceptJson()->withoutRedirecting()->post($endpoint, [
                'host' => parse_url($site, PHP_URL_HOST), 'key' => $key, 'keyLocation' => $site.'/'.$key.'.txt',
                'urlList' => array_map(fn (string $p) => $site.implode('/', array_map('rawurlencode', explode('/', $p))), $fresh),
            ]);
        } catch (ConnectionException) {
            $forget();
            throw new RuntimeException('IndexNow could not be reached.');
        }

        if (in_array($response->status(), [200, 202], true)) {
            return;
        }
        $forget();
        $error = new RuntimeException('IndexNow refused the submission (HTTP '.$response->status().').');
        if ($response->status() === 429 || $response->serverError()) {
            throw $error;
        }
        $this->fail($error);
    }
}
