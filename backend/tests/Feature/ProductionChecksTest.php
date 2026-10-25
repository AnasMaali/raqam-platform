<?php

use App\Providers\AppServiceProvider;
use App\Support\ProductionChecks;

test('a complete production configuration passes', function () {
    productionConfig();

    expect(ProductionChecks::failures())->toBe([]);
    ProductionChecks::enforce();
});

test('each unsafe setting is refused, and only that one', function (array $spoil, string $mentions) {
    productionConfig($spoil);

    $failures = ProductionChecks::failures();

    expect($failures)->toHaveCount(1)->and($failures[0])->toContain($mentions);
    expect(fn () => ProductionChecks::enforce())->toThrow(RuntimeException::class, $mentions);
})->with([
    'debug on' => [['app.debug' => true], 'APP_DEBUG'],
    'mail log' => [['mail.default' => 'log'], 'MAIL_MAILER'],
    'mail array' => [['mail.default' => 'array'], 'MAIL_MAILER'],
    'session cookie not Secure' => [['session.secure' => false], 'Secure'],
    'session cookie Secure unset' => [['session.secure' => null], 'Secure'],
    'session cookie not HttpOnly' => [['session.http_only' => false], 'HttpOnly'],
    'session cookie SameSite lax' => [['session.same_site' => 'lax'], 'SameSite=Strict'],
    'session cookie without __Host-' => [['session.cookie' => 'raqam_session'], '__Host-'],
    'session cookie with a domain' => [['session.domain' => '.raqam.ps'], '__Host-'],
    'session cookie with a path' => [['session.path' => '/admin'], '__Host-'],
    'session longer than the idle limit' => [['session.lifetime' => 120], 'SESSION_LIFETIME'],
    'test-only shorter idle limit' => [['raqam.auth.idle_seconds' => 20], 'ADMIN_IDLE_SECONDS'],
    'test-only YouTube stand-in (api)' => [['raqam.youtube.api_base' => 'http://127.0.0.1:9'], 'YOUTUBE_API_BASE'],
    'test-only YouTube stand-in (pictures)' => [['raqam.youtube.image_base' => 'http://127.0.0.1:9'], 'YOUTUBE_API_BASE'],
    'test-only shorter warning' => [['raqam.auth.warn_seconds' => 5], 'ADMIN_WARN_SECONDS'],
    'no APP_KEY' => [['app.key' => null], 'APP_KEY'],
    'no ADMIN_CODE_KEY' => [['raqam.auth.code_key' => null], 'ADMIN_CODE_KEY'],
    'no TRACKING_HASH_KEY' => [['raqam.tracking_key' => ''], 'TRACKING_HASH_KEY'],
    'no ADMIN_PATH' => [['raqam.admin_path' => null], 'ADMIN_PATH'],
    'no TURNSTILE_SECRET_KEY' => [['raqam.turnstile.secret' => null], 'TURNSTILE_SECRET_KEY'],
    'no API_SITE_TOKEN' => [['raqam.api.site_token' => ''], 'API_SITE_TOKEN'],
    'no SITE_URL' => [['raqam.site_url' => null], 'SITE_URL'],
    'SITE_URL over http' => [['raqam.site_url' => 'http://raqam.example'], 'SITE_URL'],
    'test-only Brevo stand-in' => [['raqam.newsletter.brevo.api_base' => 'http://127.0.0.1:9'], 'BREVO_API_BASE'],
    'Brevo without a list' => [['raqam.newsletter.brevo.key' => 'xkeysib-x', 'raqam.newsletter.brevo.list_id' => null, 'raqam.newsletter.brevo.webhook_token' => str_repeat('t', 32)], 'BREVO_LIST_ID'],
    'Brevo without a webhook token' => [['raqam.newsletter.brevo.key' => 'xkeysib-x', 'raqam.newsletter.brevo.list_id' => '3', 'raqam.newsletter.brevo.webhook_token' => 'short'], 'BREVO_WEBHOOK_TOKEN'],
    'test-only Turnstile stand-in' => [['raqam.turnstile.verify_url' => 'http://127.0.0.1:9'], 'TURNSTILE_VERIFY_URL'],
]);

test('every problem is listed at once and no value is revealed', function () {
    productionConfig(['app.debug' => true, 'mail.default' => 'log', 'raqam.auth.code_key' => null, 'app.key' => 'base64:SECRETVALUE']);

    $message = collect(ProductionChecks::failures())->implode(' ');

    expect(ProductionChecks::failures())->toHaveCount(3)->and($message)->not->toContain('SECRETVALUE');
});

test('the application refuses to boot in production with an unsafe configuration', function () {
    productionConfig(['mail.default' => 'log']);
    $this->app['env'] = 'production';

    expect(fn () => (new AppServiceProvider($this->app))->boot())->toThrow(RuntimeException::class, 'MAIL_MAILER');
});

test('it boots in production when the configuration is complete', function () {
    productionConfig();
    $this->app['env'] = 'production';

    (new AppServiceProvider($this->app))->boot();

    expect(true)->toBeTrue();
});

test('it does not stop in other environments', function () {
    productionConfig(['app.debug' => true, 'mail.default' => 'log']);

    (new AppServiceProvider($this->app))->boot();

    expect(true)->toBeTrue();
});

test('the image build commands run without secrets, everything else is checked', function () {
    expect(ProductionChecks::appliesTo(true, 'package:discover'))->toBeFalse()
        ->and(ProductionChecks::appliesTo(true, 'config:cache'))->toBeFalse()
        ->and(ProductionChecks::appliesTo(true, 'queue:work'))->toBeTrue()
        ->and(ProductionChecks::appliesTo(true, 'schedule:run'))->toBeTrue()
        ->and(ProductionChecks::appliesTo(true, 'migrate'))->toBeTrue()
        ->and(ProductionChecks::appliesTo(false, null))->toBeTrue();
});

test('the shipped defaults already satisfy the session checks, and .env.example weakens none of them', function () {
    $config = require base_path('config/session.php');
    $example = Dotenv\Dotenv::parse(file_get_contents(base_path('.env.example')));

    expect($config['cookie'])->toBe(env('SESSION_COOKIE', '__Host-raqam_session'))
        ->and($config['path'])->toBe('/');
    expect($example['SESSION_LIFETIME'])->toBe('30')
        ->and($example)->not->toHaveKeys(['SESSION_SECURE_COOKIE', 'SESSION_SAME_SITE', 'SESSION_COOKIE', 'SESSION_HTTP_ONLY'])
        ->and($example['SESSION_DOMAIN'])->toBe('null');
});

test('production refuses any password hashing but Argon2id (24.6)', function () {
    productionConfig(['hashing.driver' => 'bcrypt']);
    expect(ProductionChecks::failures())->toContain('HASH_DRIVER must be argon2id (passwords are hashed with Argon2id).');

    productionConfig(['hashing.driver' => 'argon2id']);
    expect(implode("\n", ProductionChecks::failures()))->not->toContain('HASH_DRIVER');
});
