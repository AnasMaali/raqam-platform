<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Rules enforced by database/schema/03-reference.sql, ported from database/tests/schema_tests.sql.

function ensurePaymentFlags(): void
{
    ensure('feature_flags', ['key' => 'booking', 'description_ar' => 'نظام الحجز الإلكتروني', 'is_enabled' => false, 'depends_on' => null]);
    ensure('feature_flags', ['key' => 'online_payments', 'description_ar' => 'الدفع الإلكتروني', 'is_enabled' => false, 'depends_on' => 'booking']);
}

function setFlag(string $key, bool $enabled): void
{
    DB::table('feature_flags')->where('key', $key)->update(['is_enabled' => $enabled]);
}

test('payments cannot be switched on while booking is off', function () {
    ensurePaymentFlags();

    expect(rejected(fn () => setFlag('online_payments', true)))
        ->toThrow(QueryException::class, 'Feature "online_payments" requires "booking" to be enabled first');
});

test('booking cannot be switched off while payments are on', function () {
    ensurePaymentFlags();
    setFlag('booking', true);
    setFlag('online_payments', true);

    expect(rejected(fn () => setFlag('booking', false)))
        ->toThrow(QueryException::class, 'Disable the features that depend on "booking" first');
});

test('a feature cannot depend on itself', function () {
    expect(rejected(fn () => DB::table('feature_flags')->insert([
        'key' => 'loop', 'description_ar' => 'حلقة', 'depends_on' => 'loop',
    ])))->toThrow(QueryException::class, '"feature_flags_check"');
});

test('a service has one active primary provider', function () {
    ensure('data_providers',
        ['code' => 'bank_of_israel', 'service' => 'currency', 'name' => 'Bank of Israel representative rates (official, free)', 'is_active' => true, 'is_backup' => false],
        ['code' => 'exchangerate_api', 'service' => 'currency', 'name' => 'ExchangeRate-API Pro (paid, hourly) — not active', 'is_active' => false, 'is_backup' => true],
    );

    expect(rejected(fn () => DB::table('data_providers')->where('code', 'exchangerate_api')
        ->update(['is_active' => true, 'is_backup' => false])))
        ->toThrow(QueryException::class, '"data_providers_one_primary_uq"');
});

test('a currency code is three capital letters', function () {
    expect(rejected(fn () => DB::table('currencies')->insert(['code' => 'usd', 'name_ar' => 'عملة', 'symbol' => '$'])))
        ->toThrow(QueryException::class, '"currencies_code_check"');
});

test('a setting value matches its type', function (string $type, string $value, string $constraint) {
    expect(rejected(fn () => DB::table('settings')->insert(['key' => 'test.value', 'value' => $value, 'value_type' => $type])))
        ->toThrow(QueryException::class, "\"{$constraint}\"");
})->with([
    'integer' => ['integer', '12a', 'settings_check'],
    'boolean' => ['boolean', 'yes', 'settings_check1'],
    'url' => ['url', 'ftp://raqam.ps', 'settings_check2'],
    'time' => ['time', '24:00', 'settings_check3'],
    'media' => ['media', 'cover.jpg', 'settings_check4'],
    'email (email_address domain)' => ['email', 'raqam.ps', 'email_address_check'],
    'phone (phone_e164 domain)' => ['phone', '0599123456', 'phone_e164_check'],
]);

test('a valid typed setting is accepted', function (string $type, string $value) {
    DB::table('settings')->insert(['key' => "test.{$type}", 'value' => $value, 'value_type' => $type]);

    expect(DB::table('settings')->where('key', "test.{$type}")->value('value'))->toBe($value);
})->with([
    ['integer', '-15'], ['boolean', 'true'], ['url', 'https://raqam.ps'], ['time', '23:59'], ['media', '42'],
    ['email', 'info@raqam.ps'], ['phone', '+970599123456'],
]);

test('a setting key is group.name', function () {
    expect(rejected(fn () => DB::table('settings')->insert(['key' => 'no_group', 'value' => 'x', 'value_type' => 'string'])))
        ->toThrow(QueryException::class, '"settings_key_check"');
});
