#!/usr/bin/env bash
# The QA environment: a copy of the platform of its own, beside the developer's, for testing on a full demo database.
# Everything it uses is its own:
#   - the database raqam_qa;
#   - the Meilisearch indexes raqam_qa_*;
#   - Redis databases 14 and 15 with the prefix raqam_qa_;
#   - a storage folder outside the repository ($RAQAM_QA_HOME, ~/raqam-qa by default), where the media go;
#   - backend/.env.qa (git-ignored; made by `setup`).
# Every command names that file (artisan --env=qa, APP_ENV=qa for the server), and refuses to run without it or when
# it points at another database. Nothing here touches raqam, raqam_testing or backend/.env.
#
#   scripts/qa.sh setup                    make .env.qa, the database, the storage folder and the search key; install
#   scripts/qa.sh admin                    create the QA admin (its password goes to ~/.raqam-qa-login, mode 600)
#   scripts/qa.sh demo [--posts=2000 ...]  fill raqam_qa with raqam:demo (2000 posts unless told otherwise)
#   scripts/qa.sh artisan <command ...>    any artisan command against raqam_qa
#   scripts/qa.sh serve                    the API and the panel on 127.0.0.1:8100, media from the QA storage
set -euo pipefail

BACKEND="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
QA_HOME="${RAQAM_QA_HOME:-$HOME/raqam-qa}"
ENV_FILE="$BACKEND/.env.qa"
DB=raqam_qa
PORT="${RAQAM_QA_PORT:-8100}"
SITE="${RAQAM_QA_SITE:-http://127.0.0.1:3300}"
export PGHOST="${PGHOST:-127.0.0.1}" PGUSER="${PGUSER:-raqam}"

die() { echo "qa.sh: $*" >&2; exit 1; }

# The file exists and names raqam_qa: otherwise Laravel would fall back to .env, the developer's own database.
checked() {
  [ -f "$ENV_FILE" ] || die "backend/.env.qa is missing; run scripts/qa.sh setup first."
  grep -qx "DB_DATABASE=$DB" "$ENV_FILE" || die "backend/.env.qa does not name the database $DB; nothing was run."
  grep -qx "MEILISEARCH_PREFIX=raqam_qa_" "$ENV_FILE" || die "backend/.env.qa does not use the raqam_qa_ indexes; nothing was run."
}

artisan() { checked; (cd "$BACKEND" && php artisan --env=qa "$@"); }

random() { openssl rand -hex "$1"; }

setup() {
  [ -e "$ENV_FILE" ] && die "backend/.env.qa exists already; nothing was changed (delete it to start again)."
  if [ -n "$(psql -X -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname = '$DB'")" ]; then
    die "the database $DB exists already; nothing was changed."
  fi

  mkdir -p "$QA_HOME"
  for dir in app/public app/private framework/cache framework/sessions framework/testing framework/views logs; do
    mkdir -p "$QA_HOME/storage/$dir"
  done
  # The web root: the real public files, with /storage pointing at the QA storage's public disk (as storage:link would).
  mkdir -p "$QA_HOME/public"
  for entry in "$BACKEND"/public/*; do
    name="$(basename "$entry")"
    [ "$name" = storage ] || ln -sfn "$entry" "$QA_HOME/public/$name"
  done
  ln -sfn "$QA_HOME/storage/app/public" "$QA_HOME/public/storage"

  # The developer's values never come over: every key is new, every outside service is off.
  umask 077
  python3 - "$BACKEND/.env.example" "$ENV_FILE" <<PY
import sys
values = {
    "APP_ENV": "qa", "APP_KEY": "base64:$(openssl rand -base64 32)", "APP_DEBUG": "false", "APP_URL": "http://127.0.0.1:$PORT",
    "LOG_CHANNEL": "single", "LOG_LEVEL": "debug",
    "DB_DATABASE": "$DB", "DB_USERNAME": "$PGUSER", "DB_PASSWORD": "",
    "REDIS_DB": "14", "REDIS_CACHE_DB": "14", "REDIS_SESSION_DB": "15",
    "MAIL_MAILER": "log", "MEDIA_DISK": "local",
    "ADMIN_PATH": "qa-$(random 6)", "ADMIN_CODE_KEY": "$(random 32)", "TRACKING_HASH_KEY": "$(random 32)",
    "SITE_WEBHOOK_URL": "$SITE/api/revalidate", "SITE_WEBHOOK_SECRET": "$(random 32)", "API_SITE_TOKEN": "$(random 32)",
    "SITE_URL": "$SITE", "MEILISEARCH_PREFIX": "raqam_qa_", "MEILISEARCH_KEY": "",
    "YOUTUBE_API_KEY": "", "OPENWEATHER_API_KEY": "", "INDEXNOW_KEY": "", "BREVO_API_KEY": "", "TURNSTILE_SECRET_KEY": "",
}
extra = {"REDIS_PREFIX": "raqam_qa_", "CACHE_PREFIX": "raqam_qa_cache_", "LARAVEL_STORAGE_PATH": "$QA_HOME/storage"}
out = []
for line in open(sys.argv[1], encoding="utf8").read().splitlines():
    key = line.split("=", 1)[0].lstrip("# ").strip()
    if "=" in line and key in values:
        line = f"{key}={values.pop(key)}"
    elif "=" in line and key in extra:
        continue
    out.append(line)
missing = [k for k in values]
if missing:
    sys.exit(f".env.example no longer has {missing}")
out += ["", "# The QA environment's own (scripts/qa.sh)."] + [f"{k}={v}" for k, v in extra.items()]
open(sys.argv[2], "w", encoding="utf8").write("\n".join(out) + "\n")
PY
  chmod 600 "$ENV_FILE"
  umask 022

  psql -X -q -d postgres -c "CREATE DATABASE $DB"
  # A key limited to the raqam_qa_ indexes, made with the master key (never stored by the application).
  [ -r "$HOME/.raqam-meili-master" ] || die "no ~/.raqam-meili-master; create the search key by hand: scripts/qa.sh artisan search:create-key --env-file=.env.qa"
  MEILISEARCH_MASTER_KEY="$(cat "$HOME/.raqam-meili-master")" artisan search:create-key --prefix=raqam_qa_ --env-file=.env.qa
  artisan migrate --force
  artisan raqam:install
  echo "QA environment ready: database $DB, storage $QA_HOME/storage. Next: scripts/qa.sh admin, then scripts/qa.sh demo."
}

admin() {
  checked
  local login="$HOME/.raqam-qa-login"
  [ -e "$login" ] && die "$login exists already; the QA admin was made before."
  local password
  password="$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-32)"
  (umask 077 && printf 'email=qa-admin@example.org\npassword=%s\n' "$password" > "$login")
  # The password goes in through the command's own questions, never on its command line.
  printf 'مسؤول الفحص\n%s\n%s\n' "$password" "$password" | artisan admin:create qa-admin@example.org
}

demo() {
  local args=("$@")
  [[ " ${args[*]-} " == *" --posts="* ]] || args+=("--posts=2000")
  artisan raqam:demo "${args[@]}"
}

serve() {
  checked
  echo "API and panel on http://127.0.0.1:$PORT (the panel's address is ADMIN_PATH in backend/.env.qa)."
  cd "$QA_HOME/public"
  # Several workers: the browser checks load pages side by side, and one PHP process answers one request at a time.
  APP_ENV=qa PHP_CLI_SERVER_WORKERS="${RAQAM_QA_WORKERS:-4}" exec php -d variables_order=EGPCS -d memory_limit=512M -d upload_max_filesize=40M -d post_max_size=48M \
    -S "127.0.0.1:$PORT" "$BACKEND/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
}

case "${1:-}" in
  setup) setup ;;
  admin) admin ;;
  demo) shift; demo "$@" ;;
  artisan) shift; artisan "$@" ;;
  serve) serve ;;
  *) sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
