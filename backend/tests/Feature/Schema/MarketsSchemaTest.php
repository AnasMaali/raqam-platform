<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Rules enforced by database/schema/07-markets.sql, ported from database/tests/schema_tests.sql.

function ensureRateBoard(): void
{
    ensureCurrencies();
    ensure('rate_board_currencies',
        ['currency_code' => 'USD', 'sort_order' => 1, 'display_decimals' => 3],
        ['currency_code' => 'JOD', 'sort_order' => 2, 'display_decimals' => 3],
        ['currency_code' => 'AED', 'sort_order' => 6, 'display_decimals' => 3],
        ['currency_code' => 'SAR', 'sort_order' => 7, 'display_decimals' => 3],
    );
    ensure('currency_pegs',
        ['currency_code' => 'AED', 'anchor_code' => 'USD', 'units_per_anchor' => 3.6725],
        ['currency_code' => 'SAR', 'anchor_code' => 'USD', 'units_per_anchor' => 3.75],
    );
}

// Rates are written with the database clock (now() is fixed inside the test transaction).
function insertRate(string $currency, float $mid, string $effectiveAt = 'now()'): void
{
    DB::table('exchange_rates')->insert([
        'currency_code' => $currency, 'provider_code' => 'bank_of_israel', 'mid_rate' => $mid, 'effective_at' => DB::raw($effectiveAt),
    ]);
}

function insertOverride(string $currency, float $buy, float $sell, string $from = 'now()', ?string $until = null): void
{
    DB::table('rate_overrides')->insert([
        'currency_code' => $currency, 'buy_rate' => $buy, 'sell_rate' => $sell,
        'valid_from' => DB::raw($from), 'valid_until' => $until === null ? null : DB::raw($until),
    ]);
}

function board(string $currency): object
{
    return DB::selectOne('select buy_rate::text, sell_rate::text, mid_rate::text, previous_rate::text, change_pct::text, is_manual, is_pegged from rate_board where currency_code = ?', [$currency]);
}

beforeEach(function () {
    ensureRateBoard();
    insertRate('USD', 3.60, "now() - interval '1 day'");
    insertRate('USD', 3.75);
});

test('rate_board applies the spread to the latest official rate', function () {
    expect(board('USD'))
        ->buy_rate->toBe('3.731250')
        ->sell_rate->toBe('3.768750')
        ->is_manual->toBeFalse();
});

test('rate_board compares with the previous publication day', function () {
    expect(board('USD'))->previous_rate->toBe('3.6000000000')->change_pct->toBe('4.17');
});

test('a second rate on the same day is not the previous day', function () {
    insertRate('USD', 3.70, "now() - interval '1 second'");

    expect(board('USD'))->previous_rate->toBe('3.6000000000');
});

test('a manual override wins while it is valid', function () {
    insertOverride('USD', 3.70, 3.80, 'now()', "now() + interval '2 hours'");

    expect(board('USD'))->buy_rate->toBe('3.7000000000')->sell_rate->toBe('3.8000000000')->is_manual->toBeTrue();
});

test('an expired override no longer applies', function () {
    insertOverride('USD', 3.70, 3.80, "now() - interval '3 hours'", "now() - interval '1 hour'");

    expect(board('USD'))->buy_rate->toBe('3.731250')->is_manual->toBeFalse();
});

test('two overrides for one currency cannot overlap in time (EXCLUDE)', function () {
    insertOverride('USD', 3.70, 3.80, 'now()', "now() + interval '2 hours'");

    expect(rejected(fn () => insertOverride('USD', 3.6, 3.9, "now() + interval '1 hour'")))
        ->toThrow(QueryException::class, '"rate_overrides_no_overlap"');
});

test('an override may start exactly when the previous one ends', function () {
    insertOverride('USD', 3.70, 3.80, 'now()', "now() + interval '2 hours'");
    insertOverride('USD', 3.60, 3.90, "now() + interval '2 hours'");

    expect(DB::table('rate_overrides')->count())->toBe(2);
});

test('an override cannot sell below its buy rate', function () {
    expect(rejected(fn () => insertOverride('JOD', 5.3, 5.2)))
        ->toThrow(QueryException::class, '"rate_overrides_check"');
});

test('the shekel has no rate against itself', function () {
    expect(rejected(fn () => insertRate('ILS', 1)))
        ->toThrow(QueryException::class, '"exchange_rates_currency_code_check"');
});

test('pegged currencies are derived from their anchor', function () {
    expect(board('AED'))->mid_rate->toStartWith('1.0211')->is_pegged->toBeTrue()
        ->and(board('SAR'))->mid_rate->toBe('1.0000000000');
});

test('a pegged currency cannot be fetched separately', function () {
    expect(rejected(fn () => insertRate('AED', 1.02)))
        ->toThrow(QueryException::class, 'AED is pegged; its rate is derived from its anchor currency');
});

test('a currency cannot be pegged to itself', function () {
    expect(rejected(fn () => DB::table('currency_pegs')->insert(['currency_code' => 'JOD', 'anchor_code' => 'JOD', 'units_per_anchor' => 1])))
        ->toThrow(QueryException::class, '"currency_pegs_check"');
});

test('the shekel is never on the board', function () {
    expect(rejected(fn () => DB::table('rate_board_currencies')->insert(['currency_code' => 'ILS'])))
        ->toThrow(QueryException::class, '"rate_board_currencies_currency_code_check"');
});

test('an inactive currency leaves the board', function () {
    DB::table('rate_board_currencies')->where('currency_code', 'USD')->update(['is_active' => false]);

    expect(DB::table('rate_board')->where('currency_code', 'USD')->exists())->toBeFalse();
});

describe('weather', function () {
    beforeEach(function () {
        ensure('cities',
            ['name_ar' => 'نابلس', 'slug' => 'نابلس', 'latitude' => 32.221100, 'longitude' => 35.254400],
            ['name_ar' => 'جنين', 'slug' => 'جنين', 'latitude' => 32.464600, 'longitude' => 35.293900],
        );
        DB::statement("INSERT INTO weather_locations (city_id, is_default, sort_order)
            SELECT id, slug = 'نابلس', row_number() OVER (ORDER BY id) FROM cities WHERE slug IN ('نابلس', 'جنين')
            ON CONFLICT DO NOTHING");
        ensure('weather_conditions', ['code' => 800, 'description_ar' => 'صافٍ', 'icon_key' => 'clear']);
    });

    test('only one city is the default', function () {
        expect(rejected(fn () => DB::table('weather_locations')
            ->where('city_id', DB::table('cities')->where('slug', 'جنين')->value('id'))->update(['is_default' => true])))
            ->toThrow(QueryException::class, '"weather_locations_one_default_uq"');
    });

    test('an observation uses a known condition code', function () {
        expect(rejected(fn () => DB::table('weather_observations')->insert([
            'city_id' => DB::table('cities')->where('slug', 'نابلس')->value('id'), 'provider_code' => 'openweathermap',
            'observed_at' => now(), 'temperature_c' => 22, 'condition_code' => 9999, 'is_day' => true,
        ])))->toThrow(QueryException::class, '"weather_observations_condition_code_fkey"');
    });

    test('a forecast maximum is not below its minimum', function () {
        expect(rejected(fn () => DB::table('weather_forecasts')->insert([
            'city_id' => DB::table('cities')->where('slug', 'نابلس')->value('id'), 'forecast_date' => '2026-10-20',
            'temp_min_c' => 20, 'temp_max_c' => 15, 'condition_code' => 800, 'provider_code' => 'openweathermap',
        ])))->toThrow(QueryException::class, '"weather_forecasts_check"');
    });
});
