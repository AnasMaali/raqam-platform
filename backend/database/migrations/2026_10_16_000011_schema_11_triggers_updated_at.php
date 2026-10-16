<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Mirrors database/schema/11-triggers-updated-at.sql as it was when this migration was
 * written. The SQL is a frozen copy on purpose: a migration that has run must
 * never change. A later edit to the schema file needs a new migration, and
 * scripts/check-schema-parity.sh fails until it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- =====================================================================
--  11 · Cross-cutting automation
--   a) updated_at trigger on every table that has the column
--   b) an index on every foreign-key column that does not have one yet
--      (PostgreSQL does not index FKs automatically; without it, joins and
--       ON DELETE checks on the parent scan the whole child table)
-- =====================================================================
DO $$
DECLARE
  r record;
BEGIN
  FOR r IN
    SELECT c.table_name
    FROM information_schema.columns c
    JOIN information_schema.tables t
      ON t.table_schema = c.table_schema AND t.table_name = c.table_name
    WHERE c.table_schema = 'public'
      AND c.column_name = 'updated_at'
      AND t.table_type = 'BASE TABLE'
  LOOP
    EXECUTE format(
      'CREATE TRIGGER %I BEFORE UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at()',
      r.table_name || '_set_updated_at', r.table_name);
  END LOOP;
END $$;

DO $$
DECLARE
  r record;
BEGIN
  FOR r IN
    SELECT c.conrelid::regclass::text AS tbl,
           string_agg(quote_ident(a.attname), ', ' ORDER BY k.ord) AS cols,
           string_agg(a.attname, '_' ORDER BY k.ord) AS col_names
    FROM pg_constraint c
    CROSS JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord)
    JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
    WHERE c.contype = 'f'
      AND c.connamespace = 'public'::regnamespace
      AND NOT EXISTS (
        SELECT 1 FROM pg_index i
        WHERE i.indrelid = c.conrelid
          AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] @> c.conkey
          AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] <@ c.conkey)
    GROUP BY c.conrelid, c.conname
  LOOP
    EXECUTE format('CREATE INDEX %I ON %s (%s)',
                   left(r.tbl || '_' || r.col_names || '_fk_idx', 63), r.tbl, r.cols);
  END LOOP;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
-- Reverse of the two loops above: only objects they create (names ending in
-- _fk_idx, triggers calling trg_set_updated_at); no other schema file uses either.
DO $$
DECLARE
  r record;
BEGIN
  FOR r IN
    SELECT c.relname
    FROM pg_class c
    WHERE c.relnamespace = 'public'::regnamespace
      AND c.relkind = 'i'
      AND c.relname LIKE '%\_fk\_idx'
  LOOP
    EXECUTE format('DROP INDEX %I', r.relname);
  END LOOP;

  FOR r IN
    SELECT t.tgname, t.tgrelid::regclass::text AS tbl
    FROM pg_trigger t
    WHERE t.tgfoid = 'trg_set_updated_at'::regproc
      AND NOT t.tgisinternal
  LOOP
    EXECUTE format('DROP TRIGGER %I ON %s', r.tgname, r.tbl);
  END LOOP;
END $$;
SQL);
    }
};
