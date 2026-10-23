<?php

namespace Tests;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;

abstract class TestCase extends BaseTestCase
{
    /** Commands that drop or rebuild the whole schema: one at a time across the processes of a parallel run. */
    private const SCHEMA_REBUILDS = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'];

    /** @var resource|null */
    private static $schemaLock = null;

    /**
     * Dropping the schema locks every one of its ~2,500 objects in one transaction, and PostgreSQL keeps all locks in
     * one shared table (max_locks_per_transaction × max_connections, 6,400 by default). Several processes rebuilding at
     * once overflow it ("out of shared memory"), so in a parallel run they take turns behind a file lock.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if (ParallelTesting::token() !== false) {
            $kernel = $app[ConsoleKernel::class];
            $kernel->rerouteSymfonyCommandEvents();
            $kernel->setArtisan(null);
            $app['events']->listen(CommandStarting::class, function (CommandStarting $event) {
                if (in_array($event->command, self::SCHEMA_REBUILDS, true) && self::$schemaLock === null) {
                    self::$schemaLock = fopen(sys_get_temp_dir().'/raqam-pest-schema.lock', 'c');
                    flock(self::$schemaLock, LOCK_EX);
                }
            });
            $app['events']->listen(CommandFinished::class, function (CommandFinished $event) {
                if (in_array($event->command, self::SCHEMA_REBUILDS, true) && self::$schemaLock !== null) {
                    flock(self::$schemaLock, LOCK_UN);
                    fclose(self::$schemaLock);
                    self::$schemaLock = null;
                }
            });
        }

        return $app;
    }

    /**
     * In a parallel run (php artisan test --parallel) each process has its own database, raqam_testing_test_N, built by
     * the migrations, and its own Redis prefix. Laravel moves the tests that use RefreshDatabase to that database; the few
     * that rebuild the database themselves (migrate:fresh, two real connections) are moved here, so within a process
     * everything happens as in a serial run on raqam_testing. Processes share the Redis test databases 6/7/8, so a key
     * one process writes is never read or removed by another, and nothing empties a whole Redis database to stay apart.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Livewire marks "a component was rendered in this request" in a static, cleared when a request ends. A test that
        // renders a component without a request leaves it set, and the next test's first HTML page would get Livewire's
        // script added, whatever that page is: which test came before must never change what a page holds.
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        SupportAutoInjectedAssets::$forceAssetInjection = false;

        if (($token = ParallelTesting::token()) !== false) {
            $this->useProcessDatabase((string) $token);
            config([
                'database.redis.options.prefix' => config('database.redis.options.prefix')."p{$token}:",
                'cache.prefix' => config('cache.prefix')."p{$token}:",
            ]);
            // The Redis manager keeps the configuration it was built with: build it again, and the cache store on it.
            $this->app->forgetInstance('redis');
            Redis::clearResolvedInstance('redis');
            $this->app['cache']->forgetDriver('redis');
        }
    }

    private function useProcessDatabase(string $token): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        if (str_ends_with($database, "_test_{$token}")) {
            return;
        }
        $own = "{$database}_test_{$token}";
        config(["database.connections.{$connection}.database" => $own]);
        DB::purge($connection);
        try {
            DB::connection($connection)->getPdo();
        } catch (QueryException|\PDOException) {
            config(["database.connections.{$connection}.database" => $database]);
            DB::purge($connection);
            Schema::createDatabase($own);
            config(["database.connections.{$connection}.database" => $own]);
            DB::purge($connection);
        }
    }
}
