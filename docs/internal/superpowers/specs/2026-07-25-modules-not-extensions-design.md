# Modules, Not Extensions — Thallo's Internal Packs Leave the Extension Universe

> Status: DESIGN (held, uncommitted). Decision record + implementation spec.
> Sequencing: lands AFTER the current green branch is pushed. Own project, own commits.

## 1. Context and decision record

Thallo has eleven `packages/thallo-*` packages: ten provider-bearing product organs — render,
collections, navigation, seo, workflow, analytics, importers, search, tenancy integration,
commerce integration — plus the provider-less `thallo-contracts` kernel. The ten provider
packages were built as composer path-repository packages of `"type": "glueful-extension"`,
which makes them **candidates** to the framework's extension machinery: they appear in the
extension catalog and browse UI, `extensions:list` reports them, `extensions:diagnose` includes
them in its candidate table, and generic `extensions:enable|disable` accepts them. Nine are
currently present in `config/extensions.php`; Search is installed but deliberately not enabled.
That identity is false — they are not merchant-installable, not independently versioned, and
their user-facing on/off is the capability switchboard, not extension activation.

The mixed identity produced three real incidents in one week: the extension installer's
rewrite of `extensions.php` destroyed load-bearing comments; CI ran red because an enablement
line lived in a file our commit policy excluded; and a cleanup stripped the tenancy flow's
state line, breaking tenant resolution — all downstream of one file carrying three ownerships
(human/installer, core packs, lifecycle state machine).

Three proposals were evaluated across two external reviews:

1. **Activation classes** (framework feature: app-integrated / installable / lifecycle-managed,
   consumed by catalog/list/browse/diagnose). Rejected as primary mechanism: framework-heavy,
   and mostly teaches tooling to un-see what the packaging made it see.
2. **Root-autoload fold** (delete nested manifests, PSR-4 map from root composer.json).
   Rejected: nested manifests carry the real package dependency graph — Twig for render,
   CommonMark for importers, and package availability constraints such as `thallo-render`
   requiring `thallo-tenancy` and `thallo-commerce` requiring `glueful/commerce` — which
   root-folding would flatten. Package dependency does not itself imply provider boot order.
3. **Type flip — composer modules, not extensions** (this spec): keep the nested packages and
   path repositories; change `"type"` from `glueful-extension` to ordinary `library`; register
   providers in `config/serviceproviders.php`. **Adopted.**

The governing principle: **composer packaging and extension activation are separate axes.**
The packs keep their packaging (namespaces, manifests, dependency declarations, boundary
checks, extraction option) and shed only the false activation identity. `thallo-contracts` has
used exactly this model from day one — `type: library`, path-required, invisible to every
extension surface — and has never caused a moment's confusion.

Packaging/publication remains an OPEN decision (deliberately not ratified as "never"): a module
may someday be published while remaining app-integrated in Thallo. Nothing here forecloses it.

## 2. Constraint: framework changes must be generally beneficial

**Pinned rule for this project (user-ratified 2026-07-25):** a framework change may ride along
only when it fixes or protects a published contract for ALL hosts. Anything whose only consumer
is Thallo's presentation gets solved product-side.

Applied to this project:

- The **candidate-removal mechanism** needs no framework change:
  `PackageManifest` already filters on `type === 'glueful-extension'` exactly
  (`src/Extensions/PackageManifest.php:24`), and lifecycle conveniences
  (`loadRoutesFrom`, `loadMigrationsFrom`, `discoverCommands`, `mergeConfig`) are
  `ServiceProvider` methods that work for app providers.
- The **parity and safety contract does require a framework release first**. Source verification
  found that the current ordering is not one shared path: `ContainerFactory` consumes the raw
  app-first merged list; `ExtensionManager::sortProviders()` runs only during uncached
  development discovery; `writeCacheNow()` persists the unsorted list; and cached discovery
  returns without sorting. It also found that permission source attribution considers only
  extension candidates, so a type-flipped module would become `managed_by: app`.
- The prerequisite framework release therefore supplies three generally useful seams, detailed
  in §8: deterministic declarative provider ordering shared by container compilation/live
  discovery/cache generation; type-agnostic provider-to-composer-package attribution; and a
  protected-provider activation guard. All three solve framework-level behavior for any host
  with app-integrated package providers or lifecycle-managed extensions. Thallo does not flip
  types until that release is published and pinned.
- Explicitly out: any catalog/list presentation flag whose only consumer would be Thallo.

## 3. Goals / non-goals

**Goals**
- The extension candidate universe (catalog, browse, the candidate tables in
  `extensions:list|diagnose`, and `config/extensions.php`) contains only genuinely installable
  extensions plus the one flow-managed line. `extensions:diagnose` still lists modules in its
  resolved-provider section, but not in its extension-candidate table; the existing output does
  not label provenance and this project does not claim that presentation change.
- Modules keep: directory layout, `Thallo\*` namespaces, nested manifests with real dependency
  declarations, provider classes and full lifecycle, migration ownership, contracts seam,
  InertnessTest / `check-pack-boundaries.php`.
- For the nine currently enabled modules, boot ordering, container definitions, permissions,
  migrations, routes, and commands remain byte-equivalent (or provably equivalent). Search is
  the explicit exception: its provider becomes always loaded like the other modules, while its
  disabled capability must remain behaviorally inert (no routes/listeners/user-facing surface).

**Non-goals**
- No renames (`glueful/thallo-*` vendor prefix stays — cosmetic churn, zero behavior).
- No publication decisions. No capability-model changes (the switchboard stays the feature
  on/off); adding the explicit `thallo.search => false` default is a parity migration because
  Search is currently off by provider absence. No changes to real extensions (commerce, payvia,
  meilisearch, media, …).
- The `EnsureFilterIndexesJob`-style app plumbing, `App\Providers\ThalloServiceProvider`, and
  the tenancy control plane are untouched.

## 4. Current state (verified)

| Package | type today | notable requires |
|---|---|---|
| thallo-contracts | **library** (the precedent) | — |
| thallo-render | glueful-extension | thallo-contracts, thallo-tenancy, twig/twig |
| thallo-importers | glueful-extension | thallo-contracts, glueful/import-export, glueful/users |
| thallo-commerce | glueful-extension | thallo-contracts, thallo-tenancy, glueful/extension-contracts, glueful/commerce |
| (analytics, collections, navigation, search, seo, tenancy, workflow) | glueful-extension | thallo-contracts (+ various) |

Activation today: nine Thallo provider lines in `config/extensions.php` `enabled`; Search is
installed as a candidate but not enabled. App providers today
(`config/serviceproviders.php` `enabled`): `TenancyControlPlaneProvider`,
`App\Providers\ThalloServiceProvider`. The ten module manifests contain
`extra.glueful.provider`; none contains `extra.glueful.requires`. Current extension selection
therefore does not derive boot order from Composer `require` or extension dependency metadata;
the committed extension-list order is the initial order, with the uncached runtime sorter as a
later, development-only pass.

**Ordering consequence to design for:** after the type flip, modules become app providers and
move ahead of extension candidates in the raw merge. Any cross-list ordering dependency must be
declared through the framework's new pre-container ordering seam (§8.1), which produces the one
ordered class list used by definitions, register, boot, diagnostics, and caches.

## 5. Design

### 5.1 Manifest changes (ten provider modules)

- `"type": "glueful-extension"` → `"type": "library"`.
- Retain `extra.glueful.provider`. Package candidacy is controlled by `type`, while the provider
  metadata lets the framework's type-agnostic package locator preserve permission
  `managed_by: glueful/thallo-*` attribution (§8.2). No module currently has
  `extra.glueful.requires`, so there is nothing by that name to remove.
- Keep name, autoload, and `require` untouched. `composer update` refreshes the lock;
  installed.json entries become plain libraries. `thallo-contracts` is unchanged.

### 5.2 Provider registration and the ordering contract

`config/serviceproviders.php` `enabled` gains the ten module providers. Search joins the
always-loaded list for the first time, so `config/thallo.php` gains an explicit
`'thallo.search' => false` capability override. Without it, the registry's absent-key default
would enable Search and silently change routes/commands after the move. Because Search's
user-facing surface was previously gated by provider absence, its provider additionally gains
the capability gate in `boot()` (the thallo-commerce `isEnabled('thallo.commerce')` pattern)
so that always-loaded + capability-off registers no routes, commands, or listeners — this is
the one module requiring a provider code change, not just relocation. **List order is
documentation and the stable tie-break, not the dependency mechanism.** Every verified
ordering dependency is declared through the framework's pre-container declarative ordering
contract (§8.1):

- `thallo-commerce` → `after: [Glueful\Extensions\Commerce\CommerceServiceProvider,
  Thallo\Tenancy\TenancyServiceProvider]`
- any further edge is added only when a source-level boot/register/definition interaction
  requires it;
- every module → nothing for `thallo-contracts` (contracts has no provider)

Composer `require` edges are **not** converted mechanically into provider-order edges. They
mean code/package availability, not lifecycle order. The implementation inventory traces
`services()`, `register()`, and `boot()` for each provider and records the evidence for every
declared edge.

The declarative order applies before service definitions are compiled and remains unchanged
through runtime register/boot and the production cache. An edge naming an absent provider is
ignored; a cycle is a resolver error that prevents cache generation/production boot rather
than a logged priority fallback.

### 5.3 `config/extensions.php` final state

`enabled` contains only: the bundled glueful extensions (Aegis, Audit, Commerce,
EmailNotification, I18n, ImportExport, Media, Meilisearch, Payvia, Users) and — when the
tenancy flow has written it — `Glueful\Extensions\Tenancy\TenancyServiceProvider` (runtime
state, flow-owned, still never committed). The file's header comment is rewritten to say
exactly this. No module-specific filtering is added to the installer, browse UI, or
`extensions:*` commands: with the modules de-typed they simply stop being candidates. Those
generic mutation surfaces do gain the framework-level protected-provider guard in §8.3.

### 5.4 Surfaces afterwards

Catalog/list/browse and Diagnose's **candidate table** show the bundled glueful extensions
(honestly, as enabled/installed) and any merchant-installed additions. Diagnose's resolved
provider section continues to show app providers, including the modules; this is operational
truth, not extension-catalog clutter. Whether bundled extensions should be visually marked
"required by Thallo" is a product-presentation decision DEFERRED to the distribution project;
the protected-provider guard (§8.3) supplies enforcement without a Thallo-only catalog flag.

### 5.5 Capabilities — unchanged

`thallo.commerce`, `thallo.render`, etc. remain the user-facing on/off for always-loaded
modules. All existing gating (settings tabs, nav, per-request checks) is untouched.

## 6. Impact inventory (each is a verification item)

1. **Container compilation** — assert the framework orderer gives `ContainerFactory`, live
   discovery, and cache generation the same class list. Definition ids/tags for the nine
   already-active modules remain identical; Search adds only its expected capability-inert
   definitions.
2. **Provider order** — assert definitions/register/boot use the same order in development and
   a fresh production-style cache; commerce extension precedes thallo-commerce and tenancy
   control plane remains ahead of its dependents.
3. **Routes** — route-table parity: identical route cache contents (names, paths, middleware)
   pre/post.
4. **Migrations** — `loadMigrationsFrom` registrations unchanged (source names + priorities);
   `scripts/run-test-migrations.php` hand-registers pack paths and is unaffected, but assert it.
5. **Permission catalog** — `aggregatePermissionCatalog()` runs over the merged providers;
   assert identical catalog and `managed_by: glueful/thallo-*` source attribution through the
   type-agnostic package locator.
6. **Commands** — module `discoverCommands()` still surfaces `thallo:*` commands (deferred
   flush is provider-type-agnostic; regenerate the commands manifest; assert list parity).
7. **Extension surfaces** — catalog/list/browse and Diagnose's candidate table no longer
   mention modules; Diagnose's resolved-provider section still does. `extensions:enable
   Thallo\...` fails with "unknown extension" because a library module is not a candidate.
8. **Tenancy enablement round trip** — generic CLI and Thallo admin toggles refuse the
   protected tenancy provider with its owning-flow message, while the owning enable → disable
   flow still rewrites only its own line in the slimmed file and regenerates a complete cache.
9. **Inactive-host inertness** — with the Commerce extension provider disabled, the
   thallo-commerce module remains behaviorally inert and its absent-provider ordering edge is
   ignored. The existing true-class-absence unit seam remains defensive coverage, but a real
   Commerce-package-absent installation is not promised while root Thallo and
   `glueful/thallo-commerce` require `glueful/commerce`; changing that distribution dependency
   is out of scope.
10. **Caches** — regenerate `bootstrap/cache/extensions.php`, commands manifest, route caches;
    dev DB and CI both boot clean.
11. **Full suites** — thallo PHP suite, admin vitest/vue-tsc/oxlint, CI matrix (plain checkout).
12. **Search parity** — committed default explicitly disables `thallo.search`; after Search
    becomes an app provider, its capability is discoverable but no routes/commands/listeners
    activate until the switchboard enables it. Existing Search enablement tests are rewritten
    from provider-list activation to capability activation.

## 7. Rollout (mechanical order, one reviewable commit sequence)

1. Framework prerequisite (§8): implement, test, publish, then pin the released framework.
2. Flip ten manifest types while retaining provider ownership metadata; `composer update`
   refreshes the lock. `thallo-contracts` remains untouched.
3. Declare only source-verified provider-order edges.
4. Add all ten providers to `serviceproviders.php`; remove the nine currently enabled module
   lines from `extensions.php`; add the explicit disabled Search capability default; rewrite
   both headers. Change tenancy's `ExtensionActivation` cache regeneration to call
   `writeCacheNow()` without its current extension-only explicit list, so the cache is rebuilt
   from the complete merged app+extension provider set.
5. Declare the tenancy extension provider in the host `extensions.protected` map and make
   Thallo's app-owned `ExtensionAdminController` consult `ProtectedProviders` before its direct
   `ExtensionStateWriter` call. The framework controller guard does not automatically cover a
   host controller that intentionally uses the policy-free writer.
6. Regenerate caches; run the verification matrix; fix fallout.
7. Update `docs/DISTRIBUTION.md`: replace the three-tier table's pack rows with the
   module/extension/lifecycle classification, record the §2 constraint and the open
   packaging question. Update `.superpowers/sdd/progress.md`.

## 8. Framework prerequisite (general release, blocking)

### 8.1 One declarative class order for every phase

Add a pre-container declarative provider-order contract (exact framework naming chosen in its
plan) whose static metadata can be read from class strings without constructing providers.
A pure orderer receives the merged app+extension class list and returns one stable topological
order:

- explicit `after` class edges; absent targets ignored;
- numeric priority and original merged position as deterministic tie-breaks;
- cycles become resolver errors, never logged fallbacks.

`ProviderClassResolver` applies it before returning. `ContainerFactory`, uncached discovery,
`extensions:diagnose`, `extensions:cache`, `writeCacheNow()`, and cached production boot all
therefore start from the same ordered class list. `writeCacheNow($explicitClasses)` also applies
the pure orderer to the supplied list; ordering is a framework guarantee even when a caller
supplies an explicit list, while completeness remains that caller's responsibility.
`ExtensionManager` must not subsequently reorder providers participating in the declarative
contract, including when legacy `OrderedProvider` edges contain a cycle and take their fallback
path. The existing instance-level `OrderedProvider` remains backward compatible for third-party
boot-only ordering, but cannot
be used for cross-phase ordering; Thallo modules use only the declarative contract. Tests pin
that a declaratively ordered pair retains the same relative order in definitions, register,
boot, diagnostics, an explicitly supplied cache list, and a freshly generated production cache.

### 8.2 Type-agnostic provider package attribution

Add a Composer metadata locator that maps `extra.glueful.provider` to package name regardless
of package `type`. `PackageManifest::getCandidates()` remains unchanged and continues filtering
extension candidates by type; the new ownership lookup is independent of candidacy.
`ExtensionManager::packageNameFor()` delegates to the locator, preserving
`managed_by: glueful/thallo-*` for library-backed app providers and falling back to `app` only
for providers with no package metadata. Duplicate provider ownership is a fatal configuration
error.

### 8.3 Protected-provider activation guard

Generic framework `extensions:enable|disable` and the framework admin toggle consult a host
config map, e.g. `extensions.protected` — `provider FQCN → ['reason' => string,
'managed_by' => string]` — and refuse listed providers with the recorded reason ("Managed by
the tenancy enablement flow — use /admin/settings/workspaces or php glueful
tenancy:disable"). This enforcement lives above the policy-free `ExtensionStateWriter`, so the
owning lifecycle flow can continue using that low-level writer. Thallo's host config declares
the tenancy enforcement provider as protected; the inactive provider cannot load and merge its
own protection entry. Thallo's separate app-owned extension toggle must consult the same guard
before calling the writer. The current installer is install-only and performs no activation, so
there is no installer auto-enable surface to guard. Templates may similarly protect
bundled-required extensions.

This release is blocking because the spec claims deterministic parity and closes the generic
toggle path that caused the tenancy incident. All three seams are framework-general and satisfy
§2.

## 9. Open questions (to resolve during implementation, none blocking start)

- Definition/tag collision policy if a module and an extension ever compile the same service id
  (none found in the current `services()` key inventory; the verification diff remains the
  backstop).
- Exact declarative order edges, derived from source-level lifecycle interactions and reviewed
  against the current effective behavior — never mechanically from Composer `require`.
