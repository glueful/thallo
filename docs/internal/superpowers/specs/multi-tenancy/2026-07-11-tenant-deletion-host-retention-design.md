# Workspace Deletion & Host-Retention — Design

**Status:** spec in review (HELD — not committed)
**Slice:** Bucket 1, lifecycle gaps #2 (tenant deletion & host-retention).
**Release chain:** `glueful/extension-contracts` → `glueful/tenancy` → Thallo. Dev follows the
vendor-first pattern (edit `vendor/glueful/{extension-contracts,tenancy}` in place, test live in
Thallo, then port to source + release, pin engine versions at release).
**Date:** 2026-07-11

---

## §0 Context — as-built (source-verified)

- **No tenant delete exists.** `TenantAdministration` (contract) has create/suspend/reactivate/
  markActive + membership ops only; `ContractTenantAdministration::transition()` is a guarded
  status flip (`UPDATE tenants SET status=:to WHERE uuid AND status=:from`, 0-rows→throw). Status
  vocabulary today: `provisioning|active|suspended`; column is `string(32)` (no DB enum).
- **Soft-delete is half-built.** `Tenant` model uses `SoftDeletes`; `tenants.deleted_at` exists;
  soft-deleted tenants already fail resolution (404) and are filtered from listings. A hard
  **purge** (content/media/blobs/storage) does not exist.
- **No FK cascade for product data.** Only `tenant_memberships` + `tenant_domains` FK-cascade off
  `tenants`. The 25+ content/media tables (`ThalloTenantTables`) carry a plain indexed
  `tenant_uuid` with **no FK** (cross-package boundary) — a registry delete orphans them.
- **Blob/storage has no GC.** `media_assets(blob_uuid UNIQUE, tenant_uuid)` FK is one-directional
  (`blob_uuid → blobs`); deleting assets never frees blobs or storage bytes. `MediaAdminController::
  destroy` only soft-flips `blobs.status`. Storage-object cleanup must be built, is not
  transactional, and cannot be rolled back inside a DB transaction.
- **Host free = immediate re-registration.** `removeDomain()` hard-deletes the `tenant_domains`
  row; `host` is protected only by a global `unique('host')` index. No cooldown/tombstone/
  `released_at` exists — the squatting/reassignment gap.
- **Guards + infra to reuse.** `assertNotFinalOwner()` (`FOR UPDATE`), `assertNotRequiredHost()`
  (`public_origin.default_hosts` + reserved labels); `Connection::afterCommit(callable)`; queue
  (`Job` + `QueueManager` + `ForEachTenant` + scheduler); advisory-lock idiom
  `pg_advisory_xact_lock(hashtextextended(:k,0))`.

---

## §1 Goal & ownership boundary

Add a reversible two-phase workspace deletion (trash → purge) and a host-cooldown ledger that closes
the reassignment gap for **every** host-release path. Split by ownership:

**Engine (`extension-contracts` + `glueful/tenancy`) — authoritative identity/domain lifecycle:**
- `TenantAdministration`: `deleteTenant()`, `restoreTenant()`, `beginPurge()`, `purgeTenantRecord()`.
- `TenantDomainAdministration`: `releaseDomain()` (cooldown-aware); `removeDomain()` delegates to it.
- Claim-time cooldown enforcement inside `addDomain`/`addPreverifiedDomain`; audited cooldown override.
- `released_hosts` migration + repository; per-host advisory locking.
- Framework `BaseEvent`s dispatched **after commit**: `TenantDeleted`, `TenantRestored`, `HostReleased`.
- Config under the engine's existing `tenancy.*` (single source for host/cooldown/retention).

**Thallo (pack) — product-data destruction & orchestration:**
- `PurgeResourceRegistry` (handlers with stable IDs + dependencies + `prepare→purge→verify`).
- A **system-global** purge ledger that survives tenant-row deletion.
- `PurgeJob` (background, checkpointed, idempotent, retryable, partial-failure reporting).
- Admin UX (delete/restore/purge; typed-slug confirmation; selected-workspace guard).

**The seam (`purgeTenantRecord()` last):** Thallo's `PurgeJob` purges every registered product
resource first; **only when all verify green** does it call the engine's `purgeTenantRecord()`, which
atomically tombstones + releases hosts, drops memberships/domains, and deletes the tenant row. Partial
failure ⇒ `purgeTenantRecord()` never runs ⇒ hosts stay reserved; the job retries.

---

## §2 Tenant lifecycle & `tenants` schema

**State machine** (each transition mirrors the guarded `transition()` idiom — a conditional UPDATE
that throws on 0 rows, so concurrent callers can't double-apply):

```
active|suspended ──deleteTenant()──►  deleted  ──beginPurge()──►  purging ──purgeTenantRecord()──► (row gone)
      ▲                                  │
      └────────restoreTenant()───────────┘   (restore accepts ONLY `deleted`, never `purging`)
```

- **`deleteTenant()`** — atomically set `status=deleted`, `deleted_at=now`, `deleted_from_status=<prior>`
  (`active` or `suspended`), `purge_after = now + trash_retention_days`. Resolution stops immediately
  (existing soft-delete/status conjunct). Domains are **retained + reserved** (rows untouched, so the
  host stays owned; the tenant-status conjunct already makes them non-resolving). Dispatches
  `TenantDeleted` after commit.
- **`restoreTenant()`** — guarded `deleted → deleted_from_status` (recovers `active` **vs** `suspended`,
  not assume-active); clears `deleted_at`/`deleted_from_status`/`purge_after`. Accepts only `deleted`
  **and `now <= purge_after`** (a workspace already `purging`, or whose restore window expired,
  cannot be restored — closes both the restore-vs-purge race and the retention ambiguity). No host
  reclaim needed (domains were never released). Dispatches `TenantRestored` after commit.
- **`beginPurge()`** — guarded `deleted → purging`. This is the point of no return that Thallo claims
  **before** destroying any product data; `purgeTenantRecord()` requires `purging`, so restore can
  never race a purge that has already begun deleting data.
- **`purgeTenantRecord()`** — see §4. Requires `purging`.

**Soft-delete reachability.** The framework automatically adds `deleted_at IS NULL` to normal
`tenants` reads and writes. Therefore `restoreTenant()`, `beginPurge()`, purge-status probes, and the
final purge must use an explicit include-deleted/unscoped engine repository path; they may not use a
normal `Tenant::query()`/builder path that silently hides the row. The final tenant removal is an
explicit **hard delete** (`forceDelete()` or equivalent raw `DELETE`), never another soft-delete.

**`tenants` schema additions** (pre-launch: fold into the engine's `001_CreateTenantsTable`, not an
ALTER): `deleted_from_status` (`string(32)` nullable) and `purge_after` (`timestamp` nullable). The
`status` column already accepts arbitrary strings, so `deleted`/`purging` need no DDL. `deleted_at`
already exists.

---

## §3 Host-cooldown ledger (engine)

**`released_hosts`** (new engine migration): `id` PK · `host` (normalized, **unique**) ·
`released_by_tenant` (`string(12)`, **indexed scalar — NO FK** to `tenants`; the releasing tenant is
historical and may be purged) · `retained_until` (timestamp) · `created_at`. Rationale: after a purge
the tenant row is gone, so a restrictive FK would break; the UUID is kept as an opaque indexed
identity (equivalently `ON DELETE SET NULL`, but a plain scalar is simpler and the value is only used
for the original-owner-reclaim comparison).

**Per-host serialization.** Claim, release, reclaim, and override all take the **same** advisory lock
keyed by the normalized host: `pg_advisory_xact_lock(hashtextextended('tenancy:host:'||:host, 0))`
inside the operation's transaction. The `unique('host')` index alone does not protect the
check-then-act race between a cooldown lookup and a domain release/claim. Operations locking more
than one host normalize, deduplicate, sort lexically, then acquire locks in that deterministic order
to prevent multi-host deadlocks.

**Release** (`releaseDomain()`, called by `removeDomain()` and by `purgeTenantRecord()`): within one
transaction and the per-host lock — capture the release tuple
`{domain_uuid, tenant_uuid, normalized_host}` before deletion, delete the `tenant_domains` row, and **upsert** the tombstone
`(host, released_by_tenant, retained_until = now + release_cooldown_days)`. Idempotent: re-releasing a
host inside the same operation/transaction retry **never shortens** `retained_until` (SQL
`GREATEST(existing.retained_until, new.retained_until)`). The public UUID-only method is not falsely
claimed to be replay-idempotent after a successful commit: once the domain row is gone it returns
not-found. This avoids stale release tokens recreating a tombstone after a later successful claim.
Emits `HostReleased` after commit.

**Claim** (`addDomain`/`addPreverifiedDomain`): normalize host first; take the per-host lock; if a
tombstone exists with `retained_until > now` **and** the claimant tenant ≠ `released_by_tenant` →
refuse with a structured conflict carrying `available_after` (the timestamp) and **never** the prior
owner. The releasing workspace may reclaim immediately. Claim checks compare `retained_until` to
`now` directly, so they are correct even if the sweeper has not pruned the row. DNS-TXT verification
remains mandatory after any successful claim — cooldown does not replace it. Every successful claim
(original-owner reclaim, post-expiry claim, or explicit override) **deletes/consumes the tombstone in
the same transaction** as the new `tenant_domains` row, so a later release records the new owner
rather than inheriting stale release identity.

**Override** — a platform superuser (slice-1 role) may force-claim a host still in cooldown via an
explicit, audited operation (`host.cooldown_overridden`); never an implicit bypass.

**System hosts** — hosts in `tenancy.public_origin.default_hosts` (+ reserved labels) are reserved
separately by the existing `assertNotRequiredHost()`/normalizer path and can **never** become
claimable through cooldown expiry.

**Sweep** — a scheduled job prunes tombstones with `retained_until < now` (housekeeping only; claim
correctness never depends on it having run).

---

## §4 `removeDomain()` delegation & the final purge transaction

- **No bypass.** `removeDomain()` is rewritten to delegate to `releaseDomain()` (still calling
  `assertNotRequiredHost()` first). No public hard-delete path may skip the ledger. (If any internal
  caller needs a true hard-delete, it becomes a private, non-public method; the public contract only
  exposes the cooldown-aware release.) `releaseDomain(domainUuid)` resolves and captures the release
  tuple before deletion; only the transaction-local ledger upsert is idempotent.
- **`purgeTenantRecord()` is one transaction.** Under a tenant lock **and** the per-host locks for all
  the tenant's domains: (1) upsert every host tombstone; (2) delete the `tenant_domains` rows
  explicitly; (3) delete `tenant_memberships`; (4) delete the `tenants` row. It must **not** rely on
  the `tenant_domains → tenants` FK cascade, because a cascade delete would drop domain rows **without
  creating cooldown tombstones**. Emits `HostReleased` per host after commit.

---

## §5 Thallo purge pipeline

**`PurgeResourceRegistry`** — extensible registry of purge handlers; each handler exposes:
- a **stable string ID** (e.g. `thallo.tables`, `thallo.media`, `thallo.collections`, `thallo.cache`),
- declared **dependencies** on other handler IDs (topologically ordered — a fixed "tables then media"
  order is unsafe because media-storage GC needs metadata that table deletion would remove),
- three phases: **`prepare($tenant)`** (capture anything destruction needs — e.g. media object
  keys/paths — into the durable purge ledger), **`purge($tenant)`** (destroy), **`verify($tenant)`**
  (confirm the resource is gone; drives checkpointing and partial-failure reporting).

**Global phase barrier.** The job completes and durably checkpoints `prepare` for **every** handler
before any handler may enter `purge`. Purge and verify then follow the dependency graph. This makes
captured manifests durable before the first destructive operation instead of relying on an ambiguous
per-handler `prepare→purge→verify` loop.

Core handlers:
- `thallo.tables` — delete `WHERE tenant_uuid=?` across the generic subset of
  `ThalloTenantTables::tableNames()` (raw writes must supply the predicate — the tenant scope does not
  auto-apply to raw `db()` deletes). Tables claimed by a specialized handler are **excluded** from
  this generic set; one resource has exactly one destructive owner. Depends on specialized handlers
  whose rows must disappear first.
- `thallo.media` — **`prepare`** records every `media_assets` blob's storage object key/path into the
  purge ledger **before** any deletion; **`purge`** deletes storage objects then blob + `media_assets`/
  `media_meta`/`media_usage` rows; **`verify`** confirms. It is the sole destructive owner of those
  media tables; `thallo.tables` depends on `thallo.media`, never the reverse.
- `thallo.cache` — invalidate tenant-scoped cache segments.
- `thallo.collections` — no-op until dynamic-collection tenancy lands (registered, guarded).

**Durable purge ledger** (system-global, survives tenant deletion): records the tenant, the plan
(handler IDs + phase checkpoints), and captured artifacts (media keys). A failed storage deletion is
retryable because the keys were persisted in `prepare` before `purge`. Not tenant-scoped; never
purged by its own handlers.

**`PurgeJob`** (background `Job` via `QueueManager`): requires `status=purging`. The purge request
first creates a durable system-global purge-run row and atomically transitions the tenant through
`beginPurge()` on the same database connection/transaction. Queue dispatch is registered
`afterCommit`; a failed dispatch records `dispatch_failed`, and a retry endpoint/scheduled recovery
scan re-dispatches any committed `requested|dispatch_failed` run. A workspace can therefore never be
left `purging` without durable work that can be recovered. The job runs the global prepare barrier,
then handlers in dependency order, checkpointing each `prepare/purge/verify`;
idempotent (re-run resumes from the last incomplete checkpoint); retryable; reports partial failures.
Only when **all** handlers `verify` green does it call engine `purgeTenantRecord()`. Emits
`tenant.purge_completed`; on failure `tenant.purge_failed` with the failing handler + phase, leaving
hosts reserved.

---

## §6 Events & audit

- **Events (engine, framework `BaseEvent`, dispatched via `Connection::afterCommit()`):**
  `TenantDeleted`, `TenantRestored`, `HostReleased`. Never emitted for a rolled-back transition.
- **Audit (separate actions):** `tenant.deleted`, `tenant.restored`, `tenant.purge_requested`
  (on `beginPurge`), `tenant.purge_completed`, `tenant.purge_failed`, `host.released`,
  `host.cooldown_overridden`. Best-effort recorder (slice-1 `AuthorityAudit` style / engine equivalent).

---

## §7 Configuration (single source: engine `tenancy.*`)

Under the engine's `config/tenancy.php` (Thallo overlays via its own `config/tenancy.php`, never
duplicating host lists):
- `domains.release_cooldown_days` = **30** (configurable globally).
- `tenants.trash_retention_days` = **30** (restore window; `purge_after` = `deleted_at + this`).
- `tenants.auto_purge_enabled` = **false** (default off — operators purge early with typed
  confirmation; enabling the scheduled sweeper is explicit until the purge pipeline has operational
  history).
- System-host reservation continues to read `tenancy.public_origin.default_hosts` + `reserved_labels`.

---

## §8 Safety gates

- **Final-workspace** — the engine refuses `deleteTenant()` of the final non-deleted
  `provisioning|active|suspended` workspace. PostgreSQL does not permit `FOR UPDATE` on an aggregate:
  the engine selects the candidate UUID rows in deterministic order **`FOR UPDATE`**, then counts the
  locked result before changing the target. This is a true engine invariant.
- **Required-host ownership** — `deleteTenant()` refuses while the target owns any configured
  `tenancy.public_origin.default_hosts`. Deleting it would stop the apex/default host resolving before
  purge. The operator must remap or remove the required-host configuration first; the refusal names
  the blocking hosts but does not weaken their permanent reservation.
- **Selected-workspace** — refusing to purge the workspace the operator is *currently acting as* is
  **request/client state**, not an engine invariant. Enforced by the Thallo controller/UI (compare the
  purge target to the resolved/selected tenant; refuse in the controller).
- **Typed confirmation** — manual purge requires the operator to type the workspace **slug/name**
  (Thallo controller validates); a generic confirm dialog is insufficient.

---

## §9 Admin surface (Thallo)

New routes under `/v1/admin/tenancy`, gated `content_permission:tenancy.manage` (post-SP3):
- `DELETE /tenants/{uuid}` → soft-delete (`deleteTenant`), confirmation required.
- `POST /tenants/{uuid}/restore` → `restoreTenant`.
- `POST /tenants/{uuid}/purge` → validate typed slug/name + selected-workspace guard → durably create
  the purge run and atomically call `beginPurge()` → dispatch `PurgeJob` after commit; returns
  accepted/in-progress. Retry re-dispatches a committed run rather than repeating destructive setup.
- `GET /tenants/{uuid}` (or list) surfaces trash state + `purge_after` + purge progress.
- SPA: trash affordance in the workspaces list; restore; typed-confirmation purge modal; cooldown
  conflict on domain add surfaces `available_after`.

---

## §10 Release chain & vendor-first development

Implement in the vendored copies first (`vendor/glueful/extension-contracts`,
`vendor/glueful/tenancy`) + Thallo pack, test live in Thallo, then port to the source repos and
release **contracts → engine → app**, pinning `glueful/extension-contracts` and `glueful/tenancy`
versions in Thallo only after they are published. A **framework storage seam** may be needed if
storage-object deletion isn't already exposed by the blob storage abstraction — confirmed at plan
time; if so it precedes the chain (framework → contracts → engine → app).

---

## §11 Failure modes

- Delete the final workspace → refused (engine, transactional).
- Delete a workspace owning a required default host → refused until the host is remapped/config changed.
- Restore after `purge_after`, or restore a `purging` workspace → refused.
- Purge the currently-selected workspace, or wrong typed slug → refused (Thallo controller).
- Handler `purge` fails mid-pipeline → job reports `purge_failed` (handler+phase), hosts stay
  reserved (`purgeTenantRecord` not reached), retry resumes from checkpoint.
- Storage deletion partially succeeds/fails → captured keys make object deletion retryable; the media
  handler does not remove blob/media rows until all required object deletions succeed.
- Concurrent claim + release of the same host → serialized by the per-host advisory lock.
- Multi-host operations acquire sorted locks, preventing lock-order deadlocks.
- Repeated ledger upsert inside one release operation/transaction retry never shortens `retained_until`;
  a post-commit UUID-only release returns not-found rather than claiming replay idempotence.
- Successful claim consumes its tombstone; a later release records the new releasing tenant.
- Queue dispatch fails after the purge transition → durable run remains recoverable and is re-dispatched.
- Rolled-back transition → no event emitted (afterCommit).
- Claim a host in cooldown by a different tenant → conflict with `available_after`, prior owner hidden.

---

## §12 Testing

- Lifecycle: delete sets status/deleted_at/deleted_from_status/purge_after; restore recovers
  active **and** suspended; restore refused after `purge_after`; beginPurge deleted→purging; restore
  refused on purging; include-deleted reads reach trashed rows; final removal is a hard delete; each
  transition's 0-row guard.
- Cooldown: release writes tombstone in the same transaction; claim refused for a different tenant
  with `available_after`; original owner reclaims; expiry honored without the sweeper; repeated
  transaction-local upsert doesn't shorten; successful claim consumes the tombstone and a later release records
  the new tenant; per-host lock serializes a concurrent claim/release; sorted multi-host locking does
  not deadlock; override audited; system host never claimable.
- `removeDomain` delegates to `releaseDomain` (no residual hard-delete path).
- `purgeTenantRecord` one-transaction: tombstones created, domains/memberships/tenant deleted, FK
  cascade NOT relied upon; requires `purging`.
- Purge pipeline: global prepare barrier; single destructive owner per table; handler dependency
  ordering; prepare-captures-media-keys before deletion; checkpoint/idempotent resume;
  partial-failure keeps hosts reserved; storage-GC retry; verify gates `purgeTenantRecord`; failed
  queue dispatch remains durably re-dispatchable.
- Gates: final-workspace + required-default-host ownership (engine); selected-workspace + typed-slug
  (Thallo controller).
- Events after commit only; audit actions each emitted.
- Config: cooldown/retention defaults; `auto_purge_enabled=false` means no scheduled purge.
- Regression: existing suspend/reactivate, resolution, SP2/SP3 suites stay green.

---

## §13 Out of scope

Dynamic-collection purge (handler registered but a no-op until collections tenancy lands — Bucket 2);
custom-domain TLS automation; background domain **re-verification** (Bucket 1 slice #3); hard
database-per-tenant isolation; cross-tenant data export/migration; undo of a completed purge.
