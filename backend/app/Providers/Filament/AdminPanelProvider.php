<?php

namespace App\Providers\Filament;

use App\Auth\AdminSessionLimits;
use App\Filament\Pages\Auth\AdminLogin;
use App\Filament\Pages\Auth\AdminLoginCode;
use App\Http\Middleware\EnforceAdminSessionLimits;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use RuntimeException;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $path = config('raqam.admin_path')
            ?: throw new RuntimeException('ADMIN_PATH is not set; the admin panel has no default path.');

        return $panel
            ->id('admin')
            ->path($path)
            ->login(AdminLogin::class)
            ->routes(fn () => Route::get('/login/code', AdminLoginCode::class)->name('auth.login.code'))
            ->brandName('رَقَم')
            ->colors(['primary' => Color::Amber])
            ->pages([Dashboard::class])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class, EnforceAdminSessionLimits::class], isPersistent: true)
            ->authenticatedRoutes(fn () => Route::post('/session/keep-alive', fn () => response()->json([
                'remaining' => app(AdminSessionLimits::class)->remaining(),
            ]))->name('session.keep-alive'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => Filament::auth()->check() ? view('filament.session-timeout', [
                'remaining' => app(AdminSessionLimits::class)->remaining(),
                'idle' => (int) config('raqam.auth.idle_minutes') * 60,
                'warn' => (int) config('raqam.auth.warn_seconds'),
                'keepAliveUrl' => route('filament.admin.session.keep-alive'),
                'loginUrl' => Filament::getLoginUrl().'?expired=1',
            ])->render() : '');
    }
}
