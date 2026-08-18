# School datasets — students, academic structure and school intelligence

How an organization whose people are **not in the ERP** is represented, and the
exact commands to bring one into a demonstrable state.

---

## 0. Demo runbook

Lions (`1000010`) is already in this state — these commands are what put it
there, and what to re-run if the data changes. All are idempotent; none deletes
anything.

```powershell
cd C:\Users\omshivay\Desktop\ADK\hp-enterprise-brain

# 1. Schema (safe to re-run; only adds columns and indexes)
php artisan migrate --force

# 2. Projection + cache warm-up. REQUIRED after any import.
php artisan students:rebuild 1000010

# 3. Backend and frontend
php artisan serve --port=8000
cd web ; npm run dev
```

**Before starting a queue worker, check what is already queued** — a stale
`CommitIngestionJob` will fire the moment one starts and re-import 388,401 rows:

```powershell
php artisan tinker --execute="echo DB::table('jobs')->count();"
# only if you intend to process what is there:
php artisan queue:work --queue=default --tries=3 --timeout=1800
```

The demo does **not** need a worker. Everything is already imported, and
`dataset:ingest` runs imports in the foreground.

### What to click

Login → Lions → **People** (students, search, open one) → **Departments**
(Academic structure) → **Intelligence Workspace** (school intelligence) →
switch to Sunrise → switch back.

---

## 1. The problem this solves

Lions (tenant `1000010`) has:

| Source | Rows | What one row is |
| --- | --- | --- |
| `lions-result-data` | 388,401 | one **exam result** — one subject, one exam, one year, one student |
| `lions-fees-data` | 10,430 | one **fee receipt** |

and **one** person in the ERP: an administrator.

Two things followed from that, and both were wrong on screen:

- **People** showed a single row — the administrator — for a school with
  thousands of children.
- **Departments** showed *"No departments are recorded for this organization"*,
  which was true and useless.

The naive fixes are both unacceptable. Writing 388,401 people into the ERP's
person table would corrupt master data every other organization depends on, and
inventing departments would publish fabricated units under a real school's name.

### What is done instead

A row per student is **derived** from the files and stored in `hpbrain_students`.
It is a projection: every column is computed from `hpbrain_operational_records`
by `students:rebuild`, nothing is entered by hand, and no student exists who is
not named in a file.

---

## 2. Identity and the join

| Concept | Key | Where it lives |
| --- | --- | --- |
| Student | `enrollment_no` (academic) = `GR NO.` (fees) | `subject_ref` on both datasets |
| Academic record | `enrollment_no + syear + standard + subject + exam` | `natural_key` |
| Fee record | `Sr No. + GR NO. + Receipt No` | `natural_key` |

**Names are never used to join.** Two children share a name more often than a
school expects, and a fee receipt attached to the wrong student is worse than no
fee receipt at all.

`sub_institute_id` is deliberately **not** mapped to anything. It is the source
system's own foreign identifier, not this application's tenant.

### The cohorts, from the live data

| Cohort | Count |
| --- | --- |
| Students in the fee register | 4,052 |
| Students in the academic export | ~5,300 |
| In **both** files | ~1,900 |

`in_academic` and `in_fees` are stored per student so this split is an indexed
count rather than a full outer join of 388k rows against 10k.

---

## 3. The four-year gap — read this before interpreting anything

- Academic results cover **2018–2021**.
- Fee receipts cover **2025–2026**.

They describe **different periods of the same child's life**. The API computes
whether the ranges overlap and reports `contemporaneous: false`; the student
profile and the intelligence panel both render an explicit caution when it is
false.

Do not present "average percentage" beside "fees paid" as a current picture of a
student. It is two separate histories.

---

## 4. What the fee data can and cannot say

**Available:** total collected, receipt count, payment mode, per-student paid
amount, payment history, receipt number, collector, receipt date.

**Not derivable, and never shown:** outstanding, overdue, collection rate,
billed/demand amount, expected revenue.

The reason is one sentence: *the export has an `Amount` per receipt and no billed
or due column anywhere in it*. A rate needs a denominator the source does not
contain. `fees.notDerivable` in the API response names each missing measure and
why, so the absence reads as a property of the file rather than a missing
feature.

---

## 5. Commands

All of these are idempotent. None deletes anything.

### 5.1 Declare what a source is

```powershell
php artisan dataset:configure 1000010 lions-result-data --role=academic

php artisan dataset:configure 1000010 lions-fees-data --role=fees --map='{\"external_ref\":[\"Sr No.\",\"GR NO.\",\"Receipt No\"],\"subject_ref\":\"GR NO.\",\"measure\":\"Amount\",\"measure_unit\":\"INR\",\"title\":\"Student Name\",\"state\":\"Standard\",\"category\":\"Payment Mode\",\"sub_category\":\"Month\",\"owner\":\"Collected By\",\"evidence_timestamp\":\"Receipt Date\",\"evidence_text\":\"Remarks\"}'
```

`--role` is what keeps a literal dataset name out of every controller: it is
stored in `hpbrain_data_sources.config.dataset_role` and read by
`DatasetRegistry`. Inspect with `--show`.

### 5.2 Ingest a file synchronously

```powershell
php artisan dataset:ingest 1000010 lions-fees-data storage/app/ingestion/1000010/<file>.csv
```

Runs the same `IngestionService::commit()` a queue worker would, in the
foreground, with progress. Rows already present are reported as duplicates and
written again — so an interrupted import is **resumed by running it again**.

### 5.3 Repair bare-year timestamps

Only needed for data imported before the `dateTime()` fix (see §8).

```powershell
php artisan dataset:repair-occurred-at 1000010 lions-result-data --wrong-after="2025-01-01 00:00:00" --by-bucket --dry-run
php artisan dataset:repair-occurred-at 1000010 lions-result-data --wrong-after="2025-01-01 00:00:00" --by-bucket
```

**Always dry-run first** — it prints the exact bucket→year mapping it intends to
apply, and applies nothing.

Three modes, in order of preference:

| Flags | How it works | Speed on 273,500 rows |
| --- | --- | --- |
| `--by-bucket --wrong-after=…` | One indexed UPDATE per distinct wrong timestamp | minutes |
| `--wrong-after=…` | Row-by-row, batch selection narrowed by an index range | ~1.5 hours |
| *(none)* | Row-by-row, full predicate over the whole dataset | slowest, most general |

`--by-bucket` is fast because `strtotime()` is deterministic: every row whose
source year was `"2018"` got the *same* wrong timestamp, so the damage occupies a
handful of distinct values rather than being spread across the table. It
**samples each bucket and refuses to rewrite one whose rows disagree on the
source year**, reporting it for the slow path instead — the fast path proves its
own precondition before writing.

`--wrong-after` is a claim about the data (*"nothing legitimate is dated after
this"*), which is why it is opt-in rather than the default.

All modes are idempotent and re-runnable; each batch is its own short
transaction.

### 5.4 Rebuild the student projection

```powershell
php artisan students:rebuild 1000010
```

Run this after **any** import that adds academic or fee rows. Every statement is
`INSERT … SELECT … GROUP BY … ON DUPLICATE KEY UPDATE`, so the collapse happens
inside MySQL and nothing crosses the wire per student.

**It also warms the two expensive derived caches** — the academic structure and
the school intelligence — because a rebuild is exactly what invalidates them. Do
not skip this before a demonstration: without it, the first person to open
Departments or the Intelligence Workspace pays the whole aggregation cost on
screen. `--no-warm` skips it if you only want the projection.

### 5.5 Queue worker

```powershell
php artisan queue:work --queue=default --tries=3 --timeout=1800
```

**Check the queue before starting a worker.** A stale `CommitIngestionJob` will
fire the moment one starts:

```powershell
php artisan tinker --execute="echo DB::table('jobs')->count();"
```

---

## 6. What each screen reads

| Screen | Endpoint | Cost |
| --- | --- | --- |
| People (KPI header) | `GET /students/{t}/summary` | one aggregate over the projection |
| People (rows) | `GET /students/{t}?page=&page_size=&q=&cohort=&sort=` | one indexed page, capped at 100 rows |
| Departments → Academic structure | `GET /students/{t}/structure` | grouped on indexed columns, each list capped |
| Student profile | `GET /students/{t}/{id}` | projection row + first page of each record type |
| Academic / fee records | `GET /students/{t}/{id}/(academic-records\|fee-records)` | paged, capped at 200 |
| Intelligence Workspace | `GET /students/{t}/intelligence` | cached on a data fingerprint |

The frontend chooses between the ERP and student experiences by **asking the
server** how many students the organization has — never by naming a tenant. An
organization with ERP people and no dataset sees exactly the screens it saw
before.

### "100% of the data" means

Every one of the 388,401 academic rows and 10,430 receipts is reachable —
through search, filters, pagination, aggregation and per-student drill-down. It
does **not** mean sending them to a browser, and nothing here ever does.

---

## 7. The three query shapes that made screens slow

All three were full scans of one tenant's slice of
`hpbrain_operational_records` (398,831 rows for Lions). None was fixed by
caching — caching a query that takes thirteen minutes just moves when you wait.

### `MAX(updated_date)` with no index — the worst one

`OrganizationDataProfiler::dataVersion()` computes the intelligence cache key and
therefore runs on **every request, including cache hits**:

```sql
SELECT COUNT(*), MAX(updated_date) FROM hpbrain_operational_records WHERE tenant_id = ?
```

Every index on that table leads `(tenant_id, dataset, …)` and none carries
`updated_date`, so with `dataset` unconstrained MySQL read the whole slice.

Observed on the **live** deployment (host `202.47.117.61`) during this work: six
concurrent copies of this exact statement, each past 950 seconds, produced by
ordinary navigation. Migration `2026_08_18_000400` adds
`(tenant_id, updated_date)`, which makes `MAX()` a single backward seek and
`COUNT(*)` an index-only scan. The result is also memoised per request.

### `COUNT(DISTINCT subject_ref)` alongside a `GROUP BY`

`subject_ref` is not in the `(tenant_id, dataset, <column>)` composites the
academic-structure and intelligence queries group on, so counting it distinctly
forced a read of every row — turning four index-only aggregates into four table
scans. Student counts now come from `hpbrain_students`, which holds one row per
student; the record-level dimensions report records and say so.

### `YEAR(occurred_at)` in a `GROUP BY`

A function of a column cannot use an index. Grouping on the raw `occurred_at`
column is index-ordered and, because the loader writes a bare year as 1 January,
yields exactly the same groups. Years are folded in PHP from a bounded number of
buckets.

**The general rule for this table:** group and filter on the promoted columns
(`status`, `category`, `sub_category`, `subject_ref`, `occurred_at`), never on a
function of them and never on anything inside `payload`.

### What is still expensive, and why that is acceptable

The school-intelligence computation reads the whole dataset a handful of times —
standard-, subject-, exam- and year-wise averages, plus the anomaly scan — and it
cannot be otherwise: `SUM(metric_value)` needs the rows, and no index carries the
measure. It is therefore:

- **cached against a data fingerprint**, so it is computed once per data version
  rather than once per page view; and
- **warmed by `students:rebuild`**, which is the command that invalidates it.

That is why the runbook says to run `students:rebuild` before demonstrating. The
per-screen cost is a cache read; the aggregation cost is paid once, off-screen,
by whoever ran the rebuild.

The read paths a user actually drives — the student list, search, filters,
paging, a student's profile and their records — never touch this table's bulk at
all. They read `hpbrain_students` (one row per student) or an indexed range of
records for one `subject_ref`, and they are measured in tens of milliseconds.

---

## 8. Two bugs this work fixed, recorded so they are not reintroduced

### `occurred_at` was a time of day

`IngestionService::dateTime()` passed a bare year to `strtotime()`, which reads
`"2018"` as **20:18**. Every academic row was stored with `occurred_at` on the
import date, differing only in the minute. That destroyed year-wise analysis and
made the `(tenant_id, dataset, occurred_at)` index useless for the one dataset
that most needed it. `dateTime()` now anchors a bare year to 1 January;
`dataset:repair-occurred-at` backfills rows written before the fix.

### Tenant cache flushing was a silent no-op

`flushTenant()` scanned `storage/framework/cache/data` for filenames containing
the tenant prefix. Laravel's file store writes entries to
`data/<2 hex>/<2 hex>/<sha1 of key>` — the top level holds only directories, so
`is_file()` was false for every entry and no basename ever contained the prefix.
The loop matched nothing on every run. `TenantScopedCache` now keeps a small
per-tenant index of the keys it wrote and forgets exactly those, with no global
flush and no assumptions about how the store spells a key on disk.

---

## 9. Adding another school

Nothing above is Lions-specific.

1. Upload the files through the Ingestion Engine.
2. `dataset:configure <tenant> <source> --role=academic|fees --map=…`
3. `dataset:ingest <tenant> <source> <path>`
4. `students:rebuild <tenant>`

The People, Departments and Intelligence screens pick it up with no code change.
