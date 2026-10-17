<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// The 3-attachment limit locks the booking row before counting. This needs two real
// connections and committed rows, so this file does not use RefreshDatabase; it rebuilds
// the test database before and after instead.

beforeEach(fn () => $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful());
afterEach(function () {
    DB::connection('pgsql_second')->disconnect();
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

test('a concurrent upload waits for the booking lock instead of counting stale rows', function () {
    $booking = insertBooking();
    $file = fn () => insertMedia(['mime_type' => 'application/pdf', 'alt_text' => null, 'width' => null, 'height' => null, 'visibility' => 'private']);
    DB::table('booking_attachments')->insert([['booking_id' => $booking, 'media_id' => $file()], ['booking_id' => $booking, 'media_id' => $file()]]);
    $fourth = $file();

    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    $second = DB::connection('pgsql_second');

    DB::beginTransaction();
    DB::table('booking_attachments')->insert(['booking_id' => $booking, 'media_id' => $file()]);   // 3rd, not committed yet

    $second->statement("SET lock_timeout = '300ms'");
    expect(fn () => $second->table('booking_attachments')->insert(['booking_id' => $booking, 'media_id' => $fourth]))
        ->toThrow(QueryException::class, 'canceling statement due to lock timeout');

    DB::rollBack();
});
