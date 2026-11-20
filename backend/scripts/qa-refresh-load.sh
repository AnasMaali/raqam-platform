#!/usr/bin/env bash
# REFRESH MATERIALIZED VIEW CONCURRENTLY post_view_totals while the site reads it and the counters write under it
# (docs/qa/database.md, "Refreshing the view totals under load").
#
#   backend/scripts/qa-refresh-load.sh <copy of raqam_qa> [seconds, 60] [concurrently|plain]
#
# On a copy only (raqam_qa_*): readers (the most-read list and one post's total, as the API asks them) and writers
# (the hourly flush's upsert into post_views_daily) run with pgbench while the view is refreshed again and again. Every
# 100 ms the reader and writer sessions waiting on a lock are counted. "plain" refreshes without CONCURRENTLY, to show
# what a lock that stops the site looks like. Needs pgbench and psql; PGHOST/PGUSER as for the other scripts.
set -euo pipefail

DB=${1:?name a copy of raqam_qa (raqam_qa_*)}
SECONDS_RUN=${2:-60}
MODE=${3:-concurrently}
[[ "$DB" == raqam_qa_* ]] || { echo "qa-refresh-load: only on a copy of raqam_qa (raqam_qa_*), not $DB" >&2; exit 1; }
[[ "$MODE" == concurrently || "$MODE" == plain ]] || { echo "qa-refresh-load: concurrently or plain" >&2; exit 1; }

WORK=$(mktemp -d)
trap 'kill $(jobs -p) 2>/dev/null || true; rm -rf "$WORK"' EXIT
MAX_POST=$(psql -X -At -d "$DB" -c "SELECT max(id) FROM posts")
MIN_POST=$(psql -X -At -d "$DB" -c "SELECT min(id) FROM posts")

cat > "$WORK/read.sql" <<SQL
\set post random($MIN_POST, $MAX_POST)
SELECT post_id, total_views FROM post_view_totals ORDER BY total_views DESC LIMIT 10;
SELECT total_views FROM post_view_totals WHERE post_id = :post;
SQL
cat > "$WORK/write.sql" <<SQL
\set post random($MIN_POST, $MAX_POST)
INSERT INTO post_views_daily (post_id, stat_date, views) SELECT id, current_date, 1 FROM posts WHERE id = :post
ON CONFLICT (post_id, stat_date) DO UPDATE SET views = greatest(post_views_daily.views, EXCLUDED.views) + 1;
SQL

if [[ $MODE == concurrently ]]; then REFRESH="REFRESH MATERIALIZED VIEW CONCURRENTLY post_view_totals"
else REFRESH="REFRESH MATERIALIZED VIEW post_view_totals"; fi
(
  refreshes=0; longest=0
  end=$((SECONDS + SECONDS_RUN))
  while (( SECONDS < end )); do
    t0=$(date +%s%N); psql -X -q -d "$DB" -c "$REFRESH"; t1=$(date +%s%N)
    refreshes=$((refreshes + 1)); ms=$(( (t1 - t0) / 1000000 )); (( ms > longest )) && longest=$ms
  done
  echo "$refreshes $longest" > "$WORK/refresh"
) &

PGAPPNAME=qa-read pgbench -n -c 8 -j 4 -T "$SECONDS_RUN" -f "$WORK/read.sql" -r "$DB" > "$WORK/read.out" 2>&1 &
READ=$!
PGAPPNAME=qa-write pgbench -n -c 2 -j 2 -T "$SECONDS_RUN" -f "$WORK/write.sql" -r "$DB" > "$WORK/write.out" 2>&1 &
WRITE=$!

samples=0; waiting_samples=0; most=0
while kill -0 "$READ" 2>/dev/null; do
  n=$(psql -X -At -d "$DB" -c "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND application_name IN ('qa-read', 'qa-write') AND wait_event_type = 'Lock'")
  samples=$((samples + 1)); (( n > 0 )) && waiting_samples=$((waiting_samples + 1)); (( n > most )) && most=$n
  sleep 0.1
done
wait "$READ" "$WRITE"
wait

read -r refreshes longest < "$WORK/refresh"
lat() { grep -E "^latency average|^tps" "$1" | sed 's/ (without initial connection time)//' | tr '\n' ' '; }
echo "mode: $MODE, $SECONDS_RUN s, $(psql -X -At -d "$DB" -c "SELECT count(*) FROM post_views_daily") daily rows"
echo "refreshes: $refreshes, longest $longest ms"
echo "readers: $(lat "$WORK/read.out")"
echo "writers: $(lat "$WORK/write.out")"
echo "lock waits: $waiting_samples of $samples samples had a reader or writer waiting (at most $most at once)"
(( waiting_samples == 0 )) && echo "RESULT: no reader or writer waited on a lock" || echo "RESULT: readers or writers waited on a lock"
