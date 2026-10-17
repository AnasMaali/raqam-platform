<?php

namespace App\Auth;

use App\Models\User;
use App\Support\TrackingHash;
use Illuminate\Support\Facades\DB;

/**
 * Sign-ins and sign-outs of the admin in audit_log (decisions 22.14, 22.15). The IP is an HMAC only.
 * The reason a session ended is a row of audit_log_changes: manual, idle, absolute or password_changed.
 * Writing the log never stops a sign-out: a failure is reported and the user is signed out anyway.
 */
final class AdminAudit
{
    public const MANUAL = 'manual';

    public const IDLE = 'idle';

    public const ABSOLUTE = 'absolute';

    public const PASSWORD_CHANGED = 'password_changed';

    private const REASON_ATTRIBUTE = 'admin_logout_reason';

    public static function login(User $user): void
    {
        self::write($user, 'login');
    }

    public static function logout(User $user, string $reason): void
    {
        self::write($user, 'logout', ['logout_reason' => $reason]);
    }

    /** Called by whatever is about to sign the user out; without it the sign-out was the user's own. */
    public static function expectLogout(string $reason): void
    {
        request()->attributes->set(self::REASON_ATTRIBUTE, $reason);
    }

    public static function expectedReason(): string
    {
        return request()->attributes->get(self::REASON_ATTRIBUTE, self::MANUAL);
    }

    /** @param  array<string, string>  $changes */
    private static function write(User $user, string $action, array $changes = []): void
    {
        if (! $user->isStaff()) {
            return;
        }

        try {
            DB::transaction(function () use ($user, $action, $changes) {
                $id = DB::table('audit_log')->insertGetId([
                    'actor_user_id' => $user->id,
                    'action' => $action,
                    'subject_table' => 'users',
                    'subject_id' => $user->id,
                    'ip_hash' => TrackingHash::ip(),
                    'user_agent' => ($agent = request()->userAgent()) ? mb_substr($agent, 0, 512) : null,
                ]);

                foreach ($changes as $field => $new) {
                    DB::table('audit_log_changes')->insert(['audit_id' => $id, 'field' => $field, 'old_value' => null, 'new_value' => $new]);
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
