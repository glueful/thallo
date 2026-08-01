# SP2c — Disable Path (`disabled_widened`) + Diagnostics — Implementation Plan (rev 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A routine, reversible OFF-switch (`on → disabled_widened → on`) with enable-grade barrier/fresh-boot/CAS discipline, default-tenant compat stamping on both write paths, an explicit resolution deactivate, and a read-only `thallo:tenancy:diagnose`.

**Architecture:** Thallo-owned (zero framework, Glueful extension-contracts, or tenancy-extension changes). A neutral `TenantWriteScope` interface in the already-shared `thallo-contracts` package prevents analytics/workflow from depending on the sibling tenancy implementation; `CompatWriteScope` implements it as a boot-refreshed snapshot and feeds the builder hook + bucket-1 raw writers. The disable machine folds into `TenancyEnablement` (new `DISABLING`/`DISABLED_WIDENED` steps, pair-state disambiguation via `tenancy.retrofit_active`, persisted cache sentinel, DisableProbe fresh-boot settle); re-enable enters through `begin()`; `deactivate()` mirrors the verified `completeFull()` CAS-transaction; diagnose composes `RetrofitDiagnostics`/`SchemaIntrospector`/provenance/`applyInsertHooks` plus the extracted `RawPdoWriteAudit`. Spec: `docs/superpowers/specs/multi-tenancy/2026-07-10-sp2c-disable-diagnostics-design.md` (user-finalized); invariants `SP2-README.md` §3.

**Tech Stack:** PHP 8.3, PostgreSQL, PHPUnit; harness `RetrofittedTenantTestCase` + the SP1 two-boot helpers.

## Global Constraints

- **Repos:** `/Users/michaeltawiahsowah/Sites/glueful/thallo` plus its held `packages/thallo-contracts` source (same working tree). **HOLD ALL COMMITS**; `dev`; NO attribution. `strict_types`, `final`, DI, `use`-imports; `composer phpcs` clean.
- Boundary: pack never imports `App\…`; app never imports `Glueful\Extensions\Tenancy\*` (SP2 index §3.2).
- **Verified transition machinery:** guard `begin()/end()` write persisted `tenancy.retrofit_active` + process flag; `refresh()` re-reads persisted (`RetrofitMaintenanceGuard.php:32-51`); `runInternal()` = process-local bypass (`:93`); finalize's transactional `guard->end()`+CAS template at `TenancyEnablement.php:185-191` (catch → `guard->refresh()`+`recordFailure`, `:192-195`). `completeFull()` CAS-txn template at `ResolutionActivationStore.php:87-107`.
- **Verified compat seams:** `Connection::applyInsertHooks(string $table, array $data): array` is STATIC and SQL-free (`Connection.php:637`); `addInsertHook(\Closure)` static (`:617`). `CacheStore::set/get/delete/deletePattern` (`CacheStore.php:26-178`).
- **Pair-state rule (spec §3):** `DISABLED_WIDENED` + `retrofit_active=1` = awaiting fresh boot; + absent = settled. `DISABLING` + barrier-absent = post-CAS crash window (raise + resume); + barrier-active = resume gates→sentinel→flip. `status()/retry()/disable()/begin()` all derive from the tuple. Never lower the barrier against an unknown/torn state.
- **Compat rule:** writes during `disabled_widened` carry the DEFAULT tenant on BOTH paths (builder hook + bucket-1 raw writers **including `TenantBlobPolicy` attribution** — no ownerless blobs created while disabled); helper is a boot snapshot, never reads `SystemFlags` in the hook path; throws when default tenant missing (never invent a tenant).
- **Gates:** hard = count==1, resolution≠FULL, default tenant present; policy (documented) = provenance clean via SOURCE-AWARE matching (spec §6 algorithm — iterate starter sources, never flat key subtraction; tenant-authored rows at unrelated keys invisible).
- **Diagnose is read-only** — `applyInsertHooks` synthetic check + unscoped read of an existing row; the only mutating probe is DisableProbe under the raised barrier (`guard->runInternal()`).
- Sequencing: T1→T2 · T3 · T4(T1,T3) · T5(T4) · T6 · T7 · T8(T7) · T9(T4,T6) · T10.

## File Structure

**Create (contract):** `packages/thallo-contracts/src/Tenancy/TenantWriteScope.php`.
**Create (pack):** `packages/thallo-tenancy/src/Compat/CompatWriteScope.php`, `packages/thallo-tenancy/src/Enablement/{DisableGates,DisableProbe}.php`, `packages/thallo-tenancy/src/Http/Controllers/TenancyResolutionController.php`, `packages/thallo-tenancy/src/Console/{TenancyDisableCommand,ResolutionDeactivateCommand,TenancyDiagnoseCommand}.php`.
**Create (app):** `app/Content/Starter/{DefaultStarterCoverageCheck,RawPdoWriteAudit}.php`.
**Modify:** `packages/thallo-tenancy/src/Enablement/{EnablementStep,EnablementStore,TenancyEnablement}.php`, `packages/thallo-tenancy/src/Resolution/{ResolutionActivationStore,FullResolutionActivation}.php`, `packages/thallo-tenancy/src/TenancyServiceProvider.php` (compat hook registration + bindings), `packages/thallo-tenancy/routes/enablement.php` (+disable, +resolution deactivate), `packages/thallo-tenancy/src/Http/Controllers/TenancyEnablementController.php` (+disable), bucket-1 raw writers (`packages/thallo-seo/src/Meta/SeoMetaRepository.php`, `packages/thallo-analytics/src/Facts/AnalyticsRecorder.php`, `packages/thallo-workflow/src/WorkflowStateRepository.php`, `app/Content/Media/TenantBlobPolicy.php`), `app/Content/Starter/StarterProvenanceRepository.php` (+`divergentStates()`), `tests/Unit/Tenancy/RawPdoScopingLintTest.php` (3-bucket + audit-core consumption).
**Tests:** `tests/Integration/Tenancy/{CompatWriteScopeTest,DisableGatesTest,DisableMachineTest,ReEnableRoundTripTest,ResolutionDeactivateTest,DiagnoseCommandTest,DisableAcceptanceTest}.php`, `tests/Unit/Content/Starter/DefaultStarterCoverageCheckTest.php`, `tests/Unit/Tenancy/{RawPdoWriteAuditTest,TenantWriteScopeContractTest}.php`.

---

### Task 1: `CompatWriteScope` + builder insert hook

**Files:**
- Create: `packages/thallo-contracts/src/Tenancy/TenantWriteScope.php`, `packages/thallo-tenancy/src/Compat/CompatWriteScope.php`
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php` (boot: snapshot + conditional hook registration)
- Test: `tests/Integration/Tenancy/CompatWriteScopeTest.php`, `tests/Unit/Tenancy/TenantWriteScopeContractTest.php`

**Interfaces:**
- Consumes (verified): `SystemFlags::{tenancyEnabled,schemaState,defaultTenantUuid}` — read ONCE at boot; `Connection::addInsertHook(\Closure(string,array):array)` static (`Connection.php:617`); `ThalloTenantTables::tableNames()`.
- Produces: neutral `Thallo\Contracts\Tenancy\TenantWriteScope` (`mode(): string; tenantUuidForWrite(): ?string`) in `thallo-contracts`; `CompatWriteScope` implements it and adds `stampIfMissing(string $table, array $row): array`. Its ctor `(bool $enabled, string $schemaState, ?string $defaultTenantUuid)` is a frozen snapshot; `mode()` returns `'normal'|'compat'|'off'` (compat iff `widened && !enabled`); `tenantUuidForWrite()` returns null in normal/off, the default in compat, and throws when compat has no default. Provider boot conditionally registers the insert hook, whose body touches ONLY the snapshot.

- [ ] **Step 1: Failing tests** — snapshot modes (on/1/widened→normal; 0/widened→compat; 0/none→off); compat + missing default → throws; `stampIfMissing` stamps owned-table rows lacking tenant_uuid, leaves non-owned + already-stamped untouched; hook path proven SQL-free via `Connection::applyInsertHooks('content_types', ['title'=>'x'])` returning a stamped row after registration; hook NOT registered when mode ≠ compat (applyInsertHooks returns input unchanged).
- [ ] **Step 2: Implement + provider wiring.** Run → PASS; SP1/SP2a/SP2b suites untouched (hook never arms while enabled). phpcs. Commit SKIPPED (HELD); ledger.

---

### Task 2: Three-bucket raw-writer split + bucket-1 compat adoption

**Files:**
- Modify: `packages/thallo-seo/src/Meta/SeoMetaRepository.php`, `packages/thallo-analytics/src/Facts/AnalyticsRecorder.php`, `packages/thallo-workflow/src/WorkflowStateRepository.php`, `app/Content/Media/TenantBlobPolicy.php`, `tests/Unit/Tenancy/RawPdoScopingLintTest.php`
- Test: the evolved lint + `tests/Integration/Tenancy/CompatWriteScopeTest.php` (extend with raw-writer cases)

**Interfaces:**
- Consumes (verified write-verb classification): **bucket-1 INSERT/upsert** = SeoMetaRepository (`INSERT…ON CONFLICT`), AnalyticsRecorder (×2), WorkflowStateRepository, TenantBlobPolicy (`ON CONFLICT DO NOTHING`); **bucket-2 UPDATE/DELETE** = MenuRepository (DELETE×3, UPDATE×2), BlockMigrationRepository, MigrationRepository, ScheduleRepository (UPDATE×3), VersionPruner (DELETE); **bucket-3 system/DDL** = EnsureFilterIndexesJob (CREATE/DROP INDEX + un-owned `filter_indexes` upsert). `CompatWriteScope::tenantUuidForWrite()` (T1).
- Produces: bucket-1 writers resolve their tenant value through one seam — today they read the current tenant (contracts `TenantScope`/`CurrentContext`); each gains the compat fallback through nullable `TenantWriteScope`, never the concrete sibling-pack class: `$tenant = $this->writeScope?->mode()==='compat' ? $this->writeScope->tenantUuidForWrite() : <existing resolution>;`. Analytics/workflow already require `thallo-contracts`, so no undeclared tenancy-pack dependency is introduced; null scope preserves standalone behavior. **`TenantBlobPolicy` (spec-critical):** `onBlobCreated`'s `!tenancyEnabled()` early-return becomes mode-aware — in compat mode it attributes to the DEFAULT tenant; clean off keeps the early-return. `authorizeAccess` unchanged.
- Lint evolution: add `WRITE_BUCKETS` const mapping every classified writer to `insert|mutate|system`; assert bucket-1 files contain `tenantUuidForWrite(` (or `CompatWriteScope`), bucket-2/3 do NOT (meaningless-call prevention, pinned P2); retain all existing lint assertions (`RUNWRITABLE_SITES` counts unchanged — compat resolution happens inside existing `runWritable` wraps).

- [ ] **Step 1: Evolve the lint FIRST (RED)** — `WRITE_BUCKETS` + per-bucket assertions.
- [ ] **Step 2: Adopt compat in the four bucket-1 writers** (inject nullable `TenantWriteScope`; existing autowiring resolves the Thallo provider binding and null preserves standalone use). Integration: with a disabled_widened snapshot stub, each bucket-1 writer's insert lands stamped default; bucket-2 update/delete works unscoped; blob upload during compat creates a default-owned `media_assets` row.
- [ ] **Step 3: Run → PASS (lint + integration + full on/off suites); phpcs. Commit SKIPPED.**

---

### Task 3: `DisableGates` + `divergentStates()`

**Files:**
- Create: `packages/thallo-tenancy/src/Enablement/DisableGates.php`, `packages/thallo-tenancy/src/Contracts/StarterCoverageCheck.php`, `app/Content/Starter/DefaultStarterCoverageCheck.php`
- Modify: `app/Content/Starter/StarterProvenanceRepository.php` (+`divergentStates(): array`)
- Modify: `app/Providers/ThalloServiceProvider.php` (bind pack interface → shared app implementation)
- Test: `tests/Integration/Tenancy/DisableGatesTest.php`, `tests/Unit/Content/Starter/DefaultStarterCoverageCheckTest.php`

**Interfaces:**
- Consumes (verified): `TenantAdministration::listTenants(ApplicationContext, ?string $status=null): array`; `ResolutionActivationStore::step()`; `SystemFlags::defaultTenantUuid()`; SP2b as-built `StarterKind::{definitions,locateExact(string): ?array{key,fingerprint}}`, `StarterDefinition::{sourceId,definitionKey,adoptionKeys}` (public readonly), `StarterDefinitions::syncKinds()`, `StarterProvenanceRepository::{findBySource,sourceIdsFor}`.
- Produces: `StarterProvenanceRepository::divergentStates(): array` — all rows (any kind) with state ∈ {customized, orphaned_source}, shape `list<array{definition_kind,definition_key,state}>`. `DisableGates::assertCanDisable(): void` throwing `EnablementException` with the gate name + unblocking command; boundary note: `DisableGates` lives in the pack but consumes starter surfaces — it takes the kinds via the pack-safe route: a Thallo-local `StarterCoverageCheck` interface `{coverageViolations(): list<string>}` declared in the pack (`packages/thallo-tenancy/src/Contracts/StarterCoverageCheck.php`), implemented app-side over `StarterDefinitions`+repo (same inversion as `TenantSeedActivator`), bound by `ThalloServiceProvider`.
- **Source-aware algorithm (spec §6, implemented app-side in the `StarterCoverageCheck` impl):** for each syncable source definition: provenance by `source_id` → exists: require state `applied` AND recorded key ∈ {current `definitionKey`} ∪ `adoptionKeys` AND `locateExact(recordedKey)` non-null (else violation "dangling/wrong-source"); absent: probe `locateExact` over {current key} ∪ `adoptionKeys` — live row found → violation "starter-shaped row of unknown origin"; none → violation "missing starter (run thallo:tenant:sync)". Tenant-authored rows at unrelated keys never inspected.

- [ ] **Step 1: Failing gates matrix** — each hard gate trips individually (two tenants; resolution FULL stub; missing default uuid); policy gate cases: customized row → blocked; orphaned_source → blocked; custom NON-starter content type → **passes** (pinned regression); live row at an adoption key without provenance → blocked (unknown origin); missing starter row → blocked naming sync; provenance recording an adoption key with the live row present → **passes** (legit rename history); provenance keyed to a key belonging to a different source → blocked (wrong-source).
- [ ] **Step 2: Implement (`divergentStates`, pack interface, app impl, gates).** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 4: Disable machine — steps, store, `disable()`, `DisableProbe`

**Files:**
- Create: `packages/thallo-tenancy/src/Enablement/DisableProbe.php`
- Modify: `packages/thallo-tenancy/src/Enablement/EnablementStep.php` (+`DISABLING`,`DISABLED_WIDENED`; `needsFreshBoot()` UNCHANGED — the pair rule governs, see below), `EnablementStore.php` (+`disable_sentinel` key: `sentinelKey()/setSentinelKey()/clearSentinel()`), `TenancyEnablement.php` (+`disable()`; `status()` + `retry()` pair-awareness)
- Test: `tests/Integration/Tenancy/DisableMachineTest.php`

**Interfaces:**
- Consumes (verified): store CAS/failure API (`EnablementStore.php:29-99`); guard `begin/end/refresh/active/runInternal`; finalize's transactional template (`TenancyEnablement.php:185-195`) — the refusal-cleanup and flip transactions copy its exact shape; `CacheTransition::purge()`; `CacheStore::set/get/delete`; `DisableGates` (T3); `CompatWriteScope` snapshot semantics (T1); `TenantEnforcementProbe::registeredTables()`; `Connection::applyInsertHooks` (static).
- Produces: `EnablementStep::DISABLING` (progress 0? — assign progress values 10/95 consistent with the enum's ladder), `DISABLED_WIDENED`; **pair-state helpers on `TenancyEnablement`**: `private function disabledPairSettled(): bool` (`step===DISABLED_WIDENED && !guardPersistedActive()`), reading the persisted flag via `SystemFlags::get('tenancy.retrofit_active')` (NOT `guard->active()` — process flag may be stale across boots); `status()` gains `reloading` truth for `DISABLED_WIDENED`+barrier (reports fresh-boot-required) and `disable()`/`begin()` branch off the tuple.
- `disable()` flow (all under `lock->withLock`), exactly spec §3 order:

```php
public function disable(): EnablementStatus
{
    return $this->lock->withLock(function (): EnablementStatus {
        $step = $this->store->step();

        if ($step === EnablementStep::DISABLED_WIDENED) {
            if ($this->guardPersistedActive()) {          // awaiting fresh-boot verification
                $this->settleDisable();                    // DisableProbe → guard->end() on pass
            }
            return $this->status();                        // settled (or probe failure recorded)
        }

        if ($step === EnablementStep::ON) {
            if (!$this->store->compareAndSet(EnablementStep::ON, EnablementStep::DISABLING)) {
                throw new StaleStateException('Enablement state changed underneath disable().');
            }
            $step = EnablementStep::DISABLING;
        }
        if ($step !== EnablementStep::DISABLING) {
            throw new EnablementException('disable() requires ON or a resumable DISABLING state.');
        }

        // Tuple recovery: barrier absent = post-CAS crash window → raise it (spec §3 resume contract).
        if (!$this->guardPersistedActive()) {
            $this->guard->begin();                         // BEFORE any mutable gate read (pinned)
        }

        try {
            $this->disableGates->assertCanDisable();
        } catch (EnablementException $refusal) {
            // Transactional, CHECKED cleanup back to ON (pinned): CAS + metadata + barrier together.
            try {
                $this->connection->transaction(function (): void {
                    if (!$this->store->compareAndSet(EnablementStep::DISABLING, EnablementStep::ON)) {
                        throw new EnablementException('Refusal cleanup lost a CAS race.');
                    }
                    $this->store->clearSentinel();
                    $this->guard->end();
                });
            } catch (\Throwable $e) {
                $this->guard->refresh();                   // never lower against an unknown state
                $this->store->recordFailure(EnablementStep::DISABLING, $e->getMessage());
                return $this->status();
            }
            throw $refusal;                                // surfaces as 422 via guarded()
        }

        // Sentinel: reuse a persisted key across retries; create only when absent (pinned).
        $sentinel = $this->store->sentinelKey();
        if ($sentinel === null) {
            $sentinel = 'tenant:' . $this->flags->defaultTenantUuid()
                . ':render:disable-sentinel:' . bin2hex(random_bytes(8));
            $this->store->setSentinelKey($sentinel);
        }
        $this->cache()->set($sentinel, '1', 3600);
        $this->cacheTransition->purge();

        // Atomic flip: enabled=0 + step, one transaction; barrier stays UP (outside the txn).
        $this->connection->transaction(function (): void {
            $this->flags->put('tenancy.enabled', '0');
            if (!$this->store->compareAndSet(EnablementStep::DISABLING, EnablementStep::DISABLED_WIDENED)) {
                throw new EnablementException('Disable flip lost a CAS race.');
            }
        });
        return $this->status();                            // pair: DISABLED_WIDENED + barrier ⇒ fresh boot
    });
}
```

- `DisableProbe` (ctor `(ApplicationContext, SystemFlags, Connection, RetrofitMaintenanceGuard, EnablementStore, CacheStore, ?TenantEnforcementProbe)`), `passes(): array{scoping,hook,write,sentinel,ok}`: (a) `enforcementProbe?->registeredTables() === []`; (b) `Connection::applyInsertHooks('content_types', ['title' => 'probe'])['tenant_uuid'] === default`; (c) inside `guard->runInternal()`, run one DB transaction that inserts a schema-valid, nonce-keyed `settings` probe row, reads it UNSCOPED, verifies it, and deletes it (any throw rolls the insert back); (d) persisted sentinel key → `cache->get() === null`. `passes()` NEVER clears sentinel metadata. `settleDisable()` on pass uses one transaction for `store->clearSentinel()` + `guard->end()`; on transaction failure it calls `guard->refresh()`, records failure, and retains both barrier and sentinel for retry. Probe failure likewise retains both.

- [ ] **Step 1: Failing machine tests** — full happy two-boot path; **failpoints at each spec §9 point** (post-CAS pre-barrier → tuple `DISABLING`+absent resumes by raising; post-barrier → resumes gates; post-sentinel → REUSES the same key (assert no second key); post-purge pre-flip → flip proceeds; post-flip → pair reports `needsFreshBoot`, no in-process path back to ON); refusal cleanup returns to `ON` with barrier down + sentinel cleared, and a stubbed cleanup-transaction failure keeps the barrier + records failure; probe failure keeps barrier + is retryable.
- [ ] **Step 2: Implement (enum, store field, disable(), probe, status()/retry() tuple handling).** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 5: Re-enable light path via `begin()`

**Files:**
- Modify: `packages/thallo-tenancy/src/Enablement/TenancyEnablement.php` (`begin()` gains the settled-`DISABLED_WIDENED` branch at the top of the existing if-ladder, `TenancyEnablement.php:54-100` region)
- Test: `tests/Integration/Tenancy/ReEnableRoundTripTest.php`

**Interfaces:**
- Consumes: pair helpers (T4); `CacheTransition::purge()`; existing `RELOADING → finalize()` machinery UNCHANGED (`FinalizationProbe` + transactional `guard->end()`+CAS at `:185-191`).
- Produces: `begin()` branch, exactly spec §4 order:

```php
if ($step === EnablementStep::DISABLED_WIDENED) {
    if ($this->guardPersistedActive()) {
        throw new EnablementException('Disable is not settled; run thallo:tenancy:disable to finish it.');
    }
    // 1. barrier FIRST; 2. purge; 3. atomic enabled=1 + CAS → RELOADING; fresh boot finalizes.
    $this->guard->begin();
    try {
        $this->cacheTransition->purge();
    } catch (\Throwable $e) {
        $this->guard->end();                       // pre-transaction failure: provably settled → lower
        throw new EnablementException('Re-enable cache purge failed: ' . $e->getMessage(), 0, $e);
    }
    try {
        $this->connection->transaction(function (): void {
            $this->flags->put('tenancy.enabled', '1');
            if (!$this->store->compareAndSet(EnablementStep::DISABLED_WIDENED, EnablementStep::RELOADING)) {
                throw new EnablementException('Re-enable flip lost a CAS race.');
            }
        });
    } catch (\Throwable $e) {
        $this->guard->refresh();
        if ($this->flags->get('tenancy.enabled') !== '1'
            && $this->store->step() === EnablementStep::DISABLED_WIDENED) {
            $this->guard->end();                   // re-read PROVES still settled-disabled → safe to lower
            throw new EnablementException('Re-enable transaction failed: ' . $e->getMessage(), 0, $e);
        } else {
            $this->store->recordFailure(EnablementStep::DISABLED_WIDENED, $e->getMessage());
        }                                          // torn/unknown pair: barrier STAYS (pinned)
        return $this->status();
    }
    return $this->status();                        // RELOADING + barrier ⇒ fresh boot → finalize() → ON
}
```

(`flags->clearCache()` before the re-read; no slug/name/owner prompt — pending fields untouched.)

- [ ] **Step 1: Failing round-trip test** — `on → disable (two boots) → begin() → RELOADING → fresh boot finalize → ON`; SP1 acceptance suite green after; **process-static-hook hazard**: after the in-process flip, an owned-table write in the SAME process is blocked by the barrier (the stale compat hook never observed); transaction-failure branches (still-settled → barrier lowered; torn pair stub → barrier retained + failure).
- [ ] **Step 2: Implement.** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 6: Resolution `deactivate()`

**Files:**
- Modify: `packages/thallo-tenancy/src/Resolution/ResolutionActivationStore.php` (+`deactivate(ResolutionActivationStep $expected): bool` — mirror of `completeFull()` `:87-107`), `FullResolutionActivation.php` (+public `deactivate(): array`), `packages/thallo-tenancy/src/Enablement/DisableGates.php` (resolution gate reads the store)
- Create: `packages/thallo-tenancy/src/Console/ResolutionDeactivateCommand.php`
- Test: `tests/Integration/Tenancy/ResolutionDeactivateTest.php`

**Interfaces:**
- Consumes (verified): `completeFull()` transactional shape (guard step check → `connection->transaction(fn: flags writes + step write)` → `clearCache()`); `RouteCache::clear()` soft-resolve pattern (`FullResolutionActivation.php:119-128`); `TenantAdministration::listTenants` (count gate).
- Produces: `ResolutionActivationStore::deactivate()` — one transaction: `flags->forget('tenancy.resolution')` + `flags->put(step, INACTIVE)` + `flags->forget(resolution_failure/_failed_from/awaiting_boot)`; CAS-guarded on `$expected===FULL`. `FullResolutionActivation::deactivate(): array` — lock → gates (step FULL else refuse; tenant count==1 else refuse) → `store->deactivate(FULL)` → `RouteCache::clear()` (soft-resolve) → status. CLI `thallo:tenancy:resolution:deactivate` (status-first, FAILURE on refusal). **`disable()` integration (T3's gate):** resolution gate reads `store->step()===FULL` → refuse naming this command.

- [ ] **Step 1: Failing tests** — FULL+one tenant → INACTIVE, flags cleared transactionally, mode falls to `bootstrap_default`, profiles inert (readiness false), required-host protection relaxed (disableDomain on a default host now permitted); refusals: step≠FULL, count>1; standalone (stays enabled, SP1 mode works); `disable()` refusal names the command.
- [ ] **Step 2: Implement.** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 7: `RawPdoWriteAudit` extraction

**Files:**
- Create: `app/Content/Starter/RawPdoWriteAudit.php` (app-owned production class)
- Modify: `tests/Unit/Tenancy/RawPdoScopingLintTest.php` (consume the core; keep test-only assertions local)
- Test: `tests/Unit/Tenancy/RawPdoWriteAuditTest.php`

**Interfaces:**
- Consumes: the lint's as-built classification lists (SCOPED/SYSTEM_READERS/SYSTEM_WRITERS/GLOBAL_BY_PROOF/RETROFIT_ENGINE/RUNWRITABLE_SITES + T2's WRITE_BUCKETS) — MOVED into the audit class as consts; filesystem scanning relative to a base path.
- Produces: `RawPdoWriteAudit::__construct(string $basePath)`; `available(): bool` (source dirs exist — packaged deployments return false); `run(): array{available:bool, unclassified:list<string>, bucketViolations:list<string>, wrapperMismatches:list<string>}` — the exact checks the lint performs today, returned as data instead of assertions. The PHPUnit lint becomes a thin consumer (`assertSame([], $audit->run()['unclassified'])` etc. — same coverage, single source of truth); diagnose (T8) consumes the same object and renders "static audit unavailable" (info) when `available()===false` (pinned).

- [ ] **Step 1: Failing test** — audit over the real tree: zero violations; audit over an empty temp dir: `available()===false`, no violations claimed; a fixture tree with an unclassified `getPDO()` file → reported.
- [ ] **Step 2: Extract + rewire the lint (must stay green with identical coverage).** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 8: `thallo:tenancy:diagnose`

**Files:**
- Create: `packages/thallo-tenancy/src/Console/TenancyDiagnoseCommand.php` + `packages/thallo-tenancy/src/Enablement/TenancyDiagnostics.php` (the composition, testable without CLI)
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php` (bindings); pack contract `packages/thallo-tenancy/src/Contracts/StaticWriteAudit.php` (pack-neutral view of T7's app class: `available(): bool; run(): array` — bound by `ThalloServiceProvider` to `RawPdoWriteAudit`, same inversion as `StarterCoverageCheck`)
- Test: `tests/Integration/Tenancy/DiagnoseCommandTest.php`

**Interfaces:**
- Consumes (verified): `RetrofitDiagnostics::{checkTables(): array<string,{ok,detail}>, checkAgreement(): {ok,detail}}`; `SchemaIntrospector`; `SystemFlags` + `EnablementStore::step()` + `ResolutionActivationStore::step()` (coherence tuples); `TenantCacheSegment::segment()` (+ `MissingTenantForCacheException`); `Connection::applyInsertHooks` (static, SQL-free); `TenantContextRunner` (scoped probe when on); collections fence marker on `/v1/collections` routes (route-table read, `RouteCoverageTest` pattern); `StaticWriteAudit` (T7 via inversion).
- Produces: `TenancyDiagnostics::report(): array{sections: array<string, array{status: 'ok'|'warn'|'fail'|'info', detail: mixed}>, ok: bool}` implementing spec §7 assertions 1–6 + the static section; **coherence tuples exactly** spec §7.1 (incl. transitional: `DISABLING` requires enabled=1, either barrier value; `RELOADING|FINALIZING` require enabled=1 + barrier; `ON` requires enabled=1 + no barrier; `DISABLED_WIDENED` per pair rule; resolution `full` requires enabled). Read-only: assertion 3's disabled-mode branch = `applyInsertHooks` synthetic + `SELECT` one existing default-tenant `content_types` row unscoped. CLI renders sections (`table()`), exit FAILURE iff any `fail`.

- [ ] **Step 1: Failing tests** — green report on a healthy ON install and on settled disabled_widened; each assertion individually falsifiable (torn pair via flag stub → fail; dropped unique via introspector stub → fail; dangling provenance fixture → fail; customized row → warn+SUCCESS; audit unavailable → info+SUCCESS); **read-only proof**: row counts of `starter_provenance`+`content_types`+`settings` identical before/after a full run in BOTH modes.
- [ ] **Step 2: Implement.** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 9: Surfaces — HTTP disable/deactivate + CLI disable

**Files:**
- Modify: `packages/thallo-tenancy/src/Http/Controllers/TenancyEnablementController.php` (+`disable()` via `guarded()`), `packages/thallo-tenancy/routes/enablement.php` (+`POST /tenancy/disable`, `POST /tenancy/resolution/deactivate`), `packages/thallo-tenancy/src/TenancyServiceProvider.php` (bind `TenancyResolutionController`)
- Create: `packages/thallo-tenancy/src/Http/Controllers/TenancyResolutionController.php` (`status()` + `deactivate()` — **resolution had NO HTTP surface as-built (CLI-only); this creates it**, flagged as a plan decision: a small dedicated controller over `FullResolutionActivation`, same `guarded()` map, rather than widening the enablement controller's dependency set), `packages/thallo-tenancy/src/Console/TenancyDisableCommand.php`
- Test: extend `tests/Integration/Tenancy/DisableMachineTest.php` + `ResolutionDeactivateTest.php` with surface cases

**Interfaces:**
- Consumes: `TenancyEnablement::disable()` (T4), `FullResolutionActivation::{status,deactivate}` (T6); verified controller pattern (`guarded()` map: locked/stale → 409, `EnablementException` → 422, `TenancyEnablementController.php:70`); route shape (`routes/enablement.php:9-18` — `tenant_system` + `content_permission:system.access`); CLI status-first pattern (`TenancyEnableCommand.php:25`).
- Produces: `POST /v1/admin/tenancy/disable` → `disable()` (409/422/200 per guarded map; refusals carry the gate message naming the unblocking command); `POST /v1/admin/tenancy/resolution/deactivate`; CLI `thallo:tenancy:disable` — status-first: settled → report; `DISABLED_WIDENED`+barrier → runs the probe settle; `ON`/`DISABLING` → advance; FAILURE when the resulting step is FAILED or a refusal was thrown (refusal text printed).

- [ ] **Step 1: Failing surface tests** — HTTP disable happy first-hop (200, pair state in payload); refusal → 422 naming gate; deactivate endpoint parity with CLI; CLI two-invocation disable (boot1 flip, boot2 settle) via the harness; route coverage test still green (`tenant_system` markers).
- [ ] **Step 2: Implement.** Run → PASS; phpcs. Commit SKIPPED.

---

### Task 10: Acceptance + regression gates

**Files:**
- Create: `tests/Integration/Tenancy/DisableAcceptanceTest.php`
- Test: this task.

- [ ] **Step 1: Acceptance (spec §9):** full journey on the two-boot harness — reach ON (SP1 helpers) → activate full resolution → `deactivate` (standalone, stays enabled) → `disable` boot1 (gates/barrier/sentinel/flip) → boot2 settle (probe: scoping disarmed, hook armed, transactional `runInternal` round-trip write, sentinel absent, barrier down) → compat era: builder insert + EVERY bucket-1 raw writer stamped default; blob upload owned by default; reads unscoped; bucket-2 ops work → `begin()` re-enable → fresh-boot finalize → ON → SP1 acceptance suite green unmodified. Diagnose is green at stable ON/disabled stations; coherent transitional tuples report warn/info and SUCCESS, while only torn/incoherent tuples fail.
- [ ] **Step 2: Gate runs (user directive — before any release/pinning):** thallo tenancy-OFF full suite; tenancy-ON full suite; SP2a inert + full-resolution acceptance; SP2b seed/sync suites; `composer phpcs`. Record all counts in the ledger.
- [ ] **Step 3: Ledger. Commits remain HELD** (batch shape at go-ahead: compat+buckets · machine+gates+probe · re-enable+deactivate · diagnose+audit · surfaces+tests).

---

## Self-Review (rev 2)

**Spec coverage:** §2 helper/snapshot/builder/3-bucket (+ blob-attribution continuity) → T1/T2; §3 machine incl. barrier-first gates, transactional refusal cleanup with `guard->refresh()`, DISABLING tuples, sentinel reuse, atomic flip, pair rule, DisableProbe, surfaces → T4/T9; §4 begin()-entry re-enable with exact ordering + torn-pair barrier retention + hook hazard → T5; §5 deactivate (CAS mirror, count gate, route-cache, standalone, disable-refusal, surfaces) → T6/T9; §6 hard+policy gates with the full source-aware algorithm → T3; §7 diagnose (six runtime assertions with transitional tuples, read-only mechanics, static-audit separation/degradation) → T7/T8; §8 failure modes distributed as named tests; §9 every matrix present (failpoints per step, gates matrix incl. the four independent provenance trips + rename pass, diagnose falsifiability, lint evolution, round-trip); §10 respected (no destructive path, no SPA work, no framework/Glueful-contract/tenancy-extension changes; one Thallo-contract interface).

**Plan decisions resolved:** (a) resolution deactivate's HTTP surface uses a dedicated, provider-bound `TenancyResolutionController` rather than widening the enablement controller. (b) Pack↔app inversions use explicit concrete app implementations and provider bindings. (c) bucket-1 writers consume neutral `thallo-contracts::TenantWriteScope`, not the sibling tenancy implementation.

**Verified-contract basis:** every Consumes cites the sweep's file:line (guard `:32-93`, finalize txn `:185-195`, `applyInsertHooks` `:637` static, `completeFull` `:87-107`, begin() ladder `:54-100`, guarded() `:70`, routes `:9-18`, lint buckets with per-file write verbs, `RetrofitDiagnostics`/`SchemaIntrospector` shapes, `CacheStore` sigs, SP2b `StarterKind`/`StarterDefinition`/provenance-repo as-built incl. `locateForAdoption` and the ABSENCE of `divergentStates`). The blob-attribution continuity requirement (T2) derives from verified `onBlobCreated` early-return behavior — without it, disabled-era uploads become ownerless and denied post-re-enable.

**Type consistency:** `TenantWriteScope::{mode,tenantUuidForWrite}` is the T1 cross-pack contract; `CompatWriteScope` implements it and adds `stampIfMissing` for T1/T4. Pair helpers are named `guardPersistedActive()` in T4 and reused verbatim in T5; `divergentStates()` is defined T3 and consumed by the T3 gate impl; `RawPdoWriteAudit::{available,run}` is identical T7/T8; enum cases `DISABLING`/`DISABLED_WIDENED` are consistent across T4/T5/T8/T9; sentinel store methods `sentinelKey/setSentinelKey/clearSentinel` are consistent T4-code/T4-probe.
