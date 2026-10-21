<?php

namespace App\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One GET to an outside service, with the limits every such call needs: short timeouts, no redirects (an answer
 * can only come from the host we asked), and a cap on the size of the answer, enforced while it downloads.
 * A failure becomes ProviderUnavailable with a message that never contains the address (it may carry a key).
 */
final class OutboundHttp
{
    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     *
     * @throws ProviderUnavailable
     */
    public static function get(string $service, string $url, array $query = [], array $headers = [], int $maxBytes = 262144, int $timeout = 10): Response
    {
        try {
            $response = Http::withHeaders($headers)->acceptJson()->timeout($timeout)->connectTimeout(5)
                ->withOptions([
                    'allow_redirects' => false,
                    'progress' => function ($total, $downloaded) use ($maxBytes) {
                        if ($total > $maxBytes || $downloaded > $maxBytes) {
                            throw new \OverflowException('answer too large');
                        }
                    },
                ])
                // Only a lost connection is worth retrying; the callback gets no exception for an HTTP error status.
                ->retry(2, 500, fn (?Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->get($url, $query);
        } catch (Throwable $e) {
            $tooLarge = str_contains($e->getMessage(), 'answer too large');
            throw new ProviderUnavailable($tooLarge ? "ردّ {$service} أكبر من المسموح." : "تعذّر الاتصال بـ {$service}.");
        }

        if (strlen($response->body()) > $maxBytes) {
            throw new ProviderUnavailable("ردّ {$service} أكبر من المسموح.");
        }
        if (! $response->successful()) {
            throw new ProviderUnavailable("ردّ {$service} بخطأ ({$response->status()}).");
        }

        return $response;
    }
}
