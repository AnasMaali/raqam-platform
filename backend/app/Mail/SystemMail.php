<?php

namespace App\Mail;

use App\Integrations\Secrets;
use App\Jobs\SendSystemEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The one way a system message leaves (decision 20): it is written to email_log first (20.9), then sent by the queue
 * with up to three tries (20.8). The sign-in messages are the exception (decision 2026-10-23): they are sent right
 * away, still tried three times and still logged, because a stuck queue must never keep the admin out.
 * A switched-off template (the dormant ones, 20.5) sends nothing.
 */
final class SystemMail
{
    /** Queues the message; returns its email_log id, or null when its template is switched off. */
    public static function queue(TemplatedEmail $mail, string $to): ?int
    {
        if (EmailCatalog::isDirect($mail->templateKey)) {
            return self::direct($mail, $to);
        }
        if (! EmailTemplates::isActive($mail->templateKey)) {
            return null;
        }

        $id = self::log($mail, $to);
        // After the commit: a message about a row that was rolled back is never sent.
        SendSystemEmail::dispatch($id, $to, $mail)->afterCommit();

        return $id;
    }

    /**
     * Queues one copy for every active admin (the alerts and the weekly summary); returns how many.
     *
     * @param  callable(): TemplatedEmail  $make
     */
    public static function toAdmins(callable $make): int
    {
        $sent = 0;
        foreach (self::adminEmails() as $email) {
            $sent += self::queue($make(), $email) === null ? 0 : 1;
        }

        return $sent;
    }

    /** @return list<string> */
    public static function adminEmails(): array
    {
        return DB::table('users')->where('user_type', 'staff')->where('is_active', true)->whereNull('deleted_at')
            ->orderBy('id')->pluck('email')->map(fn ($e) => (string) $e)->all();
    }

    /** Sends right away, tried up to the configured attempts; returns whether it went. Logged either way. */
    public static function sendNow(TemplatedEmail $mail, string $to): bool
    {
        $id = self::direct($mail, $to);

        return $id !== null && DB::table('email_log')->where('id', $id)->value('status') === 'sent';
    }

    private static function direct(TemplatedEmail $mail, string $to): ?int
    {
        if (! EmailTemplates::isActive($mail->templateKey)) {
            return null;
        }
        $id = self::log($mail, $to);
        $attempts = (int) config('raqam.mail.attempts');
        $last = null;

        for ($try = 1; $try <= $attempts; $try++) {
            try {
                Mail::to($to)->send($mail);
                self::sent($id, $try);

                return $id;
            } catch (\Throwable $e) {
                $last = $e;
                self::attemptFailed($id, $try, $e);
                if ($try < $attempts) {
                    usleep((int) config('raqam.mail.direct_pause_ms') * 1000);
                }
            }
        }

        self::failed($id, $last);
        report($last);

        return $id;
    }

    public static function sent(int $id, int $attempts): void
    {
        DB::table('email_log')->where('id', $id)->update([
            'status' => 'sent', 'attempts' => min($attempts, 3), 'sent_at' => DB::raw('now()'), 'error' => null,
        ]);
    }

    public static function attemptFailed(int $id, int $attempts, \Throwable $e): void
    {
        DB::table('email_log')->where('id', $id)->update(['attempts' => min($attempts, 3), 'error' => self::reason($e)]);
    }

    public static function failed(int $id, ?\Throwable $e): void
    {
        DB::table('email_log')->where('id', $id)->update(['status' => 'failed', 'error' => self::reason($e)]);
    }

    /** What went wrong, short and without anything secret (an SMTP password never reaches the log). */
    public static function reason(?\Throwable $e): string
    {
        $message = $e === null ? '' : trim($e->getMessage());
        $password = (string) config('mail.mailers.smtp.password');
        if (strlen($password) >= 4) {
            $message = str_replace($password, Secrets::MARK, $message);
        }

        return mb_substr(Secrets::scrub($message) ?: 'تعذّر الإرسال.', 0, 1000);
    }

    private static function log(TemplatedEmail $mail, string $to): int
    {
        return DB::table('email_log')->insertGetId(['template_key' => $mail->templateKey, 'recipient' => $to]);
    }
}
