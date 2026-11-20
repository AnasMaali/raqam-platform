-- =====================================================================
--  Raqam schema — rule tests for 07-markets.sql
--  Breaks the CHECKs of the currency board (board currencies, fetched
--  rates, pegs, manual overrides) and of weather readings and forecasts
--  that schema_tests.sql does not reach. The seeded board and weather
--  rows are shared with other files, so every row that would change
--  what the board or the weather shows is written inside expect_fail or
--  BEGIN ... ROLLBACK. Runs in the same session, after schema_tests.sql.
-- =====================================================================

-- ---------------------------------------------------------------- board currencies
SELECT pg_temp.expect_fail('rate_board_currencies: ILS is the quote currency, never on the board',
  $$INSERT INTO rate_board_currencies (currency_code) VALUES ('ILS')$$, 'rate_board_currencies_currency_code_check');
SELECT pg_temp.expect_fail('rate_board_currencies: more than 6 decimals',
  $$UPDATE rate_board_currencies SET display_decimals = 7 WHERE currency_code = 'USD'$$, 'rate_board_currencies_display_decimals_check');
SELECT pg_temp.expect_fail('rate_board_currencies: negative decimals',
  $$UPDATE rate_board_currencies SET display_decimals = -1 WHERE currency_code = 'USD'$$, 'rate_board_currencies_display_decimals_check');

-- ---------------------------------------------------------------- exchange rates
SELECT pg_temp.expect_fail('exchange_rates: rate of zero',
  $$INSERT INTO exchange_rates (currency_code, provider_code, mid_rate, effective_at)
    VALUES ('USD', 'bank_of_israel', 0, TIMESTAMPTZ '2007-07-07 12:00+03')$$, 'exchange_rates_mid_rate_check');
SELECT pg_temp.expect_fail('exchange_rates: published more than a day after it was fetched',
  $$INSERT INTO exchange_rates (currency_code, provider_code, mid_rate, effective_at)
    VALUES ('USD', 'bank_of_israel', 3.7, now() + interval '2 days')$$, 'exchange_rates_check');
-- Within a day of the fetch is accepted (provider clocks may run ahead).
BEGIN;
INSERT INTO exchange_rates (currency_code, provider_code, mid_rate, effective_at)
VALUES ('USD', 'bank_of_israel', 3.7, now() + interval '12 hours');
ROLLBACK;

-- ---------------------------------------------------------------- currency pegs
SELECT pg_temp.expect_fail('currency_pegs: a currency pegged to itself',
  $$INSERT INTO currency_pegs (currency_code, anchor_code, units_per_anchor) VALUES ('EUR', 'EUR', 1)$$, 'currency_pegs_check');
SELECT pg_temp.expect_fail('currency_pegs: ILS as the anchor',
  $$INSERT INTO currency_pegs (currency_code, anchor_code, units_per_anchor) VALUES ('EUR', 'ILS', 4)$$, 'currency_pegs_check1');
SELECT pg_temp.expect_fail('currency_pegs: ILS as the pegged currency',
  $$INSERT INTO currency_pegs (currency_code, anchor_code, units_per_anchor) VALUES ('ILS', 'USD', 3.7)$$, 'currency_pegs_check1');
SELECT pg_temp.expect_fail('currency_pegs: zero units per anchor',
  $$INSERT INTO currency_pegs (currency_code, anchor_code, units_per_anchor) VALUES ('EUR', 'USD', 0)$$, 'currency_pegs_units_per_anchor_check');

-- ---------------------------------------------------------------- manual overrides
SELECT pg_temp.expect_fail('rate_overrides: buy rate of zero',
  $$INSERT INTO rate_overrides (currency_code, buy_rate, sell_rate)
    SELECT currency_code, 0, 3.7 FROM rate_board_currencies b
    WHERE NOT EXISTS (SELECT 1 FROM rate_overrides o WHERE o.currency_code = b.currency_code) ORDER BY 1 LIMIT 1$$, 'rate_overrides_buy_rate_check');
SELECT pg_temp.expect_fail('rate_overrides: end not after the start',
  $$INSERT INTO rate_overrides (currency_code, buy_rate, sell_rate, valid_from, valid_until)
    VALUES ('USD', 3.6, 3.7, TIMESTAMPTZ '2007-07-07 12:00+03', TIMESTAMPTZ '2007-07-07 12:00+03')$$, 'rate_overrides_check1');
-- rate_overrides_sell_rate_check (sell_rate > 0) cannot be the rule that refuses a row: buy_rate > 0 and
-- sell_rate >= buy_rate already imply it, and a lower sell rate is refused by rate_overrides_check first.
SELECT pg_temp.expect_fail('rate_overrides: sell rate of zero (refused as below the buy rate)',
  $$INSERT INTO rate_overrides (currency_code, buy_rate, sell_rate) VALUES ('USD', 3.6, 0)$$, 'rate_overrides_check');
BEGIN;
INSERT INTO rate_overrides (currency_code, buy_rate, sell_rate, valid_from, valid_until)
VALUES ('USD', 3.6, 3.6, TIMESTAMPTZ '2007-07-07 12:00+03', TIMESTAMPTZ '2007-07-07 13:00+03');
ROLLBACK;

-- ---------------------------------------------------------------- weather observations
SELECT pg_temp.expect_fail('weather_observations: temperature above 70 C',
  $$INSERT INTO weather_observations (city_id, provider_code, observed_at, temperature_c, condition_code, is_day)
    VALUES (1, 'openweathermap', TIMESTAMPTZ '2007-07-07 07:00+03', 71, (SELECT min(code) FROM weather_conditions), true)$$, 'weather_observations_temperature_c_check');
SELECT pg_temp.expect_fail('weather_observations: temperature below -60 C',
  $$INSERT INTO weather_observations (city_id, provider_code, observed_at, temperature_c, condition_code, is_day)
    VALUES (1, 'openweathermap', TIMESTAMPTZ '2007-07-07 07:00+03', -61, (SELECT min(code) FROM weather_conditions), true)$$, 'weather_observations_temperature_c_check');
SELECT pg_temp.expect_fail('weather_observations: feels-like above 80 C',
  $$INSERT INTO weather_observations (city_id, provider_code, observed_at, temperature_c, feels_like_c, condition_code, is_day)
    VALUES (1, 'openweathermap', TIMESTAMPTZ '2007-07-07 07:00+03', 30, 81, (SELECT min(code) FROM weather_conditions), true)$$, 'weather_observations_feels_like_c_check');
SELECT pg_temp.expect_fail('weather_observations: humidity above 100 %',
  $$INSERT INTO weather_observations (city_id, provider_code, observed_at, temperature_c, humidity_pct, condition_code, is_day)
    VALUES (1, 'openweathermap', TIMESTAMPTZ '2007-07-07 07:00+03', 30, 101, (SELECT min(code) FROM weather_conditions), true)$$, 'weather_observations_humidity_pct_check');
SELECT pg_temp.expect_fail('weather_observations: negative wind speed',
  $$INSERT INTO weather_observations (city_id, provider_code, observed_at, temperature_c, wind_kmh, condition_code, is_day)
    VALUES (1, 'openweathermap', TIMESTAMPTZ '2007-07-07 07:00+03', 30, -1, (SELECT min(code) FROM weather_conditions), true)$$, 'weather_observations_wind_kmh_check');
SELECT pg_temp.expect_fail('weather_observations: wind direction above 360 degrees',
  $$INSERT INTO weather_observations (city_id, provider_code, observed_at, temperature_c, wind_deg, condition_code, is_day)
    VALUES (1, 'openweathermap', TIMESTAMPTZ '2007-07-07 07:00+03', 30, 361, (SELECT min(code) FROM weather_conditions), true)$$, 'weather_observations_wind_deg_check');
-- The edge values are accepted.
BEGIN;
INSERT INTO weather_observations (city_id, provider_code, observed_at, temperature_c, feels_like_c, humidity_pct, wind_kmh, wind_deg,
                                  condition_code, is_day)
VALUES (1, 'openweathermap', TIMESTAMPTZ '2007-07-07 07:00+03', 70, -80, 100, 0, 360, (SELECT min(code) FROM weather_conditions), true);
ROLLBACK;

-- ---------------------------------------------------------------- weather forecasts
SELECT pg_temp.expect_fail('weather_forecasts: day high below the day low',
  $$INSERT INTO weather_forecasts (city_id, forecast_date, temp_min_c, temp_max_c, condition_code, provider_code)
    VALUES (1, DATE '2007-07-07', 20, 10, (SELECT min(code) FROM weather_conditions), 'openweathermap')$$, 'weather_forecasts_check');
BEGIN;
INSERT INTO weather_forecasts (city_id, forecast_date, temp_min_c, temp_max_c, condition_code, provider_code)
VALUES (1, DATE '2007-07-07', 15, 15, (SELECT min(code) FROM weather_conditions), 'openweathermap');
ROLLBACK;

-- ---------------------------------------------------------------- finite numbers (NaN)
-- A numeric column takes 'NaN', and PostgreSQL counts NaN as larger than any number: "> 0" alone lets it through.
-- Each of these CHECKs also asks for less than 'Infinity', which NaN is not. Infinity itself does not fit the
-- columns' precision, so the column refuses it before any CHECK (the two "infinite" cases name no rule).
SELECT pg_temp.expect_fail('exchange_rates: a NaN rate is refused',
  $$INSERT INTO exchange_rates (currency_code, provider_code, mid_rate, effective_at)
    VALUES ('USD', 'bank_of_israel', 'NaN', TIMESTAMPTZ '2007-07-07 12:00+03')$$, 'exchange_rates_mid_rate_check');
SELECT pg_temp.expect_fail('exchange_rates: an infinite rate is refused by the column''s precision',
  $$INSERT INTO exchange_rates (currency_code, provider_code, mid_rate, effective_at)
    VALUES ('USD', 'bank_of_israel', 'Infinity', TIMESTAMPTZ '2007-07-07 12:00+03')$$);
SELECT pg_temp.expect_fail('currency_pegs: NaN units per anchor are refused',
  $$UPDATE currency_pegs SET units_per_anchor = 'NaN' WHERE currency_code = 'AED'$$, 'currency_pegs_units_per_anchor_check');
SELECT pg_temp.expect_fail('rate_overrides: a NaN buy rate is refused',
  $$INSERT INTO rate_overrides (currency_code, buy_rate, sell_rate)
    SELECT currency_code, 'NaN', 'NaN' FROM rate_board_currencies b
    WHERE NOT EXISTS (SELECT 1 FROM rate_overrides o WHERE o.currency_code = b.currency_code) ORDER BY 1 LIMIT 1$$, 'rate_overrides_buy_rate_check');
-- NaN is "not below" any buy rate, so only the sell rate's own CHECK can refuse it.
SELECT pg_temp.expect_fail('rate_overrides: a NaN sell rate is refused',
  $$INSERT INTO rate_overrides (currency_code, buy_rate, sell_rate)
    SELECT currency_code, 3.6, 'NaN' FROM rate_board_currencies b
    WHERE NOT EXISTS (SELECT 1 FROM rate_overrides o WHERE o.currency_code = b.currency_code) ORDER BY 1 LIMIT 1$$, 'rate_overrides_sell_rate_check');
SELECT pg_temp.expect_fail('rate_overrides: an infinite sell rate is refused by the column''s precision',
  $$INSERT INTO rate_overrides (currency_code, buy_rate, sell_rate)
    SELECT currency_code, 3.6, 'Infinity' FROM rate_board_currencies b
    WHERE NOT EXISTS (SELECT 1 FROM rate_overrides o WHERE o.currency_code = b.currency_code) ORDER BY 1 LIMIT 1$$);
SELECT pg_temp.expect_fail('weather_observations: a NaN wind speed is refused',
  $$INSERT INTO weather_observations (city_id, provider_code, observed_at, temperature_c, wind_kmh, condition_code, is_day)
    VALUES (1, 'openweathermap', TIMESTAMPTZ '2007-07-07 07:00+03', 30, 'NaN', (SELECT min(code) FROM weather_conditions), true)$$, 'weather_observations_wind_kmh_check');
-- Forecasts get the observations' range, which refuses NaN too.
SELECT pg_temp.expect_fail('weather_forecasts: a NaN day high is refused',
  $$INSERT INTO weather_forecasts (city_id, forecast_date, temp_min_c, temp_max_c, condition_code, provider_code)
    VALUES (1, DATE '2007-07-07', 'NaN', 'NaN', (SELECT min(code) FROM weather_conditions), 'openweathermap')$$, 'weather_forecasts_temp_max_c_check');
SELECT pg_temp.expect_fail('weather_forecasts: a day high above 70 C is refused',
  $$INSERT INTO weather_forecasts (city_id, forecast_date, temp_min_c, temp_max_c, condition_code, provider_code)
    VALUES (1, DATE '2007-07-07', 10, 71, (SELECT min(code) FROM weather_conditions), 'openweathermap')$$, 'weather_forecasts_temp_max_c_check');
SELECT pg_temp.expect_fail('weather_forecasts: a day low below -60 C is refused',
  $$INSERT INTO weather_forecasts (city_id, forecast_date, temp_min_c, temp_max_c, condition_code, provider_code)
    VALUES (1, DATE '2007-07-07', -61, 20, (SELECT min(code) FROM weather_conditions), 'openweathermap')$$, 'weather_forecasts_temp_min_c_check');
-- A NaN low under a real high fails "high >= low" first (20 >= NaN is false): refused either way.
SELECT pg_temp.expect_fail('weather_forecasts: a NaN day low is refused',
  $$INSERT INTO weather_forecasts (city_id, forecast_date, temp_min_c, temp_max_c, condition_code, provider_code)
    VALUES (1, DATE '2007-07-07', 'NaN', 20, (SELECT min(code) FROM weather_conditions), 'openweathermap')$$, 'weather_forecasts_check');
