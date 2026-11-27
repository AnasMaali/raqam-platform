<?php

use App\Support\ErrorReports;
use App\Support\ProductionChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\SentrySdk;
use Sentry\Stacktrace;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Sentry\UserDataBag;

uses(RefreshDatabase::class);

/*
 * Error reports to Sentry: the back end only, off without a DSN, only in the EU region, and every report without
 * people, secrets or the panel's address. A real report is made through the application's error handler and caught
 * by a fake transport, so what would leave the server is what is checked.
 */

const SENTRY_EU_DSN = 'https://publickey1234@o123.ingest.de.sentry.io/456';

final class CapturedReports implements TransportInterface
{
    /** @var list<Event> */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}

/** Sentry switched on with `$dsn`, sending into a fake transport; null when the client is off. */
function sentryWith(?string $dsn): CapturedReports
{
    $reports = new CapturedReports;
    config(['sentry.dsn' => ErrorReports::dsn($dsn), 'sentry.transport' => $reports]);
    app()->forgetInstance(HubInterface::class);
    SentrySdk::setCurrentHub(app(HubInterface::class));

    return $reports;
}

afterEach(function () {
    config(['sentry.dsn' => null, 'sentry.transport' => null]);
    app()->forgetInstance(HubInterface::class);
    SentrySdk::setCurrentHub(app(HubInterface::class));
});

test('off without a DSN, and off with a DSN outside the EU region', function () {
    expect(ErrorReports::dsn(null))->toBeNull()
        ->and(ErrorReports::dsn(''))->toBeNull()
        ->and(ErrorReports::dsn('https://k@o1.ingest.us.sentry.io/2'))->toBeNull()
        ->and(ErrorReports::dsn('https://k@o1.ingest.sentry.io/2'))->toBeNull()
        ->and(ErrorReports::dsn('http://k@o1.ingest.de.sentry.io/2'))->toBeNull()
        ->and(ErrorReports::dsn('https://k@o1.ingest.de.sentry.io.evil.example/2'))->toBeNull()
        ->and(ErrorReports::dsn(SENTRY_EU_DSN))->toBe(SENTRY_EU_DSN)
        // The application as configured for tests: no client at all.
        ->and(config('sentry.dsn'))->toBeNull();

    // A DSN outside the EU never reaches the client: it has nowhere to send.
    sentryWith('https://k@o1.ingest.us.sentry.io/2');
    expect(SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn())->toBeNull();
    sentryWith(SENTRY_EU_DSN);
    expect((string) SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn())->toBe(SENTRY_EU_DSN);
});

test('production refuses a DSN outside the EU region, and accepts none or an EU one', function () {
    productionConfig();
    expect(ProductionChecks::failures())->toBe([]);

    config(['raqam.sentry.dsn' => 'https://k@o1.ingest.us.sentry.io/2']);
    expect(collect(ProductionChecks::failures())->implode(' '))->toContain('SENTRY_DSN');

    config(['raqam.sentry.dsn' => SENTRY_EU_DSN]);
    expect(ProductionChecks::failures())->toBe([]);
});

test('a report from the panel carries the fault and its route, never people, secrets or the panel\'s address', function () {
    $panel = (string) config('raqam.admin_path');
    expect($panel)->not->toBe('');
    config(['raqam.api.site_token' => 'site-token-value-0123456789', 'raqam.weather.key' => 'weather-key-abcdef']);
    Route::post("/{$panel}/__boom", fn () => throw new RuntimeException(
        'Saving failed for editor@raqam.example from 198.51.100.7 / 2001:db8::7 at 12:30:45 with site-token-value-0123456789 via https://api.example/x?appid=weather-key-abcdef'));

    $reports = sentryWith(SENTRY_EU_DSN);
    $this->withCookie('__Host-raqam_session', 'session-cookie-value')
        ->withHeaders(['X-Raqam-Site-Token' => 'site-token-value-0123456789', 'X-Raqam-Visitor-Ip' => '198.51.100.7', 'Authorization' => 'Bearer abc', 'User-Agent' => 'Agent/1.0'])
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->post("/{$panel}/__boom?signature=sig123&expires=99", ['email' => 'editor@raqam.example', 'password' => 'hunter2-hunter2'])
        ->assertStatus(500);

    expect($reports->events)->toHaveCount(1);
    $event = $reports->events[0];
    $sent = json_encode([$event->getRequest(), $event->getTransaction(), $event->getUser()?->getIpAddress(), $event->getMessage(),
        array_map(fn ($e) => [$e->getType(), $e->getValue()], $event->getExceptions()), $event->getExtra(), $event->getTags(), $event->getContexts(),
        array_map(fn ($b) => [$b->getMessage(), $b->getMetadata()], $event->getBreadcrumbs())], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // What helps: the exception, its message's shape, the route.
    expect($event->getExceptions()[0]->getType())->toBe(RuntimeException::class)
        ->and($event->getExceptions()[0]->getValue())->toBe('Saving failed for [email] from [ip] / [ip] at 12:30:45 with [hidden] via https://api.example/x?appid=[hidden]')
        ->and($event->getTransaction())->toBe('POST /'.ErrorReports::PANEL.'/__boom')
        ->and($event->getRequest())->toBeIn([[], ['url' => 'http://localhost/'.ErrorReports::PANEL.'/__boom', 'method' => 'POST']])
        ->and($event->getUser())->toBeNull()
        ->and($event->getServerName())->toBeNull();

    // What must never leave.
    foreach ([$panel, 'editor@raqam.example', '198.51.100.7', '2001:db8::7', 'site-token-value-0123456789', 'weather-key-abcdef', 'session-cookie-value',
        'hunter2', 'Bearer', 'sig123', 'Agent/1.0', SENTRY_EU_DSN, 'publickey1234', (string) config('app.key')] as $secret) {
        expect(str_contains($sent, $secret))->toBeFalse("the report holds {$secret}");
    }
    foreach ($event->getExceptions()[0]->getStacktrace()?->getFrames() ?? [] as $frame) {
        expect($frame->getVars())->toBe([]);
    }
});

test('a report from a queued job or a command is cleaned the same way', function () {
    $reports = sentryWith(SENTRY_EU_DSN);
    config(['raqam.newsletter.brevo.key' => 'brevo-key-0123456789']);

    report(new RuntimeException('Brevo refused brevo-key-0123456789 for reader@example.com'));

    expect($reports->events)->toHaveCount(1)
        ->and($reports->events[0]->getExceptions()[0]->getValue())->toBe('Brevo refused [hidden] for [email]');
});

test('every secret in the configuration is found, the longest first', function () {
    config(['raqam.health.token' => 'health-token-xyz-123', 'database.connections.pgsql.password' => 'db-password-1', 'filesystems.disks.r2.secret' => 'r2-secret-value']);

    expect(ErrorReports::secrets())->toContain('health-token-xyz-123', 'db-password-1', 'r2-secret-value', (string) config('app.key'))
        ->and(ErrorReports::text('a db-password-1 b'))->toBe('a [hidden] b')
        ->and(ErrorReports::url('https://x.example/'.config('raqam.admin_path').'/posts/5/edit?token=abc#f'))->toBe('https://x.example/'.ErrorReports::PANEL.'/posts/5/edit');
});

test('a report with full request data, a user and breadcrumbs keeps only the address\'s path and the method', function () {
    $panel = (string) config('raqam.admin_path');
    $event = Event::createEvent();
    $event->setRequest(['url' => "https://api.raqam.example/{$panel}/posts/5?signature=sig123", 'method' => 'POST', 'query_string' => 'signature=sig123',
        'cookies' => ['__Host-raqam_session' => 'session-cookie-value'], 'headers' => ['x-raqam-site-token' => ['site-token']], 'data' => ['password' => 'hunter2'],
        'env' => ['REMOTE_ADDR' => '198.51.100.7']]);
    $event->setUser(UserDataBag::createFromUserIdentifier('7')->setEmail('editor@raqam.example'));
    $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'queue.job', 'Job for reader@example.com', ['to' => 'reader@example.com', 'url' => "/{$panel}/x"])]);
    $event->setExtra(['note' => "from 198.51.100.7 on /{$panel}", 'call' => 'https://api.example/v1?api_key=unlisted-key-42&city=nablus']);
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('x'), new Stacktrace([new Frame('save', 'app/X.php', 10, null, null, ['password' => 'hunter2'])]))]);

    $clean = ErrorReports::event($event);

    expect($clean->getRequest())->toBe(['url' => 'https://api.raqam.example/'.ErrorReports::PANEL.'/posts/5', 'method' => 'POST'])
        ->and($clean->getUser())->toBeNull()
        ->and($clean->getBreadcrumbs()[0]->getMessage())->toBe('Job for [email]')
        ->and($clean->getBreadcrumbs()[0]->getMetadata())->toBe(['to' => '[email]', 'url' => '/'.ErrorReports::PANEL.'/x'])
        ->and($clean->getExtra())->toBe(['note' => 'from [ip] on /'.ErrorReports::PANEL, 'call' => 'https://api.example/v1?api_key=[hidden]&city=nablus'])
        ->and($clean->getExceptions()[0]->getStacktrace()->getFrames()[0]->getVars())->toBe([]);
});
