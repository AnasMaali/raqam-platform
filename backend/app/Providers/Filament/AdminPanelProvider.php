<?php

namespace App\Providers\Filament;

use App\Auth\AdminSessionLimits;
use App\Filament\Pages\Auth\AdminLogin;
use App\Filament\Pages\Auth\AdminLoginCode;
use App\Filament\Pages\ChangePassword;
use App\Http\Controllers\AdminSessionController;
use App\Http\Middleware\EnforceAdminSessionLimits;
use Filament\Actions\Action;
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

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Without a configured path the panel is mounted at a random one nobody knows, so it is as good
        // as off (and package discovery or the image build can still boot the application).
        // Production refuses to start at all in that case: see ProductionChecks.
        $path = config('raqam.admin_path') ?: bin2hex(random_bytes(16));

        return $panel
            ->id('admin')
            ->path($path)
            ->login(AdminLogin::class)
            ->routes(fn () => Route::get('/login/code', AdminLoginCode::class)->name('auth.login.code'))
            ->brandName('رَقَم')
            ->colors(['primary' => Color::Amber])
            ->pages([Dashboard::class, ChangePassword::class])
            ->userMenuItems([
                Action::make('password')->label('تغيير كلمة المرور')->icon('heroicon-o-key')->url(fn () => ChangePassword::getUrl()),
            ])
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
            ->authenticatedRoutes(function () {
                Route::middleware('throttle:admin-session')->group(function () {
                    Route::post('/session/keep-alive', [AdminSessionController::class, 'keepAlive'])->name('session.keep-alive');
                    Route::get('/session/status', [AdminSessionController::class, 'status'])->name('session.status');
                });
            })
            ->renderHook(PanelsRenderHook::BODY_END, fn () => Filament::auth()->check() ? view('filament.session-timeout', [
                'remaining' => app(AdminSessionLimits::class)->remaining(),
                'idle' => (int) config('raqam.auth.idle_minutes') * 60,
                'warn' => (int) config('raqam.auth.warn_seconds'),
                'cap' => app(AdminSessionLimits::class)->absoluteRemaining(),
                'keepAliveUrl' => route('filament.admin.session.keep-alive'),
                'statusUrl' => route('filament.admin.session.status'),
                'loginUrl' => Filament::getLoginUrl().'?expired=1',
            ])->render() : '');
    }
}
