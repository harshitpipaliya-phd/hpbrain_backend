# ADR-006 — Relational Store is MySQL

**Status:** Accepted
**Date:** 2026-07-27
**Owner:** Engineering Director
**Deciders:** Founder, Engineering Director
**Supersedes:** the PostgreSQL choice stated in EB-EBP Ch.2, Ch.9 and ADR-003

## Context / Problem

The Engineering Blueprint (Ch.2 §2.5, Ch.9) and ADR-003 name **PostgreSQL** as the
source of truth for contracts-at-rest, transactions, audit and identity. The
implementation uses **MySQL 8** (`mysql2/promise`, `ENGINE=InnoDB`, `VARCHAR(36)`
keys) because the pilot tenant's existing database server is MySQL and the
organisation's data already lives there.

That decision was made in code and never recorded. The result was a repository
that contradicted itself: `docker-compose.yml` started `postgres:16` and set
`PGHOST`/`PGUSER`; `api/docker-entrypoint.sh` announced "Running Postgres
migrations"; `.github/workflows/build-and-test.yml` provisioned a Postgres
service — while `database/src/connection.ts` opened a `mysql2` pool. `docker
compose up` could never work, and CI was validating against a database the
application does not speak.

## Decision

**MySQL 8 is the relational store of record.** Postgres is removed from every
runtime, container and CI path.

- `database/src/connection.ts` — `mysql2/promise` pool. Unchanged; this was
  already correct.
- Migrations are MySQL dialect: `VARCHAR(36)` for every `id` / `*_id` column
  (a UUID fits exactly), `VARCHAR(255)` for any column used in a `UNIQUE`
  constraint or index, `TEXT` only for free text that is never keyed.
  MySQL rejects `TEXT PRIMARY KEY` (error 1170), which is why this matters.
- `docker-compose.yml` provisions `mysql:8.4` with a `mysqladmin ping`
  healthcheck.
- `.github/workflows/build-and-test.yml` provisions the same image, so CI
  validates the database the product actually runs on.
- Connection configuration is `DB_HOST` / `DB_PORT` / `DB_DATABASE` /
  `DB_USERNAME` / `DB_PASSWORD` / `DB_SSL`. No `PG*` variable is read anywhere.

**Neo4j is unchanged.** ADR-003's core claim — that capability and organizational
memory are graph problems, not join problems — is unaffected by the relational
vendor. Neo4j remains the source of truth for the capability graph and
organizational memory; MySQL holds the governed contract-at-rest, audit trail,
identity and event outbox.

## Alternatives considered

- **Migrate the tenant to PostgreSQL to match the Blueprint.** Correct on paper,
  but the pilot organisation's data and DBA capability are MySQL. Changing the
  customer's database to match our document is the wrong direction of fit.
- **Support both via an abstraction layer.** Doubles the test surface and the
  migration dialect problem for zero pilot benefit. Rejected as premature.
- **Leave it undocumented.** This is what happened, and it produced a repository
  that could not be started by anyone reading its own instructions. Rejected.

## Consequences

**Positive:** `docker compose up` works. CI tests the real engine. The
Blueprint and the code stop contradicting each other. The pilot tenant's
existing MySQL server is usable without a migration project.

**Negative / trade-offs:** MySQL has no `JSONB`, no partial indexes, and weaker
transactional DDL than Postgres. Migration authors must keep the
`VARCHAR(36)` / `VARCHAR(255)` key discipline above — a `TEXT` column in a key
fails at `CREATE TABLE` time, not at runtime.

**Follow-ups:**
- EB-EBP Ch.2 §2.5 and Ch.9 must be corrected to say MySQL.
- ADR-003's sentence "the contract-at-rest is authoritative in PostgreSQL"
  reads "in MySQL" as of this ADR.

## Supersedes / Superseded by

Supersedes the PostgreSQL selection only. Every other clause of ADR-003 stands.
