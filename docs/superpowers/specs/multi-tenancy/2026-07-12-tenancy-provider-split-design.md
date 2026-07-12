# Tenancy Provider Split — Design

**Status:** spec in review (HELD — not committed)
**Scope:** Split the `glueful/tenancy` engine into an always-on **control-plane** provider and an
enablement-gated **enforcement** provider; make `SystemFlags::enforcementActive()` the canonical
"tenant-aware work permitted" signal; retire the temporary 2C Thallo workarounds.
**Release chain:** `glueful/tenancy` (1.4.0) → Thallo. **No contracts change.** Vendor-first (edit
`vendor/glueful/tenancy` in place, test live in Thallo, port to source + release).
**Date:** 2026-07-12

---

## §0 Context — as-built (source-verified)

- **One provider, all-or-nothing.** `Glueful\Extensions\Tenancy\TenancyServiceProvider` binds the
  entire graph (provisioner, administration, runners, `CurrentTenantResolver`, resolver
  chain/pipeline, middleware, probes, strategy) in one ungated `services()`, loads the identity
  migrations at `MigrationPriority::DEFAULT - 50` in `boot()`, and gates only the **runtime hooks**
  (table hook, `TenantQueryGuard`, `TenantInsertStamper`, `TenantTableRegistry::loadFromConfig`) on
  `config('tenancy.enabled', true)`.
- **Activation = extensions-list membership.** The provider is NOT in `config/extensions.php` on a
  clean install. Thallo's `ExtensionActivation` (`Enablement/ExtensionActivation.php`) adds the
  FQCN via `ExtensionStateWriter::enable()` + cache regeneration during enablement
  (`ENABLING_EXTENSION` today); `isActivated()` = "FQCN is in the enabled list."
- **Two provider lists.** `config/extensions.php` holds composer-discovered extension providers —
  the manifest supports **one provider per package**, and the resolver rejects any additional FQCN
  as a missing extension provider; managed by `extensions:enable|disable`/`ExtensionStateWriter`.
  `config/serviceproviders.php` (`serviceproviders.enabled`, read by `AppProviderLoader`) holds
  always-loaded providers in declared order.
- **"Binding presence == tenancy active" is pervasive.** Proven 2026-07-12: binding the
  control-plane subset off-mode broke 11 tests. Two consumer patterns exist:
  1. **Nullable-resolver consumers** (`?CurrentTenantResolver $tenants = null`): analytics
     recorder/query, navigation `MenuRepository`, workflow `WorkflowStateRepository`, content
     `BlockMigrationRepository`/`MigrationRepository`/`FilterIndexJobDispatcher` — scope only when
     the resolver is bound.
  2. **Activation-proxy consumers** (`has(<control-plane binding>)` as "tenancy active"):
     `ScheduleRunner` (`?TenantContextRunner`), `EnsureFilterIndexesJob`
     (`has(TenantContextRunner)`), the workflow scheduled-publish tenant demand,
     plus boundary tests (`CleanInstallIdentityPlaneTest`, `TenancyEnablementApiTest`).
- **Enablement machine** (`EnablementStep`): `off → installing → [awaiting_install] →
  enabling_extension → awaiting_provider_boot → migrating_extension → awaiting_confirm →
  retrofitting → reloading → (fresh boot) finalizing → on`; disable: `on → disabling →
  disabled_widened`. `reloading` = retrofit done, `tenancy.enabled=1`, barrier still up;
  `finalizing` = a fresh process claimed the transition and verifies enforcement; only the final
  atomic step (lower barrier + set `on` in one system-channel transaction) reaches `on`.
  **Timing fact:** the enforcement provider is added at `ENABLING_EXTENSION` — well before retrofit
  and `ON` — so today provider presence does NOT mean enforcement is active.
- **2C temporary workarounds now in place (to retire):** the Thallo pack's `boot()`
  reflection-loads the engine identity migrations; the pack rebinds the concrete
  `ContractTenantProvisioner`; `TenantManagementServices` degrades tenant-management endpoints via
  lazy PSR-resolution (200-empty list / 503 create) because control-plane services are unbound
  off-mode. Memory: `project_tenancy_provider_split`.
- **`glueful/tenancy ^1.3.0` is a hard composer dependency of Thallo** — the package is always
  installed; only its provider registration is optional.

---

## §1 Provider split & binding partition

`glueful/tenancy` ships **two** providers:

**`TenancyControlPlaneProvider` (new) — always loaded.** Identity + lifecycle, everything that
works without request scoping:
- The identity **migrations** (`001_CreateTenantsTable` … `004_CreateReleasedHostsTable`), loaded at
  `MigrationPriority::DEFAULT - 50` (moved here from the current `boot()`).
- Bindings: `TenantProvisioner`, `TenantProvisioningRunner`, `TenantAdministration`,
  `TenantDomainAdministration`, `TenantContextRunner` (contract → bridge), `ReleasedHostRepository`.
- The `tenant:*` console commands and the `mergeConfig('tenancy', …)` defaults.

**`TenancyServiceProvider` (existing FQCN, stripped to enforcement) — enablement-gated.** Request
scoping + enforcement, loaded only when the enablement machine activates it:
- Bindings: `CurrentTenantResolver`, resolver chain/pipeline + profiles,
  `TenantMiddleware`/`TenantRequestMiddleware`, `TenantTableRegistry` contract,
  `TenantEnforcementProbe`, `TenantResolutionProbe`, `RowLevelStrategy`/`TenantAccess`.
- `boot()` runtime hooks: `TenantTableRegistry::loadFromConfig()`, the table hook,
  `TenantQueryGuard`, `TenantInsertStamper`. The `config('tenancy.enabled')` gate around them is
  **removed** — provider presence now means "enforcement machinery loaded"; the hooks register
  unconditionally in this provider.

Dynamic collection tables, cooldown, deletion lifecycle, and re-verification primitives all live on
the control-plane side (they are administration, not request scoping). The re-verification *sweep*
and all Thallo orchestration remain Thallo-owned as shipped.

---

## §2 Registration & activation mechanics

- **Control-plane:** registered in **`config/serviceproviders.php`** (`serviceproviders.enabled`),
  NOT `config/extensions.php` — the extension manifest supports one provider per package and the
  resolver rejects a second FQCN. Thallo commits:

  ```php
  return [
      'enabled' => [
          'Glueful\\Extensions\\Tenancy\\TenancyControlPlaneProvider',
          'App\\Providers\\ThalloServiceProvider',
      ],
  ];
  ```

  (Control-plane before the app provider, so its bindings and migrations exist when app providers
  register/boot.)
- **Enforcement:** `TenancyServiceProvider` remains the package's Composer-discovered provider in
  `config/extensions.php`, still added/removed exclusively by the enablement machine through
  `ExtensionActivation` (FQCN constant unchanged). `extensions:enable tenancy` continues to manage
  only this provider.
- **Terminology (pinned):**
  - `ExtensionActivation::isActivated()` — the enforcement provider is **allow-listed / configured
    for the next boot** (not loaded in this process).
  - Container binding/probe (`has(CurrentTenantResolver)`) — enforcement machinery **loaded in this
    boot** (for verification or operation).
  - `SystemFlags::enforcementActive()` — **normal tenant-aware work is permitted** (§3).
  - The retrofit **barrier** — mutations remain blocked during `RELOADING`/`FINALIZING`.
  - `TenantRuntimeReadiness` — request-resolution readiness, **including finalization probing**
    (must pass while the step is still `FINALIZING`; requiring `ON` there would deadlock the
    transition — which is why it is NOT the activation signal).

---

## §3 Canonical signal: `SystemFlags::enforcementActive()`

```php
public function enforcementActive(): bool
{
    // Lifecycle state is changed by other HTTP/CLI/worker processes. Never decide from the
    // process-local SystemFlags memoization cache.
    $this->clearCache();

    return $this->tenancyEnabled()
        && $this->get('tenancy.enable_step') === 'on'
        && $this->get('tenancy.retrofit_active') !== '1';
}
```

Raw `tenancyEnabled()` is `true` during `RELOADING`/`FINALIZING` (barrier up), so it is not
sufficient; `enforcementActive()` is the only predicate consumers may use to decide "run
tenant-aware work now." The persisted key contract is pinned as **`tenancy.enabled`**,
**`tenancy.enable_step`**, and **`tenancy.retrofit_active`**; implementation must use those exact
keys rather than introducing aliases during planning.
The refresh is part of the contract: long-lived workers must observe enable and disable transitions
performed by another process. Clearing once before the first read still produces one coherent
`all()` snapshot because the following two `get()` calls reuse that freshly-loaded snapshot.

**Consumer migration:**
- **Activation-proxy consumers → `enforcementActive()`:** `ScheduleRunner`,
  `EnsureFilterIndexesJob`, the workflow scheduled-publish tenant demand, and
  their tests.
- **`TenantManagementServices` is not activation-gated.** Its dependencies are now always-on
  control-plane services and resolve normally in every mode. Its lazy PSR failure handling remains
  only as a broken/incomplete-deployment backstop. Controllers and domain guards enforce any
  operation-specific lifecycle restriction explicitly; the resolver does not turn an available
  control plane into an empty/503 surface merely because enforcement is off.
- **Nullable-resolver consumers unchanged:** with `CurrentTenantResolver` bound only by the gated
  enforcement provider, it cannot appear during `AWAITING_CONFIRM`. It is first allow-listed in
  `ENABLING_ENFORCEMENT`; a crash-and-premature-reboot can load it while that step is still
  persisted, but the barrier is already raised and `enforcementActive()` remains false. Normal
  operation begins only after `ON`.
- Keep `has()`/nullable checks wherever **optional service availability** is genuinely the
  question (e.g. the pack's readiness factories).

---

## §4 Enable transition (reordered)

The old `ENABLING_EXTENSION`-time provider write and the `AWAITING_PROVIDER_BOOT` boundary for
control-plane availability are **obsolete** — control-plane bindings and identity migrations are
always present. The required fresh-boot boundary **moves to enforcement activation after
retrofit**:

```
off → migrating_extension (pending engine migrations; normally a no-op)
    → awaiting_confirm
    → retrofitting
    → enabling_enforcement  (retrofit complete; barrier stays raised)
      1. ExtensionActivation::activate()  — write enforcement FQCN + regenerate cache
      2. only after activation succeeds: atomically set tenancy.enabled=1 AND step=RELOADING
    → reloading  (fresh boot: enforcement bindings + hooks load BEHIND the still-raised barrier)
    → finalizing (fresh process claims transition; TenantRuntimeReadiness probes enforcement)
    → [lower barrier + set ON in one system-channel transaction]
    → on
```

**Ordering invariants (pinned):**
- `tenancy.enabled=1` is **never** set before enforcement activation succeeds. An activation/
  config-write failure records `FAILED` with `failed_from=ENABLING_ENFORCEMENT` (retrofit complete,
  flag off, provider not yet — or partially — allow-listed). `retry()` restores
  `ENABLING_ENFORCEMENT`; resume re-runs only `activate()` idempotently and does **not** rerun the
  retrofit.
- `activate()` and `deactivate()` are convergent across the provider-list file **and** generated
  provider cache. They always regenerate the desired cache even when the file already has the
  desired membership, so retry repairs a crash/failure between those two writes.
- `ENABLING_ENFORCEMENT` is a first-class persisted `EnablementStep`, not an alias for
  `RETROFITTING`. A crash after the provider-list write but before the flag/step transaction leaves
  this exact step persisted. A premature fresh boot may therefore load enforcement bindings while
  still at `ENABLING_ENFORCEMENT`; the raised barrier and false `enforcementActive()` predicate make
  that window safe.
- Because activation now happens post-retrofit, `CurrentTenantResolver` can never appear during
  `AWAITING_CONFIRM` — the window that would have made nullable-resolver consumers scope
  prematurely.
- The legacy persisted steps `INSTALLING`, `AWAITING_INSTALL`, `ENABLING_EXTENSION`, and
  `AWAITING_PROVIDER_BOOT` remain readable for recovery compatibility. `begin()` advances any of
  them to `MIGRATING_EXTENSION` without writing the enforcement provider. New flows do not emit
  those four steps.

---

## §5 Disable transition (with `deactivate()`)

Add **`ExtensionActivationContract::deactivate()`**, implemented via
`ExtensionStateWriter::disable()` + cache regeneration. Sequence:

```
1. CAS ON → DISABLING.
2. Raise the barrier and pass the disable gates.
3. Purge cache and write the disable sentinel.
4. deactivate() — remove the enforcement provider from config/extensions.php + regenerate cache.
5. Atomically set tenancy.enabled=0 AND step=DISABLED_WIDENED.
6. Return requiring a fresh boot.
```

**The current process retains static enforcement hooks** (table hook, guard, stamper are
process-global registrations), so the barrier **must remain raised after provider removal** for the
remainder of this process. The fresh boot has no enforcement provider; `DisableProbe` verifies
compat mode works, then lowers the barrier. Control-plane services (administration, trash/restore,
domains, cooldown) keep working while `disabled_widened`.

---

## §6 Thallo-side retirement & wiring

- **Pack `boot()`:** delete the engine-migration reflection-load (control-plane owns migrations).
- **Pack `services()`:** delete the concrete `ContractTenantProvisioner` rebinding;
  `SingleStoreTenant` injects the **`TenantProvisioner` contract** (always bound). `DefaultTenant`
  delegation unchanged.
- **`SystemFlags`:** add `enforcementActive()` (§3).
- **Consumers:** migrate the activation-proxy list (§3); leave nullable-resolver consumers alone.
- **`ExtensionActivation`:** add `deactivate()`; the machine calls `activate()` at the new
  post-retrofit point and `deactivate()` in the disable sequence.
- **Boundary tests flip polarity:** `CleanInstallIdentityPlaneTest` asserts control-plane bindings
  `assertTrue(has(...))` off-mode while `CurrentTenantResolver` stays `assertFalse`;
  `TenancyEnablementApiTest`/`TenantManagementApiTest` re-anchor on `enforcementActive()`.
  `TenantManagementServices` resolves the always-on control plane in off mode; its 200-empty/503
  fallback is exercised only when the control-plane installation is missing or unresolvable.
- **`config/serviceproviders.php`:** add the control-plane FQCN (committed config change).

---

## §7 Release chain & upgrade notes

- **`glueful/tenancy` 1.4.0** — the split, both providers, no contract changes → **Thallo** adopts.
  Vendor-first; port + release after Thallo is green; pin after publish.
- **Upgrade note (1.4.0):** existing installs **cannot** use `extensions:enable` to add the
  control-plane provider (one provider per package in the extension manifest). They must add
  `Glueful\Extensions\Tenancy\TenancyControlPlaneProvider` to `config/serviceproviders.php`
  (`serviceproviders.enabled`), or their deployment/update procedure must merge it. For this local
  dev setup it is a committed configuration change. Enabled installs upgrading mid-flight keep the
  enforcement FQCN in `config/extensions.php` — unchanged and still valid.
- The enablement machine changes ship in the same Thallo change-set (pack-owned machine).

---

## §8 Failure modes

- Clean install, tenancy never enabled → control-plane bound, `tenants` exists, admin lifecycle +
  collections provisioning work; `CurrentTenantResolver` absent; `enforcementActive()===false`;
  nullable-resolver consumers do not scope.
- Activation write fails post-retrofit → `FAILED` with
  `failed_from=ENABLING_ENFORCEMENT`; `tenancy.enabled` remains `0`; retry restores
  `ENABLING_ENFORCEMENT` and re-runs activation without rerunning retrofit.
- Crash between activation and the atomic flag+step write → provider allow-listed but
  `enabled=0`/`ENABLING_ENFORCEMENT`; resume performs the atomic write; a premature fresh boot
  loads enforcement bindings and hooks, but they sit behind the raised barrier and
  `enforcementActive()===false`.
- Crash during `RELOADING`/`FINALIZING` → existing recovery semantics unchanged; barrier up;
  consumers see `enforcementActive()===false`.
- Disable: provider removed while the current process still has static hooks → barrier stays up for
  the remainder of the process; fresh boot + `DisableProbe` lowers it.
- A consumer wrongly checking raw `tenancyEnabled()` during `RELOADING` would see `true` — the
  migration to `enforcementActive()` plus tests on the mid-transition window guard this.

---

## §9 Testing

- **Off-mode:** control-plane bindings present (`TenantProvisioner`/`TenantAdministration`/
  `TenantContextRunner`/`TenantDomainAdministration` `has()===true`), `tenants` table exists,
  `CurrentTenantResolver`/`TenantEnforcementProbe` absent, `enforcementActive()===false`; the 2C
  identity-plane tests re-asserted with the new polarity; setup/seeding/collections provisioning
  work with no PSR-degrade path exercised.
- **Enable ordering:** activation precedes `enabled=1`; failure between retrofit and `RELOADING` is
  recorded from and resumed at `ENABLING_ENFORCEMENT` without rerunning retrofit;
  nullable-resolver consumers never scope during `AWAITING_CONFIRM`; a premature boot at
  `ENABLING_ENFORCEMENT` and the normal `RELOADING`/`FINALIZING` boots all have the barrier up and
  `enforcementActive()===false`; finalization's readiness probe passes while step is `FINALIZING`;
  the atomic barrier-down+`ON` flips `enforcementActive()` to `true`.
- **Disable ordering:** `deactivate()` removes the FQCN + regenerates cache; barrier remains raised
  in-process after removal; fresh boot passes `DisableProbe` then lowers the barrier;
  control-plane administration keeps working in `disabled_widened`.
- **Consumers:** each migrated activation-proxy consumer honors `enforcementActive()` (off, mid-
  transition, on); nullable-resolver consumers scope exactly when the resolver is bound.
- **Regression:** full Thallo suites (tenancy off + on), engine suite, slice-1/2/3 + 2C suites;
  the 11 tests that failed under the 2C binding experiment are the explicit regression set.

---

## §10 Out of scope

Changing any `extension-contracts` interface (no contracts release); the framework's extension
manifest/one-provider rule; multi-package provider discovery; moving Thallo's enablement machine
into the engine; per-request enforcement toggling (activation remains boot-scoped); the deferred
Bucket 2 items (2A/2B).
