<?php

namespace App\Auth;

/**
 * The two clocks of a signed-in admin session: 30 minutes without activity, and 12 hours in all.
 * Only a request the server receives moves the idle clock; nothing in the browser can.
 */
final class AdminSessionLimits
{
    private const SIGNED_IN_AT = 'admin_session.signed_in_at';

    private const LAST_ACTIVITY = 'admin_session.last_activity';

    public function start(): void
    {
        session()->put([self::SIGNED_IN_AT => now()->getTimestamp(), self::LAST_ACTIVITY => now()->getTimestamp()]);
    }

    public function touch(): void
    {
        session()->put(self::LAST_ACTIVITY, now()->getTimestamp());
    }

    /** 'ok', 'idle' or 'max'. A session without the marks (signed in some other way) starts its clocks now. */
    public function status(): string
    {
        if (! session()->has(self::SIGNED_IN_AT)) {
            $this->start();
        }

        return match (true) {
            $this->maxRemaining() <= 0 => 'max',
            $this->idleRemaining() <= 0 => 'idle',
            default => 'ok',
        };
    }

    /** Seconds until the session ends if nothing happens. */
    public function remaining(): int
    {
        return max(0, min($this->idleRemaining(), $this->maxRemaining()));
    }

    private function idleRemaining(): int
    {
        return session(self::LAST_ACTIVITY, now()->getTimestamp()) + (int) config('raqam.auth.idle_minutes') * 60 - now()->getTimestamp();
    }

    private function maxRemaining(): int
    {
        return session(self::SIGNED_IN_AT, now()->getTimestamp()) + (int) config('raqam.auth.max_hours') * 3600 - now()->getTimestamp();
    }
}
