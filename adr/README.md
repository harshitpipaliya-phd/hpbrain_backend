# Architecture Decision Records (ADR)

Canonical log of load-bearing architectural decisions for HP Enterprise Brain.
ADRs are **append-only**: never edit an accepted decision — supersede it with a new ADR.
Every waiver of an EB-EBP / EB-DP standard requires an ADR. Each ADR links to its
Delivery-Playbook decision-log entry (EB-DP Ch.17) so the two never drift.

| ADR | Title | Status | Owner |
|-----|-------|--------|-------|
| [ADR-001](ADR-001-repository-structure.md) | Repository Structure | Accepted | Engineering Director |
| [ADR-002](ADR-002-event-bus.md) | Event Bus | Accepted | Laravel Lead (loop) |
| [ADR-003](ADR-003-graph-model.md) | Graph Model | Accepted | Neo4j Lead |
| [ADR-004](ADR-004-seven-verb-architecture.md) | Seven-Verb Architecture | Accepted | AI Lead |
| [ADR-005](ADR-005-organizational-memory.md) | Organizational Memory | Accepted | Founder / Eng Director |

**Template:** see the improved ADR format in EB-FBR §4.1.
**Location rule:** cross-cutting ADRs live here (`eb-infra`-published index); repo-specific ADRs live in that repo's `/docs/adr/`.
