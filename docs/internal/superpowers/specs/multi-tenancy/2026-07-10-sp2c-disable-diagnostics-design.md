# SP2c — Disable Path (`disabled_widened`) + Diagnostics (Design)

> Final SP2 slice (`SP2-README.md`; invariants cited as "SP2 index §3.n"). Builds on the SP1
> spec's §9 (disable) + §11 (diagnose) and the as-built SP1/SP2a/SP2b machinery (all held).
> **Thallo-owned:** zero framework, Glueful extension-contracts, or tenancy-extension
> changes. One neutral `TenantWriteScope` interface is added to the existing Thallo contracts
> package so removable sibling packs never depend on the concrete tenancy pack.

## §1 Objective and ownership

A routine, reversible OFF-switch: `on → disabled_widened` (scoping off, writes stamped to the
default tenant, schema stays widened) with the same barrier + fresh-boot + CAS discipline as
enable, its mirror `disabled_widened → on` that skips the retrofit, an explicit
`resolution:deactivate` (the SP2a one-way flag's sanctioned lowering), and
`thallo:tenancy:diagnose` — a read-only coherence report. Compat policy, disable machine,
gates, and diagnose are all Thallo-owned (pack + app); the extension's empty-registry
behavior when disabled IS the "scoping off" mechanism, untouched.

Destructive rollback to `off` (drop `tenant_uuid`, restore narrow uniques) remains a separate
future command — out of scope, boundary restated in §6.

## §2 `CompatWriteScope` + the two write paths

**`TenantWriteScope`** (neutral interface in `thallo-contracts`) answers `mode()` and
`tenantUuidForWrite()`. **`CompatWriteScope`** (thallo-tenancy pack) implements it and answers
"what `tenant_uuid` must a write carry right now":

- tenancy ON → defer to normal scoping (stamper / contracts `TenantScope`) — the helper is
  not consulted on the hot path;
- `disabled_widened` → the default tenant uuid;
- clean off → null (no column exists).

**Process-local snapshot (pinned P1):** the helper is a **boot-refreshed snapshot** — the
provider captures `{schemaState, enabled, defaultTenantUuid}` once at boot into the instance;
the insert-hook path reads ONLY the snapshot, never `SystemFlags` (no recursive flag reads —
`SystemFlags::all()` itself queries through the connection the hook is wrapping). Mid-process
flag flips are irrelevant by design: transitions take effect at the next boot (§3/§4).

**Builder path:** a pack-registered `Connection::addInsertHook` armed at boot only when the
snapshot says `widened && !enabled && defaultTenantUuid !== null`; it stamps
`ThalloTenantTables::tableNames()` rows lacking `tenant_uuid`. When tenancy is on (or clean
off) the hook is simply not registered.

**Raw-PDO path (pinned P2 — split inventory, not blanket):** the `RawPdoScopingLintTest`
classification gains three write buckets, and only the first consults `CompatWriteScope`:

1. **Tenant-bearing INSERT/upsert sites** (write a `tenant_uuid` value): resolve it via
   `CompatWriteScope` (on → current tenant as today; disabled_widened → default; off → omit
   column). Audit = the lint enumerates these sites and asserts the resolution call.
2. **UPDATE/DELETE sites** (VersionPruner deletes, scheduler drain updates, …): valid
   unscoped with exactly one tenant — no compat call; the lint documents the bucket.
3. **System/global + DDL writers** (filter-index DDL, global registries): barrier discipline
   only (`runWritable`), never tenant values.

The lint asserts bucket membership explicitly so a new raw INSERT cannot ship unbucketed, and
bucket-1 sites cannot drop their `CompatWriteScope` call silently.

## §3 Disable machine

`TenancyEnablement::disable()` — same lock, same store, same status surface:

1. CAS `ON → DISABLING`.
2. **Raise the mutation barrier** (`guard->begin()` — persisted
   `tenancy.retrofit_active=1`) before reading any mutable gate input. Provenance/content
   changes cannot race the gate decision after this point.
3. Gates (§6). Refused → one transaction performs checked CAS `DISABLING → ON`, clears
   disable-operation metadata, and calls `guard->end()`; return the refusal without entering
   `FAILED`. If the CAS or transaction fails, rollback keeps the persisted barrier raised,
   call `guard->refresh()` to restore its process-local flag, and record a failure naming the
   observed step. The machine never lowers the barrier against an unknown state.
4. **Cache sentinel (pinned P2):** write one unique segmented-render sentinel key
   (`tenant:{default}:render:disable-sentinel:{nonce}`) and persist its exact key in the disable
   operation state (store field), THEN `CacheTransition::purge()` (both key shapes).
5. **One transaction atomically writes `tenancy.enabled='0'` + step `DISABLED_WIDENED`**
   (mirror of finalize's transactional `guard->end()`+CAS — but the barrier stays UP).
6. Stop. **State disambiguation (pinned P1):** `DISABLED_WIDENED` alone is ambiguous, so the
   machine defines the pair explicitly —
   - `DISABLED_WIDENED` **+ `tenancy.retrofit_active=1`** = awaiting fresh-boot verification
     (reports `needsFreshBoot`; writes still blocked by the barrier);
   - `DISABLED_WIDENED` **+ retrofit_active absent** = settled.
   `status()`, `retry()`, and `disable()` all derive behavior from this pair — no second enum
   case, one consistent rule.
7. A fresh boot's `disable()` invocation (status-first CLI, SP1 pattern) runs **DisableProbe**
   and, on pass, `guard->end()` → settled.

**Pre-flip resume contract:** `DISABLING` is itself resumable, not an error shorthand:

- `DISABLING` + barrier absent (crash immediately after the initial CAS) → raise the barrier,
  rerun the idempotent gates, and continue;
- `DISABLING` + barrier active → rerun the gates, reuse an existing persisted sentinel key
  (create it only when absent), repeat the idempotent purge, and attempt the atomic flag/step
  flip;
- a gate refusal follows step 3's transactional, checked cleanup back to `ON`;
- once `tenancy.enabled=0` + `DISABLED_WIDENED` commit, no recovery path may return to `ON`
  in-process: the barrier remains raised and only fresh-boot DisableProbe can settle it.

`status()`, `retry()`, and the status-first CLI expose these tuples explicitly. A retry never
creates a second sentinel while one is recorded, and settling/aborting clears its metadata.

Surfaces: CLI `thallo:tenancy:disable` (status-first re-invocation advances, SP1 pattern) and
`POST /v1/admin/tenancy/disable` on the existing enablement controller (`tenant_system` +
`content_permission:system.access`, `guarded()` exception mapping — same shape as
begin/confirm/finalize). Both call the identical `TenancyEnablement::disable()` method.

**DisableProbe** (fresh process; the ONLY mutating probe, sanctioned because the persisted
barrier is still raised): asserts (a) extension scoping disarmed —
`TenantEnforcementProbe::registeredTables() === []`; (b) compat hook armed —
`Connection::applyInsertHooks('content_types', ['title' => 'probe'])` returns a payload
carrying the default `tenant_uuid` (no SQL); (c) **a real default-stamped write round-trips**:
inside `guard->runInternal()`, transactionally insert/read/delete one schema-valid nonce probe
row (rollback removes it on any failure), reading it back UNSCOPED; (d) **the persisted
sentinel key is absent** from the cache
(exact-key check — "fewer segmented keys" is not observable through `CacheStore`, the
sentinel is). The probe never clears metadata. Settlement transactionally clears sentinel
metadata with `guard->end()`; failure refreshes the process guard and retains both for retry.
Crash anywhere → barrier up, step pair unchanged, resume.

## §4 Re-enable (`disabled_widened` settled → `on`)

Light path — **no retrofit, no re-provision** (schema widened, default tenant + provenance
exist). The entry surface is the existing enable action: `TenancyEnablement::begin()` detects
settled `DISABLED_WIDENED` and takes this path; it never asks again for tenant slug/name/owner:

1. Under the enablement lock, verify the exact settled pair: step `DISABLED_WIDENED`,
   `tenancy.enabled=0`, schema `widened`, and retrofit barrier absent.
2. **Raise the barrier first**, before changing either persisted enablement value.
3. Purge both segmented and legacy cache key shapes. If this pre-transaction work fails,
   lower the barrier and remain settled `DISABLED_WIDENED`.
4. One transaction atomically writes `tenancy.enabled='1'` and checked-CASes
   `DISABLED_WIDENED → RELOADING`. If the transaction fails, refresh the guard and lower the
   barrier only after re-reading and proving the persisted pair is still settled-disabled;
   an unknown/torn pair retains the barrier and records failure. After the transaction commits,
   every failure keeps the barrier raised.
5. **Hooks are process-static with no selective removal (pinned P1):** the compat insert hook
   registered at this boot CANNOT be disarmed in-process. It is harmless only because the
   barrier blocks owned-table writes for the remainder of this process. The fresh boot resets
   the process-static registries, does NOT arm compat (snapshot sees `enabled=1`), and
   registers normal tenancy enforcement (owned-table registry populated).
6. Fresh boot rides the EXISTING `finalize()` machinery unchanged: `FinalizationProbe`
   (bindings, enforcement registration, scoped probe query, cache segment) → transactional
   `guard->end()` + CAS → `ON`.

Round-trip invariant: after `on → disabled_widened → on`, the SP1 acceptance suite passes
unmodified.

## §5 Resolution deactivate

`FullResolutionActivation::deactivate()` + `thallo:tenancy:resolution:deactivate`:

- Gates: step `FULL`; tenant count == 1 (never sever active multi-tenant traffic).
- One transaction (mirror image of the verified two-flag `completeFull()`): CAS
  `FULL → INACTIVE` + clear `tenancy.resolution` (+ failure fields).
- Route-cache rebuild; readiness flips false → profiles inert, mode falls to
  `bootstrap_default`. Required-host domain protection relaxes naturally (`isReady` false).
- A supported standalone operation — an operator may lower resolution and stay enabled.
- `disable()` refuses while resolution is `FULL`, naming this command.
- Surfaces: CLI `thallo:tenancy:resolution:deactivate` + `POST /v1/admin/tenancy/resolution/
  deactivate` on the resolution controller surface (same middleware/permission shape as §3).

## §6 Gates (`DisableGates`)

**Hard (technical):** tenant count == 1 (`TenantAdministration::listTenants`); resolution not
`FULL` (§5); `default_tenant_uuid` present (compat mode is impossible without it).

**Policy (pinned — documented as policy, not safety):** provenance clean. With one tenant and
the schema retained, divergence does NOT make scoping-off unsafe; this gate exists so a
disabled install is cleanly re-enableable and supportable. It is **hard, not policy, for the
future destructive rollback**. Two checks (pinned P1 — scoped to STARTER keys only, so
legitimate tenant-authored custom definitions never block):

1. No `starter_provenance` row in state `orphaned_source`
   (`StarterProvenanceRepository::divergentStates()` — new aggregate over kinds).
   > **Policy revision (2026-07-25, user decision):** `customized` no longer blocks. A
   > customized starter is a fully KNOWN state (provenance present, user-owned edits) and the
   > row survives disable, so re-enablement bookkeeping stays intact; blocking it made disable
   > impossible for any real site (everyone customizes their header) with no non-destructive
   > remedy — `thallo:tenant:sync` deliberately preserves customized state. The future
   > destructive rollback keeps the strict reading.
2. **Source-aware coverage for every current syncable starter definition** — never a flat
   `liveKeys - provenanceKeys` subtraction. For each source definition:
   - look up provenance by stable `source_id` first;
   - when provenance exists, require state `applied`, require its recorded key to be the
     current `definition_key` or one of that definition's `adoptionKeys`, and require the live
     row at that exact key to exist; a missing row or unrelated recorded key is dangling
     provenance and blocks;
   - when provenance does not exist, inspect only that source's current key and adoption-key
     history. A live row at one of those keys blocks as starter-shaped content of unknown
     origin; no live row also blocks as an unsynchronized/missing starter and names
     `thallo:tenant:sync` as the repair.

The algorithm iterates known starter sources, not all live definitions. Tenant-authored rows
at unrelated keys are never inspected and never block. Source-first matching also prevents a
legitimate rename/adoption history from being misclassified merely because the provenance
row still records the historical key.

Refusals name the unblocking command (`thallo:tenant:sync`, or the future destructive path).

## §7 Diagnose — `thallo:tenancy:diagnose`

Read-only, **never mutates** (pinned P1): the compat-stamping check uses
`Connection::applyInsertHooks()` on a synthetic payload (pure function of the hook registry)
plus an unscoped read of one EXISTING default-tenant row — no writes. The real write probe
lives only in DisableProbe (§3) under the raised barrier.

**Runtime/schema assertions (failures → exit FAILURE):**
1. State-pair coherence: `enabled × schema_state × resolution × enable_step` is a valid
   combination (`on`=1/widened; `disabled_widened`=0/widened; `off`=0/none; resolution `full`
   requires enabled; `DISABLED_WIDENED`+`retrofit_active` pair rules from §3). Transitional
   tuples are explicit: `DISABLING` requires enabled=1 and is resumable with either barrier
   value (barrier-absent is the post-CAS crash window); `RELOADING|FINALIZING` requires
   enabled=1 + barrier active; `ON` requires enabled=1 + barrier absent.
2. Schema: every `ThalloTenantTables` entry present has `tenant_uuid NOT NULL` + its declared
   widened uniques (`RetrofitDiagnostics::checkTables/checkAgreement` + `SchemaIntrospector`).
3. Probe: mode-tolerant — when `on`, a `runAsTenant(default)` scoped query raises no guard
   warning; when `disabled_widened`, the `applyInsertHooks` synthetic check + unscoped read.
4. Provenance integrity: dangling provenance (row whose live definition is gone without
   `orphaned_source` state) → failure; `orphaned_source`/`customized` present → **warning**
   (report, SUCCESS).
5. Cache-segment wiring: segment helper resolves per mode (`tenant:` prefix when on; fail-
   closed exception class when on-but-unresolved; empty when off/disabled).
6. Collections guard: pack present+enabled → warn "collections tenancy unsupported"; when on,
   collections routes carry the fence marker.

**Static audit (pinned — clearly separated):** the raw-PDO classification core is extracted
from `RawPdoScopingLintTest` into a production class (`RawPdoWriteAudit`, app-owned) consumed
by BOTH the PHPUnit lint and diagnose — single source of truth, including the §2 three-bucket
write inventory. Reported under a distinct "static audit" section; when the source tree is
absent (packaged deployment) it reports **"static audit unavailable"** as info — never a
failed isolation assertion.

Exit: any failed runtime/schema assertion → FAILURE; warnings/info alone → SUCCESS.

## §8 Failure modes

Disable refused: >1 tenant · resolution FULL (names deactivate) · provenance divergence or
incomplete starter coverage (policy message naming `thallo:tenant:sync`) · missing
`default_tenant_uuid`. Crash mid-disable/mid-re-enable → the explicit step/barrier tuple
determines whether the barrier is raised or retained; status-first CLI advances idempotently.
Compat write with no default tenant in the snapshot → helper throws (fail closed — never
invent a tenant). Deactivate refused: step ≠ FULL, or tenant count > 1. Re-enable refused
from any step except settled `DISABLED_WIDENED`. Diagnose on a torn state reports the exact
failing pair, exits FAILURE, mutates nothing.

## §9 Testing

- **Two-boot disable acceptance:** gates → barrier → sentinel → atomic flip → fresh-boot
  DisableProbe (scoping disarmed, hook armed via `applyInsertHooks`, real round-trip write
  under `runInternal`, sentinel absent) → barrier down; then: builder + EVERY bucket-1 raw
  writer insert successfully stamped default; reads unscoped; bucket-2 updates/deletes work.
- **Crash/resume failpoints** at every §3 step: immediately after `ON → DISABLING`, after
  barrier begin, after sentinel persistence, after purge, and between flag-flip and probe.
  Assertions cover both `DISABLING` tuples, sentinel reuse (never duplication), checked gate-
  refusal cleanup back to `ON`, and the `DISABLED_WIDENED` pair proving `needsFreshBoot`.
- **Re-enable round-trip:** `on → disabled_widened → on`; SP1 acceptance unmodified-green
  after; in-process post-flag write attempt during re-enable is blocked by the barrier (the
  process-static-hook hazard test).
- **Deactivate:** CAS + transactional clear; refusal matrix (FULL-only, count>1); standalone
  lower-and-stay-enabled; `disable()` refusal naming it.
- **Gates matrix:** each gate individually trips; custom (non-starter) definitions do NOT
  trip the provenance gate (pinned P1 regression); current/adoption-key row without source
  provenance, missing current source row, dangling applied provenance, and provenance whose
  key belongs to another source each trip independently. A legitimate source-id rename whose
  provenance records an adoption key passes.
- **Diagnose:** each assertion individually falsifiable (torn pair, missing unique, dangling
  provenance, unavailable source tree → info not failure); read-only proven (no row-count
  deltas across a full run).
- **Lint evolution:** three-bucket classification enforced; bucket-1 site without the neutral
  `TenantWriteScope` fails; unbucketed new raw INSERT fails.
- **Regression:** full off/on/inert suites; SP2a/SP2b acceptance untouched.

## §10 Out of scope

Destructive rollback to `off` (schema narrowing — future explicit command; provenance-clean
becomes a HARD gate there); disable UI (CLI/HTTP-API only — the admin SPA gains nothing in
SP2c); multi-tenant disable (count>1 is a permanent refusal in SP2, not a future SP2c
extension); framework, Glueful extension-contracts, and tenancy-extension changes. The one
Thallo-owned `TenantWriteScope` contract introduced by §2 is in scope.
