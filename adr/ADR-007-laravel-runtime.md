# ADR-007 — Application Runtime is Laravel (PHP)

**Status:** Accepted
**Date:** 2026-07-27
**Owner:** Founder / Engineering Director
**Supersedes:** the undocumented Express/TypeScript implementation

## Context / Problem

EB-EBP Ch.5 and ADR-001 both specify **Laravel** for `eb-api`, and ADR-002
assigns the event bus to a "Laravel Lead". The first implementation was built
in **Express + TypeScript** instead, and that divergence was never recorded.

The result was two undocumented deviations at once — Laravel→Node and
PostgreSQL→MySQL. A reader of the frozen documents went looking for PHP
controllers and a Postgres schema, and found neither. ADR-006 closed the
database half. This ADR closes the runtime half by aligning the code to the
document rather than the document to the code.

The deciding factor is maintainability, not correctness: both stacks can build
this product, and the team that will own it long-term writes PHP.

## Decision

**The application API is Laravel 11 on PHP 8.2+.** The Express/TypeScript
implementation is retired, not run in parallel — a full cutover, not a
strangler migration.

- **Data access is the Query Builder**, not Eloquent. The existing repositories
  are hand-written parameterised SQL; the Query Builder is a near-literal
  target for them, whereas Eloquent would require inventing model semantics for
  57 tables and would fight the ERP tables the Brain does not own.
- **The REST contract is preserved exactly** — same `/api/v1/...` paths, same
  JSON shapes. `web/` is a React SPA that speaks HTTP and is therefore
  unaffected; it must require zero changes.
- **The MySQL schema is carried over verbatim.** The 38 migrations are wrapped
  as Laravel migrations executing the original DDL rather than rewritten into
  Schema-builder calls, because that DDL has been executed against a live
  server and carries three corrections a rewrite would silently undo (see
  ADR-006 and VERIFICATION_REPORT.md).
- **Domain logic is ported, not reinterpreted.** Confidence computation,
  freshness decay, KASBA scoring, policy evaluation and the case state machine
  are behaviour, not implementation detail. Equivalence is proven numerically
  against the TypeScript, not assumed.

## Alternatives considered

- **Keep Express/TypeScript and correct the Blueprint instead.** Cheaper by a
  wide margin, and the working system had just been verified end-to-end.
  Rejected on team-maintainability grounds — the deciding question is which
  language the team can own for years, not which port is cheaper this quarter.
- **Strangler migration, both runtimes in production.** Lower risk per step,
  but doubles the operational surface and requires session/token compatibility
  across two stacks for the duration. Rejected as disproportionate for a
  pre-pilot product with one tenant.

## Consequences

**Positive:** code and documents finally agree; one language for the team that
owns it; the React frontend and the MySQL schema both survive untouched.

**Negative / trade-offs:** the 266-assertion test suite does not port and must
be rewritten in PHPUnit — until it is, the safety net that caught regressions
is absent. The end-to-end live-database verification recorded in
VERIFICATION_REPORT.md must be repeated against the Laravel build; passing it
once in Node says nothing about PHP. Expect a fresh crop of
integration-boundary defects, because that is what the Node build's first live
run produced.

**Follow-ups:** correct EB-EBP Ch.5's remaining Express references; re-run the
full live-database verification and re-issue VERIFICATION_REPORT.md against
Laravel.
