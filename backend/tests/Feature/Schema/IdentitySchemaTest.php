<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Rules enforced by database/schema/01-identity.sql, ported from database/tests/schema_tests.sql.

test('e-mail lookup is case-insensitive (citext)', function () {
    insertUser('staff', 'Admin@Raqam.ps');

    expect(DB::table('users')->where('email', 'ADMIN@raqam.ps')->exists())->toBeTrue();
});

test('the same e-mail with other letter case is refused', function () {
    insertUser('staff', 'Admin@Raqam.ps');

    expect(rejected(fn () => insertUser('staff', 'ADMIN@raqam.ps')))
        ->toThrow(QueryException::class, '"users_email_live_uq"');
});

test('a deleted account frees its e-mail', function () {
    insertUser('customer', 'ali@example.com', ['deleted_at' => now()]);

    expect(insertUser('customer', 'ali@example.com'))->toBeInt();
});

test('only staff can hold a role', function () {
    $role = DB::table('roles')->insertGetId(['code' => 'admin', 'name_ar' => 'مدير المنصة']);
    $staff = insertUser('staff', 'admin@raqam.ps');
    $customer = insertUser('customer', 'ali@example.com');

    DB::table('user_roles')->insert(['user_id' => $staff, 'role_id' => $role]);

    expect(rejected(fn () => DB::table('user_roles')->insert(['user_id' => $customer, 'role_id' => $role])))
        ->toThrow(QueryException::class, 'Only staff users can hold roles');
});

test('a verification code allows at most 5 attempts', function () {
    expect(rejected(fn () => DB::table('verification_codes')->insert([
        'purpose' => 'admin_login', 'email' => 'admin@raqam.ps', 'code_hash' => 'h',
        'attempts' => 6, 'expires_at' => now()->addMinutes(10),
    ])))->toThrow(QueryException::class, '"verification_codes_attempts_check"');
});

test('a failed login attempt needs a reason', function () {
    expect(rejected(fn () => DB::table('login_attempts')->insert(['email' => 'admin@raqam.ps', 'succeeded' => false])))
        ->toThrow(QueryException::class, '"login_attempts_check"');
});
