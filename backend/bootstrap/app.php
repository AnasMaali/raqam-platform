<?php

use App\Console\AdEndingAlertsCommand;
use App\Console\AdminCreateCommand;
use App\Console\AdminUnlockCommand;
use App\Console\BrevoSetupCommand;
use App\Console\CheckDiskCommand;
use App\Console\CheckVideosCommand;
use App\Console\CurrencyFetchCommand;
use App\Console\InstallCharactersCommand;
use App\Console\InstallChatbotCommand;
use App\Console\InstallCitiesCommand;
use App\Console\InstallEmailTemplatesCommand;
use App\Console\InstallHomeLayoutCommand;
use App\Console\InstallPagesCommand;
use App\Console\InstallServicesCommand;
use App\Console\InstallSynonymsCommand;
use App\Console\NewsletterStatsCommand;
use App\Console\NewsletterWeeklyDraftCommand;
use App\Console\PruneAuditLogCommand;
use App\Console\PruneDevicesCommand;
use App\Console\PruneEmailLogCommand;
use App\Console\PruneRetainedDataCommand;
use App\Console\PublishDuePostsCommand;
use App\Console\PurgeTrashedPostsCommand;
use App\Console\ReconcilePaymentsCommand;
use App\Console\SearchCreateKeyCommand;
use App\Console\SearchEvaluateCommand;
use App\Console\SearchReindexCommand;
use App\Console\SearchSetupCommand;
use App\Console\SearchSyncDueCommand;
use App\Console\StatsFlushCommand;
use App\Console\StatsPruneCommand;
use App\Console\WeatherFetchCommand;
use App\Console\WeeklyDigestCommand;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\ThrottleRequests;
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
        CurrencyFetchCommand::class, WeatherFetchCommand::class, InstallCitiesCommand::class, InstallServicesCommand::class,
        InstallEmailTemplatesCommand::class, PruneEmailLogCommand::class, AdEndingAlertsCommand::class, CheckDiskCommand::class, WeeklyDigestCommand::class,
        NewsletterWeeklyDraftCommand::class, NewsletterStatsCommand::class, BrevoSetupCommand::class, InstallChatbotCommand::class,
        PruneRetainedDataCommand::class, StatsFlushCommand::class, StatsPruneCommand::class, PruneAuditLogCommand::class, InstallHomeLayoutCommand::class, ReconcilePaymentsCommand::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['feature' => RequireFeature::class]);
        // A switched-off system answers 404 before a request limit counts the request.
        $middleware->prependToPriorityList(ThrottleRequests::class, RequireFeature::class);
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
