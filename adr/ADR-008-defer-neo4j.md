# ADR-008 — Neo4j Deferred; MySQL is the Sole Store for v1

**Status:** Accepted
**Date:** 2026-07-27
**Owner:** Engineering Director
**Amends:** ADR-003 (Graph Model) — for v1 only; ADR-003 is not withdrawn

## Context / Problem

ADR-003 makes Neo4j the source of truth for capability and organizational
memory, on the reasoning that "what capability does this person hold, at what
state, evidenced by what" is a traversal, not a join. That reasoning is sound
at scale.

Two facts complicate it now.

**First, the implementation never did this.** Every Neo4j write in the codebase
is a `syncOrganization()`, `syncPerson()`, `syncCapability()` call, fired from
an event consumer *after* the relational write has already committed. MySQL is
the system of record in the running code; Neo4j is a read-optimised projection
of it. ADR-003 describes an architecture that was designed but not built.

**Second, ADR-007 moves the runtime to PHP.** Neo4j publishes an official
first-party driver for JavaScript. It publishes none for PHP. The realistic
option is a community-maintained client, for the component the architecture
leans on hardest.

## Decision

**Neo4j is deferred out of v1. MySQL 8 is the sole datastore.**

- Traversal queries that motivated the graph — organizational hierarchy,
  capability chains, signal→evidence→decision→outcome→learning lineage — are
  expressed with MySQL 8 `WITH RECURSIVE` common table expressions.
- The graph seam is preserved, not deleted: graph-shaped reads live behind a
  `GraphQueryPort` interface with a MySQL implementation. Reintroducing Neo4j
  means adding an adapter, not unpicking call sites.
- **Graph Explorer is out of v1 scope.** It is the one surface that genuinely
  needs a graph engine, and shipping a degraded version would misrepresent the
  product.
- No data is lost by this decision, because the graph holds nothing the
  relational store does not already hold.

## Alternatives considered

- **`laudis/neo4j-php-client` inside Laravel.** Viable, and the maintainers are
  responsive. Rejected for v1 because it places an unofficial dependency under
  the moat during a runtime migration that is already introducing risk.
- **Retain a small Node service purely for graph access.** Technically clean and
  keeps the official driver. Rejected as contradicting ADR-007's whole purpose —
  the point of that decision was one language the team can maintain.
- **Keep Neo4j and delay v1.** Rejected: the pilot is one institute. The
  traversal volumes that justify a graph engine are far above that.

## Consequences

**Positive:** one datastore to run, back up and restore. This retires a
pilot-gate item that has never been satisfiable — *"point-in-time restore of
graph AND relational, coordinated and consistent"* — since cross-store
consistent restore is genuinely hard and has never been rehearsed. It also
removes the largest single risk in the Laravel port.

**Negative / trade-offs:** deep multi-hop queries will be slower and more
awkward in SQL than in Cypher, and that gap widens with depth. Graph Explorer
is deferred. If capability-graph traversal becomes a hot path, this decision
must be revisited — and ADR-003 stands ready, which is why it is amended here
rather than superseded.

**Revisit when:** traversals routinely exceed three hops, or a single tenant's
capability graph passes roughly 10^6 relationships.
