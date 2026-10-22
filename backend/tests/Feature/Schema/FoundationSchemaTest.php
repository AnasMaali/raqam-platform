<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Domains and shared functions from database/schema/00-foundation.sql: one definition of a rule,
// reused by every column of that type.

function cast(string $value, string $domain): mixed
{
    return DB::selectOne("select ?::{$domain} as v", [$value])->v;
}

test('a domain refuses an invalid value', function (string $domain, string $value) {
    expect(rejected(fn () => cast($value, $domain)))
        ->toThrow(QueryException::class, "violates check constraint \"{$domain}_check\"");
})->with([
    'slug with tashkeel' => ['slug_text', 'اقْتِصَاد'],
    'slug with a space' => ['slug_text', 'اقتصاد فلسطين'],
    'slug with a slash' => ['slug_text', 'a/b'],
    'slug in upper case' => ['slug_text', 'Raqam'],
    'e-mail without @' => ['email_address', 'raqam.ps'],
    'local phone number' => ['phone_e164', '0599123456'],
    'ftp url' => ['url_text', 'ftp://raqam.ps'],
    'bad colour' => ['hex_color', '#GG0000'],
    'short digest' => ['sha256_hex', 'abc'],
    'upper-case digest' => ['sha256_hex', str_repeat('A', 64)],
    'negative money' => ['money_minor', '-1'],
    'over 100 %' => ['basis_points', '10001'],
]);

test('a domain accepts a valid value', function (string $domain, string $value) {
    expect((string) cast($value, $domain))->toBe($value);
})->with([
    ['slug_text', 'ارتفاع-الدولار'],
    ['email_address', 'Admin@Raqam.ps'],
    ['phone_e164', '+970599123456'],
    ['url_text', 'https://raqam.ps/podcast'],
    ['hex_color', '#0B3D5C'],
    ['sha256_hex', str_repeat('a', 64)],
    ['money_minor', '12345'],
    ['basis_points', '1600'],
]);

test('document numbers are gap-free per type and year', function () {
    $next = fn (string $type, int $year) => DB::selectOne('select next_document_number(?, ?::smallint) as n', [$type, $year])->n;

    expect([$next('invoice', 2026), $next('invoice', 2026), $next('booking', 2026), $next('invoice', 2027)])
        ->toBe([1, 2, 1, 1]);
});
