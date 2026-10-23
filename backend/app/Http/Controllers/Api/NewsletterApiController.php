<?php

namespace App\Http\Controllers\Api;

use App\Api\SiteToken;
use App\Jobs\CheckBrevoEvent;
use App\Newsletter\NewsletterRefused;
use App\Newsletter\NewsletterSignup;
use App\Newsletter\Subscribers;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The newsletter (decision 19). Subscribe, resend, confirm and unsubscribe come from the site's server only (its token
 * and the visitor's address), JSON only. The answers never tell whether an address is subscribed. The Brevo webhook is
 * Brevo's own call, proved by a secret header, and only ever triggers a check with Brevo's API.
 */
class NewsletterApiController extends ApiController
{
    /** Brevo's events that can end a subscription. */
    private const BREVO_EVENTS = ['unsubscribe', 'unsubscribed', 'hard_bounce', 'hardBounce', 'spam', 'contact_deleted', 'contactDeleted'];

    public function subscribe(Request $request, NewsletterSignup $signup): JsonResponse
    {
        return $this->fromSite($request, fn (array $body, string $ip) => response()->json(['data' => $this->challenge($signup->request($body, $ip))], 202));
    }

    public function resend(Request $request, NewsletterSignup $signup): JsonResponse
    {
        return $this->fromSite($request, fn (array $body, string $ip) => response()->json(['data' => $this->challenge($signup->resend($this->string($body, 'challenge'), $ip))], 202));
    }

    public function confirm(Request $request, NewsletterSignup $signup): JsonResponse
    {
        return $this->fromSite($request, function (array $body, string $ip) use ($signup) {
            $signup->confirm($this->string($body, 'challenge'), $this->string($body, 'code'), $ip);

            return response()->json(['data' => ['confirmed' => true]]);
        });
    }

    public function unsubscribe(Request $request, Subscribers $subscribers): JsonResponse
    {
        return $this->fromSite($request, function (array $body) use ($subscribers) {
            $id = $body['subscriber'] ?? null;
            $valid = (is_int($id) || (is_string($id) && ctype_digit($id))) && strlen((string) $id) <= 18
                && $subscribers->unsubscribe((int) $id, $this->string($body, 'signature'));
            if (! $valid) {
                throw new NewsletterRefused('invalid_link', 'رابط إلغاء الاشتراك غير صحيح.');
            }

            return response()->json(['data' => ['unsubscribed' => true]]);
        });
    }

    public function brevoWebhook(Request $request): Response|JsonResponse
    {
        $token = (string) config('raqam.newsletter.brevo.webhook_token');
        if ($token === '' || ! hash_equals($token, (string) $request->header('X-Raqam-Brevo-Token'))) {
            return $this->notFound();
        }
        $body = $request->json()->all();
        $events = array_is_list($body) ? $body : [$body];
        foreach (array_slice($events, 0, 100) as $event) {
            $email = is_array($event) && is_string($event['email'] ?? null) ? mb_strtolower(trim($event['email'])) : null;
            $type = is_array($event) && is_string($event['event'] ?? null) ? $event['event'] : null;
            if ($email === null || strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($type, self::BREVO_EVENTS, true)) {
                continue;
            }
            // One check a minute per address is plenty, however many times the message comes.
            if (Cache::add('newsletter:brevo-event:'.hash_hmac('sha256', $email, (string) config('app.key')), 1, 60)) {
                CheckBrevoEvent::dispatch($email, $type);
            }
        }

        return response()->noContent();
    }

    /** @return array{challenge: string, resend_after: int, expires_in: int} */
    private function challenge(string $token): array
    {
        return ['challenge' => $token, 'resend_after' => (int) config('raqam.newsletter.resend_seconds'), 'expires_in' => (int) config('raqam.newsletter.code_minutes') * 60];
    }

    /** @param  array<string, mixed>  $body */
    private function string(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) && strlen($value) <= 4096 ? trim($value) : '';
    }

    /** @param  Closure(array<string, mixed>, string): JsonResponse  $handle */
    private function fromSite(Request $request, Closure $handle): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error(415, 'unsupported_media_type', 'أرسل الطلب بصيغة JSON.');
        }
        if (($visitor = SiteToken::visitor($request)) === null) {
            return $this->error(403, 'site_only', 'هذا النموذج يُرسَل من الموقع فقط.');
        }
        try {
            $response = $handle($request->json()->all(), $visitor);
        } catch (NewsletterRefused $e) {
            $response = $this->error($e->status, $e->reason, $e->getMessage());
        }

        return $response->withHeaders(['Cache-Control' => 'no-store']);
    }
}
