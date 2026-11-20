#!/usr/bin/env bash
# Every migration rolled back and run again on a copy of the QA data, not on an empty database
# (docs/qa/database.md, "Rolling back with data"). scripts/check-schema-parity.sh proves the same on an empty one.
#
#   PGHOST=127.0.0.1 PGUSER=raqam backend/scripts/qa-rollback-data.sh
#
# Each pass takes a fresh copy of raqam_qa (createdb -T, so nothing may be connected to raqam_qa).
#  1. For k = 1 .. all: `migrate:rollback --step=k`, then `migrate`. A rollback either goes through or is refused with
#     the copy left exactly as it was (a migration runs in a transaction): the seed of reference rows (the last
#     migration) cannot be taken back while the data uses those rows, and that refusal is the expected answer.
#  2. Below the seed: its row is taken off Laravel's list (its rows stay), so `migrate:rollback --step=k` takes back
#     the k migrations under it with the data in place. Each must go down without an error, keep every row of the
#     tables it does not own, and, with the seed put back on the list, `migrate` must return the same schema.
# The copies are named raqam_qa_rollback and dropped at the end. raqam_qa itself is only read.
set -uo pipefail
export LC_ALL=C   # join and sort agree with the database's order of names
: "${PGHOST:?set PGHOST}" "${PGUSER:?set PGUSER}"
BACKEND="$(cd "$(dirname "$0")/.." && pwd)"
SOURCE=raqam_qa
COPY=raqam_qa_rollback
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"; dropdb --if-exists "$COPY" 2>/dev/null || true' EXIT

schema() { pg_dump -s -O -x -d "$1" | grep -vE '^(--|\\(un)?restrict |SET |SELECT pg_catalog)' | sed '/^$/d'; }
counts() {
  psql -X -At -d "$1" -c "SELECT c.relname FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relkind = 'r' AND c.relname <> 'migrations' ORDER BY 1" |
    while read -r t; do echo "$t $(psql -X -At -d "$1" -c "SELECT count(*) FROM \"$t\"")"; done
}
artisan() { (cd "$BACKEND" && APP_ENV=qa DB_DATABASE="$COPY" php artisan "$@" --force --no-interaction 2>&1); }

schema "$SOURCE" > "$WORK/schema.before"
counts "$SOURCE" > "$WORK/counts.before"
TOTAL=$(psql -X -At -d "$SOURCE" -c "SELECT count(*) FROM migrations")
echo "$SOURCE: $(wc -l < "$WORK/counts.before") tables, $(awk '{s += $2} END {print s}' "$WORK/counts.before") rows, $TOTAL migrations"

same() { [[ "$(schema "$COPY")" == "$(cat "$WORK/schema.before")" ]] && counts "$COPY" | cmp -s - "$WORK/counts.before"; }
fresh() { dropdb --if-exists "$COPY"; createdb -T "$SOURCE" "$COPY" || { echo "could not copy $SOURCE (is something connected to it?)"; exit 1; }; }
failed=0
SEED=$(psql -X -At -d "$SOURCE" -c "SELECT migration FROM migrations ORDER BY id DESC LIMIT 1")

echo "-- 1. the whole stack, from the top"
for k in $(seq 1 "$TOTAL"); do
  fresh
  if out=$(artisan migrate:rollback --step="$k"); then
    artisan migrate > /dev/null || { echo "FAIL k=$k: migrate back stopped"; failed=$((failed + 1)); continue; }
    [[ "$(schema "$COPY")" == "$(cat "$WORK/schema.before")" ]] && echo "ok   k=$k: down and back" || { echo "FAIL k=$k: back to another schema"; failed=$((failed + 1)); }
  elif same; then
    echo "ok   k=$k: refused, nothing changed ($(echo "$out" | grep -m1 -oE 'violates foreign key constraint "[a-z_]+"'))"
  else
    echo "FAIL k=$k: refused and the copy changed: $(echo "$out" | grep -m1 -E 'SQLSTATE' | cut -c1-200)"; failed=$((failed + 1))
  fi
done

echo "-- 2. below the seed ($SEED off the list, its rows kept)"
for k in $(seq 1 "$((TOTAL - 1))"); do
  fresh
  psql -X -q -d "$COPY" -c "DELETE FROM migrations WHERE migration = '$SEED'"
  last=$(psql -X -At -d "$COPY" -c "SELECT migration FROM migrations ORDER BY id DESC OFFSET $((k - 1)) LIMIT 1")

  if ! out=$(artisan migrate:rollback --step="$k"); then
    echo "FAIL k=$k (down to $last): the rollback stopped: $(echo "$out" | grep -m1 -E 'SQLSTATE' | cut -c1-200)"
    failed=$((failed + 1)); continue
  fi
  counts "$COPY" > "$WORK/counts.down"
  lost=$(join "$WORK/counts.before" "$WORK/counts.down" | awk '$2 != $3 {print $1 " " $2 "->" $3}')
  gone=$(join -v1 "$WORK/counts.before" "$WORK/counts.down" | awk '{print $1}' | wc -l)

  psql -X -q -d "$COPY" -c "INSERT INTO migrations (migration, batch) VALUES ('$SEED', 1)"
  if ! out=$(artisan migrate); then
    echo "FAIL k=$k (down to $last): migrate back stopped: $(echo "$out" | grep -m1 -E 'SQLSTATE' | cut -c1-200)"
    failed=$((failed + 1)); continue
  fi
  schema "$COPY" > "$WORK/schema.after"
  if [[ -n "$lost" ]]; then
    echo "FAIL k=$k (down to $last): rows changed in tables it does not own: $lost"; failed=$((failed + 1))
  elif ! diff -q "$WORK/schema.before" "$WORK/schema.after" > /dev/null; then
    echo "FAIL k=$k (down to $last): the schema came back different:"; diff "$WORK/schema.before" "$WORK/schema.after" | head -10
    failed=$((failed + 1))
  else
    echo "ok   k=$k (down to $last): $gone tables went with their data, the other $(wc -l < "$WORK/counts.down") kept every row; back to the same schema"
  fi
done

echo "$failed failed"
exit $(( failed > 0 ))
