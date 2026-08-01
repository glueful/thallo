# Background Domain Re-verification — Design

**Status:** spec in review (HELD — not committed)
**Slice:** Bucket 1, lifecycle gaps #3 (background domain re-verification).
**Release chain:** `glueful/extension-contracts` → `glueful/tenancy` → Thallo. Vendor-first (edit
`vendor/glueful/{extension-contracts,tenancy}` in place, test live in Thallo, port to source +
release). **Batches with slice 2 into one extension release** (contracts + tenancy `1.3.0`).
**Date:** 2026-07-11

---

## §0 Context — as-built (source-verified)

- **Verification is one-shot.** `ContractTenantDomainAdministration::verifyDomain()` looks up the DNS
  TXT record `_thallo-verify.<host>` via `DnsTxtLookup::lookup()`, compares it to the domain's stored
  `verification_token`, and on a match flips `verification_status → verified` + stamps `verified_at`.
  It never re-checks. There is no `last_checked_at`, no failure counter, and no "was verified, now
  failing" state.
- **Only DNS-verified domains carry a token.** `addDomain()` sets a `verification_token`;
  `addPreverifiedDomain()` (operator hosts) sets **none**. So DNS re-verification is meaningful only
  for token-bearing domains; operator pre-verified/system hosts must be excluded (a DNS re-check would
  falsely fail them).
- **Resolver trust point.** `TenantDomain::isPubliclyResolvable()` returns true only when
  `verification_status === 'verified' AND status === 'active'`. Adding a `revoked` value to
  `verification_status` therefore **stops public resolution with zero resolver changes**.
- **`verification_status` is `string(16)`** (`003_CreateTenantDomainsTable`); `revoked` needs no DDL.
  `status` is the operator's independent enable/disable preference (`active|disabled`).
- **`tenant_domains` is a global (non-tenant-scoped) table** with a `unique('host')` index and an FK
  `tenant_uuid → tenants` (cascade on delete). A re-verification sweep scans it directly; it does not
  need `ForEachTenant` per-tenant fan-out.
- **`DnsTxtLookup::lookup()` collapses errors and empties.** It returns `[]` both when
  `dns_get_record()` returns `false` (lookup failure) and when the lookup succeeds with no TXT records
  — so it cannot currently distinguish `dns_error` from `mismatch`.
- **Infra to reuse.** The engine's per-host advisory-lock helper (`ReleasedHostRepository::lockHost`,
  `pg_advisory_xact_lock(hashtextextended('tenancy:host:'||host,0))`, slice 2); framework
  `Connection::newPdo()` (independent, non-pooled session PDO); `Connection::afterCommit()`; framework
  `EventService` + `BaseEvent`; `QueueManager`/`Job`; the framework scheduler. The engine currently
  registers **no** scheduled work.

---

## §1 Goal & ownership boundary

Periodically re-prove ownership of already-verified custom domains and, when proof drifts past a
grace boundary, revoke resolution — closing the domain-takeover window without letting a transient DNS
failure knock a live custom domain offline. Split by ownership (same boundary as slice 2 — engine owns
the identity/ownership-proof primitive; the app drives the scheduled sweep):

**Engine (`extension-contracts` + `glueful/tenancy`) — ownership-proof lifecycle:**
- Contract DTO `DomainReverificationResult` (neutral, crosses the contracts boundary).
- `TenantDomainAdministration::reverifyDomain(ApplicationContext, string $domainUuid): DomainReverificationResult`.
- `tenant_domains` tracking columns + a `revoked` `verification_status` value (folded into `003`).
- Structured `DnsTxtResult` + a structured `DnsTxtLookup` method (engine-local — **not** a contract).
- Three framework `BaseEvent`s dispatched after commit: `DomainReverificationFailed`,
  `DomainRevoked`, `DomainReverified`.
- Config defaults under the engine's existing `tenancy.domains.reverification.*`.

**Thallo (pack) — scheduling & operator surface:**
- `DomainReverificationSweepJob` — a global, single-runner (session advisory-lock) scheduled sweep
  that selects due domains and calls the engine primitive per domain, with per-domain failure
  isolation.
- Admin surfacing (status incl. `revoked`, `last_checked_at`, failing indicator) + a manual
  "re-verify now" route (Thallo audits the actor action).
- A listener that converts the engine's after-commit events into **system-actor** audit records.

**The seam:** the engine's `reverifyDomain()` is a self-contained, idempotent per-domain primitive.
Thallo's sweep is pure orchestration (which domains, how often, single-runner) and holds no
ownership-proof logic; the manual trigger calls the same primitive.

---

## §2 Re-check primitive (engine)

`reverifyDomain(ApplicationContext $c, string $domainUuid): DomainReverificationResult`.

**No DNS I/O inside a transaction or lock** (a `dns_get_record` can block for seconds; holding an
advisory lock across it is an availability hazard). The sequence is snapshot → DNS → guarded apply:

1. **Snapshot (no txn/lock):** read `uuid, host, verification_token, verification_status,
   last_checked_at, status`. Only token-bearing domains whose `verification_status` is exactly
   `verified|revoked` are eligible. A missing row, token-less row, `pending` row, or unknown/future
   verification status returns `outcome = ineligible` with no state change. An unknown status is also
   warning-logged/diagnosed as incoherent state rather than being silently processed.
2. **DNS lookup outside any lock/txn** — structured (see §3).
3. **Guarded apply:** `BEGIN → lockHost(host)` (host advisory lock **before** the row lock, matching
   the release/claim path to avoid deadlocks) `→ SELECT … FOR UPDATE` the domain row.
4. **Optimistic recheck:** apply only if the row still exists and its `host`, `verification_token`,
   `verification_status`, and `last_checked_at` still equal the snapshot (`last_checked_at` uses
   null-safe `IS NOT DISTINCT FROM` semantics). Otherwise (re-added, token rotated, released, deleted,
   or checked concurrently between snapshot and lock) → `outcome = stale`, `verificationStatus = null`
   when gone or the current value when present, `transition = none`, no writes. Every applied check
   advances `last_checked_at`, so two concurrent manual/background checks cannot apply contradictory
   DNS results sequentially: the later guarded apply observes the changed timestamp and becomes stale.
5. **Apply the transition** using **DB time** (`now()` in SQL) for every threshold/grace comparison;
   evaluate revocation on the **incremented** `consecutive_failures` and the **persisted**
   `first_failure_at`.

**Classification (DB write per branch):**
- **`verified`** (TXT contains the token): `last_check_status = 'verified'`, `last_checked_at = now()`,
  `consecutive_failures = 0`, `first_failure_at = null`. If the row was `revoked` → restore
  `verification_status = 'verified'`, stamp `verified_at`, `transition = restored`, emit
  `DomainReverified`. If it was already `verified` → `transition = none`, **emit nothing** (routine
  success is silent).
- **`mismatch`** (lookup succeeded, no matching token — includes no-TXT-records) and **`dns_error`**
  (lookup failed/timeout/SERVFAIL): `last_check_status` set accordingly, `last_checked_at = now()`,
  `consecutive_failures += 1`, `first_failure_at = COALESCE(first_failure_at, now())`. Emit
  `DomainReverificationFailed` (every unsuccessful check, **including while already `revoked`**). Then
  **revoke iff** `consecutive_failures >= failure_threshold` **AND**
  `now() - first_failure_at >= grace_hours`: on a `verified → revoked` transition set
  `verification_status = 'revoked'`, `transition = revoked`, emit `DomainRevoked`. A domain already
  `revoked` stays `revoked` (no second `DomainRevoked`), and its `first_failure_at`/`consecutive_failures`
  are **preserved and keep advancing** — cleared only by a successful proof.

`status` (operator enable/disable) is **never** touched by re-verification.

**Return DTO — `DomainReverificationResult`** (contracts-level, neutral; a status string cannot
represent `stale`/`ineligible` or a deleted row):

```
DomainReverificationResult {
    outcome: 'verified' | 'mismatch' | 'dns_error' | 'stale' | 'ineligible'
    verificationStatus: ?string     // resulting status; null when the row is gone
    transition: 'none' | 'revoked' | 'restored'
    consecutiveFailures: int
    checkedAt: ?string              // DB timestamp of this check; null for stale/ineligible
}
```

**`verifyDomain()` alignment.** The existing initial verification migrates to the structured
`DnsTxtLookup` with deterministic tracking semantics:
- Success stamps `last_checked_at = now()`, sets `last_check_status = verified`, keeps
  `consecutive_failures = 0` / `first_failure_at = null`, and performs the existing
  `pending → verified` transition.
- Failure stamps `last_checked_at = now()` and `last_check_status = mismatch|dns_error`, but keeps
  `consecutive_failures = 0` / `first_failure_at = null` because that counter represents drift after
  proof was established.

The manual "re-verify now" on an already-verified/`revoked` domain calls `reverifyDomain()` so
recovery follows the identical restore path.

---

## §3 Structured DNS lookup (engine-local)

`DnsTxtLookup` gains a structured method returning `DnsTxtResult` (a plain engine-local value object —
**not** an extension contract, so it never crosses the package boundary):

```
DnsTxtResult {
    status: 'success' | 'error'
    records: list<string>
}
```

- `dns_get_record($name, DNS_TXT) === false` → `status = error` (⇒ caller classifies `dns_error`).
- A successful call (array, possibly empty) → `status = success` with its TXT strings (⇒ caller
  classifies `verified` if the token is present, else `mismatch`).

The legacy `lookup(): list<string>` remains as a compatibility wrapper: it returns the structured
result's records on success and `[]` on error. Both `verifyDomain()` and `reverifyDomain()` move to
the structured method so lifecycle decisions never use the lossy wrapper. Tests inject a fake to
drive `success`/`error`/token-present paths.

---

## §4 Sweep & scheduling (Thallo)

**`DomainReverificationSweepJob`** — a global, **single-runner** scheduled job. This is **not** the
purge ledger's durable-lease mechanism; it is a **session-scoped** advisory lock and is documented
separately:

- **Single-runner lock.** Acquire `pg_try_advisory_lock(<sweep key>)` on a **dedicated, non-pooled
  session PDO** obtained via `Connection::newPdo()`; if it returns false, another sweep holds it → skip
  this run. **Release in a `finally`** with `SELECT pg_advisory_unlock(<same key>)` and verify that the
  result is true; then discard the independently-owned PDO rather than returning it to a pool.
  Process termination releases the session lock automatically — the safety net for a crashed worker.
- **Due-domain selection** (one batch, `LIMIT batch_size`, `ORDER BY last_checked_at NULLS FIRST`),
  joined to `tenants`:
  - `tenant_domains.verification_token IS NOT NULL`
  - `tenant_domains.verification_status IN ('verified','revoked')`
  - `tenants.status IN ('active','suspended') AND tenants.deleted_at IS NULL` — **exclude trashed /
    purging workspaces** (leave them unchanged for restoration).
  - Operator-**disabled domains** (`tenant_domains.status = 'disabled'`) on active/suspended workspaces
    **are** re-checked, so their ownership proof stays current.
  - Due when `last_checked_at IS NULL` **or** older than the applicable interval: `recheck_interval_hours`
    for `verified`, the slower `revoked_recheck_interval_hours` for `revoked` (background recovery).
- **Per-domain isolation.** Call `reverifyDomain()` per domain (each does its own snapshot → DNS →
  host-lock → `FOR UPDATE`). Wrap each call in try/catch; an unexpected DB/runtime error is **collected,
  not fatal** — the batch continues. After the batch, if any per-domain errors occurred, **throw** so
  the queue records an operational failure. Domains checked successfully advanced their `last_checked_at`
  and won't be due on the retry.
- **No checkpoint/resume** — each re-check is idempotent and self-stamping, so a crashed sweep simply
  resumes on the next tick.
- **Cadence.** Scheduled frequently (hourly); the per-domain interval (not the schedule) decides when a
  given domain is actually re-checked. `enabled = false` disables **only** background scheduling —
  manual re-verification stays available. The job re-reads this flag at the beginning of `handle()`
  and exits before acquiring the session lock, so a job queued before the kill-switch changed cannot
  continue re-verification afterward.

---

## §5 Config, events & audit

**Config (engine `tenancy.domains.reverification.*`):**
- `enabled` = **true** (kill-switch for background scheduling only; manual re-verify always works).
- `recheck_interval_hours` = **12**.
- `revoked_recheck_interval_hours` = **24** (slower background recovery cadence).
- `failure_threshold` = **3**.
- `grace_hours` = **24** (revoke only when threshold **and** grace are both met).
- `batch_size` = **100**.

**Events (engine, framework `BaseEvent`, dispatched via `Connection::afterCommit()`), edges tuned to
avoid audit noise:**
- `DomainReverificationFailed` — **every** unsuccessful check, including while already `revoked`.
- `DomainRevoked` — **only** the `verified → revoked` transition.
- `DomainReverified` — **only** the `revoked → verified` transition (routine `verified → verified`
  emits nothing).
- Payload for all three: `domainUuid, tenantUuid, host, outcome, consecutiveFailures,
  verificationStatus` — **never** the `verification_token`.

**Audit (Thallo, consistent with the ownership boundary — the engine has no audit dependency):**
- A Thallo listener converts the three engine events into **system-actor** audit records
  (`domain.reverification_failed`, `domain.revoked`, `domain.reverified`).
- The manual "re-verify now" route records `domain.reverification_requested` with the **actor** (Thallo
  audits the request directly). Engine outcome events remain system-attributed to avoid duplicate
  actor-attributed outcomes.

**Manual route and authorization:** `POST /v1/admin/tenancy/domains/{uuid}/reverify` lives in the
existing tenant-admin domain group with `auth`, `tenant_profile:admin`, `tenant_bootstrap`, and
`content_permission:tenant.domains.manage`. The controller resolves the domain before mutation and
requires its `tenant_uuid` to equal the resolved workspace, using the same non-revealing 404 behavior
as the existing domain actions. Workspace owners therefore retain self-service for their own domain;
cross-workspace operators reach another target only through SP3a's explicit audited bypass mode. The
actor audit records the accepted request before invoking the engine primitive, so a DNS/runtime
failure does not erase evidence that the action was requested.

The existing `thallo:tenancy:diagnose` report gains a read-only domain-proof coherence check: any
row whose `verification_status` is outside `pending|verified|revoked`, or whose
`last_check_status` is outside `verified|dns_error|mismatch|null`, fails diagnostics and identifies the
domain UUID without exposing its token. This is the operational surface paired with the primitive's
unknown-status warning.

---

## §6 Data model & indexing (engine, folded into `003`)

Pre-launch, fold into `003_CreateTenantDomainsTable` (not an ALTER):
- `last_checked_at` (`timestamp`, nullable)
- `last_check_status` (`string(16)`, nullable — `verified | dns_error | mismatch`)
- `consecutive_failures` (`integer`, default `0`)
- `first_failure_at` (`timestamp`, nullable)
- Index `(verification_status, last_checked_at)` to support the sweep's due-selection.
- `TenantDomain::VERIFICATION_REVOKED = 'revoked'` (no DDL — `verification_status` is `string(16)`).

`isPubliclyResolvable()` is unchanged (already `=== 'verified'`), so a `revoked` domain stops resolving
immediately.

---

## §7 Release chain & vendor-first

Implement in the vendored copies (`vendor/glueful/extension-contracts`, `vendor/glueful/tenancy`) +
Thallo pack, test live, then port to the source repos and release **contracts → engine → app**. This
slice **batches with slice 2** into a single extension release (`glueful/extension-contracts` and
`glueful/tenancy` `1.3.0`), pinned in Thallo only after publish.

- **contracts:** `DomainReverificationResult` DTO; `TenantDomainAdministration::reverifyDomain()`
  signature.
- **tenancy:** folded `003` columns + index + `VERIFICATION_REVOKED`; `DnsTxtResult` +
  structured `DnsTxtLookup`; `reverifyDomain()` impl + `verifyDomain()` alignment; three events;
  config keys.
- **Thallo:** `DomainReverificationSweepJob` (dedicated `Connection::newPdo()` session lock) +
  scheduler registration; admin list fields + re-verify route + audit listener.
- **No framework seam needed** — `Connection::newPdo()`, `afterCommit`, `EventService`,
  `QueueManager`, the per-host advisory lock, and the scheduler all already exist.
  `Connection::newPdo()` is source-verified and regression-tested to return a new independently-owned,
  non-pooled PDO session.

**Pre-launch folded-schema procedure.** Folding the columns into `003` governs fresh installs but
does not alter an already-migrated development database. Execution therefore must:
1. reset and fully migrate the dedicated test database from migration zero;
2. extend `RetrofitHarnessTestCase`'s disposable template-schema fingerprint to require all four
   re-verification columns (rebuild the template when stale); and
3. run a throwaway local-only additive sync against existing development databases, first recording
   tenant-domain row counts, adding only the nullable/defaulted columns and index when absent, then
   proving row counts and existing host mappings are unchanged. The sync script is deleted after use;
   no shipped ALTER migration is introduced.

---

## §8 Failure modes

- Transient DNS outage on a live domain → counted, but not revoked until **both** threshold and grace
  are met; a single recovery check resets the sequence.
- Domain re-added, token rotated, released, or checked concurrently between snapshot and lock →
  `outcome = stale`, no writes.
- Domain row deleted between snapshot and lock → `outcome = stale`, `verificationStatus = null`.
- Operator pre-verified / system host (no token) or `pending` domain → `outcome = ineligible`, no change.
- Unknown/future `verification_status` → `outcome = ineligible`, warning/diagnostic emitted, no change.
- Two sweeps overlap → the second fails `pg_try_advisory_lock` and skips; the lock releases in
  `finally` (or on process death via the dedicated session). An explicit unlock result other than
  `true` is an operational job failure; the PDO is still discarded.
- One domain throws mid-batch → collected, batch continues, job fails after the batch; checked domains
  aren't due on retry.
- Workspace trashed/purging → its domains are excluded from the sweep and preserved for restoration.
- Revoked domain recovers → background sweep (slower cadence) or manual re-verify restores `verified`,
  emits `DomainReverified`, and audit records it.
- Rolled-back apply → no event emitted (afterCommit).

---

## §9 Testing

- **Primitive:** success resets counters + restores `revoked → verified`; `mismatch` vs `dns_error`
  classification; `stale` when host/token/status/`last_checked_at` changed or the row was deleted;
  concurrent manual/background snapshots prove only one result applies; `ineligible` for
  token-less/pending/unknown status;
  revoke only when threshold **and** grace are both met (DB time); revoked stays revoked while failing
  with `first_failure_at` preserved; DNS performed **outside** the lock (assert via injected fake +
  lock-order assertion); host-lock-before-row-lock ordering.
- **Events:** `Failed` on every unsuccessful check incl. while revoked; `Revoked` only on
  `verified→revoked`; `Reverified` only on `revoked→verified`; routine success emits nothing; token
  never in payload; nothing emitted on rollback.
- **Sweep:** single-runner advisory lock (second concurrent run skips); dedicated non-pooled session
  connection explicitly unlocked, unlock-result verified, and discarded in `finally`; excludes
  trashed/purging workspaces; **includes** operator-disabled
  domains on active/suspended workspaces; per-domain failure isolation (batch continues, job fails
  after); `batch_size` + `NULLS FIRST` ordering; verified vs slower revoked cadence.
- **Config:** `enabled=false` makes an already-queued job exit before lock acquisition while manual
  re-verification still works; defaults.
- **`verifyDomain()`:** initial success stamps `verified` check metadata and performs
  `pending → verified`; pending mismatch/DNS error stamps its exact check metadata but leaves the
  post-verification counter at zero and `first_failure_at` null.
- **Audit (Thallo):** background events → system-actor records; manual trigger →
  `domain.reverification_requested` with the actor; no duplicate actor-attributed outcome records.
- **Manual route:** owner can re-check a domain in the resolved workspace; foreign/unknown targets are
  indistinguishable; cross-workspace access requires explicit operator bypass.
- **Diagnostics:** unknown verification/check statuses fail the read-only tenancy diagnosis without
  exposing verification tokens.
- **Folded schema:** a fresh test migration contains all columns/index; stale retrofit templates are
  rebuilt; local additive sync preserves every existing domain row and host mapping.
- **Regression:** public/admin resolution, slice-2 lifecycle/cooldown/purge suites stay green.

---

## §10 Out of scope

Operator notification beyond events (email/push — the events are the hook; a notifier is a later
listener); custom-domain TLS automation; re-verification of operator pre-verified / system hosts;
changing the initial `pending`-domain verification cadence (stays manual); CAA / non-TXT record checks;
per-check history table (audit events carry the history — §1 approach A).
