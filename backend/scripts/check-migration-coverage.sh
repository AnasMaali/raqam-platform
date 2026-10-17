#!/usr/bin/env bash
# Fail if a file in database/schema/ has no matching migration (or a migration
# names a schema file that does not exist). No database needed.
# database/schema/NN-<module>.sql  <->  database/migrations/*_schema_NN_<module>.php
set -euo pipefail

BACKEND="$(cd "$(dirname "$0")/.." && pwd)"
SCHEMA="$(cd "$BACKEND/../database/schema" && pwd)"

expected="$(cd "$SCHEMA" && ls -1 [0-9][0-9]-*.sql | sed 's/\.sql$//' | sort)"
actual="$(cd "$BACKEND/database/migrations" && ls -1 *_schema_[0-9][0-9]_*.php 2>/dev/null \
  | sed -E 's/^.*_schema_([0-9]{2})_(.*)\.php$/\1-\2/; s/_/-/g' | sort)"

missing="$(comm -23 <(echo "$expected") <(echo "$actual"))"
orphans="$(comm -13 <(echo "$expected") <(echo "$actual"))"

[[ -z "$missing" ]] || { echo "::error::schema files without a migration: $(echo $missing)"; exit 1; }
[[ -z "$orphans" ]] || { echo "::error::migrations without a schema file: $(echo $orphans)"; exit 1; }
echo "all $(echo "$expected" | wc -l) schema files have a migration"
