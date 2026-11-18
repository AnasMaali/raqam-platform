#!/usr/bin/env bash
# A restored database against the one it was dumped from (docs/deployment-requirements.md, "Backups and restoring"):
#   - the schema, as pg_dump writes it (tables, columns, constraints, triggers, functions, views, grants left out);
#   - every table: its number of rows and a checksum of all its rows;
#   - every sequence: where it stands (the next id of every table);
#   - every materialized view: filled, with the same rows.
# Read-only on both: it only reads. Nothing is printed of the data itself, only counts and checksums.
#
#   PGHOST=127.0.0.1 PGUSER=raqam scripts/check-restore.sh <source database> <restored database>
#
# When the source is gone (a real restore), the facts written beside the dump at backup time stand for it:
#   scripts/check-restore.sh --facts <database> > <dump>.facts      at backup time, right after pg_dump
#   scripts/check-restore.sh --against <dump>.facts <restored>     after the restore
# (dump and facts at the same moment: nothing may write between them, or the counts differ by those rows).
#
# Exit 0 when the two are the same; otherwise every difference is listed and the exit is 1.
set -euo pipefail

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

schema() {
  pg_dump --schema-only --no-owner --no-privileges --no-comments -d "$1" 2>/dev/null \
    | grep -vE '^(--|SET |SELECT pg_catalog\.set_config)' | grep -v '^\\restrict\|^\\unrestrict' | sed '/^$/d' \
    | perl -pe "s/\('([^']*)'::character varying\)::text/'\$1'/g; s/'([^']*)'::character varying/'\$1'/g; s/\(\(ARRAY\[([^\]]*)\]\)::text\[\]\)/(ARRAY[\$1])/g"
}
# PostgreSQL prints a list check (status IN ('a', 'b')) of a schema made from SQL text one way, and the same check
# read back from a dump another way (each value cast on its own). The two mean the same; perl writes both alike.

# One line per table and materialized view: name, rows, md5 of the rows in a fixed order. Then every sequence's last
# value, and whether each materialized view is filled.
rows() {
  local db="$1"
  psql -X -At -d "$db" -c "SELECT format('%I.%I', schemaname, tablename) FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')
      UNION ALL SELECT format('%I.%I', schemaname, matviewname) FROM pg_matviews ORDER BY 1" | while read -r table; do
    psql -X -At -d "$db" -c "SELECT '${table}', count(*), coalesce(md5(string_agg(md5(t::text), '' ORDER BY md5(t::text))), '-') FROM ${table} t"
  done
  psql -X -At -d "$db" -c "SELECT 'sequence ' || format('%I.%I', schemaname, sequencename), coalesce(last_value::text, 'unused') FROM pg_sequences ORDER BY 1"
  psql -X -At -d "$db" -c "SELECT 'matview ' || format('%I.%I', schemaname, matviewname), ispopulated FROM pg_matviews ORDER BY 1"
}

# The facts of one database: the checksum of its schema, then the rows.
facts() {
  echo "schema $(schema "$1" | md5sum | cut -d' ' -f1)"
  rows "$1"
}

case "${1:-}" in
  --facts)
    facts "${2:?the database}"
    exit 0 ;;
  --against)
    FACTS="${2:?the facts file}"; RESTORED="${3:?the restored database}"
    facts "$RESTORED" > "$work/restored.facts"
    if diff -u "$FACTS" "$work/restored.facts" > "$work/facts.diff"; then
      echo "the same as the facts of the backup: schema, $(grep -cv '^\(schema\|sequence\|matview\) ' "$FACTS") tables and views, $(grep -c '^sequence ' "$FACTS") sequences"
      exit 0
    fi
    echo "DIFFERENT from the facts of the backup:"; grep '^[-+][^-+]' "$work/facts.diff" | head -40
    exit 1 ;;
esac

SOURCE="${1:?the source database}"
RESTORED="${2:?the restored database}"
[ "$SOURCE" != "$RESTORED" ] || { echo "check-restore: name two different databases" >&2; exit 1; }

schema "$SOURCE" > "$work/source.sql"
schema "$RESTORED" > "$work/restored.sql"
rows "$SOURCE" > "$work/source.rows"
rows "$RESTORED" > "$work/restored.rows"

status=0
if diff -u "$work/source.sql" "$work/restored.sql" > "$work/schema.diff"; then
  echo "schema: the same ($(grep -c '^CREATE TABLE' "$work/source.sql") tables, $(grep -c '^CREATE TRIGGER' "$work/source.sql") triggers, $(grep -cE '^CREATE (OR REPLACE )?FUNCTION' "$work/source.sql") functions)"
else
  echo "schema: DIFFERENT"; head -40 "$work/schema.diff"; status=1
fi
if diff -u "$work/source.rows" "$work/restored.rows" > "$work/rows.diff"; then
  echo "rows: the same in $(grep -cv '^\(sequence\|matview\) ' "$work/source.rows") tables and views ($(awk -F'|' '!/^(sequence|matview) /{s+=$2} END {print s}' "$work/source.rows") rows), $(grep -c '^sequence ' "$work/source.rows") sequences, $(grep -c '^matview ' "$work/source.rows") materialized views filled alike"
else
  echo "rows: DIFFERENT"; grep '^[-+][^-+]' "$work/rows.diff" | head -40; status=1
fi
exit "$status"
