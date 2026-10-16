# Raqam · رَقَم

Arabic-only digital economic news platform from Nablus, Palestine.

[![Database](https://github.com/AnasMaali/raqam-platform/actions/workflows/database.yml/badge.svg)](https://github.com/AnasMaali/raqam-platform/actions/workflows/database.yml)

## Stack

| Layer | Technology |
|---|---|
| Website | Next.js (App Router), React, TypeScript, Tailwind CSS |
| API & admin | Laravel 12, Filament |
| Database | PostgreSQL 16 — normalised to 3NF |
| Search | Meilisearch with a shared Arabic normaliser (PostgreSQL fallback) |
| Cache & queues | Redis |
| Hosting | Docker Compose on a single VPS behind Cloudflare |

## Repository layout

```
database/   PostgreSQL schema (source of truth), tests and tooling
  schema/         one SQL file per module, applied in order
  raqam_schema.sql  all modules concatenated
  tests/          constraint/trigger tests + PHP↔SQL normaliser parity
  php/            ArabicNormalizer (moves to backend/app/Support)
  tools/          ER-diagram generator and docs builder
docs/       Engineering documentation
backend/    Laravel application        (next)
frontend/   Next.js website            (later)
```

## Database

131 tables in 17 modules. The database enforces the business rules itself
(CHECK constraints, domains, exclusion constraints, deferred and immutable
triggers), so invalid data is rejected even if application code has a bug.

```bash
# requires PostgreSQL 16 (citext, pg_trgm, btree_gist), PHP 8.4, Python 3
./database/run_tests.sh
```

Documentation and per-module ER diagrams: [`docs/database/README.md`](docs/database/README.md).

## Conventions

- Branches: `feature/<module>-<short-name>`, `fix/<short-name>`; `main` is always deployable.
- Every change to `database/schema/*.sql` regenerates `database/raqam_schema.sql`
  (`cat database/schema/*.sql > database/raqam_schema.sql`); CI checks it.
- Secrets never enter the repository (`.env` files are ignored).
