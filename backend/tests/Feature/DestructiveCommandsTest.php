<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    // The prohibition is static: never let it leak into the next test.
    DB::prohibitDestructiveCommands(false);
});

test('migrate:fresh rebuilds the schema migrations more than once', function () {
    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->artisan('migrate:fresh')->assertSuccessful();

    expect(DB::selectOne("select normalize_ar('أسعار') as v")->v)->toBe('اسعار');
});

test('db:wipe leaves the public schema exactly as in a new database', function () {
    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->artisan('db:wipe')->assertSuccessful();

    $objects = DB::selectOne(<<<'SQL'
        select (select count(*) from pg_class where relnamespace = 'public'::regnamespace)
             + (select count(*) from pg_proc where pronamespace = 'public'::regnamespace)
             + (select count(*) from pg_type where typnamespace = 'public'::regnamespace)
             + (select count(*) from pg_extension where extnamespace = 'public'::regnamespace) as total
        SQL)->total;
    $schema = DB::selectOne(<<<'SQL'
        select nspowner::regrole::text as owner, nspacl::text as acl,
               obj_description(oid, 'pg_namespace') as comment
        from pg_namespace where nspname = 'public'
        SQL);

    expect($objects)->toBe(0)
        ->and($schema->owner)->toBe('pg_database_owner')
        ->and($schema->acl)->toBe('{pg_database_owner=UC/pg_database_owner,=U/pg_database_owner}')
        ->and($schema->comment)->toBe('standard public schema');

    $this->artisan('migrate')->assertSuccessful();
});

test('destructive commands are refused in production, even with --force', function (string $command) {
    $this->artisan('migrate:fresh')->assertSuccessful();
    DB::table('document_counters')->insert(['doc_type' => 'invoice', 'year' => 2026, 'last_value' => 7]);

    app()->detectEnvironment(fn () => 'production');
    (new AppServiceProvider(app()))->boot();

    $this->artisan($command, ['--force' => true])->assertFailed();

    expect(DB::table('document_counters')->value('last_value'))->toBe(7);
})->with(['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback']);
