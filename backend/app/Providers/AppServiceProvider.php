<?php

namespace App\Providers;

use App\Console\WipeDatabaseCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\DB;
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
    }
}
