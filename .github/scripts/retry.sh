#!/usr/bin/env bash
# CI only: runs a command up to ATTEMPTS times (default 3), each try cut off after TRY_SECONDS (default 600), with a
# growing pause between tries. A download that hangs is stopped and tried again instead of holding the job until its
# own time limit.
#   .github/scripts/retry.sh composer install --no-interaction
set -uo pipefail

ATTEMPTS="${ATTEMPTS:-3}"
TRY_SECONDS="${TRY_SECONDS:-600}"

for attempt in $(seq 1 "$ATTEMPTS"); do
  timeout --kill-after=10 "$TRY_SECONDS" "$@" && exit 0
  status=$?
  if [ "$attempt" -lt "$ATTEMPTS" ]; then
    echo "::warning::Try $attempt of $ATTEMPTS failed (exit $status): $*"
    sleep $((attempt * 15))
  fi
done

echo "::error::Gave up after $ATTEMPTS tries: $*"
exit "$status"
