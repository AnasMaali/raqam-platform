#!/usr/bin/env bash
# Check that the Laravel migrations build exactly the schema in database/schema/.
#
# Each migration named *_schema_NN_<module>.php mirrors database/schema/NN-<module>.sql.
# The script builds one database from those SQL files and one with `php artisan migrate`,
# then compares their pg_dump output (schema and rows) (Laravel's own `migrations` table and sequence excluded).
# Once every schema file has its migration, the comparison covers all of raqam_schema.sql.
# It then rolls back one migration at a time and checks every intermediate state,
# down to an empty database.
#
# Usage: PGHOST=127.0.0.1 PGUSER=raqam ./scripts/check-schema-parity.sh
# (password from ~/.pgpass or PGPASSWORD; the role needs CREATEDB)
set -euo pipefail

: "${PGHOST:?set PGHOST}" "${PGUSER:?set PGUSER}"

BACKEND="$(cd "$(dirname "$0")/.." && pwd)"
SCHEMA="$(cd "$BACKEND/../database/schema" && pwd)"
REF_DB="${RAQAM_PARITY_DB:-raqam_parity}_ref"
MIG_DB="${RAQAM_PARITY_DB:-raqam_parity}_migrated"
WORK="$(mktemp -d)"
export PGOPTIONS="${PGOPTIONS:-} -c client_min_messages=warning"

cleanup() {
  rm -rf "$WORK"
  dropdb --if-exists "$REF_DB" 2>/dev/null || true
  dropdb --if-exists "$MIG_DB" 2>/dev/null || true
}
trap cleanup EXIT

# Schema and data (the seed rows count too), without owners, grants, comment headers or the random \restrict key.
# Timestamps written by now() defaults differ between the two builds, so they are masked.
dump() {
  pg_dump --no-owner --no-privileges --exclude-table=migrations --exclude-table=migrations_id_seq "$1" \
    | sed -E '/^\\(un)?restrict /d; /^--/d; /^$/d' \
    | sed -E 's/[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]+)?[+-][0-9]{2}(:[0-9]{2})?/<now()>/g'
}

migrate() {
  (cd "$BACKEND" && DB_CONNECTION=pgsql DB_HOST="$PGHOST" DB_PORT="${PGPORT:-5432}" \
    DB_DATABASE="$MIG_DB" DB_USERNAME="$PGUSER" DB_PASSWORD="${PGPASSWORD:-}" \
    php artisan "$@" --force --no-interaction >/dev/null)
}

echo "==> Schema files covered by migrations"
mapfile -t all_files < <(cd "$SCHEMA" && ls -1 [0-9][0-9]-*.sql)
covered=()
for m in "$BACKEND"/database/migrations/*_schema_[0-9][0-9]_*.php; do
  [[ -e "$m" ]] || continue
  name="$(basename "$m" .php)"
  covered+=("$(echo "${name#*_schema_}" | tr '_' '-').sql")
done
[[ ${#covered[@]} -gt 0 ]] || { echo "::error::no *_schema_NN_* migrations found"; exit 1; }
for i in "${!covered[@]}"; do
  [[ "${covered[$i]}" == "${all_files[$i]:-}" ]] \
    || { echo "::error::migration ${covered[$i]} is out of order (expected ${all_files[$i]:-nothing})"; exit 1; }
  echo "    ${covered[$i]}"
done

echo "==> Reference database from the SQL files"
dropdb --if-exists "$REF_DB"; createdb "$REF_DB"
for f in "${covered[@]}"; do
  psql -v ON_ERROR_STOP=1 -q -d "$REF_DB" -f "$SCHEMA/$f" >/dev/null
done

echo "==> Database built by php artisan migrate"
dropdb --if-exists "$MIG_DB"; createdb "$MIG_DB"
migrate migrate

echo "==> Comparing"
dump "$REF_DB" > "$WORK/ref.sql"
dump "$MIG_DB" > "$WORK/migrated.sql"
if ! diff -u "$WORK/ref.sql" "$WORK/migrated.sql"; then
  echo "::error::migrations do not match database/schema (a schema file changed without a new migration?)"
  exit 1
fi
echo "    identical ($(grep -c '^CREATE' "$WORK/ref.sql") CREATE statements)"
if [[ ${#covered[@]} -eq ${#all_files[@]} ]]; then
  echo "    all ${#all_files[@]} schema files covered: migrations == raqam_schema.sql"
else
  echo "    ${#covered[@]}/${#all_files[@]} schema files covered so far"
fi

echo "==> Rolling back one migration at a time"
# After each step the database must equal the SQL files that are still covered,
# so every down() is checked on its own, down to an empty database.
for (( k = ${#covered[@]} - 1; k >= 0; k-- )); do
  migrate migrate:rollback --step=1
  dropdb --if-exists "$REF_DB"; createdb "$REF_DB"
  for (( i = 0; i < k; i++ )); do
    psql -v ON_ERROR_STOP=1 -q -d "$REF_DB" -f "$SCHEMA/${covered[$i]}" >/dev/null
  done
  dump "$REF_DB" > "$WORK/ref.sql"
  dump "$MIG_DB" > "$WORK/migrated.sql"
  if ! diff -u "$WORK/ref.sql" "$WORK/migrated.sql"; then
    echo "::error::down() of ${covered[$k]} does not restore the previous state"
    exit 1
  fi
  echo "    ${covered[$k]} rolled back"
done
echo "    empty database after a full rollback"
