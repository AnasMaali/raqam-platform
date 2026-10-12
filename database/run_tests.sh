#!/usr/bin/env bash
# Build the schema in a fresh database and run every database test.
# Local:  ./database/run_tests.sh            (uses the local postgres user)
# CI:     PGHOST=... PGUSER=... PGPASSWORD=... ./database/run_tests.sh
set -euo pipefail

DB="${RAQAM_TEST_DB:-raqam_test}"
HERE="$(cd "$(dirname "$0")" && pwd)"

if [[ -n "${PGHOST:-}" ]]; then
  run() { "$@"; }
else
  run() { su postgres -c "$(printf '%q ' "$@")"; }
fi

echo "==> Concatenating schema/*.sql"
cat "$HERE"/schema/*.sql > "$HERE/raqam_schema.sql"
if ! git -C "$HERE" diff --quiet -- raqam_schema.sql 2>/dev/null; then
  echo "::warning::raqam_schema.sql was out of date with schema/*.sql (regenerated)"
fi

echo "==> Fresh database: $DB"
run dropdb --if-exists "$DB"
run createdb "$DB"

echo "==> Applying schema"
run psql -v ON_ERROR_STOP=1 -q -d "$DB" -f "$HERE/raqam_schema.sql"

echo "==> Constraint & trigger tests"
OUT="$(run psql -v ON_ERROR_STOP=1 -d "$DB" -f "$HERE/tests/schema_tests.sql")"
echo "$OUT" | grep -E "PASS|FAIL" || true
SUMMARY="$(echo "$OUT" | grep -E '^ +[0-9]+ \| +[0-9]+$' | tail -1)"
FAILED="$(echo "$SUMMARY" | awk -F'|' '{gsub(/ /,"",$2); print $2}')"
echo "passed/failed: $SUMMARY"
[[ "$FAILED" == "0" ]] || { echo "::error::schema tests failed"; exit 1; }

echo "==> Arabic normaliser parity (PHP vs SQL)"
python3 "$HERE/tests/normalizer_parity.py" "$DB"
