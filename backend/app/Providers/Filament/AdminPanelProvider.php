<?php

namespace App\Providers\Filament;

use App\Auth\AdminSessionLimits;
use App\Filament\InitialsAvatar;
use App\Filament\Pages\Auth\AdminLogin;
use App\Filament\Pages\Auth\AdminLoginCode;
use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\GeneralSettings;
use App\Filament\Pages\HomeLayoutEditor;
use App\Filament\Pages\MenuEditor;
use App\Filament\Pages\SearchStatistics;
use App\Filament\Pages\Statistics;
use App\Filament\PanelTheme;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Http\Controllers\AdminSessionController;
use App\Http\Middleware\EnforceAdminSessionLimits;
use App\Models\Post;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
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
            // The brand (DESIGN.md, "The panel"): the colours from PanelTheme, everything else from the theme's CSS,
            // built by Vite into public/build (resources/css/filament/admin/theme.css). Alexandria is declared there.
            ->colors(PanelTheme::colors())
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->font('Alexandria', provider: LocalFontProvider::class)
            // The header logo in the panel; the vertical one, with the slogan, on the sign-in steps (nobody is signed in there).
            ->brandLogo(fn () => asset(Filament::auth()->check() ? 'brand/logo-ar-header.svg' : 'brand/logo-ar-vertical.svg'))
            ->darkModeBrandLogo(fn () => asset(Filament::auth()->check() ? 'brand/logo-ar-header-white.svg' : 'brand/logo-ar-vertical-white.svg'))
            ->brandLogoHeight(fn () => Filament::auth()->check() ? '1.75rem' : '6rem')
            ->favicon(asset('brand/mark.svg'))
            // Drawn here, not fetched from ui-avatars.com (the content security policy, decision after QA-8).
            ->defaultAvatarProvider(InitialsAvatar::class)
            ->sidebarCollapsibleOnDesktop()
            ->unsavedChangesAlerts()
            // Ordered by how often an editor needs each screen: the daily ones first, with no group (posts, media, the
            // home's layout, messages), then the weekly groups, then the rare ones, closed until opened. PanelNavigationTest
            // holds the whole order.
            ->navigationGroups([
                NavigationGroup::make('النشرة البريدية'),
                NavigationGroup::make('الفيديو'),
                NavigationGroup::make('الإعلانات'),
                NavigationGroup::make('الخدمات'),
                NavigationGroup::make('الإحصائيات'),
                NavigationGroup::make('التصنيفات والأشخاص')->collapsed(),
                NavigationGroup::make('الموقع')->collapsed(),
                NavigationGroup::make('الأسعار والطقس')->collapsed(),
                NavigationGroup::make('الشات بوت')->collapsed(),
                NavigationGroup::make('البحث')->collapsed(),
                NavigationGroup::make('الإعدادات والنظام')->collapsed(),
            ])
            ->pages([Dashboard::class, MenuEditor::class, GeneralSettings::class, ChangePassword::class, SearchStatistics::class, Statistics::class, HomeLayoutEditor::class])
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
            // The bell (20.10), drawn with each page.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => Filament::auth()->check() ? Blade::render('@livewire(\'admin-bell\')') : '')
            ->renderHook(PanelsRenderHook::BODY_END, fn () => Filament::auth()->check() ? view('filament.post-editor', [
                'key' => 'raqam:post:'.Filament::auth()->id().':'.request()->route('record'),
                'serverAt' => optional(Post::query()->withTrashed()->find((int) request()->route('record')))->updated_at?->timestamp ?? 0,
                'isDraft' => Post::query()->withTrashed()->whereKey((int) request()->route('record'))->value('status') === 'draft',
            ])->render() : '', scopes: EditPost::class)
            ->renderHook(PanelsRenderHook::BODY_END, fn () => Filament::auth()->check() ? view('filament.session-timeout', [
                'remaining' => app(AdminSessionLimits::class)->remaining(),
                'idle' => app(AdminSessionLimits::class)->idleSeconds(),
                'warn' => (int) config('raqam.auth.warn_seconds'),
                'cap' => app(AdminSessionLimits::class)->absoluteRemaining(),
                'keepAliveUrl' => route('filament.admin.session.keep-alive'),
                'statusUrl' => route('filament.admin.session.status'),
                'loginUrl' => Filament::getLoginUrl().'?expired=1',
            ])->render() : '');
    }
}
