# Performance Diagnosis — slow login and slow data loading

**Date:** 2026-08-02
**Method:** measured against the live database, not inferred from the code.

---

## 1. The finding

**The database is remote, and the network is the bottleneck. Nothing else comes close.**

`DB_HOST` is a public IP address, not this machine. Measured:

| Operation | Time |
| --- | --- |
| TCP + MySQL auth handshake | **~1,200 ms** — paid on **every request** without pooling |
| A trivial `SELECT 1` | **~480 ms average** (best 39 ms, worst 1,505 ms) |
| ICMP ping to the host | 100% packet loss — the path is filtered and unstable |

Application-side costs, for comparison:

| Operation | Time |
| --- | --- |
| Composer autoload | 25 ms |
| Laravel boot | 136 ms |

So the cost of any page is essentially:

```
total ≈ 1,200 ms  (connect)  +  (number of queries × ~480 ms)
```

### What this rules out

I checked the usual suspects first, and they are all fine:

- **Indexes are not the problem.** `brain:db-diagnostics` reports every hot filter column
  already indexed, on both the ERP and Brain tables.
- **Data volume is not the problem.** The database is small: 288 users, 1,170 departments,
  6 organizations, 1 signal, 342 audit rows.
- **Query complexity is not the problem.** `SELECT 1` — which reads nothing — averages 480 ms.

The corollary matters more than the finding: **optimising a query buys nothing here. Removing a
query buys ~480 ms.** Round-trip count is the only thing worth counting.

---

## 2. Fixes applied

### 2.1 Login stopped re-hashing an already-correct password

`AuthController::verifyErpPassword()` called `Hash::make()` and `UPDATE tbluser` on **every**
successful login, even when the stored value was already a valid bcrypt hash — which it is for
every user after their first login. The write changed nothing but the salt.

At the configured bcrypt cost of 12, measured on this machine:

- `Hash::check` — **376 ms** — necessary, this is the verification
- `Hash::make` — **336 ms** — pure waste
- the `UPDATE` — **~480 ms** — one remote round trip, also pure waste

The rehash now runs only when there is something to migrate: a legacy plaintext/md5 column, or a
hash below the configured work factor (`Hash::needsRehash`).

**Saved: ~816 ms per login.** It also stops taking a row-level write lock on `tbluser` — the ERP's
shared user table — on every login.

Pinned by `ErpLoginTest::test_an_already_hashed_password_is_not_rewritten_on_login`, which asserts
the stored hash is **byte-identical** after login. That specific assertion is deliberate: an
unconditional rehash still leaves a *valid* hash, so every other test in the file would keep passing
while login silently doubled in cost again.

### 2.2 Home screen: 9 queries collapsed to 5

`WorkspaceController::homeMetrics()` ran nine `COUNT` queries. Three scanned exactly the same
`tbluser` rows under exactly the same predicate and kept one tally each; two did the same to
`hrms_departments`; two more to `hpbrain_signals`, where the high-severity count is a subset of the
open count.

Rewritten with conditional aggregation (`SUM(CASE WHEN ...)`, which reads identically on MySQL and
SQLite) so each set of rows is read once.

**Saved: 4 round trips ≈ 1,900 ms.** Verified end-to-end against the live database: now 5 queries,
1,521 ms. Covered by `HomeMetricsTest` — 11 cases, 92 assertions, all still passing, so the numbers
it reports are unchanged.

### 2.3 People list stopped selecting every column

`PersonController::index()` was an unbounded `SELECT *` on `tbluser`, the ERP's widest table. It
pulled every column of every employee across a slow link so that `map()` could discard all but
eleven — and among the discarded columns were `password` and `plain_password`, credential material
crossing the network and sitting in process memory to render a name and a department.

Now selects exactly the eleven columns used. Applied to `index`, `search` and `show`.

### 2.4 Cache store moved off the database

`CACHE_STORE=database` was the single most self-defeating setting present. A cache exists to be
faster than recomputing a value; a database-backed cache made every `get()` a ~480 ms network round
trip — so caching cost **more** than not caching, and every miss was pure loss.

Changed to `file` in `.env` (backup written to `.env.bak-perf`) and in `.env.example`.
Move to `redis` if there is ever more than one application server, since `file` is per-server.

### 2.5 Persistent connections made available (opt-in, currently off)

Added `DB_PERSISTENT` to `config/database.php`. When enabled, the PHP-FPM/Apache worker keeps the
connection open and reuses it, so only each worker's first request pays the **~1,200 ms** handshake.

**Left OFF by default, deliberately.** A pooled connection can carry server-side state — an open
transaction, a session variable, a temp table — into whichever request picks it up next, and this
link already shows 39–1505 ms jitter and drops packets. It is also pointless under
`php artisan serve` and multiplies open connections by worker count, which the shared ERP server has
a `max_connections` budget for.

Also added `DB_TIMEOUT` (default 10s) so an unreachable database returns an error in seconds instead
of occupying a web worker until the default timeout expires — otherwise a database blip becomes an
application outage.

### 2.6 New tool: `php artisan brain:db-diagnostics`

Read-only. Reports row counts and index coverage for every hot query path, on both ERP and Brain
tables, and flags any unindexed table over 1,000 rows. Safe against production; it writes nothing.
Run it before assuming an index is missing — on this database it proves they are not.

---

## 3. Expected effect

| Path | Before | After | Saved |
| --- | --- | --- | --- |
| Login | ~3.7 s | ~2.9 s | **~0.8 s** |
| Home screen metrics | ~3.4 s | ~1.5 s | **~1.9 s** |
| People list | full-row transfer | 11 columns | proportional to workforce size |
| Any cache read | ~480 ms | microseconds | ~480 ms per lookup |

Both remaining figures are still dominated by the ~1,200 ms handshake plus ~480 ms per query. **That
is network cost, and no amount of application tuning removes it.**

---

## 4. What would actually make this fast

The fixes above remove waste. They do not change the fundamental constraint: the application is
several hundred milliseconds away from its own database, over a lossy public link. In rough order of
effect:

1. **Move the application next to the database.** Same datacentre, ideally same private network.
   This turns a ~480 ms query into a sub-millisecond one and makes every other item here
   unnecessary. It is by a wide margin the highest-value change available, and it is an
   infrastructure decision rather than a code one.

2. **If that is not possible, put a private link between them** — VPN or peering rather than the
   public internet. The 100% ICMP loss and the 39→1505 ms spread say the current path is not just
   distant but unreliable, which shows up to users as *intermittent* slowness rather than
   consistent slowness, and is much harder to trust.

3. **Enable `DB_PERSISTENT=true`** once running under PHP-FPM/Apache with a known worker count and
   `max_connections` headroom. Removes ~1,200 ms from nearly every request. Verify the worker count
   × server limit arithmetic first.

4. **Keep auditing round-trip counts.** With the per-query cost this high, the cheapest future win is
   always "does this endpoint really need N queries?" `DB::enableQueryLog()` around a controller
   answers it in seconds.

5. **Check how the app is served.** `php artisan serve` is single-threaded and handles one request at
   a time — with responses in the seconds, concurrent requests queue behind each other and the whole
   UI feels frozen. Use PHP-FPM or Apache/nginx with multiple workers for anything but local
   development.

---

## 5. Not done, and why

- **`2026_08_02_000200_erp_lookup_indexes.php` will do nothing on this database, by design.** It was
  written before the diagnostics were run, and it skips any table whose leading filter column is
  already indexed — which, as §1 establishes, is all of them. It is kept because it is correctly
  guarded and costs one metadata read per index, and it protects a future deployment whose ERP
  schema is less complete. It is not a fix for the current slowness.

- **Pagination was not added to the people list.** At 288 rows it would be premature, and it would
  change the API contract the SPA depends on. Revisit past a few thousand employees.

- **The bcrypt work factor was left at 12.** Lowering it would shave ~200 ms off login, but the
  remaining `Hash::check` is the one cost here that is *supposed* to be expensive. Trading password
  security for a fifth of a second on a path already dominated by network latency is a bad trade.
