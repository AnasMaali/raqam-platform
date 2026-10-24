#!/usr/bin/env bash
# CI only: installs Debian packages with the downloaded .deb files kept in a folder that actions/cache saves
# (APT_ARCHIVES, default ~/.cache/apt-archives). A run whose packages are all cached installs them without touching a
# mirror; otherwise the package lists are refreshed and the rest is downloaded, every step time-limited and retried.
#   .github/scripts/apt-install.sh postgresql-client ffmpeg
set -euo pipefail

[ "$#" -gt 0 ] || exit 0
HERE="$(cd "$(dirname "$0")" && pwd)"
ARCHIVES="${APT_ARCHIVES:-$HOME/.cache/apt-archives}"
mkdir -p "$ARCHIVES/partial"

APT=(sudo apt-get -y -qq --no-install-recommends
  -o Dir::Cache::Archives="$ARCHIVES"
  -o Acquire::Retries=3 -o Acquire::http::Timeout=30 -o Acquire::https::Timeout=30
  -o DPkg::Lock::Timeout=120)

if timeout 120 "${APT[@]}" --no-download install "$@"; then
  echo "Installed from the cache: $*"
else
  echo "Not everything is cached; refreshing the package lists."
  # A normal run needs well under a minute for each; a mirror that stalls is cut off and tried again.
  TRY_SECONDS=120 "$HERE/retry.sh" "${APT[@]}" update
  TRY_SECONDS=240 "$HERE/retry.sh" "${APT[@]}" install "$@"
fi

# The cache step runs as the runner user: hand it the files apt downloaded as root (the lock and partial files stay out).
sudo rm -rf "$ARCHIVES/partial" "$ARCHIVES/lock"
sudo chown -R "$(id -u):$(id -g)" "$ARCHIVES"
