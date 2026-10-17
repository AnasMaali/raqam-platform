<?php

namespace App\Auth;

use App\Support\TrackingHash;
use Illuminate\Support\Facades\DB;

/** One login_attempts row per sign-in outcome (decision 22.16). The IP is stored as an HMAC only. */
final class LoginAttempts
{
    public const BAD_CREDENTIALS = 'bad_credentials';

    public const BAD_CODE = 'bad_code';

    public const EXPIRED_CODE = 'expired_code';

    public const THROTTLED = 'throttled';

    public const INACTIVE = 'inactive';

    /** @param  string|null  $failure  null records a successful sign-in */
    public function record(string $email, ?int $userId, ?string $failure): void
    {
        // The column is an e-mail domain: an address it would refuse must not break the sign-in.
        if (strlen($email) > 254 || ! preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return;
        }

        try {
            DB::transaction(fn () => DB::table('login_attempts')->insert([
                'email' => $email,
                'user_id' => $userId,
                'succeeded' => $failure === null,
                'failure_reason' => $failure,
                'ip_hash' => TrackingHash::ip(),
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
