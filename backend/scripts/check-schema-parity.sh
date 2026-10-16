#!/usr/bin/env bash
# Check that the Laravel migrations build exactly the schema in database/schema/.
#
# Each migration named *_schema_NN_<module>.php mirrors database/schema/NN-<module>.sql.
# The script builds one database from those SQL files and one with `php artisan migrate`,
# then compares their pg_dump output (Laravel's own `migrations` table and sequence excluded).
# Once every schema file has its migration, the comparison covers all of raqam_schema.sql.
# It also checks that rolling every migration back leaves an empty database.
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

# Schema-only dump without owners, grants, comments-as-headers or the random \restrict key.
dump() {
  pg_dump --schema-only --no-owner --no-privileges --exclude-table=migrations --exclude-table=migrations_id_seq "$1" \
    | sed -E '/^\\(un)?restrict /d; /^--/d; /^$/d'
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

echo "==> Rolling every migration back"
migrate migrate:reset
dropdb "$REF_DB"; createdb "$REF_DB"
dump "$REF_DB" > "$WORK/empty.sql"
dump "$MIG_DB" > "$WORK/after_reset.sql"
if ! diff -u "$WORK/empty.sql" "$WORK/after_reset.sql"; then
  echo "::error::down() left objects behind"
  exit 1
fi
echo "    empty database after reset"
