<?php

use App\Console\AdminCreateCommand;
use App\Console\AdminUnlockCommand;
use App\Console\InstallPagesCommand;
use App\Console\PruneDevicesCommand;
use App\Console\PublishDuePostsCommand;
use App\Console\PurgeTrashedPostsCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([AdminCreateCommand::class, AdminUnlockCommand::class, PruneDevicesCommand::class, PublishDuePostsCommand::class, PurgeTrashedPostsCommand::class, InstallPagesCommand::class])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
