<?php

use Illuminate\Support\Facades\DB;

test('tests run on the raqam_testing PostgreSQL database', function () {
    $row = DB::selectOne('select current_database() as db, version() as version');

    expect(DB::getDriverName())->toBe('pgsql')
        ->and($row->db)->toBe('raqam_testing')
        ->and($row->version)->toStartWith('PostgreSQL 16');
});

test('the database session reads timestamps as UTC', function () {
    // Laravel writes timestamps without an offset; any other session time zone shifts every timestamptz.
    expect(DB::selectOne('show timezone')->TimeZone)->toBe('UTC');
});
