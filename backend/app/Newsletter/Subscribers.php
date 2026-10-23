<?php

namespace App\Newsletter;

use App\Audit\AuditLogger;
use App\Jobs\SyncSubscriberToBrevo;
use Illuminate\Support\Facades\DB;

/**
 * What happens to a subscriber after they confirmed (19.4, 19.5, 19.9): an unsubscribe from their own link, a bounce or
 * an unsubscribe that Brevo reports (believed only once Brevo's API agrees), and deletion from the panel. Every change
 * reaches Brevo by a job that reads the row again, so the latest state always wins.
 */
final class Subscribers
{
    /** The subscriber's own signed link (19.5). True when the link is theirs; unsubscribing twice is fine. */
    public function unsubscribe(int $id, string $signature): bool
    {
        $row = DB::table('subscribers')->where('id', $id)->first(['id', 'email', 'status']);
        if ($row === null || ! NewsletterLinks::valid((int) $row->id, (string) $row->email, $signature)) {
            return false;
        }
        if ($row->status !== 'unsubscribed') {
            $this->mark((string) $row->email, 'unsubscribed');
        }

        return true;
    }

    /**
     * Brevo said this address unsubscribed, bounced or complained. The webhook is not signed, so it is only a hint:
     * Brevo's own record of the contact decides. A contact Brevo no longer has, or that is blocked or out of our list,
     * cannot receive the newsletter any more.
     */
    public function checkWithBrevo(string $email, string $event, BrevoClient $brevo): ?string
    {
        $row = DB::table('subscribers')->where('email', $email)->first(['status']);
        if ($row === null || $row->status !== 'active') {
            return null;
        }
        $contact = $brevo->contact($email);
        $stillReceives = $contact !== null && ! ($contact['emailBlacklisted'] ?? false)
            && in_array($brevo->listId(), array_map('intval', (array) ($contact['listIds'] ?? [])), true)
            && ! in_array($brevo->listId(), array_map('intval', (array) ($contact['listUnsubscribed'] ?? [])), true);
        if ($stillReceives) {
            return null;   // Brevo does not agree: a forged or stale message
        }
        $status = in_array($event, ['hard_bounce', 'hardBounce'], true) ? 'bounced' : 'unsubscribed';
        $this->mark($email, $status, fromBrevo: true);

        return $status;
    }

    /** Deleted from the panel (19.9): the row, its codes and the Brevo contact go; the audit keeps only that it happened. */
    public function delete(int $id, ?int $userId): void
    {
        $email = DB::table('subscribers')->where('id', $id)->value('email');
        if ($email === null) {
            return;
        }
        DB::transaction(function () use ($id, $email, $userId) {
            DB::table('verification_codes')->where('purpose', 'newsletter_confirm')->where('email', $email)->delete();
            DB::table('subscribers')->where('id', $id)->delete();
            AuditLogger::record('delete', 'subscribers', $id, [], $userId);
        });
        SyncSubscriberToBrevo::queueFor((string) $email);
    }

    private function mark(string $email, string $status, bool $fromBrevo = false): void
    {
        DB::table('subscribers')->where('email', $email)->update([
            'status' => $status, 'updated_at' => DB::raw('now()'),
            'unsubscribed_at' => $status === 'unsubscribed' ? DB::raw('now()') : DB::raw('unsubscribed_at'),
        ]);
        if (! $fromBrevo) {
            SyncSubscriberToBrevo::queueFor($email);
        }
    }
}
