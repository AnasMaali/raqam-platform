<?php

namespace App\Http\Middleware;

use App\Auth\AdminAudit;
use App\Auth\AdminSessionLimits;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends an admin session that was idle for 30 minutes or is 12 hours old, and counts every other
 * request as activity (except the status question of the warning dialog). A browser page (full load) is sent to the sign-in page with a notice; a
 * Livewire request gets 419, which the page's script turns into the same redirect.
 */
class EnforceAdminSessionLimits
{
    public function __construct(private readonly AdminSessionLimits $limits) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Filament::auth()->check()) {
            return $next($request);
        }

        if (($status = $this->limits->status()) !== 'ok') {
            AdminAudit::expectLogout($status === 'max' ? AdminAudit::ABSOLUTE : AdminAudit::IDLE);
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
                abort(419);
            }

            return redirect(Filament::getLoginUrl().'?expired=1');
        }

        // Asking how much time is left must not add any: the status request is not activity.
        if (! $request->routeIs('filament.admin.session.status')) {
            $this->limits->touch();
        }

        return $next($request);
    }
}
