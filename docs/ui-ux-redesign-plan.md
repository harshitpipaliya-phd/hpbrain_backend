# UI/UX Redesign — Implementation Plan

**Scope:** every user-facing screen of the Enterprise Brain SPA.
**Constraint:** no business logic, API contract, permission, route or auth behaviour changes.

---

## 1. Detected stack

Established by inspection, not assumption.

| Concern | What is actually there |
| --- | --- |
| Framework | React 18.3 + TypeScript 5.5, Vite 5.4 |
| **Routing** | **No router library.** `App.tsx` holds a `View` union of **31 values** and renders by `view === '…' && <Screen/>`. Navigation is `setView`. There are no URLs. |
| **State** | **No library.** Local `useState` per screen; session persisted to `localStorage` via `utils/session.ts`. |
| **Forms** | **No library.** Manual `useState` + hand-rolled validation. |
| Styling | Plain CSS. `design-tokens/*.css` (EB-DLS v1.0, vendored verbatim) → `theme.css` → `dashboard.css`. |
| Charts | `recharts` 3.9 |
| **Icons** | **None.** Unicode glyphs (`⌘ ▢ ☷ ⤳ ◆ ✦`) used as icons. |
| HTTP | `api/client.ts` — single entry point, handles auth header, 401 refresh, error unwrapping, camelCase aliasing |
| Tests | Vitest + Testing Library — 17 files, 89 tests |
| Components | 107 `.tsx` across 19 directories |

### What `rcl/` is — and is not

`components/rcl/` looks like a primitive layer but is not one: it holds eight **domain**
widgets (`KasbaBadge`, `HypothesisRow`, `EvidenceLink`, `SituationCard`…).

**There is no generic UI primitive anywhere in the codebase** — no `Button`, `Card`,
`Input`, `Table`, `Modal`, `Tabs`, `Tooltip`. That absence is the root cause of every
symptom in §3, and closing it is Phase 1.

---

## 2. Route list (31 views)

| Group | Views |
| --- | --- |
| Overview | `home` *(= Command Center)*, `commandcenter` *(alias)* |
| Foundation | `list`, `create`, `edit`, `details`, `archive`, `departments`, `people`, `capabilities` |
| Intelligence Loop | `signals`, `evidence`, `deliberation`, `workspace`, `executions` |
| Analytics | `executive`, `analytics`, `decisionintel`, `mentalmodels` |
| Knowledge | `graph`, `kasbaexplorer`, `search`, `copilot`, `aiworkspace`, `knowledgelibrary`, `memory`, `esolibrary` |
| Automation | `agents`, `tasks`, `policies` |
| Account | `settings` |
| Unauthenticated | Login (rendered before the shell, not a `View`) |

Visibility is filtered per role in `Sidebar.tsx` (`admin`, `tenant_admin`, `manager`,
`analyst`, `viewer`, `member`). **That matrix is preserved exactly.**

---

## 3. Main UI problems — measured

| Problem | Measurement |
| --- | --- |
| **Inline styles everywhere** | **1,087** `style={{…}}` occurrences across **86 of 107** components |
| **Hardcoded colours** | **225** raw hex literals in component files |
| **No shared table** | **19** files hand-roll `<table>`; no shared sort/filter/pagination/empty/loading |
| **No shared dialog** | Only **2** ad-hoc dialog patterns; no focus trap, no Escape handling |
| **No primitives** | Every button, card and input is bespoke markup |
| **No icon system** | Unicode glyphs render inconsistently across platforms and cannot be sized or coloured reliably |
| **No URLs** | A `view`-state switch means no deep links, no back button, no shareable screens |

### Why inline styles are the core issue

A `style` attribute cannot express `:hover`, `:focus-visible`, `:disabled`, or a media
query. Every screen styled this way is **structurally incapable** of having the
interaction and responsive states this brief requires. They cannot be fixed by editing
values — the markup has to move to classes.

---

## 4. Proposed design system

### Token layer

`design-tokens/colors.css` declares itself copied verbatim from the EB-DLS source of
truth, so **it is not edited**. A new layer, `design-tokens/brand.css`, loads after it and
re-points the semantic roles to the specified palette. Same technique already used for
the health colours in `dashboard.css`.

Navy `#071426 · #0A192D · #10243D` · Gold `#D5A32C · #E4B442 · #F0C75E` ·
Warm `#F7F6F1 · #FAF9F5 · #FFFFFF` · Text `#0F172A · #334155 · #64748B · #94A3B8` ·
Success `#16A34A` · Warning `#D97706` · Danger `#DC2626` · Info `#2563EB`

Plus: radius (8/12/16/22), shadows (sm/md/lg as specified), 8-point spacing, sidebar
260/76, topbar 64, z-index scale, transitions, table density, control sizes.

### Component library — `src/ui/`

A new directory, deliberately separate from `components/` (domain) and `rcl/` (domain
widgets). Every primitive is class-based so it can carry hover/focus/disabled/loading.

Layout · `AppShell` `Sidebar` `SidebarSection` `SidebarItem` `TopHeader` `Breadcrumbs`
`PageHeader` `PageToolbar`
Actions · `Button` `IconButton` `Dropdown` `CommandPalette`
Data · `Card` `MetricCard` `DataTable` `TablePagination` `SortControl` `FilterBar`
`StatusBadge` `RiskBadge` `ScoreGauge` `ChartCard` `Avatar`
Forms · `TextInput` `Textarea` `Select` `MultiSelect` `Checkbox` `RadioGroup` `Switch`
`SearchInput` `DatePicker` `FormField` `FormSection`
Feedback · `Alert` `Toast` `Tooltip` `Popover` `Modal` `ConfirmationDialog` `Drawer`
`Spinner` `LoadingSkeleton`
States · `EmptyState` `ErrorState` `PermissionDeniedState` `NotFoundState`
Navigation · `Tabs` `Accordion` `Stepper` `Timeline` `ActivityFeed`

### Icons

`lucide-react` — tree-shakeable, one consistent 24px grid, replaces the unicode glyphs.

---

## 5. Migration order

| Phase | Work | Risk |
| --- | --- | --- |
| **1** | Tokens, global styles, `src/ui/` primitives, icons | **Low** — additive only; nothing existing is edited |
| **2** | App shell, sidebar, header, breadcrumbs, responsive | Medium — touches `App.tsx` |
| **3** | Login + auth screens, particle canvas | Low — isolated |
| **4** | Command Center, Organizations, Departments | Medium |
| **5** | People, Capabilities, Signals, Evidence, Deliberation, Workspace, Execution | **High volume** — ~7 screens, heaviest inline-style debt |
| **6** | Analytics, Knowledge, Search, Settings, admin | **High volume** — ~12 screens |
| **7** | States, dialogs, responsive + a11y sweep, consistency review | Medium |

Each phase must build, typecheck and pass tests before the next begins.

---

## 6. Risks and compatibility concerns

**1 — Volume is the dominant risk.** 86 components and 1,087 inline styles. Phases 5–6
are roughly 19 screens of mechanical but careful conversion. This is not a
single-sitting job, and any claim that it is would be false.

**2 — `useTheme` is load-bearing.** 21 screens read colours from it. It was repointed at
the design tokens earlier this session, so those screens follow the theme today. It stays
until each screen migrates to classes; deleting it early would break all 21 at once.

**3 — No router means no URL to verify against.** Screens can only be reached by
clicking. Introducing a router would give deep links and browser-back, but it changes
navigation architecture and is **out of scope** unless requested.

**4 — The vendored token file must not be edited.** It states it is copied verbatim from
the design-system source. All palette work goes in the overlay layer.

**5 — `web/` is gitignored** (`.gitignore:67`). None of this frontend work is version
controlled, so a branch switch cannot restore it. **Recommend tracking `web/` before
Phase 5** — that is where the bulk of irreversible manual work lands.

**6 — Status colour collision, still open.** In the light theme `--status-good`
(`gold-600`) and `--status-warn` (`amber-600`) are near-identical browns, and
`--feedback-success-*` is byte-identical to `--feedback-info-*`. Worked around locally in
`dashboard.css`; the real fix belongs upstream in EB-DLS.

**7 — Bundle size.** Already 739 kB in one chunk. Phase 1 adds `lucide-react`
(tree-shaken, small). Code-splitting the 31 screens is listed under Phase 7 performance
work and would substantially reduce first load.

---

## 7. Definition of done per screen

1. `PageHeader` with title, supporting text, consistently placed primary action
2. Shared primitives — no bespoke button/card/table markup
3. Loading (skeleton), empty, error and permission states
4. Responsive at 360 / 768 / 1024 / 1280 / 1440 / 1920
5. Keyboard reachable, visible focus, status never colour-alone
6. No hardcoded hex; tokens only
7. Business logic, API calls and permissions untouched
