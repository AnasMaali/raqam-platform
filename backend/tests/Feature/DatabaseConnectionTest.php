<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;

test('tests run on the raqam_testing PostgreSQL database (raqam_testing_test_N in process N of a parallel run)', function () {
    $row = DB::selectOne('select current_database() as db, version() as version');
    $token = ParallelTesting::token();

    expect(DB::getDriverName())->toBe('pgsql')
        ->and($row->db)->toBe($token === false ? 'raqam_testing' : "raqam_testing_test_{$token}")
        ->and($row->version)->toStartWith('PostgreSQL 16');
});

test('the database session reads timestamps as UTC', function () {
    // Laravel writes timestamps without an offset; any other session time zone shifts every timestamptz.
    expect(DB::selectOne('show timezone')->TimeZone)->toBe('UTC');
});
