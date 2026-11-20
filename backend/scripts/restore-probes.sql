-- The database's own rules, tried on a restored database (docs/deployment-requirements.md, "Backups and restoring").
-- database/tests/schema_tests.sql needs an empty database; these need nothing and work on one full of data. Everything
-- runs in one transaction that is rolled back: the restored database is left exactly as it was.
--
--   psql -X -v ON_ERROR_STOP=1 -d <restored database> -f scripts/restore-probes.sql
--
-- Each line says PASS or FAIL; the last says how many failed.

BEGIN;

CREATE TEMP TABLE probe_results (n serial, label text, ok boolean) ON COMMIT DROP;

-- A statement that the database must refuse with this SQLSTATE.
CREATE FUNCTION pg_temp.refused(p_label text, p_sql text, p_state text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  BEGIN
    EXECUTE p_sql;
    INSERT INTO probe_results (label, ok) VALUES (p_label || ' (accepted)', false);
  EXCEPTION WHEN OTHERS THEN
    INSERT INTO probe_results (label, ok) VALUES (p_label || CASE WHEN SQLSTATE = p_state THEN '' ELSE ' (' || SQLSTATE || ': ' || SQLERRM || ')' END, SQLSTATE = p_state);
  END;
END $$;

-- Domains, checks and keys.
SELECT pg_temp.refused('a slug with spaces and capitals', $q$INSERT INTO tags (name_ar, slug) VALUES ('فحص الاسترجاع', 'Not A Slug')$q$, '23514');
SELECT pg_temp.refused('an e-mail that is not one', $q$INSERT INTO subscribers (email, source_placement) VALUES ('not-an-email', 'home')$q$, '23514');
SELECT pg_temp.refused('a subscriber status that does not exist', $q$INSERT INTO subscribers (email, status, source_placement) VALUES ('probe@example.org', 'maybe', 'home')$q$, '23514');
SELECT pg_temp.refused('a post in a category that does not exist', $q$INSERT INTO posts (kind_code, title, slug, category_id) VALUES ('news', 'فحص', 'فحص-الاسترجاع', -1)$q$, '23503');
SELECT pg_temp.refused('the same tag name twice', $q$INSERT INTO tags (name_ar, slug) SELECT name_ar, slug || '-x' FROM tags LIMIT 1$q$, '23505');

-- The audit trail cannot be changed or removed (append only).
INSERT INTO probe_results (label, ok) SELECT 'the audit trail has rows to try', EXISTS (SELECT 1 FROM audit_log);
SELECT pg_temp.refused('changing the audit trail', $q$UPDATE audit_log SET action = action WHERE id = (SELECT min(id) FROM audit_log)$q$, '23514');
SELECT pg_temp.refused('removing from the audit trail', $q$DELETE FROM audit_log WHERE id = (SELECT min(id) FROM audit_log)$q$, '23514');

-- New rows take the next ids after the restored ones (the identity sequences came back where they were).
WITH t AS (INSERT INTO tags (name_ar, slug) VALUES ('فحص الاسترجاع', 'فحص-الاسترجاع') RETURNING id, search_name)
INSERT INTO probe_results (label, ok)
SELECT 'a new tag takes the next id, and its search name is made by normalize_ar',
       t.id > (SELECT max(id) FROM tags WHERE id <> t.id) AND t.search_name = normalize_ar('فحص الاسترجاع') FROM t;
WITH s AS (INSERT INTO subscribers (email, source_placement) VALUES ('restore-probe@example.org', 'home') RETURNING id)
INSERT INTO probe_results (label, ok) SELECT 'a new subscriber takes the next id', s.id > (SELECT max(id) FROM subscribers WHERE id <> s.id) FROM s;

-- The Arabic normaliser answers as the application's (database/tests/normalizer_parity.py tries every case).
INSERT INTO probe_results (label, ok) SELECT 'normalize_ar folds hamza, ta marbuta and diacritics', normalize_ar('أَحْمَدُ مدرسة') = normalize_ar('احمد مدرسه');

-- The materialized view of the view counts can be refreshed.
REFRESH MATERIALIZED VIEW post_view_totals;
INSERT INTO probe_results (label, ok) SELECT 'post_view_totals refreshes', true;

SELECT CASE WHEN ok THEN 'PASS  ' ELSE 'FAIL  ' END || label FROM probe_results ORDER BY n;
SELECT count(*) FILTER (WHERE ok) AS passed, count(*) FILTER (WHERE NOT ok) AS failed FROM probe_results;

ROLLBACK;
