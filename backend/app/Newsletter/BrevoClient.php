<?php

namespace App\Newsletter;

use App\Integrations\ProviderHealth;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Brevo's API v3 (decisions 19.4–19.6, 19.10), with the free plan's features only: contacts in one list, a campaign
 * left as a draft (never scheduled or sent from here), and the campaign's numbers. The key goes in the api-key header
 * and nowhere else; any failure becomes BrevoUnavailable with a sentence that never holds it, and is recorded in
 * provider_health (the third failure in a row alerts the admins).
 */
class BrevoClient
{
    public const BASE = 'https://api.brevo.com/v3';

    /** The contact attribute that holds the subscriber's own unsubscribe link (our page). */
    public const UNSUBSCRIBE_ATTRIBUTE = 'RAQAM_UNSUB';

    public static function configured(): bool
    {
        return filled(config('raqam.newsletter.brevo.key')) && ctype_digit((string) config('raqam.newsletter.brevo.list_id'));
    }

    public function listId(): int
    {
        return (int) config('raqam.newsletter.brevo.list_id');
    }

    /** Adds or updates a contact in our list, and lifts a block from an earlier unsubscribe (they confirmed again). */
    public function upsertContact(string $email, ?string $name, string $unsubscribeUrl): void
    {
        $attributes = [self::UNSUBSCRIBE_ATTRIBUTE => $unsubscribeUrl];
        if (filled($name)) {
            $attributes['FIRSTNAME'] = $name;
        }
        $this->call('post', '/contacts', [
            'email' => $email, 'attributes' => $attributes, 'listIds' => [$this->listId()], 'updateEnabled' => true, 'emailBlacklisted' => false,
        ]);
    }

    /** Takes a contact out of our list and blocks marketing to it (an unsubscribe). A contact Brevo does not have is fine. */
    public function unsubscribe(string $email): void
    {
        $this->call('put', '/contacts/'.rawurlencode($email).'?identifierType=email_id', ['unlinkListIds' => [$this->listId()], 'emailBlacklisted' => true], [404]);
    }

    /** Forgets the contact (a subscriber deleted from the panel). */
    public function deleteContact(string $email): void
    {
        $this->call('delete', '/contacts/'.rawurlencode($email).'?identifierType=email_id', [], [404]);
    }

    /** @return array<string, mixed>|null the contact as Brevo has it, or null when it has none */
    public function contact(string $email): ?array
    {
        $response = $this->call('get', '/contacts/'.rawurlencode($email).'?identifierType=email_id', [], [404]);

        return $response->status() === 404 ? null : (array) $response->json();
    }

    /** Creates a draft campaign for our list (19.6: the admin reviews and sends it in Brevo). Returns its id. */
    public function createDraftCampaign(string $name, string $subject, string $html, string $senderEmail, string $senderName): string
    {
        $response = $this->call('post', '/emailCampaigns', [
            'name' => $name, 'subject' => $subject, 'htmlContent' => $html,
            'sender' => ['name' => $senderName, 'email' => $senderEmail],
            'recipients' => ['listIds' => [$this->listId()]],
            'inlineImageActivation' => false,
        ]);
        $id = $response->json('id');
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw $this->fail('ردّ Brevo بلا رقم للحملة.');
        }

        return (string) $id;
    }

    public function updateDraftCampaign(string $id, string $subject, string $html): void
    {
        $this->call('put', '/emailCampaigns/'.rawurlencode($id), ['subject' => $subject, 'htmlContent' => $html]);
    }

    /** @return array{status: string, sent_at: ?string, delivered: int, opens: int, clicks: int, unsubscribes: int} */
    public function campaign(string $id): array
    {
        $c = (array) $this->call('get', '/emailCampaigns/'.rawurlencode($id).'?statistics=globalStats')->json();
        $stats = (array) ($c['statistics']['globalStats'] ?? []);
        $int = fn (string ...$keys) => (int) collect($keys)->map(fn ($k) => $stats[$k] ?? null)->first(fn ($v) => is_numeric($v), 0);

        return [
            'status' => (string) ($c['status'] ?? 'draft'), 'sent_at' => is_string($c['sentDate'] ?? null) ? $c['sentDate'] : null,
            'delivered' => $int('delivered'), 'opens' => $int('uniqueViews', 'viewed'), 'clicks' => $int('uniqueClicks', 'clickers'),
            'unsubscribes' => $int('unsubscriptions'),
        ];
    }

    /** One-time setup: the contact attribute and the webhook for unsubscribes, bounces and complaints. */
    public function setUp(string $webhookUrl, string $webhookToken): void
    {
        $this->call('post', '/contacts/attributes/normal/'.self::UNSUBSCRIBE_ATTRIBUTE, ['type' => 'text'], [400]);   // 400: it exists
        $this->call('post', '/webhooks', [
            'url' => $webhookUrl, 'description' => 'Raqam: unsubscribes, bounces, complaints', 'type' => 'marketing',
            'events' => ['unsubscribed', 'hardBounce', 'spam', 'contactDeleted'],
            'headers' => [['key' => 'X-Raqam-Brevo-Token', 'value' => $webhookToken]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<int>  $alsoFine  statuses that are an answer, not a failure (e.g. 404 for "no such contact")
     *
     * @throws BrevoUnavailable
     */
    protected function call(string $method, string $path, array $body = [], array $alsoFine = []): Response
    {
        if (! self::configured()) {
            throw new BrevoUnavailable('Brevo غير مربوط (المفتاح أو رقم القائمة غير مضبوط).');
        }
        try {
            $response = $this->http()->send(strtoupper($method), ltrim($path, '/'), $body === [] ? [] : ['json' => $body]);
        } catch (\Throwable) {
            throw $this->fail('تعذّر الاتصال بـ Brevo.');
        }
        if ($response->successful() || in_array($response->status(), $alsoFine, true)) {
            ProviderHealth::success('brevo');

            return $response;
        }
        $code = is_string($response->json('code')) ? ' ('.mb_substr($response->json('code'), 0, 60).')' : '';

        throw $this->fail('رفض Brevo الطلب: '.$response->status().$code.'.');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) (config('raqam.newsletter.brevo.api_base') ?: self::BASE), '/').'/')
            ->withHeaders(['api-key' => (string) config('raqam.newsletter.brevo.key'), 'accept' => 'application/json'])
            ->timeout(10)->connectTimeout(5)->withoutRedirecting();
    }

    private function fail(string $message): BrevoUnavailable
    {
        ProviderHealth::failure('brevo', $message);

        return new BrevoUnavailable($message);
    }
}
