<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Reference rows from database/schema/12-seed-reference.sql that constraints and code rely on.

test('the seed provides the reference rows', function (string $table, int $rows) {
    expect(DB::table($table)->count())->toBe($rows);
})->with([
    ['currencies', 8], ['rate_board_currencies', 7], ['currency_pegs', 2], ['cities', 12], ['weather_locations', 12],
    ['data_providers', 6], ['provider_health', 6], ['social_platforms', 8], ['sections', 7], ['categories', 6],
    ['post_kinds', 11], ['guest_roles', 3], ['pose_types', 5], ['awareness_types', 7], ['ad_placements', 6],
    ['service_groups', 2], ['booking_statuses', 9], ['booking_status_transitions', 15], ['payment_gateways', 2],
    ['feature_flags', 9], ['roles', 1], ['menus', 3], ['traffic_sources', 11], ['event_types', 6], ['settings', 13],
    ['weather_conditions', 55],
]);

test('every section has exactly one post kind', function () {
    $kinds = DB::table('sections')->leftJoin('post_kinds', 'post_kinds.section_id', '=', 'sections.id')
        ->groupBy('sections.key')->selectRaw('sections.key, count(post_kinds.code) as kinds')->pluck('kinds', 'key');

    expect($kinds->count())->toBe(7)->and($kinds->unique()->values()->all())->toBe([1]);
});

test('Nablus is the default weather city', function () {
    expect(DB::table('weather_locations')->join('cities', 'cities.id', '=', 'weather_locations.city_id')
        ->where('is_default', true)->pluck('slug')->all())->toBe(['نابلس']);
});

test('only the chatbot is switched on at launch', function () {
    expect(DB::table('feature_flags')->where('is_enabled', true)->pluck('key')->all())->toBe(['chatbot']);
});

test('each service has one active primary provider and the paid currency backup is off', function () {
    expect(DB::table('data_providers')->where('is_active', true)->where('is_backup', false)->pluck('code', 'service')->all())
        ->toEqualCanonicalizing([
            'currency' => 'bank_of_israel', 'weather' => 'openweathermap', 'video' => 'youtube',
            'email' => 'purelymail', 'newsletter' => 'brevo',
        ])
        ->and(DB::table('data_providers')->where('code', 'exchangerate_api')->value('is_active'))->toBeFalse();
});

test('every booking status except the terminal ones can move on', function () {
    $stuck = DB::table('booking_statuses')->where('is_terminal', false)
        ->whereNotExists(fn ($q) => $q->from('booking_status_transitions')->whereColumn('from_code', 'booking_statuses.code'))
        ->pluck('code');

    expect($stuck)->toBeEmpty();
});

test('a new booking can reach published without being cancelled', function () {
    $reachable = collect(DB::select(<<<'SQL'
        WITH RECURSIVE path(code) AS (
          SELECT 'new'::varchar
          UNION
          SELECT t.to_code FROM booking_status_transitions t JOIN path p ON t.from_code = p.code
          WHERE t.to_code <> 'cancelled')
        SELECT code FROM path
        SQL))->pluck('code');

    expect($reachable)->toContain('published', 'paid', 'completed');
});

test('seeded typed settings are valid for their type', function () {
    expect(DB::table('settings')->where('key', 'newsletter.send_time')->value('value'))->toBe('09:00')
        ->and(DB::table('settings')->where('key', 'audit.retention_days')->value('value'))->toBe('730');
});
