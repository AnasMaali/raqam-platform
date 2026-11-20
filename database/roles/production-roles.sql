-- =====================================================================
--  The application's database role, with the least it needs
--  (docs/deployment-requirements.md, "The database roles").
--
--    psql -v ON_ERROR_STOP=1 -v owner=raqam_owner -v app=raqam_app -v db=raqam \
--         -d raqam -f database/roles/production-roles.sql
--
--  Two roles, both made beforehand (CREATE ROLE ... LOGIN PASSWORD ...):
--    - :owner owns the schema. Only the deploy uses it (php artisan raqam:install, which migrates).
--    - :app is what the web server, the queue worker and the scheduler sign in as. It reads and writes rows
--      and nothing else: it cannot create, alter, drop or truncate anything, switch a trigger off, or change
--      the audit log (only add to it; deleting is left to audit:prune, which the database still limits to
--      rows older than two years).
--  Run as the owner (or a superuser), after every deploy's migrations: it is idempotent and covers the
--  tables a new migration made. The default privileges below cover them in between.
-- =====================================================================
\set ON_ERROR_STOP on

-- The database and the schema: connect, use, nothing more.
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"db" TO :"app";
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM :"app";
GRANT USAGE ON SCHEMA public TO :"app";

-- Rows: read and write every table (TRUNCATE, REFERENCES, TRIGGER are never granted).
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM :"app";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"app";
-- No right on any sequence: every id is an identity column, whose numbers need none, and nothing calls nextval().
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM :"app";

-- The views and the materialized view are read only (none of them could take a write anyway; this says so in the grants).
SELECT set_config('raqam.roles_app', :'app', false) \gset
DO $$
DECLARE r record;
BEGIN
  FOR r IN SELECT c.oid::regclass AS rel FROM pg_class c
           WHERE c.relnamespace = 'public'::regnamespace AND c.relkind IN ('v', 'm') LOOP
    EXECUTE format('REVOKE INSERT, UPDATE, DELETE ON %s FROM %I', r.rel, current_setting('raqam.roles_app'));
  END LOOP;
END $$;

-- Written once, never changed: the audit log (deleted only by audit:prune, which its trigger limits to rows
-- older than two years), an invoice and its lines (only the PDF is attached, once), a booking's history.
REVOKE UPDATE ON audit_log, audit_log_changes FROM :"app";
REVOKE UPDATE, DELETE ON invoices, invoice_lines, booking_status_history FROM :"app";
GRANT UPDATE (pdf_media_id) ON invoices TO :"app";

-- The view totals: only their owner can refresh a materialized view, so the application runs the owner's function,
-- which does exactly that and nothing else (stats:flush). Nobody else may run it.
REVOKE ALL ON FUNCTION refresh_post_view_totals() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION refresh_post_view_totals() TO :"app";

-- Laravel's list of migrations belongs to the deploy.
REVOKE INSERT, UPDATE, DELETE ON migrations FROM :"app";

-- Tables a later migration makes get the same rows-only rights until this script runs again.
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"app";
