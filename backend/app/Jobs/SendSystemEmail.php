<?php

namespace App\Jobs;

use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one queued system message (20.8): up to three tries, each one counted in its email_log row, which ends as
 * "sent" or, after the last try, "failed" with a short reason (20.9).
 */
class SendSystemEmail implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public int $logId, public string $to, public TemplatedEmail $mail)
    {
        $this->tries = (int) config('raqam.mail.attempts');
        $this->backoff = (array) config('raqam.mail.backoff');
    }

    public function handle(): void
    {
        $try = min($this->attempts(), 3);
        try {
            Mail::to($this->to)->send($this->mail);
        } catch (\Throwable $e) {
            SystemMail::attemptFailed($this->logId, $try, $e);

            throw $e;
        }
        SystemMail::sent($this->logId, $try);
    }

    public function failed(?\Throwable $e): void
    {
        // A worker that died mid-try leaves no reason behind; keep the last one we have.
        $error = $e === null ? DB::table('email_log')->where('id', $this->logId)->value('error') : null;
        SystemMail::failed($this->logId, $e ?? ($error ? new \RuntimeException((string) $error) : null));
    }
}
