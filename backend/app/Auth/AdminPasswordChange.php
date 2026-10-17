<?php

namespace App\Auth;

use App\Mail\PasswordChanged;
use App\Models\User;
use App\Support\UserAgentSummary;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Sets a new password for the signed-in admin. Every other session of the account ends with the reason
 * password_changed (the session check notices the new password on its next request); this one gets a
 * fresh session id and goes on. An e-mail says it happened, after the response.
 */
final class AdminPasswordChange
{
    public function change(User $user, #[\SensitiveParameter] string $newPassword): void
    {
        $user->forceFill(['password_hash' => $newPassword])->save();        // the cast stores the Argon2id hash

        // The session check compares each session's copy of the password hash with the account's. This
        // session takes the new one now (a Livewire request would not do it for us), so only the OTHER
        // sessions no longer match, and each of them ends on its next request.
        session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            Filament::auth()->hashPasswordForCookie($user->getAuthPassword()),
        );
        session()->regenerate();

        AdminAudit::account('update', $user, $user, ['password' => 'changed']);

        $summary = UserAgentSummary::of(request()->userAgent());
        $at = now();
        defer(function () use ($user, $summary, $at) {
            try {
                Mail::to($user->email)->send(new PasswordChanged($summary['browser'], $summary['os'], $at));
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
