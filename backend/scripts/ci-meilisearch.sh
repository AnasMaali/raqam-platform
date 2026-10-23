#!/usr/bin/env bash
# CI only: installs the pinned Meilisearch (checked by SHA-256), starts it on 127.0.0.1:7700 with a master key
# made up for this run, and hands the tests a key scoped to raqam_test_* (MEILISEARCH_TEST_KEY).
# The binary path goes to MEILISEARCH_BIN, so browser tests can start engines of their own. MEILI_DIR names the folder
# that keeps the binary (the workflows cache it, so a run normally downloads nothing).
# With --binary-only it only installs the binary and sets MEILISEARCH_BIN (the browser tests start their engines).
set -euo pipefail

VERSION=1.54.3
SHA256=0ece934f9791f0db83e1184f4f505a38eae7d93ff1c47efaa9426cf2b3a12ba7
DIR="${MEILI_DIR:-${RUNNER_TEMP:-/tmp}/meilisearch-${VERSION}}"
BIN="$DIR/meilisearch"

mkdir -p "$DIR"
if [ ! -x "$BIN" ] || ! echo "${SHA256}  $BIN" | sha256sum -c --status -; then
  curl -fsSL --connect-timeout 20 --max-time 300 --retry 3 --retry-all-errors -o "$BIN.part" "https://github.com/meilisearch/meilisearch/releases/download/v${VERSION}/meilisearch-linux-amd64"
  echo "${SHA256}  $BIN.part" | sha256sum -c -
  mv "$BIN.part" "$BIN"
  chmod +x "$BIN"
fi

if [ "${1:-}" = "--binary-only" ]; then
  echo "MEILISEARCH_BIN=$BIN" >> "${GITHUB_ENV:-/dev/null}"
  exit 0
fi

MASTER=$(openssl rand -hex 32)
echo "::add-mask::$MASTER"
# Data and log outside the cached folder.
RUN_DIR="${RUNNER_TEMP:-/tmp}"
(cd "$RUN_DIR" && MEILI_MASTER_KEY="$MASTER" MEILI_NO_ANALYTICS=true nohup "$BIN" --http-addr 127.0.0.1:7700 --db-path "$RUN_DIR/meili-data.ms" > "$RUN_DIR/meili.log" 2>&1 &)

for _ in $(seq 1 60); do
  curl -fs http://127.0.0.1:7700/health > /dev/null && break
  sleep 0.5
done
curl -fs http://127.0.0.1:7700/health > /dev/null

ENV_FILE=$(mktemp)
printf 'MEILISEARCH_KEY=\n' > "$ENV_FILE"
MEILISEARCH_MASTER_KEY="$MASTER" MEILISEARCH_HOST=http://127.0.0.1:7700 \
  php artisan search:create-key --prefix=raqam_test_ --env-file="$ENV_FILE"
KEY=$(grep '^MEILISEARCH_KEY=' "$ENV_FILE" | cut -d= -f2-)
rm -f "$ENV_FILE"
echo "::add-mask::$KEY"

{
  echo "MEILISEARCH_TEST_KEY=$KEY"
  echo "MEILISEARCH_BIN=$BIN"
} >> "${GITHUB_ENV:-/dev/null}"
