<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// A failing statement aborts a PostgreSQL transaction; the savepoint keeps the test's own transaction usable.
function rejected(Closure $statement): Closure
{
    return fn () => DB::transaction($statement);
}

// Reference rows exactly as database/schema/12-seed-reference.sql inserts them. Rows the seed already
// holds are skipped, so every test sees the same state before and after the seed migration.
function ensure(string $table, array ...$rows): void
{
    DB::table($table)->insertOrIgnore($rows);
}

function insertUser(string $type, string $email, array $extra = []): int
{
    return DB::table('users')->insertGetId([
        'user_type' => $type, 'name' => 'User', 'email' => $email, 'password_hash' => 'x', ...$extra,
    ]);
}

// A valid public JPEG; tests override only the column they exercise.
function insertMedia(array $overrides = []): int
{
    static $n = 0;
    $n++;

    return DB::table('media_assets')->insertGetId([
        'path' => "test/{$n}.jpg", 'original_name' => "{$n}.jpg", 'mime_type' => 'image/jpeg',
        'size_bytes' => 1000, 'checksum_sha256' => hash('sha256', (string) $n), 'alt_text' => 'صورة',
        'width' => 800, 'height' => 600, ...$overrides,
    ]);
}
