<?php

namespace App\Providers;

use App\Api\SiteToken;
use App\Auth\AdminAudit;
use App\Console\WipeDatabaseCommand;
use App\Events\PostChanged;
use App\Filament\Forms\LtrFields;
use App\Filament\Pages\Auth\AdminLoginCode;
use App\Listeners\NotifySiteOfPostChange;
use App\Search\SearchSync;
use App\Support\ProductionChecks;
use App\Support\TrackingHash;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // migrate:fresh calls db:wipe; ours also drops functions, domains and extensions.
        $this->app->extend(WipeCommand::class, fn ($command, $app) => $app->make(WipeDatabaseCommand::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // db:wipe, migrate:fresh/refresh/reset/rollback never run in production, not even with --force.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // The sign-in code page is a bare route, not a panel page, so Filament does not register it. Without
        // a name Livewire cannot find it again when the page posts back to /livewire/update (419).
        Livewire::component('admin-login-code', AdminLoginCode::class);

        // The dialog's keep-alive and status requests: 10 a minute for each, per admin.
        RateLimiter::for('admin-session', fn (Request $request) => Limit::perMinute(10)
            ->by($request->route()?->getName().'|'.($request->user()?->getAuthIdentifier() ?? TrackingHash::visitor($request->ip()))));

        // The preview page and the upload endpoint of Livewire: 60 a minute per visitor (an unnamed throttle keys on a bare hash of the address).
        RateLimiter::for('per-visitor', fn (Request $request) => Limit::perMinute(60)->by(TrackingHash::visitor($request->ip())));
        config(['livewire.temporary_file_upload.middleware' => 'throttle:per-visitor']);

        // The largest file the media library accepts (an image); the library itself checks each type's own limit.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(int) (max(config('media.limits.image_upload'), config('media.limits.video')) / 1024)]]);

        LtrFields::register();

        Event::listen(PostChanged::class, NotifySiteOfPostChange::class);
        SearchSync::register();

        // The public API: 120 a minute per client address; the website, holding the secret token, gets far more.
        RateLimiter::for('api-public', function (Request $request) {
            return SiteToken::valid($request) ? Limit::perMinute((int) config('raqam.api.site_limit'))->by('site') : Limit::perMinute((int) config('raqam.api.limit'))->by(TrackingHash::visitor($request->ip()));
        });

        // Search (10.13): 60 a minute per visitor. Instant search reaches us through the site's own route, so the
        // visitor is the address the site names, believed only with the site's token; the key is an HMAC of it
        // like every other counter. The site without a visitor address gets its own large budget.
        RateLimiter::for('api-search', function (Request $request) {
            $perVisitor = Limit::perMinute((int) config('raqam.search.per_minute'));
            if (($visitor = SiteToken::visitor($request)) !== null) {
                return $perVisitor->by(TrackingHash::visitor($visitor));
            }

            return SiteToken::valid($request) ? Limit::perMinute((int) config('raqam.api.site_limit'))->by('site') : $perVisitor->by(TrackingHash::visitor($request->ip()));
        });

        // Every sign-out of an admin is logged with its reason. CurrentDeviceLogout is what
        // AuthenticateSession raises when the password changed since the session began.
        Event::listen(Logout::class, fn (Logout $event) => $event->user && AdminAudit::logout($event->user, AdminAudit::expectedReason()));
        Event::listen(CurrentDeviceLogout::class, fn (CurrentDeviceLogout $event) => $event->user && AdminAudit::logout($event->user, AdminAudit::PASSWORD_CHANGED));

        if ($this->app->isProduction() && ProductionChecks::appliesTo($this->app->runningInConsole(), $_SERVER['argv'][1] ?? null)) {
            ProductionChecks::enforce();
        }
    }
}
