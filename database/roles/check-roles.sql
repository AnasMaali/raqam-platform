-- =====================================================================
--  What the application's role can and cannot do (database/roles/check-roles.sh runs it as that role, on a
--  database of its own built by the owner role and given production-roles.sql). Each "refused" case must fail
--  with a permission error or the rule named; each "allowed" case must work.
-- =====================================================================
\set QUIET on
\o /dev/null
SET client_min_messages = warning;
CREATE TEMP TABLE role_results (n serial, label text, ok boolean, detail text);

-- p_why: the start of the error expected (a permission error by default), so a refusal for another reason fails.
CREATE FUNCTION pg_temp.refused(p_label text, p_sql text, p_why text DEFAULT 'permission denied|must be owner') RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
  BEGIN
    EXECUTE p_sql;
  EXCEPTION WHEN others THEN
    INSERT INTO role_results (label, ok, detail) VALUES (p_label, SQLERRM ~ p_why, SQLERRM);
    RETURN;
  END;
  INSERT INTO role_results (label, ok, detail) VALUES (p_label, false, 'it was allowed');
END $$;

CREATE FUNCTION pg_temp.allowed(p_label text, p_sql text) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
  EXECUTE p_sql;
  INSERT INTO role_results (label, ok, detail) VALUES (p_label, true, 'allowed');
EXCEPTION WHEN others THEN
  INSERT INTO role_results (label, ok, detail) VALUES (p_label, false, SQLERRM);
END $$;

SELECT pg_temp.allowed('the session is the application role, not a superuser',
  $sql$DO $do$ BEGIN IF (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN RAISE EXCEPTION 'superuser'; END IF; END $do$$sql$);

-- ---------------------------------------------------------------- the structure: never
SELECT pg_temp.refused('DROP a table', 'DROP TABLE posts');
SELECT pg_temp.refused('DROP the audit log', 'DROP TABLE audit_log CASCADE');
SELECT pg_temp.refused('ALTER a table', 'ALTER TABLE posts ADD COLUMN x integer');
SELECT pg_temp.refused('drop a CHECK', 'ALTER TABLE payments DROP CONSTRAINT payments_amount_check');
SELECT pg_temp.refused('switch the audit log''s trigger off', 'ALTER TABLE audit_log DISABLE TRIGGER USER');
SELECT pg_temp.refused('switch the refund limit off', 'ALTER TABLE refunds DISABLE TRIGGER USER');
SELECT pg_temp.refused('CREATE a table', 'CREATE TABLE public.intruder (id int)');
SELECT pg_temp.refused('CREATE a function', 'CREATE FUNCTION public.intruder() RETURNS int LANGUAGE sql AS $f$ SELECT 1 $f$');
SELECT pg_temp.refused('replace a trigger function', $sql$CREATE OR REPLACE FUNCTION trg_audit_append_only() RETURNS trigger LANGUAGE plpgsql AS $f$ BEGIN RETURN NEW; END $f$$sql$);
SELECT pg_temp.refused('TRUNCATE a table', 'TRUNCATE login_attempts');
SELECT pg_temp.refused('TRUNCATE the audit log', 'TRUNCATE audit_log CASCADE');
SELECT pg_temp.refused('DROP the schema', 'DROP SCHEMA public CASCADE');
SELECT pg_temp.allowed('give others its rights: the GRANT changes nothing', $sql$DO $do$ BEGIN
  GRANT ALL ON posts TO PUBLIC;
  IF has_table_privilege('public', 'posts', 'SELECT') THEN RAISE EXCEPTION 'PUBLIC can read posts now'; END IF; END $do$$sql$);
SELECT pg_temp.refused('keys off for the session', 'SET session_replication_role = replica');
SELECT pg_temp.refused('write the list of migrations', $$INSERT INTO migrations (migration, batch) VALUES ('x', 99)$$);

-- ---------------------------------------------------------------- the audit log: add, never change
SELECT pg_temp.allowed('add to the audit log',
  $$INSERT INTO audit_log (action, subject_table, subject_id) VALUES ('update', 'posts', 1)$$);
SELECT pg_temp.refused('change an audit row', 'UPDATE audit_log SET action = ''delete''');
SELECT pg_temp.refused('change an audit row''s fields', 'UPDATE audit_log_changes SET new_value = ''x''');
SELECT pg_temp.refused('delete a young audit row (even when pruning)',
  $sql$DO $do$ BEGIN PERFORM set_config('raqam.audit_prune', 'on', true); DELETE FROM audit_log; END $do$$sql$,
  'younger than 2 years');
SELECT pg_temp.refused('delete an audit row outside the prune', 'DELETE FROM audit_log', 'append-only');

-- ---------------------------------------------------------------- written once
SELECT pg_temp.refused('change an invoice', 'UPDATE invoices SET billed_name = ''x''');
SELECT pg_temp.refused('change an invoice line', 'UPDATE invoice_lines SET quantity = 2');
SELECT pg_temp.refused('delete an invoice', 'DELETE FROM invoices');
SELECT pg_temp.refused('rewrite a booking''s history', 'UPDATE booking_status_history SET note = ''x''');
SELECT pg_temp.allowed('the grants give no write on any view or the materialized view', $sql$DO $do$
DECLARE v text; BEGIN
  SELECT string_agg(c.relname, ', ') INTO v FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relkind IN ('v', 'm')
    AND (has_table_privilege(c.oid, 'INSERT') OR has_table_privilege(c.oid, 'UPDATE') OR has_table_privilege(c.oid, 'DELETE'));
  IF v IS NOT NULL THEN RAISE EXCEPTION 'writable: %', v; END IF; END $do$$sql$);
SELECT pg_temp.allowed('no right on any sequence (identity columns need none)', $sql$DO $do$
DECLARE v text; BEGIN
  SELECT string_agg(c.relname, ', ') INTO v FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relkind = 'S'
    AND (has_sequence_privilege(c.oid, 'USAGE') OR has_sequence_privilege(c.oid, 'UPDATE'));
  IF v IS NOT NULL THEN RAISE EXCEPTION 'usable: %', v; END IF; END $do$$sql$);
SELECT pg_temp.refused('write into a view', $$INSERT INTO video_usage VALUES (1, 1, 'body')$$, 'permission denied|cannot insert into view');

-- ---------------------------------------------------------------- the rows: everything the platform does
SELECT pg_temp.allowed('read every table and view', $sql$DO $do$ DECLARE r record; BEGIN
  FOR r IN SELECT c.oid::regclass AS rel FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relkind IN ('r', 'v', 'm') LOOP
    EXECUTE format('SELECT 1 FROM %s LIMIT 1', r.rel);
  END LOOP; END $do$$sql$);
SELECT pg_temp.allowed('write, change and delete a tag (identity numbers included)', $sql$DO $do$ BEGIN
  INSERT INTO tags (name_ar, slug) VALUES ('وسم الدور', 'وسم-الدور');
  UPDATE tags SET name_ar = 'وسم الدور ٢' WHERE slug = 'وسم-الدور';
  DELETE FROM tags WHERE slug = 'وسم-الدور'; END $do$$sql$);
SELECT pg_temp.allowed('a booking through its history, its invoice numbered, the PDF attached once', $sql$DO $do$
DECLARE v_booking bigint; v_payment bigint; v_invoice bigint; v_media bigint;
BEGIN
  INSERT INTO service_groups (name_ar, sort_order) VALUES ('مجموعة الدور', 1);
  INSERT INTO services (group_id, name_ar, slug, summary, price_mode, price_amount, currency_code)
  SELECT id, 'خدمة الدور', 'خدمة-الدور', 'ملخص', 'fixed', 12345, 'USD' FROM service_groups WHERE name_ar = 'مجموعة الدور';
  INSERT INTO customers (name, email, phone) VALUES ('زبون الدور', 'role-check@example.com', '+970599000001');
  INSERT INTO bookings (service_id, customer_id, preferred_contact, details, price_amount, currency_code, access_token_hash, consent_at)
  SELECT s.id, c.id, 'email', 'تفاصيل', 12345, 'USD', encode(sha256('role-check'::bytea), 'hex'), now()
  FROM services s, customers c WHERE s.slug = 'خدمة-الدور' AND c.email = 'role-check@example.com' RETURNING id INTO v_booking;
  INSERT INTO booking_status_history (booking_id, status_code) VALUES (v_booking, 'awaiting_payment');
  INSERT INTO payment_gateways (code, name_ar) VALUES ('fake', 'تجريبية') ON CONFLICT DO NOTHING;
  INSERT INTO payments (booking_id, gateway_code, attempt_no, gateway_order_id, amount, currency_code, status, idempotency_key, paid_at)
  VALUES (v_booking, 'fake', 1, 'ROLE-1', 12345, 'USD', 'captured', 'role-1', now()) RETURNING id INTO v_payment;
  INSERT INTO refunds (payment_id, amount, status) VALUES (v_payment, 1000, 'succeeded');
  INSERT INTO invoices (payment_id, billed_name, billed_email, currency_code) VALUES (v_payment, 'زبون الدور', 'role-check@example.com', 'USD')
  RETURNING id INTO v_invoice;
  INSERT INTO invoice_lines (invoice_id, line_no, description, quantity, unit_amount) VALUES (v_invoice, 1, 'بند', 1, 12345);
  INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, visibility)
  VALUES ('private/role-invoice.pdf', 'فاتورة.pdf', 'application/pdf', 1000, encode(sha256('role-pdf'::bytea), 'hex'), 'private') RETURNING id INTO v_media;
  UPDATE invoices SET pdf_media_id = v_media WHERE id = v_invoice;
END $do$$sql$);
SELECT pg_temp.allowed('a booking deleted takes its history with it (the key acts as the owner)', $sql$DO $do$ BEGIN
  INSERT INTO bookings (service_id, customer_id, preferred_contact, details, price_amount, currency_code, access_token_hash, consent_at)
  SELECT s.id, c.id, 'email', 'للحذف', 12345, 'USD', encode(sha256('role-check-2'::bytea), 'hex'), now()
  FROM services s, customers c WHERE s.slug = 'خدمة-الدور' AND c.email = 'role-check@example.com';
  DELETE FROM bookings WHERE details = 'للحذف'; END $do$$sql$);
SELECT pg_temp.allowed('prune an audit row older than two years', $sql$DO $do$ BEGIN
  INSERT INTO audit_log (action, subject_table, subject_id, occurred_at) VALUES ('update', 'posts', 2, now() - interval '3 years');
  PERFORM set_config('raqam.audit_prune', 'on', true);
  DELETE FROM audit_log WHERE occurred_at < now() - interval '2 years'; END $do$$sql$);
SELECT pg_temp.allowed('lock the synonyms while they are rebuilt (as SynonymLibrary does)',
  'LOCK TABLE synonym_terms IN SHARE ROW EXCLUSIVE MODE');
SELECT pg_temp.allowed('a temporary table of its own', 'CREATE TEMP TABLE role_scratch (id int)');
SELECT pg_temp.allowed('refresh the view totals through the owner''s function (stats:flush)', 'SELECT refresh_post_view_totals()');
SELECT pg_temp.allowed('the refresh function runs as its owner, with its own search path, and PUBLIC cannot run it', $sql$DO $do$
DECLARE f record; BEGIN
  SELECT p.prosecdef, p.proconfig, pg_get_userbyid(p.proowner) AS owner INTO f FROM pg_proc p WHERE p.oid = 'refresh_post_view_totals()'::regprocedure;
  IF NOT f.prosecdef THEN RAISE EXCEPTION 'not SECURITY DEFINER'; END IF;
  IF f.proconfig IS DISTINCT FROM ARRAY['search_path=pg_catalog, public'] THEN RAISE EXCEPTION 'search_path: %', f.proconfig; END IF;
  IF f.owner = current_user THEN RAISE EXCEPTION 'owned by the application'; END IF;
  IF has_function_privilege('public', 'refresh_post_view_totals()', 'EXECUTE') THEN RAISE EXCEPTION 'PUBLIC can run it'; END IF;
END $do$$sql$);
SELECT pg_temp.refused('refresh the view totals itself', 'REFRESH MATERIALIZED VIEW CONCURRENTLY post_view_totals');
SELECT pg_temp.refused('drop the view totals', 'DROP MATERIALIZED VIEW post_view_totals');
SELECT pg_temp.refused('replace the refresh function', $sql$CREATE OR REPLACE FUNCTION refresh_post_view_totals() RETURNS void LANGUAGE sql AS $f$ SELECT $f$$sql$);

-- ---------------------------------------------------------------- report
\o
\set QUIET off
SELECT n AS "#", CASE WHEN ok THEN 'PASS' ELSE 'FAIL' END AS result, label, left(detail, 100) AS detail FROM role_results ORDER BY n;
SELECT count(*) FILTER (WHERE ok) AS passed, count(*) FILTER (WHERE NOT ok) AS failed FROM role_results;
