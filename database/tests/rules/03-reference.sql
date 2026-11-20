-- =====================================================================
--  Raqam schema — rule tests for 03-reference.sql
--  currencies, cities, organizations, social platforms, data providers,
--  provider health, typed settings and feature flags. Runs after
--  schema_tests.sql in the same session; unique values carry the prefix
--  r03. Shared rows (seeded providers, flags) are only touched inside
--  expect_fail, which rolls back.
-- =====================================================================

-- ---------------------------------------------------------------- currencies
SELECT pg_temp.expect_fail('currencies: a code that is not three capital letters is refused',
  $$INSERT INTO currencies (code, name_ar, symbol) VALUES ('r03', 'r03 عملة', 'R')$$, 'currencies_code_check');
SELECT pg_temp.expect_fail('currencies: more than 4 minor units is refused',
  $$INSERT INTO currencies (code, name_ar, symbol, minor_units) VALUES ('RQA', 'r03 عملة', 'R', 5)$$, 'currencies_minor_units_check');
SELECT pg_temp.expect_fail('currencies: negative minor units are refused',
  $$INSERT INTO currencies (code, name_ar, symbol, minor_units) VALUES ('RQA', 'r03 عملة', 'R', -1)$$, 'currencies_minor_units_check');

-- ---------------------------------------------------------------- cities
SELECT pg_temp.expect_fail('cities: a latitude beyond 90 is refused',
  $$INSERT INTO cities (name_ar, slug, latitude, longitude) VALUES ('r03 مدينة', 'r03-city', 90.5, 35.25)$$, 'cities_latitude_check');
SELECT pg_temp.expect_fail('cities: a longitude beyond -180 is refused',
  $$INSERT INTO cities (name_ar, slug, latitude, longitude) VALUES ('r03 مدينة', 'r03-city', 32.22, -180.5)$$, 'cities_longitude_check');
INSERT INTO cities (name_ar, slug, latitude, longitude) VALUES ('r03 مدينة', 'r03-city', 32.22, 35.25);

-- ---------------------------------------------------------------- organizations
SELECT pg_temp.expect_fail('organizations: a founding year before 1700 is refused',
  $$INSERT INTO organizations (name, slug, founded_year) VALUES ('r03 شركة', 'r03-company', 1699)$$, 'organizations_founded_year_check');
SELECT pg_temp.expect_fail('organizations: a founding year after 2200 is refused',
  $$INSERT INTO organizations (name, slug, founded_year) VALUES ('r03 شركة', 'r03-company', 2201)$$, 'organizations_founded_year_check');

-- ---------------------------------------------------------------- social_platforms
SELECT pg_temp.expect_fail('social_platforms: a code with digits is refused',
  $$INSERT INTO social_platforms (code, name) VALUES ('r03', 'r03 platform')$$, 'social_platforms_code_check');
SELECT pg_temp.expect_fail('social_platforms: an upper-case code is refused',
  $$INSERT INTO social_platforms (code, name) VALUES ('Threads', 'Threads')$$, 'social_platforms_code_check');

-- ---------------------------------------------------------------- data_providers & provider_health
SELECT pg_temp.expect_fail('data_providers: a code with a dash is refused',
  $$INSERT INTO data_providers (code, service, name) VALUES ('r03-provider', 'currency', 'r03')$$, 'data_providers_code_check');
SELECT pg_temp.expect_fail('data_providers: an unknown service is refused',
  $$INSERT INTO data_providers (code, service, name) VALUES ('r03_provider', 'stocks', 'r03')$$, 'data_providers_service_check');
SELECT pg_temp.expect_fail('provider_health: a negative failure count is refused',
  $$WITH p AS (INSERT INTO data_providers (code, service, name) VALUES ('r03_health', 'weather', 'r03') RETURNING code)
    INSERT INTO provider_health (provider_code, consecutive_failures) SELECT code, -1 FROM p$$, 'provider_health_consecutive_failures_check');

-- ---------------------------------------------------------------- settings
SELECT pg_temp.expect_fail('settings: a key without group.name is refused',
  $$INSERT INTO settings (key, value, value_type) VALUES ('rthree_key', 'x', 'string')$$, 'settings_key_check');
SELECT pg_temp.expect_fail('settings: a group name with digits is refused',
  $$INSERT INTO settings (key, value, value_type) VALUES ('r03.key', 'x', 'string')$$, 'settings_key_check');
SELECT pg_temp.expect_fail('settings: an unknown value type is refused',
  $$INSERT INTO settings (key, value, value_type) VALUES ('rthree.r03_json', '{}', 'json')$$, 'settings_value_type_check');
SELECT pg_temp.expect_fail('settings: an integer setting refuses text',
  $$INSERT INTO settings (key, value, value_type) VALUES ('rthree.r03_int', '12a', 'integer')$$, 'settings_check');
SELECT pg_temp.expect_fail('settings: a boolean setting refuses yes',
  $$INSERT INTO settings (key, value, value_type) VALUES ('rthree.r03_bool', 'yes', 'boolean')$$, 'settings_check1');
SELECT pg_temp.expect_fail('settings: a url setting refuses another scheme',
  $$INSERT INTO settings (key, value, value_type) VALUES ('rthree.r03_url', 'ftp://r03.example.com', 'url')$$, 'url_text_check');
SELECT pg_temp.expect_fail('settings: a time setting refuses 24:00',
  $$INSERT INTO settings (key, value, value_type) VALUES ('rthree.r03_time', '24:00', 'time')$$, 'settings_check3');
SELECT pg_temp.expect_fail('settings: a media setting refuses a non-number',
  $$INSERT INTO settings (key, value, value_type) VALUES ('rthree.r03_media', 'r03.png', 'media')$$, 'settings_check4');
-- The e-mail and phone settings are refused by the domains' own CHECKs (the cast fails first).
SELECT pg_temp.expect_fail('settings: an e-mail setting refuses a malformed address',
  $$INSERT INTO settings (key, value, value_type) VALUES ('rthree.r03_email', 'r03-not-an-email', 'email')$$, 'email_address_check');
SELECT pg_temp.expect_fail('settings: a phone setting refuses a local number',
  $$INSERT INTO settings (key, value, value_type) VALUES ('rthree.r03_phone', '0599123456', 'phone')$$, 'phone_e164_check');

-- ---------------------------------------------------------------- feature_flags
SELECT pg_temp.expect_fail('feature_flags: a key with digits is refused',
  $$INSERT INTO feature_flags (key, description_ar) VALUES ('r03_flag', 'r03')$$, 'feature_flags_key_check');
SELECT pg_temp.expect_fail('feature_flags: a flag cannot depend on itself',
  $$INSERT INTO feature_flags (key, description_ar, depends_on) VALUES ('rthree_self', 'r03', 'rthree_self')$$, 'feature_flags_check');

-- ---------------------------------------------------------------- the url setting reads as url_text
SELECT pg_temp.expect_fail('settings: a url setting with a space is refused (url_text)',
  $$INSERT INTO settings (key, value, value_type) VALUES ('rthree.r03_url_space', 'https:// not a url', 'url')$$, 'url_text_check');
