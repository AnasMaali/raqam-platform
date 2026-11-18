<?php

use App\Console\AdEndingAlertsCommand;
use App\Console\AdminCreateCommand;
use App\Console\AdminUnlockCommand;
use App\Console\BrevoSetupCommand;
use App\Console\CheckDiskCommand;
use App\Console\CheckVideosCommand;
use App\Console\CurrencyFetchCommand;
use App\Console\DemoCommand;
use App\Console\FeatureSwitchCommand;
use App\Console\InstallCharactersCommand;
use App\Console\InstallChatbotCommand;
use App\Console\InstallCitiesCommand;
use App\Console\InstallEmailTemplatesCommand;
use App\Console\InstallHomeLayoutCommand;
use App\Console\InstallPagesCommand;
use App\Console\InstallPermissionsCommand;
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
use App\Console\RaqamInstallCommand;
use App\Console\ReconcilePaymentsCommand;
use App\Console\SearchCreateKeyCommand;
use App\Console\SearchEvaluateCommand;
use App\Console\SearchReindexCommand;
use App\Console\SearchSetupCommand;
use App\Console\SearchSyncDueCommand;
use App\Console\StatsFlushCommand;
use App\Console\StatsPruneCommand;
use App\Console\SystemWatchCommand;
use App\Console\WatchSignInFailuresCommand;
use App\Console\WeatherFetchCommand;
use App\Console\WeeklyDigestCommand;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\QueryReport;
use App\Http\Middleware\RedisOutage;
use App\Http\Middleware\RejectMalformedText;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\SecurityHeaders;
use App\Panel\SystemWatch;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        // `/up` for a monitor outside the server: every part of the platform, not only that PHP answers (QA-6). Outside
        // the web group: it has no session, which lives in Redis, one of the parts it looks at.
        then: fn () => Route::get('/up', HealthController::class),
    )
    ->withCommands([AdminCreateCommand::class, AdminUnlockCommand::class, PruneDevicesCommand::class, PublishDuePostsCommand::class, PurgeTrashedPostsCommand::class, InstallPagesCommand::class, CheckVideosCommand::class, InstallCharactersCommand::class,
        SearchSetupCommand::class, SearchReindexCommand::class, SearchSyncDueCommand::class, SearchCreateKeyCommand::class, InstallSynonymsCommand::class, SearchEvaluateCommand::class,
        CurrencyFetchCommand::class, WeatherFetchCommand::class, InstallCitiesCommand::class, InstallServicesCommand::class,
        InstallEmailTemplatesCommand::class, PruneEmailLogCommand::class, AdEndingAlertsCommand::class, CheckDiskCommand::class, WeeklyDigestCommand::class,
        NewsletterWeeklyDraftCommand::class, NewsletterStatsCommand::class, BrevoSetupCommand::class, InstallChatbotCommand::class,
        PruneRetainedDataCommand::class, StatsFlushCommand::class, StatsPruneCommand::class, PruneAuditLogCommand::class, InstallHomeLayoutCommand::class, InstallPermissionsCommand::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        // Redis not answering: the API's reads go on from the database, the rest answers 503 (QA-6).
        $middleware->prepend(RedisOutage::class);
        // The QA environment's query count (off unless QUERY_REPORT is set; production refuses it). First, so it counts all.
        $middleware->prepend(QueryReport::class);
        // Text that is not UTF-8 is refused before anything reads it (phase 10b security review).
        $middleware->api(prepend: [RejectMalformedText::class]);
        $middleware->alias(['feature' => RequireFeature::class]);
        // A switched-off system answers 404 before a request limit counts the request.
        $middleware->prependToPriorityList(ThrottleRequests::class, RequireFeature::class);
    })
    // Online payment (decision 16, switched off).
    ->withCommands([ReconcilePaymentsCommand::class])
    // The switches only the server turns on (phase 10 review N1).
    ->withCommands([FeatureSwitchCommand::class])
    // Many failed sign-ins in an hour alert the admins (24.15).
    ->withCommands([WatchSignInFailuresCommand::class])
    // The scheduler's beat and the queue's watch (QA-6).
    ->withCommands([SystemWatchCommand::class])
    // Every install step in order, safe to repeat (phase 10b freeze).
    ->withCommands([RaqamInstallCommand::class])
    // Made-up content for building the website against; refuses to run in production.
    ->withCommands([DemoCommand::class])
    // Every listener is registered by hand in AppServiceProvider. With discovery on as well, a class in app/Listeners ran
    // twice for each event (two webhooks for every post change).
    ->withEvents(discover: false)
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API always answers in the same JSON shape, whatever went wrong.
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (ValidationException $e, $request) {
            return $request->is('api/*') ? response()->json(['error' => ['code' => 'invalid', 'message' => 'أحد المعطيات غير صحيح.', 'details' => $e->errors()]], 422) : null;
        });
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            return $request->is('api/*') ? response()->json(['error' => ['code' => 'not_found', 'message' => 'غير موجود.']], 404) : null;
        });
        // Redis stopping halfway through a request (RedisOutage looks before it starts): 503 and the admins told, never a 500.
        $exceptions->render(function (RedisException $e, $request) {
            defer(fn () => SystemWatch::redisDown())->always();

            return $request->is('api/*') ? RedisOutage::unavailable() : response()->view('errors.503', [], 503);
        });
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            return $request->is('api/*') ? response()->json(['error' => ['code' => 'too_many_requests', 'message' => 'طلبات كثيرة، حاول بعد قليل.']], 429, $e->getHeaders()) : null;
        });
    })->create();
