<?php

use App\Console\AdminCreateCommand;
use App\Console\AdminUnlockCommand;
use App\Console\CheckVideosCommand;
use App\Console\CurrencyFetchCommand;
use App\Console\InstallCharactersCommand;
use App\Console\InstallCitiesCommand;
use App\Console\InstallPagesCommand;
use App\Console\InstallServicesCommand;
use App\Console\InstallSynonymsCommand;
use App\Console\PruneDevicesCommand;
use App\Console\PublishDuePostsCommand;
use App\Console\PurgeTrashedPostsCommand;
use App\Console\SearchCreateKeyCommand;
use App\Console\SearchEvaluateCommand;
use App\Console\SearchReindexCommand;
use App\Console\SearchSetupCommand;
use App\Console\SearchSyncDueCommand;
use App\Console\WeatherFetchCommand;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([AdminCreateCommand::class, AdminUnlockCommand::class, PruneDevicesCommand::class, PublishDuePostsCommand::class, PurgeTrashedPostsCommand::class, InstallPagesCommand::class, CheckVideosCommand::class, InstallCharactersCommand::class,
        SearchSetupCommand::class, SearchReindexCommand::class, SearchSyncDueCommand::class, SearchCreateKeyCommand::class, InstallSynonymsCommand::class, SearchEvaluateCommand::class,
        CurrencyFetchCommand::class, WeatherFetchCommand::class, InstallCitiesCommand::class, InstallServicesCommand::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API always answers in the same JSON shape, whatever went wrong.
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (ValidationException $e, $request) {
            return $request->is('api/*') ? response()->json(['error' => ['code' => 'invalid', 'message' => 'أحد المعطيات غير صحيح.', 'details' => $e->errors()]], 422) : null;
        });
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            return $request->is('api/*') ? response()->json(['error' => ['code' => 'not_found', 'message' => 'غير موجود.']], 404) : null;
        });
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            return $request->is('api/*') ? response()->json(['error' => ['code' => 'too_many_requests', 'message' => 'طلبات كثيرة، حاول بعد قليل.']], 429, $e->getHeaders()) : null;
        });
    })->create();
