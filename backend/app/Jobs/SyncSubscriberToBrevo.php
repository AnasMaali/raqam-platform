<?php

namespace App\Jobs;

use App\Newsletter\BrevoClient;
use App\Newsletter\NewsletterLinks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Makes Brevo agree with our subscribers table for one address (19.4): it reads the row when it runs, so a late or
 * repeated job never undoes a newer change. Active = in our list; unsubscribed or bounced = out of it and blocked;
 * no row (deleted in the panel) = the contact is deleted. Without Brevo configured nothing is queued.
 */
class SyncSubscriberToBrevo implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public string $email) {}

    public static function queueFor(string $email): void
    {
        if (BrevoClient::configured()) {
            self::dispatch($email)->afterCommit();
        }
    }

    public function handle(BrevoClient $brevo): void
    {
        $row = DB::table('subscribers')->where('email', $this->email)->first(['id', 'email', 'name', 'status']);
        if ($row === null) {
            $brevo->deleteContact($this->email);
        } elseif ($row->status === 'active') {
            $brevo->upsertContact((string) $row->email, $row->name, NewsletterLinks::unsubscribeUrl((int) $row->id, (string) $row->email));
        } else {
            $brevo->unsubscribe((string) $row->email);
        }
    }
}
