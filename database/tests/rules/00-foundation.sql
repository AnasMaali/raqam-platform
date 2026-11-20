-- =====================================================================
--  Raqam schema — rule tests for 00-foundation.sql
--  The shared domains (basis_points, hex_color, money_minor, sha256_hex,
--  url_text) and the foundation tables (document_counters, job_batches).
--  Runs after schema_tests.sql in the same session; unique values carry
--  the prefix r00.
-- =====================================================================

-- ---------------------------------------------------------------- domain basis_points
SELECT pg_temp.expect_eq('basis_points: 0 and 10000 (0 % and 100 %) are allowed',
  (0::basis_points)::text || ',' || (10000::basis_points)::text, '0,10000');
SELECT pg_temp.expect_fail('basis_points: a spread above 100 % is refused',
  $$UPDATE rate_board_currencies SET buy_spread_bp = 10001 WHERE currency_code = 'USD'$$, 'basis_points_check');
SELECT pg_temp.expect_fail('basis_points: a negative spread is refused',
  $$UPDATE rate_board_currencies SET sell_spread_bp = -1 WHERE currency_code = 'USD'$$, 'basis_points_check');

-- ---------------------------------------------------------------- domain hex_color
SELECT pg_temp.expect_eq('hex_color: #RRGGBB in either case is allowed',
  ('#12aB9f'::hex_color)::text, '#12aB9f');
SELECT pg_temp.expect_fail('hex_color: a colour name is refused',
  $$INSERT INTO characters (name_ar, slug, role_ar, accent_color) VALUES ('r00 شخصية', 'r00-character', 'دور', 'blue')$$, 'hex_color_check');
SELECT pg_temp.expect_fail('hex_color: a non-hex digit is refused',
  $$INSERT INTO characters (name_ar, slug, role_ar, accent_color) VALUES ('r00 شخصية', 'r00-character', 'دور', '#12345G')$$, 'hex_color_check');

-- ---------------------------------------------------------------- domain money_minor
SELECT pg_temp.expect_eq('money_minor: zero is allowed', (0::money_minor)::text, '0');
SELECT pg_temp.expect_fail('money_minor: a negative amount is refused',
  $$INSERT INTO services (group_id, name_ar, slug, summary, price_mode, price_amount, currency_code)
    SELECT id, 'r00 خدمة', 'r00-service', 'ملخص', 'fixed', -1, 'ILS' FROM service_groups ORDER BY id LIMIT 1$$, 'money_minor_check');

-- ---------------------------------------------------------------- domain sha256_hex
SELECT pg_temp.expect_fail('sha256_hex: upper-case hex is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256)
    VALUES ('r00/upper.pdf', 'upper.pdf', 'application/pdf', 1000, repeat('A', 64))$$, 'sha256_hex_check');
SELECT pg_temp.expect_fail('sha256_hex: a digest shorter than 64 characters is refused',
  $$INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256)
    VALUES ('r00/short.pdf', 'short.pdf', 'application/pdf', 1000, repeat('a', 63))$$, 'sha256_hex_check');

-- ---------------------------------------------------------------- domain url_text
SELECT pg_temp.expect_eq('url_text: http and https addresses are allowed',
  ('https://r00.example.com/a?b=1'::url_text)::text, 'https://r00.example.com/a?b=1');
SELECT pg_temp.expect_fail('url_text: a javascript: address is refused',
  $$INSERT INTO seo_meta (canonical_url) VALUES ('javascript:alert(1)')$$, 'url_text_check');
SELECT pg_temp.expect_fail('url_text: an address with a space is refused',
  $$INSERT INTO seo_meta (canonical_url) VALUES ('https://r00.example.com/a b')$$, 'url_text_check');
SELECT pg_temp.expect_fail('url_text: an address without a scheme is refused',
  $$INSERT INTO organizations (name, slug, website) VALUES ('r00 شركة', 'r00-company', 'www.r00.example.com')$$, 'url_text_check');

-- ---------------------------------------------------------------- document_counters
SELECT pg_temp.expect_fail('document_counters: only bookings and invoices are numbered',
  $$INSERT INTO document_counters (doc_type, year, last_value) VALUES ('receipt', 2030, 1)$$, 'document_counters_doc_type_check');
SELECT pg_temp.expect_fail('document_counters: a year before 2024 is refused',
  $$INSERT INTO document_counters (doc_type, year, last_value) VALUES ('booking', 2023, 1)$$, 'document_counters_year_check');
SELECT pg_temp.expect_fail('document_counters: a year after 2200 is refused',
  $$INSERT INTO document_counters (doc_type, year, last_value) VALUES ('booking', 2201, 1)$$, 'document_counters_year_check');
SELECT pg_temp.expect_fail('document_counters: a negative counter is refused',
  $$INSERT INTO document_counters (doc_type, year, last_value) VALUES ('invoice', 2199, -1)$$, 'document_counters_last_value_check');

-- ---------------------------------------------------------------- job_batches
SELECT pg_temp.expect_fail('job_batches: a negative job total is refused',
  $$INSERT INTO job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, created_at)
    VALUES (gen_random_uuid(), 'r00-batch', -1, 0, 0, '[]', 1700000000)$$, 'job_batches_total_jobs_check');
SELECT pg_temp.expect_fail('job_batches: a negative failed-job count is refused',
  $$INSERT INTO job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, created_at)
    VALUES (gen_random_uuid(), 'r00-batch', 1, 0, -1, '[]', 1700000000)$$, 'job_batches_failed_jobs_check');
