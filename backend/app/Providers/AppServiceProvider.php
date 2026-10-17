<?php

namespace App\Providers;

use App\Auth\AdminAudit;
use App\Console\WipeDatabaseCommand;
use App\Support\ProductionChecks;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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

        // The dialog's keep-alive and status requests: 10 a minute for each, per admin.
        RateLimiter::for('admin-session', fn (Request $request) => Limit::perMinute(10)
            ->by($request->route()?->getName().'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // The largest file the media library accepts (an image); the library itself checks each type's own limit.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(int) (config('media.limits.image_upload') / 1024)]]);

        // Every sign-out of an admin is logged with its reason. CurrentDeviceLogout is what
        // AuthenticateSession raises when the password changed since the session began.
        Event::listen(Logout::class, fn (Logout $event) => $event->user && AdminAudit::logout($event->user, AdminAudit::expectedReason()));
        Event::listen(CurrentDeviceLogout::class, fn (CurrentDeviceLogout $event) => $event->user && AdminAudit::logout($event->user, AdminAudit::PASSWORD_CHANGED));

        if ($this->app->isProduction() && ProductionChecks::appliesTo($this->app->runningInConsole(), $_SERVER['argv'][1] ?? null)) {
            ProductionChecks::enforce();
        }
    }
}
