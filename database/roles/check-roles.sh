#!/usr/bin/env bash
# Checks database/roles/production-roles.sql on a database of its own (docs/deployment-requirements.md, "The
# database roles"): two roles, a database the owner role owns, the schema built by that owner, the grants, then
# check-roles.sql run as the application role.
#
#   PGHOST=... PGPORT=... PGUSER=<a superuser of a scratch server> database/roles/check-roles.sh
#
# It makes the roles raqam_check_owner and raqam_check_app (no login: the superuser takes them on with
# `options=-c role=...`) and the database raqam_roles_check, drops that database first if it is there, and leaves
# both behind for a look. Never point it at a server whose roles or data matter.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
DB=raqam_roles_check
OWNER=raqam_check_owner
APP=raqam_check_app

[[ "$(psql -X -At -d postgres -c 'SELECT rolsuper FROM pg_roles WHERE rolname = current_user')" == t ]] \
  || { echo "check-roles: needs a superuser of a scratch server (it makes roles)" >&2; exit 1; }

psql -X -q -v ON_ERROR_STOP=1 -d postgres <<SQL
DROP DATABASE IF EXISTS $DB;
DO \$\$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$OWNER') THEN CREATE ROLE $OWNER NOLOGIN; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$APP') THEN CREATE ROLE $APP NOLOGIN; END IF;
END \$\$;
CREATE DATABASE $DB OWNER $OWNER TEMPLATE template0 ENCODING 'UTF8';
SQL

as() { PGOPTIONS="-c role=$1" psql -X -q -v ON_ERROR_STOP=1 -d "$DB" "${@:2}"; }

echo "==> The schema, built by the owner"
as "$OWNER" -f "$HERE/../raqam_schema.sql"
# Laravel's own list (php artisan migrate makes it); here only so the grants can name it.
as "$OWNER" -c "CREATE TABLE migrations (id serial PRIMARY KEY, migration varchar(255) NOT NULL, batch integer NOT NULL)"

echo "==> production-roles.sql, run by the owner"
as "$OWNER" -v owner="$OWNER" -v app="$APP" -v db="$DB" -f "$HERE/production-roles.sql"

echo "==> check-roles.sql, run as the application"
OUT="$(PGOPTIONS="-c role=$APP" psql -X -v ON_ERROR_STOP=1 -d "$DB" -f "$HERE/check-roles.sql")"
echo "$OUT" | grep -E "PASS|FAIL" || true
SUMMARY="$(echo "$OUT" | grep -E '^ +[0-9]+ \| +[0-9]+$' | tail -1)"
echo "passed/failed: $SUMMARY"
[[ "$(echo "$SUMMARY" | awk -F'|' '{gsub(/ /,"",$2); print $2}')" == 0 ]]
