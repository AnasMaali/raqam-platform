<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Every moment is stored as timestamptz: a plain timestamp (or time) means nothing without the zone it was read in,
 * and the server, the database and Palestine (Asia/Hebron, with its summer time) do not share one. A column without a
 * zone is allowed only with its reason written here.
 *
 * Plain dates are days, not moments: each one below is a Palestine calendar day (the day a count, a forecast or a
 * meeting belongs to), worked out from a timestamptz in Asia/Hebron before it is written.
 */
const ZONELESS_TIME_COLUMNS = [];

const PALESTINE_DAY_COLUMNS = [
    'ad_stats_daily.stat_date' => 'the Palestine day the views and clicks were counted on',
    'device_stats_daily.stat_date' => 'the Palestine day of the visits',
    'event_stats_daily.stat_date' => 'the Palestine day of the events',
    'newsletter_issues.week_start' => 'the Saturday the weekly issue starts on, in Palestine',
    'post_views_daily.stat_date' => 'the Palestine day of the views',
    'rate_board.rate_date' => 'the Palestine day of a rate (a view: effective_at in Asia/Hebron)',
    'roundtable_sessions.held_on' => 'the day the session was held, as the editors write it',
    'search_stats_daily.stat_date' => 'the Palestine day of the searches',
    'service_contact_clicks_daily.stat_date' => 'the Palestine day of the clicks',
    'site_stats_daily.stat_date' => 'the Palestine day of the visits',
    'traffic_sources_daily.stat_date' => 'the Palestine day of the visits',
    'video_plays_daily.stat_date' => 'the Palestine day of the plays',
    'weather_forecasts.forecast_date' => 'the Palestine day the forecast is for',
];

/** @return list<string> table.column of every column of the given types, in tables and views alike */
function columnsOfType(array $types): array
{
    return collect(DB::select(
        'SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = ? AND data_type = ANY(?) ORDER BY 1, 2',
        ['public', '{'.implode(',', array_map(fn ($t) => '"'.$t.'"', $types)).'}'],
    ))->map(fn ($c) => "{$c->table_name}.{$c->column_name}")->all();
}

test('every moment is a timestamptz: no timestamp or time without a zone, unless its reason is written', function () {
    expect(columnsOfType(['timestamp without time zone', 'time without time zone']))
        ->toBe(array_keys(ZONELESS_TIME_COLUMNS));
});

test('every plain date is a Palestine calendar day with its reason written, and nothing else is a date', function () {
    $dates = columnsOfType(['date']);
    $listed = array_keys(PALESTINE_DAY_COLUMNS);
    sort($listed);

    expect($dates)->toBe($listed);
});

test('the database session itself speaks UTC, so a timestamptz is never read in the server\'s zone by accident', function () {
    expect(DB::selectOne('SHOW timezone')->TimeZone)->toBe('UTC');
});
