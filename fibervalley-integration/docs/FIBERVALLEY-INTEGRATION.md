# FiberValley Integration

How FiberValley became another organization in HP Enterprise Brain, and how to
run it. Written for whoever picks this up next — including the parts that are
deliberately unfinished.

---

## The shape of the change

FiberValley is **not a new code path**. It is:

1. one row in `institute_detail` (plus `org_details` and the `Employee` profile
   row the ERP demands), which makes it selectable and addressable;
2. its workbook data, tenant-scoped by that `sub_institute_id`;
3. signal rules attached to it *by the datasets it holds*, evaluated by the
   existing `SignalGenerator`.

Everything downstream — evidence, reasoning, recommendations, decisions,
workspace, analytics, knowledge library — already works once signals exist under
that `tenant_id`. That is what tenant-scoping buys, and it is why there are no
frontend changes: `web/src/utils/tenant.ts` already resolves the selected
organization for every intelligence call.

---

## Running it

```
php artisan db:seed --class=FiberValleySeeder
php artisan brain:import fibervalley --tenant=<id> --generate-signals
```

The seeder prints the allocated `sub_institute_id`. It is idempotent — re-running
reports the existing id rather than allocating a second one.

Useful variants (one option per token; PowerShell rejects `&&`):

```
php artisan brain:import --list
php artisan brain:import fibervalley --tenant=7 --dry-run
php artisan brain:import fibervalley --tenant=7 --only=complaints
php artisan brain:import fibervalley --tenant=7 --log-rows
```

Workbooks live in `storage/imports/fibervalley/`. File patterns are globs
resolved to the **most recently modified** match, so next quarter's
`Complain 2025-26 R3.xlsx` is picked up with no config change.

**Re-running is the normal case.** Every profile declares a natural key;
unchanged rows are skipped by content hash, changed rows update in place, new
rows insert. A second import of an unchanged workbook creates nothing.

---

## Verified against the real data

Full pipeline, MySQL-shaped schema on SQLite, four production workbooks:

| Profile | Rows read | Created | Time |
|---|---:|---:|---:|
| complaints | 65,268 | 65,268 | 14.3s |
| work_orders | 5,790 | 5,790 | 1.6s |
| helpdesk_monthly | 12 | 12 | <0.1s |
| helpdesk_attendance | 365 | 2,980 | 0.3s |
| field_attendance | 2,570 | 22,366 | 2.1s |
| staff_roster | 96 | 96 | 0.1s |

Re-import: 0 created, all skipped, row totals unchanged, table fingerprint
identical across three consecutive runs.

Signal generation produced **9 signals**: the 7 operational rules, plus 2 of the
5 pre-existing ERP rules (`departments_without_manager`, `people_without_email`)
which fire for FiberValley automatically because the roster loads into the ERP
tables. That reuse was the point of splitting the loaders.

Test suite: **332 passing** (309 pre-existing, 23 new).

---

## Why the roster goes into the ERP and everything else does not

`docs/ERP-TO-BRAIN-MAPPING.md` already draws this line: the Brain reads
Organization, Department and Person from the ERP and owns everything it reasons
*with*.

Of the four workbooks, only the `Left ot Join` sheet is master data — department,
employee name, reporting TL. It loads into `hrms_departments` and `tbluser`, so
`PersonRepository`, `DepartmentRepository`, the home-screen ERP tiles and the
five existing signal rules all work with no new code.

The other three are transactional. A complaint ticket is not a Person, and
forcing 65,268 of them into `tbluser` would corrupt the master-data semantics
every other organization depends on. They go to `hpbrain_operational_records`.

---

## Judgement calls worth knowing about

**Blank attendance cells are not absences.** The sheets distinguish an empty cell
("no submission recorded") from an explicit `Absent`. Importing blanks as
absences would manufacture accusations against named employees out of missing
paperwork. `matrix.skip_blank` enforces this.

**Unparseable numbers become NULL, never 0.** The `Hours` column contains the
literal string `NO`; `Time Group` contains one `#VALUE!`. Casting those to zero
would silently *improve* the SLA figure with every bad row. Per the Product
Bible: a fact the system hasn't verified is null, never defaulted to 0.

**The roster leaves email and employee number blank.** Not laziness — the source
has neither, and synthesising placeholders would both put fictional data in the
ERP and suppress the `people_without_email` rule, which is reporting a real gap.

**Duplicate keys resolve last-occurrence-wins.** The attendance source contains
241 duplicate (date, person) submissions, 35 of them contradicting each other —
22,628 submissions collapsing to 22,366 person-days. The rule is applied
identically whether or not the row has been imported before, so the table
converges on the first run rather than the second.

**Rollback will not delete ERP master data.** `ErpRosterLoader::createdIds()`
returns empty deliberately. Staff rows are referenced by other tables and cited
as evidence; undoing a spreadsheet load must not be able to erase an
organization's people. Operational records *are* rollbackable.

---

## Defects found in existing code

**Fixed (both were dead paths that could never have worked):**

- `ImportEngine::parseFile()` handled CSV only, while `validateFile()` accepted
  `.xlsx`. An xlsx preview returned zero rows and reported itself valid — an
  empty success.
- `ImportEngine::rollbackImport()` looked the job up with a hardcoded
  `find('platform', $jobId)`. Every repository scopes by tenant, so this returned
  null for every real organization and the method answered `false` without
  touching the data. Verified: 5,790 recorded ids, rollback returned false, zero
  rows removed. Now takes an optional tenant id (backward-compatible signature)
  and `ImportController::rollback()` passes the resolved tenant.

**Found and deliberately NOT fixed:**

- `SignalGenerator::createEvidence()` writes `'signal_id' => $signalTenantId` —
  it stores the *tenant* id in the signal foreign key. Every evidence row the
  five ERP rules have ever written points at a signal that does not exist, which
  is why those signals show zero linked evidence in the Evidence Workspace.
  Correcting it rewrites data live organizations have already written, so it
  needs its own migration and its own decision — not something to smuggle into
  this change. The new `recordEvidence()` does it correctly and links evidence
  to the signal after creation.
- `ImportEngine::processImport()` is still a no-op that marks jobs complete
  having written nothing. Left alone; the new path goes through
  `WorkbookImporter`. If the CSV API route is ever used in anger, this needs
  attention.

---

## Adding the next workbook, or the next organization

Edit `config/import_profiles.php`. No new class, no migration, no engine change.

A profile declares: file glob, sheet name, header row, shape
(`tabular` | `matrix`), loader (`operational` | `erp_roster`), natural key,
column map, casts, and which columns to keep in `payload`.

Two safety properties are worth relying on:

- A profile naming a column the workbook does not contain **fails before writing
  anything**, listing both the missing and the available headers. A renamed
  column can never silently import 65,000 rows with a null field.
- Header matching is case- and whitespace-insensitive, because the real exports
  contain `zone` lowercase among title-case siblings and `Junction Box ` with a
  trailing space.

Profiles are also mirrored into `hpbrain_entity_mappings`, which shipped with no
writer and had been dead weight — so the mapping is now visible through the
existing `GET /api/v1/entity-mappings/{tenantId}`.

---

## Why there is no new Composer dependency

`app/Support/Spreadsheet/XlsxReader.php` is a streaming reader on `ZipArchive` +
`XMLReader`.

PhpSpreadsheet would materialise a Cell object per cell — about 2.1 million
objects for the complaint sheet alone, routinely a gigabyte to open. Every import
here is a forward-only pass, so that object graph is pure waste. Measured on the
real 65,268 × 33 sheet: **8 MB peak, 5.9s**.

It handles: sheets addressed by name via `workbook.xml` → rels (tab order and
`sheetN.xml` numbering do not agree); cells placed by decoding the column letters
in the `r` attribute (empty cells are omitted from the XML entirely, so encounter
order shifts every later value); shared strings including multi-run `<si>`; and
Excel date serials anchored at 1899-12-30 to absorb the 1900-leap-year bug.

It does not handle formulas (cached values only), styling, or writing. If any of
those are ever needed, that is the moment to reconsider PhpSpreadsheet — not
before.

---

## Files

**New:** `app/Support/Spreadsheet/{XlsxReader,SpreadsheetException}.php`,
`app/Services/Import/*` (profile, registry, mapper, importer, exception),
`app/Services/Import/Loaders/*`, `app/Repositories/OperationalRecordRepository.php`,
`app/Domain/Signals/{OperationalSignalRules,SignalRuleRegistry}.php`,
`app/Console/Commands/ImportOrganizationData.php`,
`database/seeders/FiberValleySeeder.php`,
`database/migrations/2026_08_04_000100_operational_records.php`,
`config/import_profiles.php`, `tests/Feature/FiberValleyImportTest.php`,
`tests/fixtures/imports/testco/*`.

**Modified (five, all additive):** `app/Services/ImportEngine.php`,
`app/Domain/Signals/SignalGenerator.php`,
`app/Http/Controllers/Api/ImportController.php`, `config/brain.php`,
`tests/Support/BuildsBrainSchema.php`.

**Untouched:** the entire `web/` SPA, `EnsureTenantScope`, `WorkspaceController`,
`AnalyticsController`, `OrganizationRepository`, `KnowledgeLibraryController`,
every existing migration, every existing seeder.

---

## Not done yet

- **`--generate-signals` runs synchronously.** Fine at this volume (0.7s), but it
  belongs behind the existing event/queue backbone if imports get scheduled.
- **No scheduled import.** Today it is a manual artisan invocation. If FiberValley
  drops a new export monthly, wire `brain:import` into the scheduler.
- **Rules evaluate the latest month only.** Deliberate — a year of breaches is
  noise, last month is actionable — but there is no trend rule yet ("breach rate
  rose for three consecutive months"), which is the obvious next addition and
  needs no new data.
- **`field_attendance` and `helpdesk_attendance` are imported but no rule reads
  them.** ~25,000 records of staff attendance are sitting there. An
  absence-versus-workload rule (does complaint backlog track TL-level absence?)
  is the highest-value rule not yet written.
