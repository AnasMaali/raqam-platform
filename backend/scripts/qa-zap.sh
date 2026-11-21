#!/usr/bin/env bash
# The outside security scan of the QA environment (docs/qa/security.md) with OWASP ZAP, run on this machine (no
# container), against the QA API and panel (127.0.0.1:8100) and the QA website (127.0.0.1:3300) only.
#
#   scripts/qa-zap.sh api | site-baseline | site-full | panel-baseline | panel-crawl
#
# - ZAP: the version pinned in docs/qa/security.md, unpacked in $ZAP_INSTALL (~/tools/zap/ZAP_<version>), its own
#   settings and add-ons in $ZAP_HOME (~/tools/zap/home). Its proxy listens on 127.0.0.1 only, its API is off, it never
#   calls home, its out-of-band service is off and its callback server (which listens on every
#   address unless told) is bound to 127.0.0.1. It stops when the plan ends.
# - The plans (scripts/zap/*.yaml) hold no secret and no panel address: the site token, the panel's address and the
#   signed-in cookie are read here, from backend/.env.qa and the QA browser state, and given to ZAP in its environment
#   only. Nothing is printed.
# - The reports go to private/qa-zap/ (git-ignored), never to the repository. Each run works in a folder of its own,
#   deleted when it ends (ZAP's session, log and options, which hold the values it was given), and the secret values
#   are taken out of what ZAP prints and out of the reports.
set -euo pipefail

BACKEND="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT="$(cd "$BACKEND/.." && pwd)"
ENV_FILE="$BACKEND/.env.qa"
ZAP_VERSION=2.17.0
ZAP_INSTALL="${ZAP_INSTALL:-$HOME/tools/zap/ZAP_$ZAP_VERSION}"
ZAP_HOME="${ZAP_HOME:-$HOME/tools/zap/home}"
REPORTS="$ROOT/private/qa-zap"
PLAN="${1:-}"

die() { echo "qa-zap.sh: $*" >&2; exit 1; }

case "$PLAN" in
  api | site-baseline | site-full | panel-baseline | panel-crawl) ;;
  *) sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
[ -x "$ZAP_INSTALL/zap.sh" ] || die "no ZAP $ZAP_VERSION in $ZAP_INSTALL (docs/qa/security.md says how to install it)."
[ -f "$ENV_FILE" ] && grep -qx 'DB_DATABASE=raqam_qa' "$ENV_FILE" || die "backend/.env.qa is missing or not the QA environment's."
git -C "$ROOT" check-ignore -q "$REPORTS/x.html" || die "private/ is not ignored by git: no report is written."

qa_value() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2-; }

export QA_API="http://127.0.0.1:${RAQAM_QA_PORT:-8100}" QA_SITE="http://127.0.0.1:3300"
export ZAP_REPORT_DIR="$REPORTS" ZAP_OPENAPI="$ROOT/docs/api/openapi.yaml"
QA_SITE_TOKEN="$(qa_value API_SITE_TOKEN)"
QA_ADMIN_PATH="$(qa_value ADMIN_PATH)"
export QA_SITE_TOKEN QA_ADMIN_PATH
[ -n "$QA_SITE_TOKEN" ] && [ -n "$QA_ADMIN_PATH" ] || die "backend/.env.qa has no API_SITE_TOKEN or ADMIN_PATH."
curl -fsS -o /dev/null "$QA_API/livez" || die "the QA API is not answering on $QA_API (scripts/qa.sh serve)."

# A published article of the QA data, for the site's active scan (its Arabic slug percent-encoded, as a browser sends it).
if [ "$PLAN" = site-full ]; then
  curl -fsS -o /dev/null "$QA_SITE/" || die "the QA website is not answering on $QA_SITE (scripts/qa.sh site start)."
  # The API's limit without the token (120 a minute) may be spent by a check just before: wait for it.
  for _ in $(seq 1 30); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$QA_API/api/v1/posts")" = 200 ] && break
    sleep 3
  done
  QA_ARTICLE_PATH="$(curl -fsS -H 'Accept: application/json' "$QA_API/api/v1/posts" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo implode("/", array_map("rawurlencode", explode("/", $d["data"][0]["path"])));')"
  export QA_ARTICLE_PATH
fi

# The signed-in panel: the QA browser state the panel checks keep (e2e/.qa, git-ignored), renewed first by signing in
# as an editor does. Only its cookies for the QA API's host go to ZAP.
QA_PANEL_COOKIE=""
if [ "$PLAN" = panel-crawl ]; then
  (cd "$ROOT/e2e" && npx playwright test --config playwright.qa.config.ts --project panel-session --no-deps --reporter=line > /dev/null) \
    || die "the QA admin could not be signed in (e2e/qa/panel-session.ts)."
  QA_PANEL_COOKIE="$(node -e '
    const state = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));
    process.stdout.write(state.cookies.filter((c) => c.domain === "127.0.0.1").map((c) => `${c.name}=${c.value}`).join("; "));
  ' "$ROOT/e2e/.qa/panel-state.json")"
  [ -n "$QA_PANEL_COOKIE" ] || die "the QA browser state holds no cookie for the panel."
fi
export QA_PANEL_COOKIE

mkdir -p "$REPORTS"
[ -d "$ZAP_HOME/plugin" ] || die "no ZAP home in $ZAP_HOME (docs/qa/security.md: the add-ons are installed there once)."
# Everything of this run lives in a folder of its own, deleted when it ends: ZAP writes the values it was given (the
# token, the cookie) into its log and its saved options, and its session holds every request. So ZAP runs on a copy
# of its home, never on $ZAP_HOME itself, and on a copy of the plan with the values written in (ZAP does not read the
# environment inside a replacer rule).
RUN="$(mktemp -d)"
cleanup() { rm -rf "$RUN"; }
trap cleanup EXIT
cp -r "$ZAP_HOME" "$RUN/home"
rm -rf "$RUN/home/session" "$RUN/home/zap.log"*
(umask 077 && python3 -c '
import os, re, sys
names = ["QA_API", "QA_SITE", "QA_SITE_TOKEN", "QA_ADMIN_PATH", "QA_PANEL_COOKIE", "QA_ARTICLE_PATH", "ZAP_REPORT_DIR", "ZAP_OPENAPI"]
text = open(sys.argv[1], encoding="utf8").read()
text = re.sub(r"\$\{(\w+)\}", lambda m: os.environ.get(m.group(1), "") if m.group(1) in names else m.group(0), text)
open(sys.argv[2], "w", encoding="utf8").write(text)' "$BACKEND/scripts/zap/$PLAN.yaml" "$RUN/plan.yaml")

# The secret values (the token, each cookie's value), taken out of what ZAP prints and out of the reports.
SECRETS="$(printf '%s\n%s' "$QA_SITE_TOKEN" "$QA_PANEL_COOKIE" | tr ';' '\n' | sed 's/^ *//; s/^[^=]*=//' | awk 'length >= 16')"
export SECRETS
scrub() {
  python3 -c '
import os, sys
secrets = [s for s in os.environ["SECRETS"].splitlines() if s]
for line in sys.stdin:
    for s in secrets:
        line = line.replace(s, "[removed]")
    sys.stdout.write(line)
    sys.stdout.flush()'
}

# A free port on 127.0.0.1 for ZAP's own proxy (the plans never use it; it only must not listen anywhere else).
PROXY_PORT="$(python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])')"

set +e
"$ZAP_INSTALL/zap.sh" -cmd -dir "$RUN/home" -newsession "$RUN/session" \
  -host 127.0.0.1 -port "$PROXY_PORT" \
  -config api.enabled=false -config api.disablekey=false \
  -config callhome.tel.enabled=false -config start.checkForUpdates=false -config start.checkAddonUpdates=false \
  -config oast.activeScanService=None -config oast.callback.localaddr=127.0.0.1 -config oast.callback.remoteaddr=127.0.0.1 \
  -autorun "$RUN/plan.yaml" 2>&1 | scrub
status="${PIPESTATUS[0]}"
set -e

# The reports must not hold the token or the session either (ZAP's evidence can quote a request).
while read -r value; do
  [ -n "$value" ] || continue
  { grep -rlF -- "$value" "$REPORTS" 2>/dev/null || true; } | while read -r file; do
    VALUE="$value" python3 -c '
import os, sys
path = sys.argv[1]
text = open(path, encoding="utf8").read()
open(path, "w", encoding="utf8").write(text.replace(os.environ["VALUE"], "[removed]"))' "$file"
    echo "qa-zap.sh: a secret value was found in $(basename "$file") and replaced by [removed]." >&2
  done
done <<< "$SECRETS"
echo "Reports in private/qa-zap/ (ZAP exit status $status; 1 when the plan noted an error, as the OpenAPI reader's licence note)"
exit "$status"
