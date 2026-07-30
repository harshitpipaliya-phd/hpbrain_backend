# @hpbrain/contracts -- the hub

Every other package imports from here. Nothing else is authoritative.

## Contents

| File | Source of truth | Notes |
|---|---|---|
| `eso/eso.schema.yaml` | Product Discovery section 5.2 | **TWELVE blocks.** Not nine -- see the header. |
| `taxonomy/root-cause.schema.yaml` | Product Bible | Eight families. |

## Workflow

```bash
npm install
npm run generate     # schemas -> dist/*.d.ts
```

Never edit `dist/`. CI fails the build if generated types are stale.

## Open decisions -- resolve before dropping DRAFT on the ESO schema

See the block at the bottom of `eso/eso.schema.yaml`. The three that matter:

1. **objective enum** -- section 5.2 says `DEVELOP|PERFORM|ASSESS|DECIDE`; the Product
   Bible says `Assessment|Learning|Workflow|Communication`. Two taxonomies. Pick one.
2. **executorClass** -- section 5.2 binds executor **per step** (`human|agent|software|hybrid`);
   the Blueprint says per-ESO (`human|ai|hybrid`). Adopt section 5.2, correct the Blueprint.
3. **autonomy** -- trust ladder `observe|suggest|approve|autonomous`.
   Recommend: trustLevel = ceiling; runtime computes effective autonomy - ceiling.
