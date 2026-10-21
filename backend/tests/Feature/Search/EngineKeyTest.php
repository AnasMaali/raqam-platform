<?php

use App\Jobs\SyncSearchDocuments;
use App\Search\MeiliClient;
use App\Search\SearchSync;
use App\Search\SearchUnavailable;
use App\Support\ProductionChecks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const MEILI_TEST_SECRET = 'very-secret-meili-key-0123456789';

beforeEach(function () {
    Http::swap(new Factory);
    config(['raqam.search.host' => 'http://search.test', 'raqam.search.key' => MEILI_TEST_SECRET, 'raqam.search.prefix' => 'raqam_']);
});

// ---- the application never runs on the master key ----------------------------------------------------

test('search:setup refuses a key that can manage API keys (the master key)', function () {
    Http::fake(['search.test/keys*' => Http::response(['results' => []]), '*' => Http::response(['taskUid' => 1])]);

    $this->artisan('search:setup')->expectsOutputToContain('master key')->assertFailed();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/indexes'));
});

test('search:setup goes on with a scoped key', function () {
    Http::fake([
        'search.test/keys*' => Http::response(['message' => 'The provided API key is invalid.', 'code' => 'invalid_api_key'], 403),
        'search.test/tasks/*' => Http::response(['status' => 'succeeded']),
        '*' => Http::response(['taskUid' => 1], 202),
    ]);

    $this->artisan('search:setup')->assertSuccessful();

    Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->url() === 'http://search.test/indexes/raqam_posts/settings');
});

test('search:create-key makes a key limited to the prefix, with no global action, and needs the master key', function () {
    putenv('MEILISEARCH_MASTER_KEY=the-master');
    Http::fake([
        'search.test/keys?*' => Http::response(['results' => []]),
        'search.test/version' => Http::response(['pkgVersion' => '1.54.3']),
        'search.test/keys' => Http::response(['key' => 'the-new-scoped-key', 'uid' => 'u'], 201),
    ]);
    $env = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($env, "APP_NAME=x\nMEILISEARCH_KEY=old\n");

    try {
        $this->artisan('search:create-key', ['--prefix' => 'raqam_', '--env-file' => $env])
            ->doesntExpectOutputToContain('the-new-scoped-key')->assertSuccessful();

        expect(file_get_contents($env))->toBe("APP_NAME=x\nMEILISEARCH_KEY=the-new-scoped-key\nMEILISEARCH_PREFIX=raqam_\n");
        Http::assertSent(function ($r) {
            if ($r->method() !== 'POST' || $r->url() !== 'http://search.test/keys') {
                return false;
            }
            expect($r['indexes'])->toBe(['raqam_*'])
                ->and($r['actions'])->not->toContain('*')->not->toContain('keys.create')->not->toContain('keys.get')
                ->not->toContain('dumps.create')->not->toContain('snapshots.create')->not->toContain('version')
                ->and($r->header('Authorization')[0])->toBe('Bearer the-master');

            return true;
        });
    } finally {
        putenv('MEILISEARCH_MASTER_KEY');
        unlink($env);
    }
});

test('search:create-key refuses a key that is not the master key, and another engine version', function () {
    putenv('MEILISEARCH_MASTER_KEY=not-master');
    Http::fake(['search.test/keys*' => Http::response(['code' => 'invalid_api_key'], 403)]);
    $this->artisan('search:create-key', ['--prefix' => 'raqam_'])->expectsOutputToContain('not the master key')->assertFailed();

    Http::swap(new Factory);
    Http::fake(['search.test/keys*' => Http::response(['results' => []]), 'search.test/version' => Http::response(['pkgVersion' => '1.60.0'])]);
    $this->artisan('search:create-key', ['--prefix' => 'raqam_'])->expectsOutputToContain('pinned to 1.54.3')->assertFailed();
    Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    putenv('MEILISEARCH_MASTER_KEY');
});

test('search:create-key refuses a prefix that is not plain', function () {
    $this->artisan('search:create-key', ['--prefix' => '*'])->assertExitCode(2);
});

// ---- the key never leaves the class --------------------------------------------------------------

test('the key travels only in the Authorization header, never in an address', function () {
    Http::fake(['*' => Http::response(['results' => [['hits' => []]]])]);

    MeiliClient::fromConfig()->multiSearch([['indexUid' => 'raqam_posts', 'q' => 'x']]);

    Http::assertSent(fn ($r) => ! str_contains($r->url(), MEILI_TEST_SECRET) && ! str_contains($r->body(), MEILI_TEST_SECRET) && $r->header('Authorization')[0] === 'Bearer '.MEILI_TEST_SECRET);
});

test('no failure message carries the key or the engine answer', function () {
    $answers = [
        fn () => throw new ConnectionException('cURL error 7 for http://search.test with Bearer '.MEILI_TEST_SECRET),
        fn () => Http::response(['message' => 'echo '.MEILI_TEST_SECRET, 'code' => 'invalid_api_key '.MEILI_TEST_SECRET], 403),
        fn () => Http::response('<html>'.MEILI_TEST_SECRET.'</html>', 502),
    ];
    foreach ($answers as $answer) {
        Http::swap(new Factory);
        Http::fake(['*' => $answer]);
        try {
            MeiliClient::fromConfig()->multiSearch([['indexUid' => 'raqam_posts', 'q' => 'x']]);
            $this->fail('should have failed');
        } catch (SearchUnavailable $e) {
            expect($e->getMessage())->not->toContain(MEILI_TEST_SECRET)->not->toContain('echo')->not->toContain('<html>');
        }
    }
});

test('with the engine off nothing is queued at all', function () {
    config(['raqam.search.key' => '']);
    Queue::fake();

    SearchSync::posts([1, 2]);
    SearchSync::queue('videos', [3]);

    Queue::assertNothingPushed();
    expect(fn () => (new SyncSearchDocuments('posts', [1]))->handle())->not->toThrow(Throwable::class);
});

// ---- production -------------------------------------------------------------------------------------

test('production refuses to start without the search key or with a bad prefix', function () {
    productionConfig();
    expect(ProductionChecks::failures())->toBe([]);

    productionConfig(['raqam.search.key' => '']);
    expect(ProductionChecks::failures())->toContain('MEILISEARCH_KEY (a scoped key from search:create-key) and MEILISEARCH_PREFIX must be set.');

    productionConfig(['raqam.search.prefix' => 'a b*']);
    expect(implode(' ', ProductionChecks::failures()))->toContain('MEILISEARCH_PREFIX');
});
