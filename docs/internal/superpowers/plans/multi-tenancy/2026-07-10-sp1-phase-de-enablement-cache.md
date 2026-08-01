# Phase D/E — Safe Tenancy Enablement to `on` + Cache Isolation — Implementation Plan (rev 15)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `TenancyEnablement` state machine that safely turns multi-tenancy from `off` to `on` — install → activate → migrate → confirm → retrofit → **RELOADING → (fresh boot) finalize** → `on` — plus a strictly-bounded single-tenant **bootstrap request-resolution mode** and the `tenantCacheSegment()` cache isolation that together make reaching `on` safe against cross-tenant leaks and tenant-less requests.

**Architecture:** A resumable, CAS-locked step machine persisted in the unscoped `thallo_system_flags` channel drives the already-built retrofit engine (Phase C) and the on-demand `glueful/tenancy` extension lifecycle. Reaching `on` is a **two-boot** operation: `confirm()` runs the retrofit under the write-barrier, purges caches, writes `tenancy.enabled=1`, and lands in **RELOADING with the barrier still up**; a separate `finalize()`, on a *fresh process* that actually booted the extension, verifies bindings (including the required generic blob lifecycle/access seams) + table registration + request readiness + a real tenant-scoped probe + a non-empty cache segment, then **lowers the barrier last** and CAS-transitions to `on`. Because SP2's full multi-tenant request resolution does not exist yet, D/E ships a **bootstrap-default resolution mode** (one unambiguous tenant) so every tenant-data request is safely scoped; a neutral `TenantRuntimeReadiness` contract reports `bootstrap_default | full_resolution` and SP2 swaps the mode in later without touching storage or the state machine.

**Tech Stack:** PHP 8.3+, Glueful framework (context-first APIs), PostgreSQL-only, `thallo_system_flags` system channel, on-demand `glueful/tenancy` framework extension, `glueful/thallo-tenancy` pack.

**Execution record (2026-07-10):** Tasks 1–20 are implemented and verified with commits held. The framework blob boundary was corrected during execution to two generic seams (`BlobCreatedHook` and `BlobAccessPolicy`); tenant ownership/authorization remains entirely in Thallo's `TenantBlobPolicy`. Task 21 is intentionally not executed: it is a release-only gate that requires published framework/contracts/tenancy versions before the local path repository can be removed safely.

## Global Constraints

- **Work on `dev` directly.** No feature branches.
- **Hold all commits until explicit go-ahead.** Do not commit the plan. Each task's "Commit" step is written but MUST NOT run until the user says so.
- **No AI/Anthropic attribution** anywhere (commits, PR bodies, code comments). No `Co-Authored-By`.
- **Never stage/commit `CLAUDE.md`.** Dev-DB/test-DB migrations + seeds are local-only.
- `declare(strict_types=1)` + `final class` + constructor DI + `use`-imports (no inline FQCNs).
- **Contract-only cross-package rule:** Thallo/pack code MUST NOT import concrete `Glueful\Extensions\Tenancy\*` classes. Use the neutral contracts in `Glueful\Extensions\Contracts\Tenancy\*` (`CurrentTenantResolver`, `TenantContextRunner`, `TenantProvisioner`, and the new `TenantRuntimeReadiness`) and the pack-local `Thallo\Contracts\Tenancy\WriteBarrier`.
- `composer phpcs` clean before a task is done (warnings count as failures; 120-char lines).
- **Postgres-only.** Advisory locks, PG DDL. No MySQL/SQLite branches.
- **NO disable path** in this phase (deferred until seed/sync + `starter_provenance`). Every action only advances toward `on`.
- **Distribution model = on-demand (fixed).** `glueful/tenancy` is a **path repository + `require-dev`** (dev-installable, prod-installed-on-demand) — **no production hard-require**. It ships **disabled** (absent from `config/extensions.php` `enabled`). Install and activation are **cross-request**: after a successful install the flow persists the next step and RETURNS; activation happens on a subsequent request whose autoloader can see the newly-installed package.
- **Reaching `on` is two-boot.** `confirm()` never reaches `on`; it lands in `RELOADING` with the barrier up. `finalize()`, on a fresh boot, is the only path to `on`. `status()` MUST NEVER report `on` before `finalize()` succeeds.
- **Bootstrap resolution invariants (spec for the SP1 request mode):**
  1. Applies **only to tenant-data routes**; system/enablement/setup routes are exempt.
  2. Runs only when `tenancy.enabled=1` AND no tenant is already resolved.
  3. Requires the default-tenant pointer (`tenancy.default_tenant_uuid`).
  4. Requires **exactly one active tenant**, matching that pointer.
  5. Requires `TenantContextRunner`; absence **fails closed**.
  6. Wraps the **entire downstream request, including cache lookup**, in `runAsTenant(defaultUuid, …)`.
  7. Missing pointer, zero tenants, or **multiple tenants → HTTP 503** and readiness fails.
  8. **Tenant creation beyond one is blocked** while bootstrap mode is active.
  9. `finalize()` requires readiness **plus** a real tenant-scoped query **and** a non-empty cache segment.
  10. Once SP2's full resolution is active, bootstrap fallback is **disabled**; a failed domain resolution stays **404**, never falling through to the default tenant.
  11. `TenantRuntimeReadiness` exposes a **mode** (`bootstrap_default | full_resolution | none`) so the transition and diagnostics report exactly what protects requests. SP2 replaces the mode before allowing tenant two — without changing storage or the state machine.
- **Framework changes ARE required (held/uncommitted, pin at release):** (a) the mutation-quiescence lock must span prepare+execute, but `QueryInterceptorInterface` is **before-only** (verified: `QueryExecutor::runInterceptors()` returns before `$stmt->execute()`) — Task 11a adds an **execution-wrapper seam** (`QueryExecutor::addExecutionWrapper()`); (b) `Connection` exposes only `getPDO()` + a **private** `createPDOConnection()` (verified — no public second-session accessor), so Task 11b adds a `Connection::newPdo(): PDO` seam for the dedicated participant + maintenance advisory-lock sessions; (c) the new `ExecutionWrapperTest` must reset `clearExecutionWrappers()` in its own `setUp()`, mirroring the existing per-test-class registry resets (`QueryInterceptorTest`/`InsertHookTest`/`ConnectionTableHookTest`) — there is **no** central framework test-bootstrap reset (Task 11a Step 5).
- **Cross-repo new artifacts** (`glueful/extension-contracts`): `TenantRuntimeReadiness`, `FullTenantResolutionReadiness` (SP2 capability), `TenantEnforcementProbe` (registry-membership read — the existing neutral `TenantTableRegistry` is write-only).
- **DI-binding rule (finding #4):** exactly **one** provider binds each shared contract ID. SP1 binds `TenantRuntimeReadiness` to an SP1-owned **composite** that *soft-resolves* the optional `FullTenantResolutionReadiness` capability. SP2 binds its **own distinct** `FullTenantResolutionReadiness` — it NEVER overrides SP1's `TenantRuntimeReadiness` binding. No competing defaults under a shared ID.
- **confirm() ordering (finding #7):** purge caches **first** (barrier up), **then** write `tenancy.enabled=1` and persist `RELOADING`. A purge failure then leaves the runtime unambiguously disabled and retryable.
- **finalize() enforcement (finding #5 + rev-14 P1):** before lowering the barrier, verify via `TenantEnforcementProbe` that **every** `ThalloTenantTables::tableNames()` entry is registered as tenant-owned in this process **and** that `BlobCreatedHook` plus `BlobAccessPolicy` resolve to the same external implementation. A scoped probe query is insufficient (the prod guard only logs, and an unregistered table's query still succeeds). Once enabled, a boot missing either seam fails loudly before serving requests.
- **Release gate (finding #8):** the on-demand `require-dev` + sibling path repository is a **development** model. The release checklist (final task) MUST remove the path repository, drop the `require-dev` entry, and pin a **published** `glueful/tenancy` version, so production Composer never depends on local repo layout.
- **Raw-PDO mutation boundary (rev 4, finding #1):** the execution wrapper only covers builder statements. Every **raw-PDO** owned-table writer MUST run its mutation inside `WriteBarrier::runWritable(callable)` — which holds the shared advisory lock **around the actual PDO op** — not merely call `assertWritable()` before it. The B2 lint (`RawPdoScopingLintTest`) enforces `runWritable(` on all classified writers.
- **Lock sessions (rev 4, finding #2):** the shared and exclusive advisory locks use **dedicated `Connection::newPdo()` sessions** (a lazy *participant* PDO for shared, a distinct *maintenance* PDO for exclusive) — **never the application statement PDO** — so a mutation transaction that aborts cannot poison or leak the lock session.
- **Middleware ordering (rev 4, finding #3):** `tenant_bootstrap` MUST be the **outermost** middleware (stack index 0) on every tenant-data route, so tenant resolution wraps `RenderPageCache` and all downstream reads. The router builds inside-out (first = outermost) and `Route::middleware()` appends — put it **first** in group/route arrays; never append it after a cache/rate middleware. `RouteCoverageTest` enumerates **every** registered route and asserts index-0 placement.
- **Crash-safe finalize (rev 4, finding #4):** the central invariant is **never barrier-down while step ≠ on**. finalize() CLAIMS `RELOADING → FINALIZING` (checked CAS, barrier up), verifies, then lowers the barrier AND sets `on` in **one system-channel transaction** with a checked inner CAS. A crash/rollback leaves `FINALIZING` with the barrier up — recoverable. CAS results are **never ignored**.
- **Static-registry reset (rev 4, finding #5):** `QueryExecutor::clearExecutionWrappers()` MUST be cleared in every two-boot cleanup (Thallo harness `resetTenancyGlobals()`; and, framework-side, in the new `ExecutionWrapperTest::setUp()` — the existing interceptor/insert-hook registry tests each reset in their own `setUp()`, there is no shared bootstrap reset), or a second boot retains boot-one's wrapper bound to a dropped connection.
- **Lazy retrofit (rev 5, finding P1 #1):** `TenancyEnablement` MUST NOT inject `SchemaRetrofit` (it transitively hard-requires the extension-bound `TenantProvisioner` via `DefaultTenant`, breaking `status()`/`begin()` while off). Resolve it lazily inside `confirm()`, only after `container->has(TenantProvisioner::class)`.
- **Two fresh-boot boundaries (rev 5, finding P1 #2):** reaching `on` crosses **two** process boundaries — `AWAITING_PROVIDER_BOOT` (the just-activated provider is not bound in the current container) and `RELOADING` (a fresh boot arms table registration). `EnablementStep::needsFreshBoot()` marks both; the CLI/HTTP surface MUST stop and require a fresh invocation at each, never loop in one process.
- **Collections fail-closed (rev 5, finding P1 #4):** the enable preflight only blocks pre-existing definitions; it does NOT prevent post-`on` creation. Collections routes MUST carry `collections_disabled_when_tenant` (503 while `tenancyEnabled()`), since collections tenancy is unsupported in SP1.
- **Route classification is fail-closed (rev 5, finding P1 #3):** `RouteCoverageTest` treats every route NOT in the explicit system/setup/collections allow-lists as tenant-data and requires `tenant_bootstrap` at stack index 0. A new route is tenant-data until consciously allow-listed.
- **No ignored CAS (rev 5, finding P2 #6):** every `compareAndSet` result is checked — including the `FINALIZING → RELOADING` probe-failure revert. The CLI runs `finalize()` for BOTH `RELOADING` and crash-left `FINALIZING`.
- **Media ownership (rev 14, finding P1 #1–#5 + refinements):** the framework-global `blobs` library is NOT tenant-isolated and its upload/serve routes are framework-owned (no `tenant_bootstrap`). Task 10b adds **held generic framework `BlobCreatedHook` and `BlobAccessPolicy` seams** — post-create attribution with no retrying transaction, PostgreSQL `ON CONFLICT … RETURNING` atomicity, deterministic cleanup/quarantine, ownership-first authorization, and post-attribution best-effort thumbnails. Glueful's array-shaped `Utils::getUser()` result is normalized once and reused for attribution plus every `BlobAccessContext`. The raw ownership INSERT uses `WriteBarrier::assertWritable()` in Task 10b and is migrated to `runWritable()` in Task 11c; `MediaOwnershipBackfill` remains `RETROFIT_ENGINE`-exempt. The framework provides **no default contract binding**: Thallo is the sole binder of both generic seams, `FinalizationProbe` refuses `on` without both resolving to the same implementation, and an enabled boot missing either fails loudly before requests can reach the inline null fallbacks. Tenant resolution remains resolved request tenant → default only in `bootstrap_default` → fail closed. Ownership is the Thallo-owned `media_assets` table with global `blob_uuid` uniqueness preserved; retrofit backfill runs before enablement, and `MediaAdminController` queries are rooted at `media_assets`.
- **Every Thallo-owned route carries exactly one marker (rev 6, findings P1 #2 + P2 #5):** `tenant_bootstrap` (tenant-data, outermost) | `tenant_system` (Thallo global/system, no-op marker) | `collections_disabled_when_tenant` (deferred). `RouteCoverageTest` filters to `App\`/`Thallo\` handlers and requires exactly one marker — framework/extension routes (~288 total) are out of scope. `scheduled-tasks` + `icons` are `tenant_system`, not tenant-data.
- **CLI inspects status before acting (rev 6, finding P1 #3):** the enable command reads the current step FIRST and picks finalize/confirm/begin accordingly, stopping only when an action *newly* produces a boot boundary — so a fresh invocation at `RELOADING` reaches `finalize()` and can advance to `ON`.

---

## Reference: verified ground this plan stands on

State (all in `thallo_system_flags` via `Thallo\Tenancy\System\SystemFlags`, ctor `(ApplicationContext)`):
- `tenancy.enabled` = `'1'` — **no writer today; `confirm()` is the first writer.**
- `tenancy.schema_state` = `'none'|'widened'` — written by `SchemaRetrofit`.
- `tenancy.retrofit_active` = `'1'` — barrier, `RetrofitMaintenanceGuard::begin()/end()`.
- `tenancy.default_tenant_uuid` — set by the retrofit's `DefaultTenant`.

`SystemFlags`: `get/put/forget`, `tenancyEnabled(): bool`, `schemaState(): 'none'|'widened'`, `defaultTenantUuid(): ?string`, `clearCache(): void`. Shared+autowire; aliased to `Thallo\Contracts\Settings\SystemChannel`.

`RetrofitMaintenanceGuard implements Thallo\Contracts\Tenancy\WriteBarrier`: `refresh()`, `begin()`, `end()`, `active(): bool`, `assertWritable(): void`, `runInternal(callable)`. Persist key `tenancy.retrofit_active`.

`SchemaRetrofit::run(string $slug, string $name, string $ownerUserUuid): RetrofitReport` — **raises the barrier itself** (`guard->begin()`), idempotent/resumable, sets `schema_state='widened'`, **leaves the barrier UP**. `RetrofitReport`: `defaultTenantUuid(): string`, `widenedTables(): list<string>`, `widenedTableCount(): int`, `movedSettingsKeys(): list<string>`. Creates exactly one default tenant + owner membership.

`ThalloTenantTables::tableNames(): list<string>`.

Extension lifecycle (framework):
- `Glueful\Extensions\Install\ExtensionInstaller::install(string $package): array{status,package,exitCode,output,error}` — synchronous; gated by `config('extensions.install.enabled')`; throws **`Glueful\Extensions\Install\InstallDisabledException | HostNotWritableException | PackageNotAllowedException`** (namespace is `Glueful\Extensions\Install`, NOT `...\Exceptions`). Leaves package installed-but-disabled.
- `Glueful\Extensions\ExtensionStateWriter::enable(string $configPath, string $provider, bool $dryRun=false, bool $backup=false): void`.
- `Glueful\Extensions\ExtensionManager::writeCacheNow(?array $providerClasses=null): void`.
- `Glueful\Extensions\PackageManifest` (ctor `(ApplicationContext)`): `getCandidates(): array<string,object{provider:string}>`.
- `base_path(\Glueful\Bootstrap\ApplicationContext $context, string $path=''): string` — **requires context** (no-arg is invalid).
- `config_path($context, 'extensions.php')` → path to `config/extensions.php`.
- **Installable gate = `glueful/tenancy`** (framework extension, provider `Glueful\Extensions\Tenancy\TenancyServiceProvider`, migrations `extensions/tenancy/migrations/001_CreateTenantsTable.php`, `002_CreateTenantMembershipsTable.php`). Distinct from the always-on `glueful/thallo-tenancy` pack.
- `Glueful\Database\Migrations\MigrationManager` (ctor `(string $path, ?$logger, ApplicationContext)`): `addMigrationPath(string,MigrationPriority,string): void`, `migrate($pending=null): array{applied:list<string>,failed:list<string>}`.

**The same-boot-cannot-arm fact (drives the two-boot design):** the extension registers owned tables in `boot()` via `registerTenantTables()` gated on `tenancy.enabled` **read at boot** (`packages/thallo-tenancy/src/TenancyServiceProvider.php:188`). A request that flips `tenancy.enabled=1` cannot arm scoping in its own already-booted process — only the next fresh boot registers tables. Hence `RELOADING → (fresh boot) finalize → on`.

Tenancy runtime contracts (`extensions/contracts/src/Tenancy/`):
- `CurrentTenantResolver::tenantUuid(ApplicationContext): string` — `''` when none (never null).
- `TenantContextRunner::runAsTenant(string,callable): mixed`, `runAsSystem(callable): mixed`, `forEachTenant(callable(string):void): void` (deterministic, fail-fast; bound to `ContractTenantRunner`).
- `TenantProvisioner::provisionDefault(ApplicationContext,string,string,string,string): string`, `hasAnyTenant(ApplicationContext): bool`.
- **NEW (Task 4):** `TenantRuntimeReadiness` — `isReady(ApplicationContext): bool`, `mode(ApplicationContext): string` (`MODE_NONE|MODE_BOOTSTRAP_DEFAULT|MODE_FULL_RESOLUTION`).
- Runtime "scoping on" in Thallo = `SystemFlags::tenancyEnabled()`.

Cache (framework `Glueful\Cache\CacheStore`): `get/set/delete`, `deletePattern(string $glob): bool`, `invalidateTags(array): bool`, `remember(...)`, `flush()`. **Segmented keys look like `tenant:{uuid}:render:…`, so a `render:*` glob no longer matches them** — purge globs must cover both the legacy unsegmented shape AND the `tenant:*:` shape. Route cache is filesystem-global — never segmented. **Driver requirement (rev-14 sweep):** tenancy needs a cache driver that can purge by pattern — `file`/`redis`/`array` do; **`memcached` does NOT** (`MemcachedCacheDriver::deletePattern()` returns `false` unconditionally, `getKeys()` returns `[]` — verified `src/Cache/Drivers/MemcachedCacheDriver.php:304,320`), so segment/legacy purges silently no-op there and un-segmented keys would leak into the default tenant. `begin()` fails closed via `CacheTransition::supportsPatternPurge()` (a behavioural probe, not a config-name allowlist). Also verified: `config('cache.prefix')` (`config/cache.php:15`) is currently **dead** (no `src/` consumer applies it), so the un-prefixed purge globs match today — if it is ever wired in, every purge pattern must gain the prefix. Legacy `thallo:preview:working:*` (short-TTL) and `db:*` Twig keys (immutable, versioned → a save is a NEW key) are intentionally NOT purged: stale entries can't be served wrongly.

Cache-key sites (verified): `RenderPageCache.php:104` (`render:{theme}:{appearance}:{path}`, factory `RenderServiceProvider::makeRenderPageCache`), `RenderErrorCache.php:35`, `SitemapBuilder.php:41,50,63` via `SitemapCache`/`FrameworkSitemapCache`, `DatabaseTemplateLoader.php:50` (Twig compile key). Purge command `ClearRenderCacheCommand.php:34` (`deletePattern('render:*')`).

Routing/CLI: controllers registered in provider `services()` (autowire+shared); routes via `loadRoutesFrom` in `boot()`; existing **system-admin routes gate on `content_permission:system.access`** (extensions/cache/health all use it). CLI: `Glueful\Console\BaseCommand` + `#[AsCommand]`, discovered via `discoverCommands('Thallo\\Tenancy\\Console', __DIR__.'/Console')`.

---

## §10 Cache-surface audit (complete inventory — segmentation vs proof of natural isolation)

| Surface | Server cache key? | Disposition |
|---|---|---|
| Render **page** cache | `render:{theme}:{appearance}:{path}` (CacheStore) | **Segment** (Task 8). |
| Render **error** cache | `render:{theme}:{appearance}:{status}` (CacheStore) | **Segment** (Task 8). |
| Twig **compile** cache | `db:{theme}:{name}:{version}:…` (Twig FS cache) | **Segment** the key (Task 10). |
| **Navigation** | none — resolves from `MenuRepository`, no CacheStore use | **Natural isolation:** output is cached only *inside* the render page cache (segmented in Task 8) and purged via the `thallo:render:page` tag. No separate key exists. Proven by grep: zero cache calls in `thallo-navigation`. |
| **SEO / sitemap** | `thallo:seo:sitemap:*` (CacheStore via `SitemapCache`) | **Segment** (Task 9). |
| **Media / blob URL** | none in app/ or packages/ — `EngineMediaUrlResolver::url()` hits the `blobs` table per call; any URL caching is inside the framework `glueful/media` package | **No server cache key to segment.** Ownership is enforced by data + generic framework lifecycle/access seams, not cache: `blobs` is framework-global, so **Task 10b** adds a Thallo-owned `media_assets` ownership table, external `BlobCreatedHook`/`BlobAccessPolicy` implementations, and a retrofit backfill of existing blobs. Framework `glueful/media` URL caching remains a follow-up. |
| **Repository / query** | none — `EngineContentDeliveryReader` and repos have zero `remember()`/`->cache()`; `ContentTypeRepository.cache_ttl` is a stored column, not a query cache | **Natural isolation:** delivery relies on HTTP/CDN validators (`Cache-Tag` headers), not a server cache. Reads are already tenant-scoped by the auto-injection hook. |
| **Preview working copy** | `thallo:preview:working:{entry}:{locale}` (CacheStore) | **Segment** (Task 10, folded in) — it is per-tenant entry data. |
| **Form rate-limit** | `forms:rate:{formKey}:{md5(ip)}` (CacheStore) | **Leave global** — rate-limit is per-IP/per-form, not tenant content; segmenting would weaken abuse protection. Recorded as a deliberate non-segmentation. |
| Framework **route** cache | filesystem, `storage/cache/routes_*.php` | **Global by design** (framework routes aren't tenant content). |

---

## File Structure

**New — framework (`glueful/framework`, held/pin at release):**
- `src/Database/Execution/ExecutionWrapperInterface.php` — around-execution seam (Task 11a).
- (modify) `src/Database/Execution/QueryExecutor.php` — `addExecutionWrapper`/`clearExecutionWrappers` + compose.
- (modify) `src/Database/Connection.php` — public `newPdo(): PDO` for independent lock sessions (Task 11b).
- (new) `tests/Unit/Database/ExecutionWrapperTest.php` resets `clearExecutionWrappers()` in `setUp()` (mirroring the interceptor/hook registry tests); no central bootstrap reset exists (Task 11a Step 5).

**New — contracts (`glueful/extension-contracts`):**
- `src/Tenancy/TenantRuntimeReadiness.php` — neutral readiness + mode contract.
- `src/Tenancy/FullTenantResolutionReadiness.php` — SP2 capability the SP1 composite soft-resolves.
- `src/Tenancy/TenantEnforcementProbe.php` — registry-membership read for finalize.

**New — pack enablement (`packages/thallo-tenancy/src/Enablement/`):**
- `EnablementStep.php` (enum, incl. `RELOADING`), `EnablementStore.php`, `EnablementLock.php`, `EnablementStatus.php`.
- `EnablementException.php`, `StaleStateException.php`, `EnablementLockedException.php`, `RequestResolutionNotReadyException.php`.
- `ExtensionActivation.php`, `FinalizationProbe.php`, `TenancyEnablement.php`.

**New — pack runtime (`packages/thallo-tenancy/src/Runtime/`):**
- `TenancyRuntimeReadiness.php` — SP1 **composite** `TenantRuntimeReadiness` (bootstrap now, soft-resolves SP2 full-resolution).
- `BootstrapDefaultTenantMiddleware.php` — wraps tenant-data requests in `runAsTenant(default)` (nullable ext deps).
- `BootstrapTenantCreationGuard.php` — blocks a 2nd tenant while mode is `bootstrap_default`.
- `CollectionsDisabledWhenTenantMiddleware.php` — 503s collections routes while tenancy is enabled (SP1 fence).
- `TenantSystemMiddleware.php` — no-op classification marker for Thallo-owned system/global routes.

**New — generic framework blob seams (Task 10b, held/pin at release):**
- `src/Uploader/Contracts/BlobCreatedHook.php`, `BlobAccessPolicy.php`, `BlobAccessContext.php`, and `BlobAction.php`, plus unbound `NullBlobCreatedHook`/`NullBlobAccessPolicy` fallbacks. None contains tenancy behavior.
- (modify) `src/Controllers/UploadController.php` — attribute AFTER create (no transaction) with a `compensateOwnerlessBlob()` fail-safe (checked storage delete → hard-delete → verified quarantine fallback); defer the thumbnail to post-attribution; authorize on `show/info/delete/signedUrl`; `checkBlobAccess()` gains a precomputed `bool $signatureValid` param (signature computed ONCE in `show()`). Inject `LoggerInterface` for the `critical` reconciliation records.
- (modify) `src/Uploader/FileUploader.php` — add a public `generateThumbnailFor(mixed $fileInput, string $storagePath, string $filename, string $mime, array $options): ?string` wrapping the existing private `maybeGenerateThumbnail()`, so the controller can generate the thumbnail only after attribution succeeds. (`uploadMedia()` is called with `generate_thumbnail => false`; **no `thumb_path` is added** — `UploadResultData`/OpenAPI unchanged.)
- (modify) `src/Container/Providers/StorageProvider.php` — the `UploadController` `FactoryDefinition` (~:135) soft-resolves the generic hook and policy independently.

**New — media ownership (Task 10b):**
- `app/Content/Media/TenantBlobPolicy.php` — attributes uploads + authorizes private-blob access by owner.
- `packages/thallo-tenancy/src/Retrofit/MediaOwnershipBackfill.php` — seeds `media_assets` from existing blobs → default tenant.
- `database/dependent-migrations/00X_CreateMediaAssetsTable.php` — Thallo-owned `media_assets(blob_uuid UNIQUE, tenant_uuid)`.
- (modify) `packages/thallo-tenancy/src/ThalloTenantTables.php` — own `media_assets`/`media_meta`/`media_usage` (uniques NOT widened).
- (modify) `app/Http/Controllers/MediaAdminController.php` — scope by rooting queries at `media_assets`.
- (modify) `app/Providers/ThalloServiceProvider.php` — sole bindings for both generic contracts, backed by one `TenantBlobPolicy`, plus an enabled-boot fail-closed assertion.

**New — pack cache (`packages/thallo-tenancy/src/Cache/`):**
- `TenantCacheSegment.php`, `MissingTenantForCacheException.php`, `CacheTransition.php`.

**New — pack quiescence (`packages/thallo-tenancy/src/Retrofit/`):**
- `MutationBoundaryLock.php` — shared/exclusive advisory lock on **dedicated participant + maintenance PDOs** (Task 11b).
- `MutationQuiescenceWrapper.php` — `ExecutionWrapperInterface` holding the shared lock across execute.
- (keep) `RetrofitWriteBarrierInterceptor.php` — cheap in-memory first-line reject via `before()` calling the guard's `active()` (`active()` lives on `RetrofitMaintenanceGuard`, not the interceptor); unchanged.
- (modify) `RetrofitMaintenanceGuard.php` — implements `WriteBarrier::runWritable()` (Task 11c).

**New — pack HTTP/CLI:**
- `Http/Controllers/TenancyEnablementController.php`, `routes/enablement.php`.
- `Console/TenancyEnableCommand.php`, `Console/TenancyStatusCommand.php`.

**Modified — contracts + raw-writers (Task 11c):** `packages/thallo-contracts/src/Tenancy/WriteBarrier.php` (add `runWritable`); the ~14 classified raw-PDO writers (SeoMetaRepository, MenuRepository, AnalyticsRecorder, WorkflowStateRepository, BlockMigrationRepository, MigrationRepository, ScheduleRepository, VersionPruner, EnsureFilterIndexesJob) **plus `TenantBlobPolicy` (migrated from Task 10b's `assertWritable()`)** wrap mutations in `runWritable()`; `tests/Unit/Tenancy/RawPdoScopingLintTest.php` (require `runWritable(`).

**Modified:** `TenancyServiceProvider.php` (register everything; bind `TenantRuntimeReadiness`; load routes; middleware alias; discoverCommands); root `composer.json` + `config/extensions.php` (Task 3); render/SEO cache files + factories; `DatabaseTemplateLoader.php`; the tenant-data route files (`routes/*.php` + `packages/*/routes/*-routes.php`) — `tenant_bootstrap` **outermost**; `tests/Support/RetrofitHarnessTestCase.php` (`clearExecutionWrappers`).

**Tests:** `packages/thallo-tenancy/tests/...` (unit) + `tests/Integration/Tenancy/...` (two-boot, `tenancy-retrofit` suite).

---

### Task 1: EnablementStep enum (with RELOADING) + EnablementStore

**Files:** Create `Enablement/EnablementStep.php`, `Enablement/StaleStateException.php`, `Enablement/EnablementStore.php`; Test `tests/Unit/Enablement/EnablementStoreTest.php`.

**Interfaces:** Consumes `SystemFlags`. Produces `EnablementStep` (incl. `RELOADING`); `EnablementStore` with `step()/setStep()/compareAndSet()/recordFailure()/recordFailureCleared()/failure()/failedFrom()/setPendingTenant()/pendingSlug()/pendingName()/clearPending()`.

- [ ] **Step 1: Failing test** (as in rev 1 Task 1, plus a RELOADING round-trip assertion)

```php
public function testReloadingRoundTrips(): void
{
    $s = $this->store();
    $s->setStep(EnablementStep::RELOADING);
    self::assertSame(EnablementStep::RELOADING, $s->step());
}
```
(Keep the rev-1 tests for OFF default, setStep, compareAndSet match/stale, failure+pending round-trip.)

- [ ] **Step 2: Run → FAIL** (`vendor/bin/phpunit --no-coverage packages/thallo-tenancy/tests/Unit/Enablement/EnablementStoreTest.php`).

- [ ] **Step 3: Enum**

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

/**
 * Resumable enablement machine (spec §9). Reaching `on` crosses TWO fresh-boot boundaries — one so the
 * newly-installed provider's autoloader/bindings appear, one so `tenancy.enabled` arms table registration:
 *   off → installing → [awaiting_install] → enabling_extension → awaiting_provider_boot
 *       → migrating_extension → awaiting_confirm → retrofitting → reloading → (fresh boot) finalizing → on
 * `awaiting_provider_boot` = the provider was written into config/extensions.php + activated, but is NOT
 * bound in THIS already-booted container; the next fresh request has it and may verify contracts + migrate.
 * `reloading` = retrofit done, tenancy.enabled=1, BARRIER STILL UP. `finalizing` = a fresh-process
 * finalize() CLAIMED the transition (barrier still up) and is verifying enforcement; a crash here is
 * recoverable. Only the final atomic step (lower barrier + set `on` in ONE system-channel transaction)
 * reaches `on`. Distinct from the runtime gate (SystemFlags::tenancyEnabled()). No disable state here.
 */
enum EnablementStep: string
{
    case OFF = 'off';
    case INSTALLING = 'installing';
    case AWAITING_INSTALL = 'awaiting_install';
    case ENABLING_EXTENSION = 'enabling_extension';
    case AWAITING_PROVIDER_BOOT = 'awaiting_provider_boot';
    case MIGRATING_EXTENSION = 'migrating_extension';
    case AWAITING_CONFIRM = 'awaiting_confirm';
    case RETROFITTING = 'retrofitting';
    case RELOADING = 'reloading';
    case FINALIZING = 'finalizing';
    case ON = 'on';
    case FAILED = 'failed';

    /** Steps that REQUIRE a fresh process before the machine can advance (CLI/HTTP must stop + re-request). */
    public function needsFreshBoot(): bool
    {
        return $this === self::AWAITING_PROVIDER_BOOT || $this === self::RELOADING;
    }

    public function progress(): int
    {
        return match ($this) {
            self::OFF => 0,
            self::INSTALLING, self::AWAITING_INSTALL => 10,
            self::ENABLING_EXTENSION => 20,
            self::AWAITING_PROVIDER_BOOT => 30,
            self::MIGRATING_EXTENSION => 40,
            self::AWAITING_CONFIRM => 50,
            self::RETROFITTING => 75,
            self::RELOADING => 90,
            self::FINALIZING => 95,
            self::ON => 100,
            self::FAILED => 0,
        };
    }
}
```

- [ ] **Step 4: `StaleStateException` + `EnablementStore`** — identical to rev 1 Task 1 (KEY_STEP `tenancy.enable_step`, KEY_FAILURE `tenancy.enable_failure`, KEY_FAILED_FROM `tenancy.enable_failed_from`, KEY_PENDING_SLUG/NAME), plus `recordFailureCleared()` (forget failure + failed_from). Copy that code verbatim.

- [ ] **Step 5: Run → PASS.** phpcs clean.
- [ ] **Step 6: Commit** — `feat(tenancy): enablement step enum (+reloading) + state store (CAS)`.

---

### Task 2: EnablementLock (Postgres advisory mutex)

Identical to rev 1 Task 2 — `EnablementLock::withLock(callable): mixed` via `pg_try_advisory_lock(4823710)`, `EnablementLockedException`, in-scope `$held` guard. Copy verbatim. Commit `feat(tenancy): enablement advisory-lock mutex`.

---

### Task 3: On-demand `glueful/tenancy` — path repo + `require-dev`, disabled

**Why (rev 2):** distribution model is **on-demand, no production hard-require**. Declare a path **repository** so composer can resolve the package, and add it to **`require-dev`** so dev/CI have it installed (discoverable) while production (`composer install --no-dev`) does not — production installs it on demand via the flow. It ships **disabled** (absent from `config/extensions.php` `enabled`).

**Files:** Modify root `composer.json` (repositories + `require-dev`); verify `config/extensions.php`; Test `tests/Unit/Enablement/TenancyPackageDiscoverableTest.php`.

- [ ] **Step 1: Failing test**

```php
public function testGluefulTenancyIsADevCandidate(): void
{
    $candidates = (new \Glueful\Extensions\PackageManifest($this->appContext()))->getCandidates();
    self::assertArrayHasKey('glueful/tenancy', $candidates); // dev has require-dev installed
    self::assertSame(
        'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider',
        $candidates['glueful/tenancy']->provider
    );
}

public function testGluefulTenancyIsNotEnabledByDefault(): void
{
    $enabled = require dirname(__DIR__, 4) . '/config/extensions.php';
    self::assertNotContains(
        'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider',
        $enabled['enabled']
    );
}

public function testGluefulTenancyIsNotAProductionRequire(): void
{
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/composer.json'), true);
    self::assertArrayNotHasKey('glueful/tenancy', $composer['require'] ?? []);
    self::assertArrayHasKey('glueful/tenancy', $composer['require-dev'] ?? []);
}
```

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Edit `composer.json`** — add repository `{ "type": "path", "url": "../extensions/tenancy", "options": { "symlink": true } }`; add to **`require-dev`**: `"glueful/tenancy": "*"`. Do NOT add to `require`.
- [ ] **Step 4: `composer update glueful/tenancy --no-interaction`** (local). Confirm it lands in `installed.json` and `config/extensions.php` `enabled` still omits the provider.
- [ ] **Step 5: Run → PASS.**
- [ ] **Step 6: Commit** — `chore(tenancy): glueful/tenancy as on-demand (require-dev path repo), disabled`.

---

### Task 4: Neutral contracts — readiness, SP2 capability, enforcement probe

**Files (all in `glueful/extension-contracts/src/Tenancy/`):** `TenantRuntimeReadiness.php`, `FullTenantResolutionReadiness.php`, `TenantEnforcementProbe.php`. Test: contracts-repo interface-shape tests.

**Interfaces:** Produces the three neutral contracts. `TenantRuntimeReadiness` is bound ONCE by SP1's composite (Task 5). `FullTenantResolutionReadiness` is the SP2 capability the composite soft-resolves (no SP1 impl). `TenantEnforcementProbe` exposes registry membership (the existing `TenantTableRegistry` is write-only) — the extension implements it.

- [ ] **Step 1: `TenantRuntimeReadiness`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tenancy;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Reports whether — and HOW — the running app resolves a tenant for tenant-data requests:
 *   - MODE_NONE            → no request resolution; being `on` is UNSAFE (finalize must refuse).
 *   - MODE_BOOTSTRAP_DEFAULT → SP1 single-tenant bootstrap: one unambiguous tenant.
 *   - MODE_FULL_RESOLUTION → SP2 domain/path/header resolution active; bootstrap fallback disabled.
 * SP1 binds ONE composite implementation of THIS contract; SP2 does NOT override it — SP2 binds
 * FullTenantResolutionReadiness (below), which the composite soft-resolves and reports as full_resolution.
 */
interface TenantRuntimeReadiness
{
    public const MODE_NONE = 'none';
    public const MODE_BOOTSTRAP_DEFAULT = 'bootstrap_default';
    public const MODE_FULL_RESOLUTION = 'full_resolution';

    public function isReady(ApplicationContext $context): bool;

    /** @return self::MODE_* */
    public function mode(ApplicationContext $context): string;
}
```

- [ ] **Step 2: `FullTenantResolutionReadiness` (SP2 capability — no SP1 impl)**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tenancy;

use Glueful\Bootstrap\ApplicationContext;

/**
 * SP2 capability: real multi-tenant request resolution (domain/path/header) is wired and enforcing.
 * SP2 binds an implementation of THIS contract. SP1's composite TenantRuntimeReadiness soft-resolves it
 * (container->has(...)) and, when present and ready, reports MODE_FULL_RESOLUTION and stands its
 * bootstrap fallback down. This keeps a single binding per shared contract ID (no competing defaults).
 */
interface FullTenantResolutionReadiness
{
    public function isReady(ApplicationContext $context): bool;
}
```

- [ ] **Step 3: `TenantEnforcementProbe` (registry membership — for finalize)**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tenancy;

/**
 * Read-side view of the tenant-owned table registry (the existing TenantTableRegistry contract is
 * write-only). finalize() uses this to PROVE, in a fresh process, that every table the retrofit widened
 * is actually registered as tenant-owned here — i.e. the auto-injection read hook will scope it. A
 * scoped probe query is not enough: an unregistered table's query still succeeds and the prod guard only
 * logs. The tenancy extension implements this over its authoritative registry.
 */
interface TenantEnforcementProbe
{
    public function isRegistered(string $table): bool;

    /** @return list<string> */
    public function registeredTables(): array;
}
```

- [ ] **Step 4:** phpcs (contracts repo) clean. Commit (contracts repo) — `feat(contracts): tenant runtime-readiness + full-resolution capability + enforcement-probe`.

> **Extension binding (held, in `glueful/tenancy`):** add a `Bridge\ContractEnforcementProbe` implementing `TenantEnforcementProbe` over `Query\TenantTableRegistry::isTenantOwned()`/`all()`, and register it + bind `TenantEnforcementProbe` in the extension's `services()`. This is an extension change (held), verified present by Task 13's finalize probe.

> Cross-repo note: all contract/extension edits are held/uncommitted; pin versions at release.

---

### Task 5: `TenancyRuntimeReadiness` (SP1 composite — bootstrap now, full_resolution when SP2 binds)

**Files:** Create `Runtime/TenancyRuntimeReadiness.php`; Test `tests/Unit/Runtime/TenancyRuntimeReadinessTest.php`.

**Interfaces:** Consumes `SystemFlags`, `Connection`, `TenantContextRunner` (nullable), and **soft-resolves** the optional `FullTenantResolutionReadiness` via `container->has(...)`. Produces the single `TenantRuntimeReadiness` binding. Returns `MODE_FULL_RESOLUTION` when the SP2 capability is bound and ready; else `MODE_BOOTSTRAP_DEFAULT` iff invariants 2–5 hold; else `MODE_NONE`. **This is the only `TenantRuntimeReadiness` binding — SP2 adds `FullTenantResolutionReadiness`, never overrides this.**

- [ ] **Step 1: Failing test**

```php
public function testNotReadyWhenTenancyOff(): void
{
    $r = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness::class);
    self::assertFalse($r->isReady($this->appContext()));
    self::assertSame(
        \Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness::MODE_NONE,
        $r->mode($this->appContext())
    );
}
```
(The positive `bootstrap_default` case is covered in the Task 20 two-boot acceptance where one tenant + pointer exist.)

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Runtime;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Thallo\Tenancy\System\SystemFlags;

/**
 * SP1-owned composite readiness — the SINGLE TenantRuntimeReadiness binding.
 *   1. If the SP2 capability (FullTenantResolutionReadiness) is bound AND ready → MODE_FULL_RESOLUTION
 *      (bootstrap fallback stands down; SP2 owns unresolved-domain behavior).
 *   2. Else bootstrap: ready iff exactly ONE unambiguous tenant to resolve to — enabled + default pointer
 *      + exactly one active tenant matching the pointer + a runner to scope with → MODE_BOOTSTRAP_DEFAULT.
 *   3. Else MODE_NONE (forces the middleware to 503 and finalize() to refuse).
 * SP2 binds FullTenantResolutionReadiness; it never rebinds TenantRuntimeReadiness — no competing defaults
 * under a shared contract ID.
 */
final class TenancyRuntimeReadiness implements TenantRuntimeReadiness
{
    public function __construct(
        private readonly SystemFlags $flags,
        private readonly Connection $db,
        private readonly ?TenantContextRunner $runner = null,
    ) {
    }

    public function isReady(ApplicationContext $context): bool
    {
        return $this->mode($context) !== self::MODE_NONE;
    }

    public function mode(ApplicationContext $context): string
    {
        // 0. OFF dominates (finding #8): if tenancy is disabled, mode is NONE regardless of whether an
        //    SP2 capability happens to be bound/ready — an off system resolves no tenant.
        if (!$this->flags->tenancyEnabled()) {
            return self::MODE_NONE;
        }

        // 1. SP2 full resolution takes precedence when present + ready.
        $container = $context->getContainer();
        if ($container->has(FullTenantResolutionReadiness::class)) {
            $full = $container->get(FullTenantResolutionReadiness::class);
            if ($full instanceof FullTenantResolutionReadiness && $full->isReady($context)) {
                return self::MODE_FULL_RESOLUTION;
            }
        }

        // 2. SP1 bootstrap: exactly one unambiguous tenant.
        if ($this->runner === null) {
            return self::MODE_NONE;
        }
        $default = $this->flags->defaultTenantUuid();
        if ($default === null || $default === '') {
            return self::MODE_NONE;
        }
        if (!$this->db->getSchemaBuilder()->hasTable('tenants')) {
            return self::MODE_NONE;
        }
        $rows = $this->db->table('tenants')->where('status', 'active')->select(['uuid'])->get();
        if (count($rows) !== 1 || (string) ($rows[0]['uuid'] ?? '') !== $default) {
            return self::MODE_NONE; // zero, many, or mismatched → unsafe for bootstrap scoping
        }
        return self::MODE_BOOTSTRAP_DEFAULT;
    }
}
```

- [ ] **Step 4: Bind ONCE in the pack provider (soft-resolve the nullable runner)**

```php
TenantRuntimeReadiness::class => ['factory' => [self::class, 'makeReadiness'], 'shared' => true],
```
```php
public static function makeReadiness(ContainerInterface $c): TenantRuntimeReadiness
{
    // Runner is extension-bound (absent while off) — soft-resolve to null, never hard-require.
    $runner = $c->has(TenantContextRunner::class) ? $c->get(TenantContextRunner::class) : null;
    return new TenancyRuntimeReadiness($c->get(SystemFlags::class), $c->get(Connection::class), $runner);
}
```
> A plain `autowire` would hard-require `TenantContextRunner` and 500 while tenancy is off. SP2 registers `FullTenantResolutionReadiness` in ITS provider `services()` — a distinct ID — and this composite picks it up via `container->has()`. Neither provider competes on the `TenantRuntimeReadiness` ID.

- [ ] **Step 5: Run → PASS.** phpcs clean.
- [ ] **Step 6: Commit** — `feat(tenancy): SP1 composite tenant-runtime readiness (bootstrap/full-resolution)`.

---

### Task 6: TenantCacheSegment (fail-closed) + CacheTransition (correct purge globs)

**Files:** Create `Cache/MissingTenantForCacheException.php`, `Cache/TenantCacheSegment.php`, `Cache/CacheTransition.php`; Tests `tests/Unit/Cache/TenantCacheSegmentTest.php`, `tests/Unit/Cache/CacheTransitionTest.php`.

**Interfaces:** Consumes `SystemFlags`, `CurrentTenantResolver` (optional), `CacheStore`. Produces `TenantCacheSegment::segment(ApplicationContext,string='cache'): string`; `CacheTransition::purge(): void`; `CacheTransition::supportsPatternPurge(): bool` (driver capability probe — `begin()` fails closed when false). **Test (`CacheTransitionTest`):** with the array driver `supportsPatternPurge()` is `true`; with a stubbed `CacheStore` whose `deletePattern()` no-ops (memcached shape) it is `false`.

- [ ] **Step 1: Failing tests**

```php
// TenantCacheSegmentTest
public function testNoSegmentWhenScopingOff(): void
{
    self::assertSame('', $this->container()->get(TenantCacheSegment::class)->segment($this->appContext()));
}

// CacheTransitionTest — purge must clear BOTH legacy and segmented keys
public function testPurgeClearsLegacyAndSegmentedRenderAndSitemap(): void
{
    $cache = $this->container()->get(CacheStore::class);
    $cache->set('render:default:light:/', ['x'], 3600);
    $cache->set('tenant:abc123456789:render:default:light:/', ['x'], 3600);
    $cache->set('thallo:seo:sitemap:root', 's', 3600);
    $cache->set('tenant:abc123456789:thallo:seo:sitemap:root', 's', 3600);

    $this->container()->get(CacheTransition::class)->purge();

    self::assertNull($cache->get('render:default:light:/'));
    self::assertNull($cache->get('tenant:abc123456789:render:default:light:/'));
    self::assertNull($cache->get('thallo:seo:sitemap:root'));
    self::assertNull($cache->get('tenant:abc123456789:thallo:seo:sitemap:root'));
}
```

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: `TenantCacheSegment` (fail-closed per finding #3)**

```php
public function segment(ApplicationContext $context, string $surface = 'cache'): string
{
    if (!$this->flags->tenancyEnabled()) {
        return '';                              // off / disabled_widened → no segment
    }
    if ($this->resolver === null) {
        throw new MissingTenantForCacheException($surface); // enabled but no resolver → FAIL CLOSED
    }
    $uuid = $this->resolver->tenantUuid($context);
    if ($uuid === '') {
        throw new MissingTenantForCacheException($surface); // enabled but no tenant → FAIL CLOSED
    }
    return 'tenant:' . $uuid . ':';
}
```
(Constructor + `MissingTenantForCacheException` as in rev 1 Task 6. Register with the nullable-resolver factory pattern.)

- [ ] **Step 4: `CacheTransition` (purge both shapes per corrections)**

```php
public function purge(): void
{
    // Legacy unsegmented AND per-tenant segmented shapes — a `render:*` glob does NOT match
    // `tenant:{uuid}:render:*`, so purge both explicitly.
    $this->cache->deletePattern('render:*');
    $this->cache->deletePattern('tenant:*:render:*');
    $this->cache->deletePattern('thallo:seo:sitemap:*');
    $this->cache->deletePattern('tenant:*:thallo:seo:sitemap:*');
    $this->cache->invalidateTags(['thallo:render:page']);
}

/**
 * Whether the configured cache driver can actually purge by pattern (finding rev-14 sweep, cache P1).
 * Verified: MemcachedCacheDriver::deletePattern() returns false UNCONDITIONALLY and getKeys() returns []
 * (`src/Cache/Drivers/MemcachedCacheDriver.php:304,320`) — so purge() silently no-ops there and legacy
 * un-segmented keys would leak into the bootstrap-default tenant. Probe the ACTUAL store (driver-agnostic,
 * not a config-name allowlist) so custom drivers are judged by behaviour: write a sentinel, deletePattern
 * it, and check it is gone. `deletePattern`'s bool return is unreliable on a no-match, so re-read instead.
 */
public function supportsPatternPurge(): bool
{
    $probe = 'thallo:tenancy:capexpr:' . bin2hex(random_bytes(4));
    $this->cache->set($probe, '1', 60);
    $this->cache->deletePattern('thallo:tenancy:capexpr:*');
    $survived = $this->cache->get($probe) !== null;
    if ($survived) {
        $this->cache->delete($probe); // clean the sentinel on an unsupported driver
    }
    return !$survived;
}
```

- [ ] **Step 5: Also fix `ClearRenderCacheCommand.php:34`** and the content-type routing purge to include the `tenant:*:` shape:
```php
$this->cache->deletePattern('render:*');
$this->cache->deletePattern('tenant:*:render:*');
```

- [ ] **Step 6: Register + run → PASS.** phpcs clean.
- [ ] **Step 7: Commit** — `feat(tenancy): fail-closed cache segment + transition purge (legacy+segmented)`.

---

### Task 7: ExtensionActivation (detect / install→return / activate / migrate)

**Files:** Create `Enablement/ExtensionActivation.php`; Test `tests/Unit/Enablement/ExtensionActivationTest.php`.

**Fixes applied:** Install exception namespace `Glueful\Extensions\Install`; `base_path($this->context)`; install does NOT activate in the same request.

**Interfaces:** Produces `PACKAGE`/`PROVIDER` consts; `isInstalled(): bool`; `isActivated(): bool`; `install(): array{status,blocked,reason,cli,output}`; `activate(): void`; `migrate(): array{applied,failed}`.

- [ ] **Step 1: Failing test** (as rev 1 Task 4, plus:)
```php
public function testInstallOfAnAlreadyInstalledPackageIsANonBlockingSkip(): void
{
    $r = $this->container()->get(ExtensionActivation::class)->install();
    self::assertSame('installed', $r['status']);
    self::assertFalse($r['blocked']);
}
```

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** — same body as rev 1 Task 4 **with these corrections**:
  - Imports: `use Glueful\Extensions\Install\HostNotWritableException; use Glueful\Extensions\Install\InstallDisabledException; use Glueful\Extensions\Install\PackageNotAllowedException;` (NOT `Install\Exceptions\*`).
  - `migrate()`: `$root = base_path($this->context);` (context arg).
  - Keep `install()` returning terminal `installed`/`blocked` only — it never calls `activate()`. (The state machine, Task 16, persists `ENABLING_EXTENSION` and returns after a successful install, so activation runs on a later request.)

- [ ] **Step 4: Register + run → PASS.** phpcs clean.
- [ ] **Step 5: Commit** — `feat(tenancy): extension activation (detect/install/activate/migrate)`.

---

### Task 8: Thread the segment into render page + error caches

Same as rev 1 Task 7 — inject `TenantCacheSegment` + `ApplicationContext` into `RenderPageCache` and `RenderErrorCache`, prepend `->segment($ctx,'render')` to the key builders, wire through the `RenderServiceProvider` factories. Two-boot test `RenderCacheSegmentTest` asserts distinct non-empty segments per tenant. Commit `feat(tenancy): segment render page + error caches by tenant`.

---

### Task 9: Thread the segment into the SEO sitemap cache

Same as rev 1 Task 8 — prefix `FrameworkSitemapCache` keys via `->segment($ctx,'seo')`; `forgetAll()` purge glob prefixed. Two-boot test `SitemapCacheSegmentTest`. Commit `feat(tenancy): segment SEO sitemap cache by tenant`.

---

### Task 10: Thread the segment into Twig compile + preview caches

Same as rev 1 Task 9 for `DatabaseTemplateLoader::getCacheKey` (prefix `->segment($ctx,'template')`), **plus** prefix `PreviewWorkingCopyStore` keys with `->segment($ctx,'preview')` (per the §10 audit). Two-boot test `TemplateCompileCacheSegmentTest` asserts per-tenant key inequality. Commit `feat(tenancy): segment Twig compile + preview caches by tenant`.

---

### Task 10b: Media tenant ownership — generic framework blob seams + `media_assets` ownership (finding P1 #1–#5)

**Why:** the media library is GLOBAL. `MediaAdminController::index()` lists the framework `blobs` table directly (`:51`); the Thallo sidecars `media_meta`/`media_usage` have no `tenant_uuid`; and the real upload path is the **framework-owned** `POST /v1/blobs` (`vendor/glueful/framework/routes/blobs.php:43`, `Glueful\Controllers\UploadController`) whose only middleware is `auth`/`rate_limit` — NOT `tenant_bootstrap`. So a pre-insert `Connection` hook (rev 6) sees no tenant, and after enablement two tenants share one library AND any authenticated tenant can `show/info/delete/signed-url` another tenant's private blob by UUID.

**The rev-6 pre-insert hook is abandoned.** Five defects (round 6) force a redesign around **two held framework seams** — a post-create attribution callback and an access-authorization callback — plus correct ownership storage and a retrofit backfill:
1. Upload attribution must run where a tenant is known → a **post-create** framework seam, not a pre-insert `Connection` hook (which fires outside `tenant_bootstrap`).
2. The tenancy table hook scopes only the **primary** `table(...)` (`vendor/glueful/tenancy/…/TenancyServiceProvider.php:168`), so a `table('blobs')->join('media_assets')` is NOT scoped → **root queries at `media_assets`**.
3. Framework PG `upsert` is hardcoded `ON CONFLICT (id)` (`PostgreSQLDriver.php:89`) and would let a repeat UUID **transfer ownership** → use raw `INSERT … ON CONFLICT (blob_uuid) DO NOTHING`, then **verify** the existing owner; never update ownership.
4. Widening the media uniques to `(tenant_uuid, blob_uuid)` would **drop the global `blob_uuid` unique** (multi-owner) → **do NOT widen** UUID-backed uniques; and existing blobs have no ownership row → **special retrofit backfill** assigns every pre-existing blob to the default tenant.
5. Private-blob authz cannot be deferred → the access seam ships **now**; Thallo enforces owner==current-tenant for private `show/info/delete/signed-url`.

**Files — framework (held, pin at release):** Create generic `src/Uploader/Contracts/BlobCreatedHook.php`, `BlobAccessPolicy.php`, `BlobAccessContext.php`, `BlobAction.php` (backed enum), `NullBlobCreatedHook.php`, and `NullBlobAccessPolicy.php` (fallbacks only, not bound); Modify `src/Controllers/UploadController.php` (separate hook/policy ctor args; attribute after create + **`compensateOwnerlessBlob()` fail-safe** (checked storage delete → hard-delete → verified quarantine, `\Throwable`); defer the thumbnail; authorize on `show/info/delete/signedUrl`), `src/Uploader/FileUploader.php` (public `generateThumbnailFor()`), `src/Container/Providers/StorageProvider.php` (soft-resolve both contracts in the existing `UploadController` `FactoryDefinition`), and direct controller tests. **Thallo:** Modify `packages/thallo-tenancy/src/ThalloTenantTables.php`; Create `database/dependent-migrations/011_CreateMediaAssetsTable.php` + fold nullable staging columns into `006_CreateMediaTables.php`; Create `app/Content/Media/TenantBlobPolicy.php` (implements both contracts; injects `WriteBarrier`) + `packages/thallo-tenancy/src/Retrofit/MediaOwnershipBackfill.php`; Modify `MediaAdminController.php` (root at `media_assets`), `ThalloServiceProvider` (bind both contracts to one shared `TenantBlobPolicy` + enabled-boot fail-closed assertion), `SchemaRetrofit` (inject backfill), and `RawPdoScopingLintTest`. Tests: `MediaOwnershipTest`, `MediaOwnershipBackfillTest`, and `BlobPolicyBootGuardTest`. **Ordering:** 10b initially used `assertWritable()`; Task 11c migrated `TenantBlobPolicy` to `runWritable()` with the other raw writers.

- [ ] **Step 1 (framework, HELD): generic lifecycle/access seams + a request-derived access context** — the policy must know the request facts the framework already computed (auth + whether a signed grant validated), so it receives a `BlobAccessContext`, not a bare action string:
```php
<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

/** Closed set of blob-access actions — a security boundary, so a backed enum, not a string (finding P2 #4). */
enum BlobAction: string
{
    case VIEW = 'view';     // serve content (show)
    case INFO = 'info';     // read metadata
    case DELETE = 'delete'; // remove the blob
    case SIGN = 'sign';     // mint a signed URL
}

/** Facts the framework has already established about a blob-access request. */
final class BlobAccessContext
{
    public function __construct(
        public readonly BlobAction $action,
        public readonly ?string $authenticatedUserUuid, // null for an anonymous signed-URL fetch
        public readonly bool $signatureValid,   // framework already verified a signed-URL grant (VIEW only)
    ) {
    }
}

/** Optional post-persistence extension point for ownership, indexing, or policy checks. */
interface BlobCreatedHook
{
    /**
     * Called AFTER a blob row is persisted, within the authenticated upload request. MUST throw if it
     * cannot attribute the blob (e.g. tenancy enabled but no tenant resolves) — the framework then
     * COMPENSATES by deleting the just-stored blob (Step 2), so a persisted-but-ownerless blob is
     * impossible.
     */
    public function onBlobCreated(string $blobUuid, ?string $uploaderUserUuid): void;

}

/** Optional synchronous authorization extension point applied after core blob access checks. */
interface BlobAccessPolicy
{
    /** @param array<string,mixed> $blob */
    public function authorizeAccess(array $blob, BlobAccessContext $ctx): bool;
}
```
`NullBlobCreatedHook` is a no-op and `NullBlobAccessPolicy` returns true. They are inline framework fallbacks, **not container bindings**.

- [ ] **Step 2 (framework, HELD): soft-resolve via the EXISTING factory (finding P2 #3) + post-create attribution with deferred thumbnail (findings rev-11 P1 #1–#4) + single-signature authorization**
  - **Resolution — edit the existing factory, not just the class.** `UploadController` is built by a `FactoryDefinition` in `src/Container/Providers/StorageProvider.php` (`:135`). The framework binds neither contract; pass independently soft-resolved hook/policy fallbacks:
    ```php
    // StorageProvider.php factory closure:
    fn (ContainerInterface $c) => new UploadController(
        /* …existing args… */,
        $c->has(BlobCreatedHook::class) ? $c->get(BlobCreatedHook::class) : new NullBlobCreatedHook(),
        $c->has(BlobAccessPolicy::class) ? $c->get(BlobAccessPolicy::class) : new NullBlobAccessPolicy(),
    );
    ```
    Thallo remains the only binder of both contract IDs. Update direct controller tests to pass the two null implementations.
  - **NO transaction around the upload — `onBlobCreated` is atomic by construction (findings rev-11 P1 #1/#2).** The rev-10 design wrapped `uploadMedia()`+`onBlobCreated()` in `Connection::transaction()`; that is WRONG — `TransactionManager::transaction()` retries deadlocks up to 3× (`TransactionManager.php:63`), so it would re-run `uploadMedia()`'s **storage side effects** (new random file + thumbnail every retry, leaking the earlier ones), and it catches `Exception`, not `Throwable` (`:80`), so an `Error` from the policy would bypass rollback. It is also unnecessary: `onBlobCreated` **never leaves a partial write** (see Step 5) — it throws either (a) *before* the INSERT when the tenant is unresolved, or (b) on the `ON CONFLICT DO NOTHING` no-write path (owner mismatch), where a read-only owner check throws having written nothing. So there is no ownership row to roll back on any throw path. We therefore drop the transaction entirely and attribute *after* create, compensating the (now ownerless) blob:
    ```php
    // Glueful returns the authenticated user as an ARRAY, never an object. Normalize it once and reuse the
    // same value for upload attribution and every BlobAccessContext (finding rev-14 P2).
    $user = Utils::getUser();
    $userUuid = is_array($user) && is_string($user['uuid'] ?? null) && $user['uuid'] !== ''
        ? $user['uuid']
        : null;

    // In upload(): uploadMedia stores the object + saves the blob record ONCE, with the thumbnail DEFERRED.
    // It is OUTSIDE the try below, so its ValidationException/UploadException reach the existing handlers
    // (finding rev-11 P1 #3 — the catch wraps ONLY the policy call).
    $result = $uploader->uploadMedia($fileInput, $pathPrefix, array_merge($opts, ['generate_thumbnail' => false])); // override; save_to_blobs stays true
    try {
        $this->tenancyPolicy->onBlobCreated((string) $result['blob_uuid'], $userUuid); // raw INSERT (see Step 5)
    } catch (\Throwable $e) {                       // \Throwable catches Errors too, unlike TransactionManager
        // Attribution failed → the blob row is ownerless (onBlobCreated is write-free on every throw path).
        $this->compensateOwnerlessBlob($uploader, (string) $result['blob_uuid'], (string) $result['path']);
        return Response::error('Upload could not be attributed to a tenant', 500);
    }
    // Attribution committed → generate the thumbnail ONCE, outside any transaction, TRULY best-effort. The
    // temp source still exists (upload()'s finally has not run). The blob + ownership are ALREADY committed,
    // so a thumbnail failure must NOT fail the request — the media impl catches only \Exception, so an \Error
    // (e.g. TypeError) would otherwise 500 a succeeded upload and invite a duplicate retry (finding rev-13 P2).
    try {
        $result['thumb_url'] = $uploader->generateThumbnailFor($fileInput, $pathPrefix, (string) $result['filename'], (string) $result['mime_type'], $thumbOpts);
    } catch (\Throwable $e) {
        $this->logger->warning('upload.thumbnail.deferred_failed', ['blob_uuid' => $result['blob_uuid'], 'error' => $e->getMessage()]);
        $result['thumb_url'] = null; // still return 201 — the upload itself succeeded
    }
    ```
  - **Compensation is a fail-safe, not just a log (findings rev-12 P1 #3 / P2 #4).** Prefer a HARD delete (FK `ON DELETE CASCADE` clears any ownership). If it returns `false`, do NOT stop at a log — **quarantine** the still-active blob via `status='deleted'` so it can never be served, **verify** the transition, and **record** it for SP1 reconciliation. Storage cleanup checks the boolean too (`FlysystemStorage::delete()` returns `false`, it does not throw):
    ```php
    private function compensateOwnerlessBlob(FileUploader $uploader, string $blobUuid, string $path): void
    {
        // (a) Remove the single stored object (no thumbnail exists yet — it is deferred). delete() RETURNS
        //     false on failure (it does not throw), so check it — a swallowed try/catch would hide leaks.
        if ($path !== '' && !$uploader->getStorage()->delete($path)) {
            $this->logger->critical('upload.compensation.object_orphaned', ['blob_uuid' => $blobUuid, 'path' => $path]);
        }
        // (b) Preferred: hard-delete the blob row (FK cascade clears any ownership).
        if ($this->blobs->delete($blobUuid)) {
            return;
        }
        // (c) Hard-delete failed → the row is still ACTIVE and ownerless. QUARANTINE it (unreachable), then
        //     VERIFY (re-read, don't trust the bool alone) and RECORD for reconciliation. Note the AUTHORITATIVE
        //     guard is TenantBlobPolicy::authorizeAccess denying every ownerless blob (finding rev-13 P1) — even
        //     if BOTH the hard delete and this quarantine fail, an ownerless blob is never served.
        $updated = $this->blobs->updateStatus($blobUuid, 'deleted');      // bool — checked, not ignored
        $row = $this->blobs->findByUuidWithDeleteFilter($blobUuid, includeDeleted: true);
        $quarantined = $updated && $row !== null && ($row['status'] ?? null) === 'deleted'; // verified transition
        $this->logger->critical('upload.compensation.blob_quarantined', [
            'blob_uuid' => $blobUuid,
            'quarantined' => $quarantined, // false ⇒ escalate; authorizeAccess still fails closed on no-owner
        ]);
    }
    ```
  - **Thumbnail deferral avoids the unavailable `thumb_path` (finding rev-11 P1 #4).** `MediaProcessorInterface::generateThumbnail()` returns only a URL and `glueful/media`'s `ThumbnailGenerator::generate()` builds a **random** thumb path internally (`generateFilename()`), then returns `getUrl($thumbPath)` — the path is unrecoverable, so changing only `FileUploader` cannot delete a thumbnail on compensation. By generating the thumbnail **only after attribution succeeds**, a failed attribution never creates one — no orphaned/publicly-reachable thumbnail, no media-contract change, and `thumb_path` is never added to the result (so `UploadResultData`/OpenAPI are untouched — finding rev-11 P2). This needs one held framework seam: a public `FileUploader::generateThumbnailFor(mixed $fileInput, string $storagePath, string $filename, string $mime, array $options): ?string` that normalizes `$fileInput` and calls the existing private `maybeGenerateThumbnail()`. `uploadMedia()` is called with `generate_thumbnail => false`. **The controller wraps this post-attribution call in `catch (\Throwable)` (finding rev-13 P2):** the blob + ownership are already committed, and `ThumbnailGenerator::generate()` catches only `\Exception` — an `\Error` (e.g. a `TypeError` from the image library) would otherwise turn a *succeeded* upload into a 500 and invite a duplicate retry. On any throwable, log and set `thumb_url = null`, still returning 201. The FK `ON DELETE CASCADE` (Step 4) remains as general defense-in-depth so a hard blob delete always clears ownership (`onBlobCreated` is now write-free on every throw path, so there is no partial-ownership case to undo).
  - **Authorization — signature computed ONCE (findings P1 #1/#2).** `checkBlobAccess()` already calls `hasValidSignature()`; do NOT call it again. Refactor `checkBlobAccess(Request, array $blob, bool $signatureValid): ?Response` to accept the precomputed flag, compute it once in `show()`, and pass the SAME boolean into both `checkBlobAccess()` and the context. `info()/delete()/signedUrl()` never validate a signature → pass `false`:
    ```php
    // Resolve once per action: Utils::getUser() is array{uuid,...}|null, not an object.
    $user = Utils::getUser();
    $userUuid = is_array($user) && is_string($user['uuid'] ?? null) && $user['uuid'] !== ''
        ? $user['uuid']
        : null;
    // show():
    $signatureValid = $this->hasValidSignature($request);
    if (($denied = $this->checkBlobAccess($request, $blob, $signatureValid)) !== null) { return $denied; }
    $ctx = new BlobAccessContext(BlobAction::VIEW, $userUuid, $signatureValid);
    // info() / delete() / signedUrl(): AFTER their own auth check —
    $ctx = new BlobAccessContext(BlobAction::INFO, $userUuid, false); // INFO / DELETE / SIGN
    if (!$this->tenancyPolicy->authorizeAccess($blob, $ctx)) {
        return Response::notFound('Blob not found');
    }
    ```
    Framework tests: no binding → identical to today; a denying policy → 404; authenticated requests pass the exact array-derived user UUID into attribution and every access context (anonymous signed views pass `null`); **expired/tampered signed grant → `signatureValid=false` → denied**; a **valid** grant authorizes only `VIEW` (`INFO`/`DELETE`/`SIGN` still 404 under the same grant). (Attribution-failure/compensation behavior is covered by the Step 8 cases, not here.)

- [ ] **Step 3: Own the media sidecars — WITHOUT widening the UUID uniques (finding P1 #4)** — add to `ThalloTenantTables::tableNames()` with **empty** `widened_uniques` (the global `blob_uuid` / `(blob_uuid,entry_uuid)` uniques are UUID-backed and MUST survive; the retrofit still adds the `tenant_uuid` column + index + backfill):
```php
'media_assets' => self::row($inst, [], 'media_assets'), // special backfill seeds from blobs (Step 6)
'media_meta'   => self::row($inst, []),                  // keep global unique(blob_uuid)
'media_usage'  => self::row($inst, []),                  // keep global unique(blob_uuid, entry_uuid)
```
> Empty `widened_uniques` ⇒ `AdditiveRetrofit` preserves the existing unique constraints (no drop/rebuild), so one blob keeps exactly one owner.

- [ ] **Step 4: `media_assets` ownership table** (Thallo-owned; `database/dependent-migrations/`, `tenant_uuid` folded in pre-launch):
```php
$schema->createTable('media_assets', function ($table) {
    $table->bigInteger('id')->primary()->autoIncrement();
    $table->string('blob_uuid', 12);
    $table->string('tenant_uuid', 12)->nullable();  // NOT NULL after the retrofit promotes it
    $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
    $table->unique('blob_uuid');                     // GLOBAL — a blob has exactly ONE owner (never widened)
    $table->index('tenant_uuid');
    // FK → blobs so a HARD blob delete cascades ownership away. This backstops Step 2's compensation
    // (checked hard-delete of an ownerless blob) and any out-of-band hard purge of a blob.
    $table->foreign('blob_uuid')->references('uuid')->on('blobs')->onDelete('cascade');
});
```
> The cascade only fires on a HARD row delete of `blobs`; the soft-delete (`updateStatus('deleted')`) path does NOT — which is why Step 2's compensation *prefers* a hard delete via `BlobRepository::delete()` (checking the bool), falling back to a verified quarantine (`status='deleted'`) if the hard delete fails. `onBlobCreated` is write-free on every throw path, so there is never a stray ownership row to cascade — the FK is general defense-in-depth for normal hard purges.

- [ ] **Step 5: Thallo binds `TenantBlobPolicy`** — attribution + authorization, using raw `ON CONFLICT (blob_uuid) DO NOTHING` and a mismatch check (never transfer ownership):
```php
final class TenantBlobPolicy implements BlobCreatedHook, BlobAccessPolicy
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemFlags $flags,
        private readonly ?WriteBarrier $barrier = null, // mutation boundary (finding rev-11 P1 #5)
    ) {}

    public function onBlobCreated(string $blobUuid, ?string $uploaderUserUuid): void
    {
        if (!$this->flags->tenancyEnabled()) {
            return; // off → global blobs, no ownership rows
        }
        $tenant = $this->currentTenantUuid();
        if ($tenant === '') {
            // Enabled but no tenant resolved → THROW so the framework compensates (removes the blob).
            // Thrown BEFORE any write → no ownership row.
            throw new \RuntimeException("Cannot attribute blob {$blobUuid}: no tenant resolved.");
        }
        // Raw owned-table write → gate on the mutation boundary. In Task 10b this is the cheap pre-write
        // assertWritable() (rejects while the retrofit is active), MATCHING every other raw writer at this
        // point; Task 11c migrates it (with the others) to the race-closing runWritable(). Null barrier =
        // no-op gate. (Using runWritable() here would be a forward reference — it does not exist until 11c.)
        $this->barrier?->assertWritable();
        $pdo = db($this->context)->getPDO();
        // Atomic by construction (finding rev-12 P1 #2): ON CONFLICT DO NOTHING … RETURNING returns a row
        // ONLY when THIS statement inserted. If it inserted, the owner is us — return immediately, no second
        // read, no throw-after-write. Only the no-write (conflict) path falls through to a read-only check.
        $stmt = $pdo->prepare(
            'INSERT INTO media_assets (blob_uuid, tenant_uuid, created_at)
             VALUES (?, ?, CURRENT_TIMESTAMP) ON CONFLICT (blob_uuid) DO NOTHING
             RETURNING tenant_uuid'
        );
        $stmt->execute([$blobUuid, $tenant]);
        if ($stmt->fetchColumn() !== false) {
            return; // we inserted → owner is $tenant by construction; nothing more to verify
        }
        // Conflict: a row already existed and we wrote NOTHING. Read the existing owner (read-only) and
        // reject a foreign owner. This throw performs NO write, so every throwing path is write-free.
        $owner = (string) ($pdo->query(
            'SELECT tenant_uuid FROM media_assets WHERE blob_uuid = ' . $pdo->quote($blobUuid)
        )->fetchColumn() ?: '');
        if ($owner !== $tenant) {
            throw new \RuntimeException("Blob {$blobUuid} is already owned by another tenant.");
        }
    }

    public function authorizeAccess(array $blob, BlobAccessContext $ctx): bool
    {
        if (!$this->flags->tenancyEnabled()) {
            return true; // off → framework default behavior
        }
        // OWNERSHIP FIRST (finding rev-13 P1): resolve the owner BEFORE any public/signed shortcut. Under
        // tenancy, every valid blob has an owner (uploads attribute; the retrofit's MediaOwnershipBackfill
        // seeds pre-existing blobs — and it runs INSIDE confirm() BEFORE `tenancy.enabled=1` is written, so
        // tenancyEnabled() is never true while a legitimate blob is still ownerless → no false denials). An
        // OWNERLESS blob therefore only exists transiently after a failed compensation — deny it outright,
        // even if public or signed, so hard-delete AND quarantine failure still cannot expose it.
        $owner = (string) (db($this->context)->getPDO()->query(
            'SELECT tenant_uuid FROM media_assets WHERE blob_uuid = '
            . db($this->context)->getPDO()->quote((string) ($blob['uuid'] ?? ''))
        )->fetchColumn() ?: '');
        if ($owner === '') {
            return false; // no owner row → fail closed (covers ownerless/orphaned blobs)
        }
        // Owner exists → public content stays publicly servable (VIEW only).
        if ($ctx->action === BlobAction::VIEW && ($blob['visibility'] ?? null) === 'public') {
            return true;
        }
        // A framework-validated signed grant authorizes VIEWING this specific (owned) private blob, even with
        // no authenticated tenant context. Minting a signed URL, deleting, or reading metadata still requires
        // tenant ownership.
        if ($ctx->action === BlobAction::VIEW && $ctx->signatureValid) {
            return true;
        }
        $tenant = $this->currentTenantUuid();
        if ($tenant === '') {
            return false; // enabled but unresolved (full_resolution) → fail closed
        }
        return $owner === $tenant; // owner must be the current tenant
    }

    /**
     * Resolution order (finding P1 #1): the RESOLVED request tenant wins; fall back to the default ONLY
     * in bootstrap_default mode; under full_resolution an unresolved tenant fails closed (empty string).
     */
    private function currentTenantUuid(): string
    {
        $c = $this->context->getContainer();
        $resolved = $c->has(CurrentTenantResolver::class)
            ? app($this->context, CurrentTenantResolver::class)->tenantUuid($this->context)
            : '';
        if ($resolved !== '') {
            return $resolved;
        }
        $mode = app($this->context, TenantRuntimeReadiness::class)->mode($this->context);
        if ($mode === TenantRuntimeReadiness::MODE_BOOTSTRAP_DEFAULT) {
            return (string) ($this->flags->defaultTenantUuid() ?? ''); // single-tenant fallback
        }
        return ''; // full_resolution / none → no silent default
    }
}
```
> Raw `getPDO()` reads query `media_assets` by `blob_uuid` **without** the tenancy auto-injection narrowing it — the policy needs the *actual* owner to compare. Bind both `BlobCreatedHook` and `BlobAccessPolicy` to the same shared `TenantBlobPolicy`; the framework provides no default container binding.

**Boot-time fail-closed assertion (finding rev-14 P1).** `ThalloServiceProvider`'s own class documentation confirms all `services()` definitions are collected before provider `boot()` runs. After binding the policy in `services()`, call this near the start of `boot()`:
```php
private function assertBlobPolicyBoundWhenTenancyEnabled(ApplicationContext $context): void
{
    $flags = app($context, SystemFlags::class);
    if (!$flags->tenancyEnabled()) {
        return; // single-tenant/off installs keep the framework's permissive inline fallback
    }
    if (
        !$context->getContainer()->has(BlobCreatedHook::class)
        || !$context->getContainer()->get(BlobCreatedHook::class) instanceof TenantBlobPolicy
        || !$context->getContainer()->has(BlobAccessPolicy::class)
        || !$context->getContainer()->get(BlobAccessPolicy::class) instanceof TenantBlobPolicy
    ) {
        throw new \LogicException(
            'Tenancy is enabled but its blob lifecycle/access policies are not bound.'
        );
    }
}
```
This does **not** create competing/default bindings: Thallo remains the sole binder. It only refuses to boot an enabled runtime that would otherwise let the framework factory choose its generic null fallbacks. `BlobPolicyBootGuardTest` proves off+missing passes and on+missing throws. **SP2 note:** full multi-tenant resolution MUST include the framework `/v1/blobs` routes in its resolution surface, or `currentTenantUuid()` fails closed there. In SP1 the sole tenant is the default, so the fallback attributes/authorizes correctly; enforcement becomes load-bearing the instant SP2 admits tenant two — NOT deferred.

- [ ] **Step 6: Retrofit backfill — assign EVERY existing blob to the default tenant (findings P1 #4 + P2 #5)** — `media_meta`/`media_usage` rows backfill via the standard column backfill, but `media_assets` starts EMPTY, so existing blobs would vanish. Add a `MediaOwnershipBackfill` and wire it with the **verified** value + sequence: `SchemaRetrofit::run` obtains `$tenantUuid = $this->defaultTenant->ensure(...)` at `:68` (the `RetrofitReport` is only built at `:123`, so it is NOT available mid-loop — use `$tenantUuid`). The `media_assets.tenant_uuid` column is folded into the migration (Step 4), so seeded rows can carry it before `apply()` promotes NOT NULL:
```php
final class MediaOwnershipBackfill
{
    public function __construct(private readonly ApplicationContext $context) {}

    /** Seed one ownership row per pre-existing blob → the default tenant. Idempotent. */
    public function run(string $defaultTenantUuid): void
    {
        db($this->context)->getPDO()->prepare(
            'INSERT INTO media_assets (blob_uuid, tenant_uuid, created_at)
             SELECT b.uuid, ?, CURRENT_TIMESTAMP FROM blobs b
             ON CONFLICT (blob_uuid) DO NOTHING'
        )->execute([$defaultTenantUuid]);
    }
}
```
In `SchemaRetrofit`'s table loop, when `$meta['special_backfill'] === 'media_assets'`, run the seed BEFORE the additive apply, using the local `$tenantUuid` (exact sequence):
```php
// $tenantUuid = $this->defaultTenant->ensure(...) obtained earlier at line 68.
$this->mediaBackfill->run($tenantUuid);   // seed media_assets from blobs → default tenant
$this->additive->apply('media_assets');    // add/promote tenant_uuid NOT NULL (seeded rows satisfy it)
```
Inject `MediaOwnershipBackfill $mediaBackfill` into `SchemaRetrofit`. Idempotent/resumable: `ON CONFLICT DO NOTHING` + `apply()` already tolerates a pre-existing column. **Barrier-exempt (finding rev-11 P1 #5):** this runs *inside* `SchemaRetrofit::run`, which already holds the exclusive maintenance lock, so its raw `getPDO()` write MUST NOT wrap in `runWritable()` (that would deadlock against the retrofit's own exclusive hold) — it is classified `RETROFIT_ENGINE` in the Task 11c sweep, like `AdditiveRetrofit`/`TableRebuilder`.

- [ ] **Step 7: Scope `MediaAdminController` by ROOTING at `media_assets` (finding P1 #2)** — never `table('blobs')->join('media_assets')` (blobs is the primary → not scoped). Instead:
```php
// index(): root at the tenant-owned ownership table so the auto-injection hook scopes it, THEN join blobs.
$query = db($this->context)->table('media_assets')
    ->join('blobs', 'media_assets.blob_uuid', '=', 'blobs.uuid') // media_assets is PRIMARY → scoped
    ->select(['blobs.*']);
// show/update/destroy/optimize/usage($uuid): ownership check rooted at media_assets (auto-scoped).
$owned = db($this->context)->table('media_assets')->where('blob_uuid', '=', $uuid)->first();
if ($owned === null) { return $this->notFound(); } // cross-tenant uuid → no owned row → 404
```

- [ ] **Step 8: Tests — real HTTP upload, compensation, backfill, signed grant** (`MediaOwnershipTest`, two-boot, tenancy on):
  - **Attribution (P1 #1 + rev-14 P2):** drive the actual `POST /v1/blobs` (not a direct insert) → assert `onBlobCreated` received the exact `$result['blob_uuid']`, the exact authenticated UUID from `Utils::getUser()['uuid']`, and the blob got a `media_assets` row for the default tenant. An anonymous signed view passes `authenticatedUserUuid=null` without warnings.
  - **Compensation — no transaction, deterministic cleanup (findings rev-11 P1 #1–#4):**
    - *Ordinary upload errors pass through (P1 #3):* a `uploadMedia()` that throws `ValidationException`/`UploadException` returns the framework's existing 4xx/5xx — NOT "could not be attributed" (asserts the policy `catch` wraps only `onBlobCreated`).
    - *Policy `Exception` → 500, blob removed (P1 #4):* a policy that throws on `onBlobCreated`, upload → 500, and neither the `blobs` row nor any `media_assets` row remains, and the stored object is gone.
    - *Policy `Error` also compensates (P1 #2):* a policy that throws an `\Error` (e.g. `TypeError`) still returns 500 and removes the blob — proving the `\Throwable` catch, not `TransactionManager`'s `Exception`-only path.
    - *Hard-delete false → QUARANTINE, not just a log (finding rev-12 P1 #3):* stub `BlobRepository::delete()` → `false`; assert the blob is re-read and transitioned to `status='deleted'` (so `GET /v1/blobs/{uuid}` no longer serves it), the transition is verified, a `upload.compensation.blob_quarantined` `critical` record is emitted, and the response is 500.
    - *Storage-delete false is surfaced (finding rev-12 P2 #4):* stub the disk's `delete()` → `false`; assert a `upload.compensation.object_orphaned` `critical` record (not silently swallowed).
    - *Atomic-by-construction owner conflict (finding rev-12 P1 #2):* pre-seed a `media_assets` row owned by tenant B, then attribute the same blob as tenant A → `onBlobCreated` throws, and assert tenant B's ownership row is **unchanged** (the `RETURNING` path wrote nothing).
    - *No orphaned thumbnail — with the media processor ENABLED (P1 #1/#4):* attribution fails, and because the thumbnail is DEFERRED to after attribution, assert **no thumbnail object was ever written** (nothing to leak) — not that it was cleaned up.
    - *Deadlock does not duplicate storage (P1 #1):* there is no `Connection::transaction()` around the upload, so a deadlock cannot re-run `uploadMedia()`; assert the stored object is written exactly once (a regression guarding against reintroducing the transaction).
    - *Ownerless blob is denied even when public/signed (finding rev-13 P1):* directly seed a `blobs` row with NO `media_assets` owner — once `visibility='public'`, once `private` with a valid signed URL — and assert BOTH `GET /v1/blobs/{uuid}` return **404/denied** under tenancy-on. Combine with hard-delete-`false` + quarantine-`false` stubs and assert the same: even when both cleanup paths fail, `authorizeAccess` (ownership-first) never serves the ownerless blob.
    - *Deferred thumbnail is truly best-effort (finding rev-13 P2):* a media processor whose `generateThumbnail` throws a `\TypeError` after attribution → the upload still returns **201** with `thumb_url: null` and a `upload.thumbnail.deferred_failed` warning; the blob + `media_assets` row are intact (no duplicate-retry trap).
  - **Existing-blob backfill (P2 #5):** a blob created BEFORE enablement is owned by the default tenant after the retrofit and stays visible in `index()`.
  - **Authorization (P1 #2/#5):** a second seeded tenant's `index()` excludes tenant A's blob; `GET /v1/blobs/{uuid}/info` and `DELETE` for another tenant's private blob → 404; a **validated signed URL** can `view` that private (owned) blob; a public (owned) blob remains servable.
  - **Binding safety (rev-14 P1):** `BlobPolicyBootGuardTest` proves tenancy-off + no policy is allowed, tenancy-on + no policy throws during `ThalloServiceProvider::boot()`, and tenancy-on + `TenantBlobPolicy` bound boots normally.
  - `media_meta`/`media_usage` writes carry `tenant_uuid`. Run → PASS; phpcs clean (framework + Thallo).
- [ ] **Step 9: Commit** — Thallo: `feat(tenancy): media tenant ownership (media_assets) + blob access policy`. Framework (HELD): `feat(uploader): generic blob lifecycle and access seams` + CHANGELOG `[Unreleased]`.

---

### Task 11a: Framework execution-wrapper seam (finding #1 — the lock must span execute)

**Why:** `QueryInterceptorInterface` is **before-only** — `QueryExecutor::executeStatement()` calls `runInterceptors()` and returns *before* `$stmt->execute()` (verified: `src/Database/Execution/QueryExecutor.php:220` then prepare/execute at `:233,239`). A `before()` hook that `tryShared()`+`releaseShared()`s releases the shared lock **before the SQL runs**, so the retrofit's exclusive acquire would NOT wait for the mutation — zero quiescence. We need a seam that wraps the *actual* prepare/execute so a wrapper can hold a lock across it and release in `finally`. This is a general around-execution seam, useful beyond tenancy (tracing, retries, timeouts).

**Files (framework, held/uncommitted — pin at release):** Create `src/Database/Execution/ExecutionWrapperInterface.php`; Modify `src/Database/Execution/QueryExecutor.php`; Test `tests/Unit/Database/ExecutionWrapperTest.php`.

**Interfaces:** Produces `Glueful\Database\Execution\ExecutionWrapperInterface::around(string $sql, array $bindings, callable $proceed): PDOStatement`; `QueryExecutor::addExecutionWrapper(ExecutionWrapperInterface): void`, `clearExecutionWrappers(): void` (process-static, mirroring the interceptor registry).

- [ ] **Step 1: Failing test** — a wrapper observes the statement AND runs code after `$proceed()` (proving it spans execution):

```php
public function testExecutionWrapperSpansExecute(): void
{
    $order = [];
    QueryExecutor::clearExecutionWrappers();
    QueryExecutor::addExecutionWrapper(new class ($order) implements ExecutionWrapperInterface {
        public function __construct(public array &$order) {}
        public function around(string $sql, array $bindings, callable $proceed): \PDOStatement
        {
            $this->order[] = 'before';
            try {
                return $proceed();
            } finally {
                $this->order[] = 'after'; // MUST run after the statement executed
            }
        }
    });
    $executor = $this->makeExecutor(); // in-memory sqlite PDO for the unit
    $executor->executeStatement('SELECT 1');
    self::assertSame(['before', 'after'], $order);
    QueryExecutor::clearExecutionWrappers();
}
```

- [ ] **Step 2: Run → FAIL** (`addExecutionWrapper` undefined).
- [ ] **Step 3: `ExecutionWrapperInterface`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\Execution;

use PDOStatement;

/**
 * Around-execution seam. Unlike QueryInterceptorInterface (before-only), an execution wrapper straddles
 * the actual prepare+execute: it receives a $proceed callable that performs the statement and returns the
 * PDOStatement, so a wrapper can hold a resource (e.g. an advisory lock) across execution and release it
 * in a finally. Registered once at boot; all wrappers compose (first-registered outermost).
 */
interface ExecutionWrapperInterface
{
    /**
     * @param array<int|string, mixed> $bindings
     * @param callable():PDOStatement  $proceed Runs the statement; returns the executed PDOStatement.
     */
    public function around(string $sql, array $bindings, callable $proceed): PDOStatement;
}
```

- [ ] **Step 4: Wire into `QueryExecutor`** — add the registry + compose the wrappers around the existing prepare/execute core in `executeStatement()`:

```php
/** @var array<int, ExecutionWrapperInterface> */
private static array $executionWrappers = [];

public static function addExecutionWrapper(ExecutionWrapperInterface $wrapper): void
{
    self::$executionWrappers[] = $wrapper;
}

public static function clearExecutionWrappers(): void
{
    self::$executionWrappers = [];
}
```

Refactor `executeStatement()`: keep `runInterceptors()` first (before-only pre-checks stay), extract the prepare/execute/log block into a `$core` closure returning `PDOStatement`, then run it through the wrapper chain:

```php
public function executeStatement(string $sql, array $bindings = []): PDOStatement
{
    $this->runInterceptors($sql, $bindings);

    $timerId = $this->logger->startTiming($this->debugMode ? 'query_with_debug' : 'query');
    $purpose = $this->queryPurpose;
    $this->queryPurpose = null;
    $flattenedParams = $this->binder->flattenBindings($bindings);

    $core = function () use ($sql, $flattenedParams, $timerId, $purpose): PDOStatement {
        try {
            $stmt = $this->pdo->prepare($sql);
            if (!$stmt) {
                throw new PDOException('Failed to prepare statement');
            }
            $stmt->execute($flattenedParams);
            $sanitizedBindings = $this->binder->sanitizeBindingsForLog($flattenedParams);
            $this->logger->logQuery($sql, $sanitizedBindings, $timerId, null, $purpose);
            return $stmt;
        } catch (PDOException $e) {
            $sanitizedBindings = $this->binder->sanitizeBindingsForLog($flattenedParams);
            $this->logger->logQuery($sql, $sanitizedBindings, $timerId, $e, $purpose);
            throw $e;
        }
    };

    if (self::$executionWrappers === []) {
        return $core();
    }
    $chain = $core;
    foreach (array_reverse(self::$executionWrappers) as $wrapper) {
        $next = $chain;
        $chain = static fn (): PDOStatement => $wrapper->around($sql, $bindings, $next);
    }
    return $chain();
}
```

- [ ] **Step 5: Reset the new static registry in ALL two-boot cleanup (finding #5)** — `$executionWrappers` is a NEW process-static that accumulates across boots exactly like the interceptor/hook registries. Add `QueryExecutor::clearExecutionWrappers()` everywhere those are already cleared, or a second boot keeps boot-one's wrapper (bound to a dropped connection) AND registers another:
  - **Thallo harness** `tests/Support/RetrofitHarnessTestCase.php` `resetTenancyGlobals()` (currently clears `clearInsertHooks`/`clearTableHooks`/`clearQueryInterceptors` at `:37`) — add `QueryExecutor::clearExecutionWrappers();`. It is invoked before every boot AND in teardown.
  - **Framework tests** — the static-registry resets live in **per-test `setUp()`**, not a shared bootstrap (verified: `QueryInterceptorTest.php:16`, `InsertHookTest.php:14`, `ConnectionTableHookTest.php:14`). The new `ExecutionWrapperTest::setUp()` resets `clearExecutionWrappers()` the same way (held framework change).
  - Add a harness assertion (or a comment) that after `resetTenancyGlobals()` the wrapper registry is empty.
- [ ] **Step 6: Run → PASS.** Framework `composer phpcs` clean; run `tests/Unit/Database/` to confirm no execution-path regressions.
- [ ] **Step 7: Commit (framework repo, HELD)** — `feat(database): around-execution wrapper seam for QueryExecutor`. Add to framework CHANGELOG `[Unreleased]`. (Harness reset lands with Task 11b's pack commit.)

---

### Task 11b: MutationBoundaryLock + quiescence wrapper (finding #1/#6 — real drain)

**Files:** Create `Retrofit/MutationBoundaryLock.php`, `Retrofit/MutationQuiescenceWrapper.php`; Modify `TenancyServiceProvider` (register the wrapper at boot); Modify `SchemaRetrofit` (exclusive acquire around DDL); Test `tests/Integration/Tenancy/MutationQuiescenceTest.php`.

**Protocol (pins real quiescence, not just blocking):** the retrofit DDL window is guarded by a Postgres advisory lock shared by every owned-table mutation boundary and acquired **exclusively** by the retrofit:
- The retrofit (inside `SchemaRetrofit::run`, already barrier-guarded) acquires `pg_advisory_lock(MUTATION_KEY)` **exclusively** for the DDL span from a **dedicated maintenance PDO**. Acquiring it exclusively **waits for in-flight shared holders to release** — that is the drain.
- The `MutationQuiescenceWrapper` (an `ExecutionWrapperInterface` from Task 11a), for an owned-table INSERT/UPDATE/DELETE, `tryShared()`s **and holds the shared lock across `$proceed()`** (the real execute), releasing in `finally`. If it cannot get the shared lock (retrofit holds it exclusively) it throws `RetrofitInProgressException` (fail-closed) **before** executing. Because the shared lock is now held *through* execute, the retrofit's exclusive acquire genuinely blocks until in-flight owned-table writes finish. DB-global, so it also closes the in-memory `active()` staleness race.
- The cheap in-memory `RetrofitWriteBarrierInterceptor` (`before()`, `active()` fast-path) **stays** as a first-line reject; the wrapper is the authoritative drain. Both key off the same owned-table match.

**Interfaces:** `MutationBoundaryLock::acquireExclusive(): void`, `releaseExclusive(): void`, `tryShared(): bool`, `releaseShared(): void` (key `4823711`). `MutationQuiescenceWrapper implements ExecutionWrapperInterface`.

- [ ] **Step 1: Failing test** — a worker that would pass the in-memory check is rejected while the retrofit holds the exclusive lock from a separate session:

```php
public function testOwnedTableWriteIsRejectedWhileRetrofitHoldsExclusiveLock(): void
{
    // Exclusive hold on a DEDICATED maintenance PDO (advisory locks are session-re-entrant, so the
    // exclusive acquire must be a DIFFERENT session than the app connection under test).
    $maint = self::$engineApp->getContainer()->get(\Thallo\Tenancy\Retrofit\MutationBoundaryLock::class);
    $maint->acquireExclusive(); // maintenance session holds it exclusively
    try {
        $this->expectException(\Thallo\Tenancy\Retrofit\RetrofitInProgressException::class);
        self::$engineApp->getContainer()->get(\Glueful\Database\Connection::class)
            ->table('content_types')->insert(['uuid' => 'm00000000001', 'slug' => 'x', 'name' => 'X']);
    } finally {
        $maint->releaseExclusive();
    }
}
```
> `MutationBoundaryLock` opens its OWN PDO (a second connection to the same DB) for the exclusive hold, so the wrapper's `tryShared()` on the app connection genuinely contends. Document this in the test and in the class.

- [ ] **Step 2: Run → FAIL** (wrapper not registered yet).
- [ ] **Step 3 (framework, HELD): add the `Connection::newPdo()` seam** — a public `newPdo(): PDO` returning `$this->createPDOConnection($this->getDriverName())` (fresh independent session). Verified: `createPDOConnection(string $engine): PDO` is private and side-effect-free (`Connection.php:265` — unconditional `new PDO()`, never writes `$this->pdo`/`self::$instances`), and `getDriverName()` returns the same engine key the constructor used, so this compiles and yields a genuinely distinct session. **Docblock the seam:** unlike `getPDO()` (which returns a *pooled* PDO when pooling is on), `newPdo()` mints a **non-pooled, independent** connection that lives until GC — which is exactly the property the participant/maintenance lock sessions need (dedicated, never recycled). Add a framework unit test asserting `newPdo()` returns a distinct `PDO` instance from `getPDO()`. Framework `composer phpcs` clean; commit (held) `feat(database): Connection::newPdo() for an independent session` + CHANGELOG `[Unreleased]`.
- [ ] **Step 4: Implement `MutationBoundaryLock`** (dedicated maintenance PDO for exclusivity)

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Connection;
use PDO;

/**
 * Postgres advisory lock shared by every owned-table mutation boundary (held across execute by
 * MutationQuiescenceWrapper + around raw writes by WriteBarrier::runWritable) and acquired exclusively by
 * the enable-time retrofit for its DDL window. Shared/exclusive semantics give DB-global quiescence the
 * in-memory RetrofitMaintenanceGuard::active() flag cannot (a worker booted before begin() has a stale
 * flag; this lock is authoritative). The exclusive acquire blocks on in-flight shared holders — the drain.
 *
 * BOTH locks use DEDICATED sessions, NOT the application statement PDO (finding #2):
 *   - the SHARED lock is held on a lazy PARTICIPANT PDO, so if a mutation's transaction aborts on the app
 *     session, the participant session stays alive and releaseShared() still succeeds (a shared unlock on
 *     the app PDO could hit an aborted transaction, throw in finally, mask the real error, and leak the
 *     lock until disconnect);
 *   - the EXCLUSIVE lock is held on a lazy MAINTENANCE PDO (a third session), so exclusive-vs-shared
 *     contention is real across distinct sessions (advisory locks are re-entrant WITHIN a session).
 */
final class MutationBoundaryLock
{
    private const KEY = 4823711;

    private ?PDO $participantPdo = null;
    private ?PDO $maintenancePdo = null;

    public function __construct(private readonly Connection $db)
    {
    }

    public function acquireExclusive(): void
    {
        $this->maintenance()->exec('SELECT pg_advisory_lock(' . self::KEY . ')');
    }

    public function releaseExclusive(): void
    {
        $this->maintenance()->exec('SELECT pg_advisory_unlock(' . self::KEY . ')');
    }

    public function tryShared(): bool
    {
        // Participant session (NOT the app statement PDO) — survives an aborted mutation transaction.
        $got = $this->participant()->query('SELECT pg_try_advisory_lock_shared(' . self::KEY . ')')->fetchColumn();
        return $got === true || $got === '1' || $got === 1 || $got === 't';
    }

    public function releaseShared(): void
    {
        $this->participant()->exec('SELECT pg_advisory_unlock_shared(' . self::KEY . ')');
    }

    private function participant(): PDO
    {
        return $this->participantPdo ??= $this->db->newPdo();
    }

    private function maintenance(): PDO
    {
        return $this->maintenancePdo ??= $this->db->newPdo();
    }
}
```
> **Verified:** `Connection` exposes only `getPDO()` (the shared session) and a **private** `createPDOConnection(string $engine): PDO`; there is no public way to open a second session. Add a tiny **held** framework seam `Connection::newPdo(): PDO` that returns `$this->createPDOConnection($this->getDriverName())` (a fresh, independent session). The participant and maintenance PDOs are **distinct** `newPdo()` sessions. All lock ops use raw PDO (never `QueryExecutor`), so they can't re-enter the wrapper.

- [ ] **Step 4b: Regression — a failed mutation transaction still releases the shared boundary**
```php
public function testFailedMutationTransactionStillReleasesSharedBoundary(): void
{
    $c = self::$engineApp->getContainer();
    $pdo = $c->get(\Glueful\Database\Connection::class)->getPDO();
    $pdo->beginTransaction();

    // Manual catch (NOT expectException) so the assertions AFTER the failure actually run (finding P2 #5).
    $threw = false;
    try {
        // A shared holder enters via the wrapper, then the statement fails inside the open transaction.
        $c->get(\Glueful\Database\Connection::class)
            ->table('content_types')->insert(['uuid' => 'dup', 'nonexistent_col' => 'x']); // SQL error
    } catch (\PDOException) {
        $threw = true;
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    self::assertTrue($threw, 'the mutation must have failed inside the transaction');

    // The participant session is unaffected by the app session's aborted transaction: a subsequent
    // exclusive acquire (maintenance session) must succeed — proving the shared lock was released.
    $lock = $c->get(\Thallo\Tenancy\Retrofit\MutationBoundaryLock::class);
    $lock->acquireExclusive();
    $lock->releaseExclusive();
    $this->addToAssertionCount(1);
}
```

- [ ] **Step 5: Implement `MutationQuiescenceWrapper`** (holds the shared lock across execute)

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Retrofit;

use Glueful\Database\Execution\ExecutionWrapperInterface;
use PDOStatement;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * Around-execution wrapper: for an owned-table INSERT/UPDATE/DELETE it takes the shared mutation lock and
 * HOLDS it across the real execute ($proceed), releasing in finally. Failing to take it (retrofit holds
 * the key exclusively) fails closed BEFORE the statement runs. Holding across execute is what makes the
 * retrofit's exclusive acquire a genuine drain. Non-owned statements and SELECTs pass straight through.
 */
final class MutationQuiescenceWrapper implements ExecutionWrapperInterface
{
    /** @var list<string>|null */
    private static ?array $owned = null;

    public function __construct(private readonly MutationBoundaryLock $lock)
    {
    }

    public function around(string $sql, array $bindings, callable $proceed): PDOStatement
    {
        if (!$this->isOwnedMutation($sql)) {
            return $proceed();
        }
        if (!$this->lock->tryShared()) {
            throw new RetrofitInProgressException('A tenancy schema change is in progress (mutation barrier).');
        }
        try {
            return $proceed(); // shared lock HELD across the actual execute
        } finally {
            $this->lock->releaseShared();
        }
    }

    private function isOwnedMutation(string $sql): bool
    {
        $lower = strtolower(ltrim($sql));
        if (
            !str_starts_with($lower, 'insert')
            && !str_starts_with($lower, 'update')
            && !str_starts_with($lower, 'delete')
        ) {
            return false;
        }
        $padded = ' ' . $lower . ' ';
        foreach (self::owned() as $table) {
            if (preg_match('/[\s"`\']' . preg_quote($table, '/') . '[\s"`\'(]/', $padded) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function owned(): array
    {
        return self::$owned ??= array_map('strtolower', ThalloTenantTables::tableNames());
    }
}
```

- [ ] **Step 6: Register the wrapper at boot** — in `TenancyServiceProvider::boot()`, after the existing `addQueryInterceptor(RetrofitWriteBarrierInterceptor)` registration, add:
```php
QueryExecutor::addExecutionWrapper($context->getContainer()->get(MutationQuiescenceWrapper::class));
```
Register `MutationBoundaryLock` and `MutationQuiescenceWrapper` in `services()` (autowire+shared). **Keep** the in-memory `RetrofitWriteBarrierInterceptor` as-is (first-line reject).

- [ ] **Step 7: Wire the exclusive acquire into `SchemaRetrofit::run`** — around the DDL span (after `guard->begin()`, before the additive/rebuild steps): `$this->mutationLock->acquireExclusive();` … `finally { $this->mutationLock->releaseExclusive(); }`. Inject `MutationBoundaryLock`.

- [ ] **Step 8: Drain test (finding #6 — exclusive WAITS for an in-flight shared holder, not just rejection)** — three distinct sessions prove the drain directly; a single thread can't block, so use the try-variant of the exclusive acquire as the observable:
```php
public function testExclusiveAcquireIsBlockedWhileASharedHolderIsInFlightThenSucceeds(): void
{
    $conn = self::$engineApp->getContainer()->get(\Glueful\Database\Connection::class);
    $participant = $conn->newPdo();   // session S — the in-flight owned-table writer's shared hold
    $contender   = $conn->newPdo();   // session X — the retrofit's exclusive acquirer

    // S enters the mutation boundary (shared held).
    self::assertTrue((bool) $participant->query('SELECT pg_try_advisory_lock_shared(4823711)')->fetchColumn());

    // X CANNOT get the exclusive lock while S holds shared — the exclusive acquire would BLOCK (drain).
    self::assertFalse((bool) $contender->query('SELECT pg_try_advisory_lock(4823711)')->fetchColumn());

    // S releases (the write finished) → X's exclusive acquire now succeeds: the drain completed.
    $participant->exec('SELECT pg_advisory_unlock_shared(4823711)');
    self::assertTrue((bool) $contender->query('SELECT pg_try_advisory_lock(4823711)')->fetchColumn());
    $contender->exec('SELECT pg_advisory_unlock(4823711)');
}
```
> `pg_try_advisory_lock` returning false is exactly the condition under which the real `pg_advisory_lock` (blocking) would wait — so this proves the exclusive acquire drains in-flight shared holders, not merely that a checked writer is rejected (Step 1).

- [ ] **Step 9: Run → PASS.** Full `tenancy-retrofit` suite green. phpcs clean.
- [ ] **Step 10: Commit** — `feat(tenancy): mutation-boundary lock held across execute via wrapper seam`.

---

### Task 11c: Raw-PDO mutation boundary — `WriteBarrier::runWritable()` (finding #1)

**Why:** the execution wrapper (11a/11b) only covers `QueryExecutor` (builder) statements. The **raw-PDO** owned-table writers call `$this->barrier?->assertWritable()` and THEN run a raw mutation — the original check-to-write race the shared lock was meant to close. Verified raw writers (from the existing B2 lint `tests/Unit/Tenancy/RawPdoScopingLintTest.php`): `SeoMetaRepository`, `MenuRepository`, `AnalyticsRecorder`, `WorkflowStateRepository`, `BlockMigrationRepository`, `MigrationRepository`, `ScheduleRepository` (`:141,203,228`), `VersionPruner` (`:143`), `EnsureFilterIndexesJob` (`:135,176,243,263,298`). Each must **hold the shared advisory lock around the actual PDO operation**, not merely assert once. The retrofit-ENGINE writers (`AdditiveRetrofit`, `TableRebuilder`, provider DDL) stay barrier-EXEMPT (they ARE the retrofit).

**Files:** Modify `packages/thallo-contracts/src/Tenancy/WriteBarrier.php`; Modify `packages/thallo-tenancy/src/Retrofit/RetrofitMaintenanceGuard.php`; migrate the raw-writer sites listed above; Modify `tests/Unit/Tenancy/RawPdoScopingLintTest.php`; Test `tests/Integration/Tenancy/Retrofit/RawWriteBoundaryTest.php`.

**Interfaces:** Adds `WriteBarrier::runWritable(callable $fn): mixed` — asserts writable, then holds the shared `MutationBoundaryLock` across `$fn()` (fail-closed if the retrofit holds it exclusively). `assertWritable()` stays (cheap first-line reject; some non-mutating gates still use it).

- [ ] **Step 1: Tighten the B2 lint FIRST (RED) — MUTATION-LEVEL, not file-level (finding P2 #7)** — a single `runWritable(` anywhere in a file is NOT enough (a second raw mutation could stay unwrapped). Pin the **expected wrapper count per file** to the number of raw mutation sites, require **each site is inside a `runWritable(`** (no bare `assertWritable(); <mutation>` remains), and keep the unclassified-`getPDO()` sweep:
```php
/**
 * file => number of DISTINCT raw owned-table mutation sites that MUST each be wrapped in runWritable().
 * Counts are the verified assert-then-write sites (grep). A NEW raw mutation must bump the count here,
 * forcing a conscious wrap — a single runWritable() no longer satisfies a multi-mutation file.
 */
private const RUNWRITABLE_SITES = [
    'packages/thallo-seo/src/Meta/SeoMetaRepository.php' => 1,
    'packages/thallo-navigation/src/MenuRepository.php' => 3,
    'packages/thallo-analytics/src/Facts/AnalyticsRecorder.php' => 2,
    'packages/thallo-workflow/src/WorkflowStateRepository.php' => 1,
    'app/Content/Blocks/Migration/BlockMigrationRepository.php' => 1,
    'app/Content/Repositories/MigrationRepository.php' => 1,
    'app/Content/Repositories/ScheduleRepository.php' => 3,
    'app/Content/Retention/VersionPruner.php' => 1,
    'app/Content/Indexing/EnsureFilterIndexesJob.php' => 5, // 5 boundary gates: 2 raw CONCURRENTLY index DDL (:176,:243) + 3 builder writes on the deliberately-GLOBAL filter_indexes registry (:135,:263,:298) — all gated because the interceptor skips non-owned tables; all 5 convert to runWritable()
    'app/Content/Media/TenantBlobPolicy.php' => 1,   // onBlobCreated raw INSERT (finding rev-11 P1 #5)
];

public function testEveryRawMutationSiteIsWrappedInRunWritable(): void
{
    foreach (self::RUNWRITABLE_SITES as $rel => $expected) {
        $body = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
        self::assertSame(
            $expected,
            substr_count($body, 'runWritable('),
            "$rel: expected $expected runWritable() wrapper(s) — one per raw owned-table mutation. "
            . 'A mismatch means a mutation is unwrapped (or the site count moved; update RUNWRITABLE_SITES).',
        );
        // No bare pre-write gate may remain in a wrapped writer: assertWritable() belongs only INSIDE
        // runWritable() now, so a standalone `->assertWritable();` line followed by a raw op is a smell.
        self::assertStringNotContainsString(
            "?->assertWritable();\n",
            $body,
            "$rel: replace bare `assertWritable();` pre-write gates with runWritable() wrappers.",
        );
    }
}
```
> `substr_count` is a coarse but effective pin: it fails if a mutation is added without a wrapper (count too low) or a wrapper removed (too low), and the `assertWritable();`-line check catches the specific bare-gate regression. The existing `testNoUnclassifiedGetPdoSites()` sweep is **retained**. **Migration of `TenantBlobPolicy` (finding rev-12 P1 #1):** Task 10b already classified `app/Content/Media/TenantBlobPolicy.php` as `SCOPED` and shipped it using the pre-existing `assertWritable()` gate; 11c now migrates that gate to `runWritable()` (so its ownership INSERT holds the shared lock around the op), adds it to `RUNWRITABLE_SITES` (`=> 1`), and updates its `REQUIRED_FRAGMENTS` proof from `assertWritable(` to `runWritable(`. `MediaOwnershipBackfill` stays `RETROFIT_ENGINE` (barrier-EXEMPT — it runs *inside* `SchemaRetrofit::run` under the exclusive maintenance lock; wrapping it in `runWritable()` would self-block against the retrofit's own exclusive hold).

- [ ] **Step 2: Run → FAIL** (writers still use bare `assertWritable()`; counts are 0).
- [ ] **Step 3: Extend the `WriteBarrier` contract**
```php
interface WriteBarrier
{
    public function assertWritable(): void;

    /**
     * Run a raw-PDO owned-table mutation while HOLDING the shared mutation boundary across the actual
     * write, closing the assert-then-write race. Fails closed if the retrofit holds the boundary
     * exclusively. Builder writes are covered automatically by the execution wrapper; this is for the
     * raw-PDO sites that bypass QueryExecutor.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function runWritable(callable $fn): mixed;
}
```

- [ ] **Step 4: Implement in `RetrofitMaintenanceGuard`** (inject `MutationBoundaryLock`)
```php
public function __construct(
    private readonly SystemFlags $flags,
    private readonly MutationBoundaryLock $lock,
) {
}

public function runWritable(callable $fn): mixed
{
    $this->assertWritable();                 // fresh persisted reject (fast path)
    if (!$this->lock->tryShared()) {
        throw new RetrofitInProgressException(); // retrofit grabbed exclusive between assert and here
    }
    try {
        return $fn();                        // shared lock HELD across the raw mutation
    } finally {
        $this->lock->releaseShared();
    }
}
```
> `MutationBoundaryLock`'s shared lock is on its own participant session (Task 11b), so a raw write that aborts its transaction does not corrupt the lock session.

- [ ] **Step 5: Migrate each raw-writer site** — wrap the actual mutation, null-safe when the barrier is absent. Representative conversions:

`VersionPruner::deleteGuarded()` (`:143`):
```php
foreach (array_chunk($uuids, self::DELETE_BATCH) as $chunk) {
    $run = function () use ($chunk): int {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $this->db->getPDO()->prepare(
            "DELETE FROM entry_versions WHERE uuid IN ({$placeholders}) AND NOT EXISTS (...)"
        );
        $stmt->execute($chunk);
        return $stmt->rowCount();
    };
    $deleted += (int) ($this->barrier !== null ? $this->barrier->runWritable($run) : $run());
}
```
`EnsureFilterIndexesJob::dropIndex()` (`:243`):
```php
$run = fn () => $db->getPDO()->exec(sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $name));
$barrier !== null ? $barrier->runWritable($run) : $run();
```
Apply the same shape to every site currently doing `assertWritable(); <raw mutation>` in `ScheduleRepository` (`:141,203,228`), `SeoMetaRepository`, `MenuRepository`, `AnalyticsRecorder`, `WorkflowStateRepository`, `BlockMigrationRepository`, `MigrationRepository`, the remaining `EnsureFilterIndexesJob` sites (`:135,176,263,298`), **and `TenantBlobPolicy::onBlobCreated`** (from Task 10b — migrate its `assertWritable()` gate to a `runWritable()` wrapper around the `INSERT … ON CONFLICT … RETURNING` + conditional owner-read). **Do NOT** touch the `RETROFIT_ENGINE` set (barrier-exempt), which now includes `MediaOwnershipBackfill`.
> `CONCURRENTLY` DDL cannot run inside a transaction — that's fine: `runWritable` holds the shared lock via the participant session, not a transaction on the app session.

- [ ] **Step 6: Boundary regression test** — a raw writer is rejected while the retrofit holds the exclusive lock (parallels Task 11b Step 1 but through `runWritable`):
```php
public function testRawWriterIsRejectedWhileRetrofitHoldsExclusiveLock(): void
{
    $c = self::$engineApp->getContainer();
    $c->get(MutationBoundaryLock::class)->acquireExclusive();
    try {
        $this->expectException(RetrofitInProgressException::class);
        $c->get(\App\Content\Retention\VersionPruner::class)->deleteGuarded(['v0000000001']);
    } finally {
        $c->get(MutationBoundaryLock::class)->releaseExclusive();
    }
}
```

- [ ] **Step 7: Run → PASS** (lint + boundary regression + full `tenancy-retrofit` suite). phpcs clean. Update the contract's other implementers (test doubles/null barrier) to add `runWritable`.
- [ ] **Step 8: Commit** — `feat(tenancy): raw-PDO writers hold the mutation boundary (runWritable)`.

---

### Task 12: BootstrapDefaultTenantMiddleware + BootstrapTenantCreationGuard + collections fence

**Files:** Create `Runtime/BootstrapDefaultTenantMiddleware.php`, `Runtime/BootstrapTenantCreationGuard.php`, `Runtime/CollectionsDisabledWhenTenantMiddleware.php`, `Runtime/TenantSystemMiddleware.php`; Modify EVERY Thallo route file per the Step 6 inventory + both collections route files (Step 6b) + `routes/admin.php` system segments + `routes/admin_spa.php` + Task 19 enablement routes (markers); Modify `TenancyServiceProvider` (3 aliases + services). Tests `tests/Integration/Tenancy/BootstrapResolutionTest.php` (two-boot), `tests/Integration/Tenancy/RouteCoverageTest.php`, `tests/Integration/Tenancy/CollectionsFenceTest.php`.

**Fix (finding #2 — construct-safe while off):** the middleware is applied to tenant-data routes **unconditionally** (the alias is always on those groups), so it is constructed on every such request **even when tenancy is off and `glueful/tenancy` is not installed**. Its extension-provided deps (`TenantContextRunner`, `CurrentTenantResolver`) are **NOT bound** until the extension boots. Therefore they MUST be **nullable + soft-resolved** — a non-null autowired dep would make the container throw at construction and 500 every tenant-data route while off. `TenantRuntimeReadiness` and `SystemFlags` are pack-owned (always bound), so they stay non-null.

**Interfaces:** Consumes `TenantRuntimeReadiness` (non-null, pack), `SystemFlags` (non-null, pack), and **nullable** `TenantContextRunner`/`CurrentTenantResolver` (extension). Produces a middleware that, on a tenant-data route with `tenancy.enabled=1` and no resolved tenant: if `readiness->mode() === MODE_BOOTSTRAP_DEFAULT` wraps `$next` in `runAsTenant(default)`, else **503**. `BootstrapTenantCreationGuard::assertCanCreateTenant()` throws while mode is bootstrap_default and a tenant already exists.

- [ ] **Step 1: Failing test**

```php
public function testBootstrapWrapsRequestInDefaultTenant(): void
{
    // After enable, one tenant + pointer exist; a tenant-data read under NO explicit tenant must
    // resolve to the default (segment non-empty), not throw.
    $seg = $this->container()->get(\Thallo\Tenancy\Cache\TenantCacheSegment::class);
    // Simulate a bare request path via the middleware: it should scope to the default tenant.
    $mw = $this->container()->get(\Thallo\Tenancy\Runtime\BootstrapDefaultTenantMiddleware::class);
    $out = $mw->handle($this->jsonRequest('GET', '/'), fn () => $seg->segment($this->appContext(), 'render'));
    self::assertStringStartsWith('tenant:', (string) $out);
}
```
> Adapt to the framework `RouteMiddleware::handle(Request,callable,...$params): Response` shape — the callable returns a Response; assert via a Response whose body carries the segment, or assert the runner scoped by checking `CurrentTenantResolver::tenantUuid()` inside `$next`.

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement the middleware**

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Runtime;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\{Request, Response};
use Thallo\Tenancy\System\SystemFlags;

/**
 * SP1 bootstrap request resolution. Applied ONLY to tenant-data route groups (never system/enablement/
 * setup). When tenancy is on and no tenant is already resolved, it wraps the ENTIRE downstream request
 * (including cache lookups) in runAsTenant(defaultUuid) so every read/write and cache key is scoped to
 * the single default tenant. If bootstrap invariants do not hold (mode != bootstrap_default → zero/many
 * tenants, missing pointer, missing runner), it fails closed with 503. SP2's full-resolution mode makes
 * this a no-op (mode != bootstrap_default AND a tenant is already resolved upstream).
 *
 * Runner/resolver are NULLABLE: this middleware sits on tenant-data routes unconditionally and is
 * constructed even when tenancy is off and glueful/tenancy is NOT installed (its contracts unbound).
 * When off it short-circuits before touching them; when on-but-unbound it fails closed (503).
 */
final class BootstrapDefaultTenantMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemFlags $flags,
        private readonly TenantRuntimeReadiness $readiness,
        private readonly ?CurrentTenantResolver $resolver = null,
        private readonly ?TenantContextRunner $runner = null,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): Response
    {
        if (!$this->flags->tenancyEnabled()) {
            return $next($request);                                   // off → untouched (deps may be null)
        }
        if ($this->resolver !== null && $this->resolver->tenantUuid($this->context) !== '') {
            return $next($request);                                   // already resolved (SP2) → untouched
        }
        // enabled + not resolved: need bootstrap mode AND a runner to scope with, else fail closed.
        if (
            $this->runner === null
            || $this->readiness->mode($this->context) !== TenantRuntimeReadiness::MODE_BOOTSTRAP_DEFAULT
        ) {
            return new Response('Tenant resolution unavailable.', 503); // invariant 7: fail closed
        }
        $default = (string) $this->flags->defaultTenantUuid();
        return $this->runner->runAsTenant($default, static fn (): Response => $next($request));
    }
}
```
> Register with a factory that soft-resolves the two extension contracts (`$c->has(...) ? $c->get(...) : null`) — a plain `autowire` would hard-require them and 500 while off. The `services()` entry MUST use `['factory' => [self::class, 'makeBootstrapMiddleware'], 'shared' => true]`.

- [ ] **Step 4: Creation guard**

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Runtime;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Thallo\Tenancy\Enablement\EnablementException;

/**
 * Enforces bootstrap invariant 8: while request resolution is bootstrap_default (single-tenant), a
 * SECOND tenant must not be created — that would make the bootstrap scoping ambiguous and unsafe. SP2
 * flips readiness to full_resolution BEFORE allowing tenant two, at which point this guard is inert.
 * Any tenant-creation seam (SP2 admin flow, tenant:create) MUST call assertCanCreateTenant() first.
 */
final class BootstrapTenantCreationGuard
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TenantRuntimeReadiness $readiness,
    ) {
    }

    public function assertCanCreateTenant(): void
    {
        if ($this->readiness->mode($this->context) === TenantRuntimeReadiness::MODE_BOOTSTRAP_DEFAULT) {
            throw new EnablementException(
                'A second tenant cannot be created while single-tenant bootstrap resolution is active. '
                . 'Enable full multi-tenant resolution (SP2) first.'
            );
        }
    }
}
```

- [ ] **Step 5: Register the middleware aliases + creation guard + the `tenant_system` marker**

Provider `services()`: register `BootstrapDefaultTenantMiddleware` via the soft-resolve factory (Step 3 note) with `'alias' => ['tenant_bootstrap']`, and `BootstrapTenantCreationGuard` (autowire+shared).

Also register a **no-op classification marker** so every Thallo-owned route carries exactly one explicit tenancy marker (`tenant_bootstrap` | `tenant_system` | `collections_disabled_when_tenant`). `tenant_system` does nothing at runtime — it exists only so the coverage test (Step 7) can PROVE a route was consciously classified as *not* tenant-scoped, rather than accidentally missing `tenant_bootstrap`:
```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Runtime;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\{Request, Response};

/**
 * Classification marker only — a no-op passthrough. Tags a Thallo-owned route as system/global (users,
 * api-keys, health, cache, capabilities, extensions, icons, scheduled-tasks, enablement) so the
 * RouteCoverageTest can require EVERY Thallo route to carry exactly one tenancy marker. Carries no
 * runtime behavior; safe to construct anytime (no deps).
 */
final class TenantSystemMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): Response
    {
        return $next($request);
    }
}
```
Register with `'alias' => ['tenant_system']`.

- [ ] **Step 6: Apply `tenant_bootstrap` per the VERIFIED route inventory — ORDER MATTERS (finding #3)**

**Ordering rule (load-bearing).** The router builds the pipeline inside-out (`Router::executeWithMiddleware`), so **the FIRST middleware in the effective stack is the OUTERMOST** (runs first). Group middleware is applied at route construction (`Router.php:118`) and route-level `->middleware()` **appends** (`Route::middleware` = `array_merge`), so the final stack is `[...group, ...route]`. `tenant_bootstrap` MUST be **outermost** so tenant resolution wraps everything downstream — critically **`RenderPageCache`**, or a bare request would read the cache before the tenant is scoped. Therefore:
- **Group routes:** put `tenant_bootstrap` **first** in the group `middleware` array.
- **Route-level arrays:** put `tenant_bootstrap` **first** in the array literal (rewrite the array; do NOT append a second `->middleware()` call after a cache/rate middleware).

**These are the REAL files (verified `ls`). Package route files are `*-routes.php`; app/public routes live in repo-root `routes/`. The inventory is EXHAUSTIVE — every route not in the system/setup/deferred allow-lists below is tenant-data and MUST carry `tenant_bootstrap` outermost.**

| File | Routes (verified) | Current | Action (tenant_bootstrap FIRST) |
|---|---|---|---|
| `routes/content.php` | group `/v1/content` `:20` | `['optional_api_key']` | → `['tenant_bootstrap', 'optional_api_key']` |
| `routes/preview.php` | group `/v1/preview` `:23` | (none) | → `['tenant_bootstrap']` |
| `routes/forms.php` | `/_forms/submit` `:18` | route-level `rate_limit` | first call `->middleware('tenant_bootstrap')` **before** `rate_limit` |
| `packages/thallo-render/routes/public-routes.php` | **ALL** render routes `:25–60`: `/_preview/exit`, `/_preview/{token}`, `/_preview-assets/{token}/{path}`, `/_preview.css`, `/_preview-bridge.js`, `/custom.css`, `/theme-assets/{path}`, `/`, `/{path}` | mixed route-level (`RenderPageCache` on `/`,`/{path}`) | `tenant_bootstrap` **first** on every route array (rewrite `[PreviewSessionMiddleware, RenderPageCache]` → `['tenant_bootstrap', PreviewSessionMiddleware::class, RenderPageCache::class]`; add `->middleware('tenant_bootstrap')` as the first call on the asset/preview routes) — **all serve per-tenant content/CSS/assets** |
| `packages/thallo-render/routes/admin-routes.php` | group `/v1/admin/render` `:25` | `['auth']` | → `['tenant_bootstrap', 'auth']` |
| `packages/thallo-seo/routes/public-routes.php` | meta/sitemap/robots `:14,18,20,25` | route-level `rate_limit` | first `->middleware('tenant_bootstrap')` before `rate_limit` on each |
| `packages/thallo-seo/routes/admin-routes.php` | group (auth) | `['auth']` | → `['tenant_bootstrap', 'auth']` |
| `packages/thallo-navigation/routes/public-routes.php` | `/v1/menus/{slug}` `:11` | route-level `rate_limit` | first `->middleware('tenant_bootstrap')` before `rate_limit` |
| `packages/thallo-navigation/routes/admin-routes.php` | group (auth) | `['auth']` | → `['tenant_bootstrap', 'auth']` |
| `packages/thallo-analytics/routes/admin-routes.php` | group (auth) | `['auth']` | → `['tenant_bootstrap', 'auth']` |
| `packages/thallo-workflow/routes/admin-routes.php` | group (auth) | `['auth']` | → `['tenant_bootstrap', 'auth']` |
| `packages/thallo-search/routes/public-routes.php` | `/v1/search` `:11` | route-level `optional_api_key, rate_limit` | first `->middleware('tenant_bootstrap')` before the others |
| **`routes/admin.php`** | **group `/v1/admin` `:39` — MIXED** | `['auth']` | **per-route (below); NOT at group level** |

**`routes/admin.php` `/v1/admin` is MIXED — verified handler namespaces (`grep` of `use` imports).** Every controller is Thallo-owned `App\…` **except `WebhookController` (`Glueful\Api\Webhooks\…`)**, which is a framework extension and is therefore **left untouched** (no marker; the coverage test ignores non-Thallo handlers). Give each Thallo-owned segment exactly one marker as its FIRST route-level middleware:
- **`tenant_bootstrap` (tenant-data, outermost):** `content-types`, `block-types`, `entries` (+ drafts/locales/routes/versions/preview/schedules/publish), `migrations`, `media` (see Task 10b — needs ownership scoping too), `redirects`, `regions`, `locales`, `form-submissions`, `settings` (post-split `settings` table is tenant-scoped), `import-export`.
- **`tenant_system` (Thallo-owned but global/system — NOT bootstrap-scoped):** `users`, `api-keys`, `extensions`, `capabilities`, `health`, `cache`, **`scheduled-tasks`** (code-level schedule config, gated by `system.access` — `routes/admin.php:352`), **`icons`** (global vendored inventory — `IconInventoryController:14`).
- **Framework (leave alone, no marker):** `webhooks` (`Glueful\…`).
```php
$router->get('/entries', [...])->middleware('tenant_bootstrap')->middleware('content_permission:content.view');
$router->get('/scheduled-tasks', [...])->middleware('tenant_system')->middleware('content_permission:system.access');
$router->get('/icons', [...])->middleware('tenant_system')->middleware('content_permission:content.view');
// ...tenant_bootstrap OR tenant_system is the FIRST ->middleware() call on every App\-handled route.
```
> **Correction from rev 5:** `scheduled-tasks` and `icons` were wrongly listed as tenant-data — they are global/system → `tenant_system` (finding P2 #5).

**Collections (deferred → FAIL CLOSED, not just un-scoped):** see Step 6b. Collections routes get **no** `tenant_bootstrap`; instead they carry `collections_disabled_when_tenant` so they 503 while tenancy is enabled.

**Setup/enablement-exempt (never `tenant_bootstrap`):** `/admin/config`, `/admin/setup`, and the Task 19 `/v1/admin/tenancy/*` routes — reachable before any tenant exists.

- [ ] **Step 6b: Collections fail-closed guard (finding P1 #4)** — the enable preflight only blocks when definitions already exist; nothing stops the unchanged collections routes from creating global collection definitions/data AFTER reaching ON. Since collections tenancy is unsupported in SP1, its routes must **fail closed while tenancy is enabled**.

Create `packages/thallo-tenancy/src/Runtime/CollectionsDisabledWhenTenantMiddleware.php`:
```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Runtime;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\{Request, Response};
use Thallo\Tenancy\System\SystemFlags;

/**
 * SP1 boundary: collections tenancy is unsupported, so while tenancy is enabled ALL collection routes
 * fail closed (503). Pack-owned dep (SystemFlags) only — safe to construct while off, where it is inert.
 */
final class CollectionsDisabledWhenTenantMiddleware implements RouteMiddleware
{
    public function __construct(private readonly SystemFlags $flags)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): Response
    {
        if ($this->flags->tenancyEnabled()) {
            return new Response('Collections are unavailable while multi-tenancy is enabled (SP1).', 503);
        }
        return $next($request);
    }
}
```
Register with `'alias' => ['collections_disabled_when_tenant']`; apply it **first** in both collections route files' group middleware: `packages/thallo-collections/routes/collections.php` (`['collections_disabled_when_tenant', 'optional_api_key']`) and `packages/thallo-collections/routes/admin-routes.php` (`['collections_disabled_when_tenant', 'auth']`). A companion test asserts a collections route returns 503 when `tenancyEnabled()` and passes through when off.
> The alias lives in the always-installed pack (not the extension), so it exists even before the extension boots.

- [ ] **Step 7: `RouteCoverageTest` — scope to THALLO-OWNED routes, require EXACTLY ONE marker (findings P1 #2 + P2 #5)**

The framework router carries ~288 routes (`route:debug`) — framework core (auth/blobs/data/docs/health) and extensions (email/RBAC/audit/i18n/import-export). Applying the fail-closed rule to all of them is wrong: those are out of scope until each extension gets its own tenancy-adoption design. So the test **filters to Thallo-owned handlers** (controller class under `App\` or `Thallo\`) and requires **exactly one** of the three markers on each; a `tenant_bootstrap` marker must be **outermost**.
```php
private const MARKERS = ['tenant_bootstrap', 'tenant_system', 'collections_disabled_when_tenant'];

/** Extract the handler class of a route, or null for closures / non-array handlers. */
private static function handlerClass(\Glueful\Routing\Route $route): ?string
{
    $h = $route->getHandler();
    if (is_array($h) && isset($h[0]) && is_string($h[0])) { return $h[0]; }
    if (is_string($h) && str_contains($h, '::')) { return explode('::', $h, 2)[0]; }
    return null; // closure / invokable object → treat as non-Thallo (skip)
}

private static function isThalloOwned(?string $class): bool
{
    return $class !== null && (str_starts_with($class, 'App\\') || str_starts_with($class, 'Thallo\\'));
}

public function testEveryThalloRouteCarriesExactlyOneTenancyMarker(): void
{
    $router = self::$app->getContainer()->get(\Glueful\Routing\Router::class);
    $all = [];
    foreach ($router->getStaticRoutes() as $r) { $all[] = $r; }
    foreach ($router->getDynamicRoutes() as $method => $routes) { foreach ($routes as $r) { $all[] = $r; } }
    self::assertNotEmpty($all);

    $checked = 0;
    foreach ($all as $route) {
        if (!self::isThalloOwned(self::handlerClass($route))) {
            continue; // framework/extension/closure route — out of SP1 scope
        }
        $checked++;
        $path = $route->getPath();
        $mw = $route->getMiddleware();
        $present = array_values(array_intersect(self::MARKERS, $mw));

        // Exactly ONE marker: no unclassified Thallo route, no contradictory double-marking.
        self::assertCount(
            1,
            $present,
            "$path (Thallo-owned) must carry EXACTLY ONE tenancy marker "
            . '(tenant_bootstrap | tenant_system | collections_disabled_when_tenant); found: '
            . json_encode($present)
        );

        if ($present[0] === 'tenant_bootstrap') {
            self::assertSame('tenant_bootstrap', $mw[0] ?? null, "$path: tenant_bootstrap must be outermost");
        }
        if (str_starts_with($path, '/v1/collections')) {
            self::assertSame('collections_disabled_when_tenant', $present[0], "$path must be fenced");
        }
    }
    self::assertGreaterThan(40, $checked, 'sanity: the Thallo route surface should be dozens of routes');
}
```
> **Why this shape:** filtering by handler namespace ignores the ~288 framework/extension routes (they receive their own tenancy design later), while the **exactly-one-marker** rule keeps the fail-closed property for Thallo routes — a new Thallo route with NO marker fails (0 markers), and a wrong classification (both `tenant_bootstrap` and `tenant_system`) also fails. Every Thallo route is thus consciously tagged. The `> 40` floor guards against the filter silently matching nothing (e.g., a namespace rename).

- [ ] **Step 8: Run → PASS** (route-coverage + collections-fence tests + suite). phpcs clean.
- [ ] **Step 9: Commit** — `feat(tenancy): SP1 bootstrap middleware + collections fence + fail-closed route coverage`.

---

### Task 13: FinalizationProbe (the on-gate)

**Files:** Create `Enablement/FinalizationProbe.php`; Test `tests/Unit/Enablement/FinalizationProbeTest.php` (negative) + covered positively in Task 20.

**Fix (finding #5 + rev-14 P1):** a scoped probe query is **not** proof of enforcement — the prod guard only *logs*, so an unregistered owned table's query still succeeds. finalize() MUST verify, via `TenantEnforcementProbe`, that **every** `ThalloTenantTables::tableNames()` entry is registered as tenant-owned in this process (the read hook will scope it), and it MUST verify that both generic blob contracts resolve to the same external implementation. Add `enforcement` + `blobPolicy` gates to the probe.

**Interfaces:** Consumes `ApplicationContext`, `SystemFlags`, `TenantRuntimeReadiness`, `TenantCacheSegment`, `Connection`, nullable `TenantContextRunner`, nullable `TenantEnforcementProbe`, nullable `BlobCreatedHook`, and nullable `BlobAccessPolicy`. Produces `report(ApplicationContext): array{bindings,blobPolicy,enabled,ready,enforcement,scopedQuery,segment,ok}` and `passes(ApplicationContext): bool`. Passes iff: tenancy contracts are present, both blob seams are the same external implementation, `tenancyEnabled()`, request resolution is ready, every owned table is registered, a scoped read succeeds, and the cache segment is non-empty.

- [ ] **Step 1: Failing test**

```php
public function testFinalizeProbeFailsWhenTenancyOff(): void
{
    self::assertFalse($this->container()->get(\Thallo\Tenancy\Enablement\FinalizationProbe::class)
        ->passes($this->appContext()));
}

public function testFinalizeProbeFailsWhenBlobPolicyIsMissing(): void
{
    $probe = $this->makeOtherwisePassingProbe(blobPolicy: null);
    $report = $probe->report($this->appContext());

    self::assertFalse($report['blobPolicy']);
    self::assertFalse($report['ok']);
}
```
`makeOtherwisePassingProbe()` is a test-fixture helper added in this test file: it constructs the probe with enabled flags, ready runtime, a real tenant segment, a runner, and an enforcement probe that reports every owned table registered; only the named `blobPolicy` argument varies. That isolates this gate instead of letting another false condition make the test pass accidentally.

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Enablement;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantEnforcementProbe;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobCreatedHook;
use Thallo\Tenancy\Cache\TenantCacheSegment;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\ThalloTenantTables;

/**
 * The ONLY gate to `on`. Runs on a fresh boot (spec §9 step 7): confirms THIS process actually enforces
 * tenancy — contract bindings present (extension booted), enabled, request resolution ready, EVERY owned
 * table registered as tenant-owned (enforcement — a scoped query alone is not proof, since the prod guard
 * only logs), the generic blob lifecycle/access seams are externally implemented, a scoped probe read
 * succeeds, and the cache
 * segment is non-empty. Only then may finalize() lower the barrier and CAS to `on`.
 */
final class FinalizationProbe
{
    private const CONTRACT_RESOLVER = 'Glueful\\Extensions\\Contracts\\Tenancy\\CurrentTenantResolver';
    private const CONTRACT_RUNNER = 'Glueful\\Extensions\\Contracts\\Tenancy\\TenantContextRunner';

    public function __construct(
        private readonly SystemFlags $flags,
        private readonly Connection $db,
        private readonly TenantRuntimeReadiness $readiness,
        private readonly TenantCacheSegment $segment,
        private readonly ?TenantContextRunner $runner = null,
        private readonly ?TenantEnforcementProbe $enforcementProbe = null,
        private readonly ?BlobCreatedHook $blobCreatedHook = null,
        private readonly ?BlobAccessPolicy $blobAccessPolicy = null,
    ) {
    }

    public function passes(ApplicationContext $context): bool
    {
        return $this->report($context)['ok'];
    }

    /** @return array{bindings:bool,blobPolicy:bool,enabled:bool,ready:bool,enforcement:bool,scopedQuery:bool,segment:bool,ok:bool} */
    public function report(ApplicationContext $context): array
    {
        $c = $context->getContainer();
        $bindings = $c->has(self::CONTRACT_RESOLVER) && $c->has(self::CONTRACT_RUNNER);
        $blobPolicy = $this->blobCreatedHook !== null
            && $this->blobAccessPolicy !== null
            && $this->blobCreatedHook === $this->blobAccessPolicy;
        $enabled = $this->flags->tenancyEnabled();
        $ready = $this->readiness->isReady($context);
        $default = (string) ($this->flags->defaultTenantUuid() ?? '');

        // Enforcement: EVERY owned table must be registered as tenant-owned in THIS process.
        $enforcement = false;
        if ($this->enforcementProbe !== null) {
            $enforcement = true;
            foreach (ThalloTenantTables::tableNames() as $table) {
                if (!$this->enforcementProbe->isRegistered($table)) {
                    $enforcement = false;
                    break;
                }
            }
        }

        $scopedQuery = false;
        $segment = false;
        if ($bindings && $blobPolicy && $enabled && $ready && $enforcement && $default !== '' && $this->runner !== null) {
            try {
                $this->runner->runAsTenant($default, function () use (&$scopedQuery, &$segment, $context): void {
                    // A real scoped read of an owned table must not throw the guard.
                    $this->db->table('content_types')->select(['id'])->limit(1)->get();
                    $scopedQuery = true;
                    $segment = str_starts_with($this->segment->segment($context, 'render'), 'tenant:');
                });
            } catch (\Throwable) {
                $scopedQuery = false;
            }
        }

        $ok = $bindings && $blobPolicy && $enabled && $ready && $enforcement && $scopedQuery && $segment;
        return compact('bindings', 'blobPolicy', 'enabled', 'ready', 'enforcement', 'scopedQuery', 'segment', 'ok');
    }
}
```
> `TenantEnforcementProbe` is bound by `glueful/tenancy`; the generic blob contracts are bound only by Thallo to one shared `TenantBlobPolicy`. All are nullable so the pack constructs while dependencies are absent; a missing seam makes finalize refuse. Framework null implementations are deliberately not container bindings.

- [ ] **Step 4: Register via a soft-resolve factory** — tenancy contracts and both generic blob seams are optional while off; a plain autowire would hard-require them and 500:
```php
FinalizationProbe::class => ['factory' => [self::class, 'makeFinalizationProbe'], 'shared' => true],
```
```php
public static function makeFinalizationProbe(ContainerInterface $c): FinalizationProbe
{
    return new FinalizationProbe(
        $c->get(SystemFlags::class),
        $c->get(Connection::class),
        $c->get(TenantRuntimeReadiness::class),
        $c->get(TenantCacheSegment::class),
        $c->has(TenantContextRunner::class) ? $c->get(TenantContextRunner::class) : null,
        $c->has(TenantEnforcementProbe::class) ? $c->get(TenantEnforcementProbe::class) : null,
        $c->has(BlobCreatedHook::class) ? $c->get(BlobCreatedHook::class) : null,
        $c->has(BlobAccessPolicy::class) ? $c->get(BlobAccessPolicy::class) : null,
    );
}
```
Run → PASS. phpcs clean.
- [ ] **Step 5: Commit** — `feat(tenancy): finalization probe (the on-gate)`.

---

### Task 14: EnablementStatus DTO + TenancyEnablement::status()

**Files:** Create `Enablement/EnablementStatus.php`, `Enablement/EnablementException.php`, `Enablement/RequestResolutionNotReadyException.php`, `Enablement/TenancyEnablement.php` (status only). Test `tests/Unit/Enablement/TenancyEnablementStatusTest.php`.

**Interfaces:** `EnablementStatus` adds `mode: string` (readiness mode) and `reloading: bool`. `status()` composes step + `SystemFlags` + `Transition… ` → reports `RELOADING` distinctly and includes the readiness `mode`.

- [ ] **Step 1: Failing test** — status on fresh install reports `off`, `mode=none`, progress 0. (As rev 1, plus `assertSame('none', $arr['mode'])`.)
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: `EnablementStatus`** — rev 1 fields plus `public readonly string $mode` and a `reloading` bool; `toArray()` adds `'mode'` and `'reloading'`.
- [ ] **Step 4: `EnablementException` + `RequestResolutionNotReadyException extends EnablementException`.**
- [ ] **Step 5: `TenancyEnablement` (ctor + status())** — ctor injects `ApplicationContext, EnablementStore, EnablementLock, SystemFlags, ExtensionActivation, FinalizationProbe, TenantRuntimeReadiness, RetrofitMaintenanceGuard, CacheTransition, Connection`. **`SchemaRetrofit` is NOT injected (finding P1 #1):** it transitively requires `DefaultTenant`, whose ctor hard-requires the extension-bound `TenantProvisioner` (`DefaultTenant.php:32`). Injecting it would make the container fail to construct `TenancyEnablement` — breaking `status()`/`begin()` while the extension is disabled. Instead resolve it **lazily inside `confirm()`** (Task 16), only after verifying `TenantProvisioner` is bound. A private helper:
```php
private function resolveRetrofit(): SchemaRetrofit
{
    $c = $this->context->getContainer();
    if (!$c->has(TenantProvisioner::class)) {
        throw new RequestResolutionNotReadyException(
            'The tenancy extension is not booted in this process; retrofit cannot run yet.'
        );
    }
    return $c->get(SchemaRetrofit::class);
}
```
`status()`:
```php
public function status(): EnablementStatus
{
    $step = $this->store->step();
    $mode = $this->readiness->mode($this->context);
    $cli = $step === EnablementStep::AWAITING_INSTALL
        ? 'composer require ' . ExtensionActivation::PACKAGE . '   # then: php glueful tenancy:enable'
        : null;
    return new EnablementStatus(
        step: $step,
        enabled: $this->flags->tenancyEnabled(),
        schemaState: $this->flags->schemaState(),
        progress: $step->progress(),
        reloading: $step === EnablementStep::RELOADING || $step === EnablementStep::FINALIZING,
        mode: $mode,
        pendingSlug: $this->store->pendingSlug(),
        pendingName: $this->store->pendingName(),
        failure: $this->store->failure(),
        cliFallback: $cli,
    );
}
```
- [ ] **Step 6: Register + run → PASS.** phpcs clean.
- [ ] **Step 7: Commit** — `feat(tenancy): enablement status DTO (+mode/+reloading) + status()`.

---

### Task 15: begin() — install → activate → PROVIDER-BOOT boundary → migrate → awaiting_confirm

**Files:** Modify `Enablement/TenancyEnablement.php`. Test `tests/Unit/Enablement/TenancyEnablementBeginTest.php`.

**Fix (finding P1 #2 — provider boot needs its OWN fresh-boot boundary):** writing the provider into `config/extensions.php` + activating it does **not** boot it into the current container. So `begin()` must stop after activation and require a fresh process before migrating/confirming. Boundaries (each a separate request/process):
- **Req 1:** install (or skip if present) → persist `ENABLING_EXTENSION`, **return**.
- **Req 2:** activate (write config, cache) → persist `AWAITING_PROVIDER_BOOT`, **return**. *(Provider not bound in THIS container.)*
- **Req 3 (fresh boot with the provider):** verify `TenantProvisioner` is bound; if not, stay `AWAITING_PROVIDER_BOOT` and return; else `migrate()` → `AWAITING_CONFIRM`. `confirm()` may now run.
- **Req 4 (fresh boot with `tenancy.enabled=1`):** `finalize()` after table registration.

- [ ] **Step 1: Failing test** — model each boundary explicitly (dev has the package installed, so install is a skip):
```php
public function testBeginStopsAtProviderBootBoundaryThenMigrates(): void
{
    $svc = $this->container()->get(TenancyEnablement::class);
    self::assertSame(EnablementStep::ENABLING_EXTENSION, $svc->begin()->step);      // req1: install-skip
    self::assertSame(EnablementStep::AWAITING_PROVIDER_BOOT, $svc->begin()->step);  // req2: activate → STOP
    // req3 is a fresh boot in real life; in-process the dev provider is already bound, so begin() advances:
    self::assertSame(EnablementStep::AWAITING_CONFIRM, $svc->begin()->step);        // req3: verify+migrate
}
```
> DEV note: in a single test process the dev candidate's provider is already autoloaded/bound, so req3 advances immediately. In production req2 returns to the client and req3 is a genuinely fresh process. The state boundary is modeled either way; the CLI/HTTP surface (Task 19) stops at `AWAITING_PROVIDER_BOOT` and asks for a fresh invocation.

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement begin()** — activation and migration are split by the `AWAITING_PROVIDER_BOOT` boundary:
```php
public function begin(): EnablementStatus
{
    return $this->lock->withLock(function (): EnablementStatus {
        $step = $this->store->step();

        // Cache-driver preflight (rev-14 sweep, cache P1): tenancy REQUIRES a cache driver that can purge by
        // pattern. Memcached's deletePattern() is a hard no-op, so CacheTransition::purge() would silently
        // leave legacy un-segmented keys to leak into the bootstrap-default tenant. Fail closed BEFORE any
        // install/activate side effects, only while still off/installing (don't re-block a run already past
        // the boundary). Probe the real store, not a config-name allowlist.
        if (($step === EnablementStep::OFF || $step === EnablementStep::INSTALLING)
            && !$this->cacheTransition->supportsPatternPurge()) {
            $this->store->recordFailure(
                EnablementStep::OFF,
                'Tenancy requires a cache driver that supports pattern purge (file/redis/array). The configured '
                . 'driver cannot purge by pattern (e.g. memcached), which would leak un-segmented cache across '
                . 'the tenant boundary.'
            );
            return $this->status();
        }

        if ($step === EnablementStep::OFF || $step === EnablementStep::INSTALLING) {
            $this->store->setStep(EnablementStep::INSTALLING);
            $install = $this->activation->install();
            if ($install['blocked']) {
                $this->store->setStep(EnablementStep::AWAITING_INSTALL);
                return $this->status();
            }
            $this->store->setStep(EnablementStep::ENABLING_EXTENSION); // boundary: fresh autoloader
            return $this->status();
        }

        if ($step === EnablementStep::AWAITING_INSTALL) {
            if (!$this->activation->isInstalled()) {
                return $this->status(); // still blocked; operator installs via CLI/deploy then re-polls
            }
            $this->store->setStep(EnablementStep::ENABLING_EXTENSION);
            return $this->status();
        }

        if ($step === EnablementStep::ENABLING_EXTENSION) {
            if (!$this->activation->isActivated()) {
                $this->activation->activate();
            }
            // Boundary: the provider is now in config/extensions.php but NOT bound in this container.
            $this->store->setStep(EnablementStep::AWAITING_PROVIDER_BOOT);
            return $this->status();
        }

        if ($step === EnablementStep::AWAITING_PROVIDER_BOOT) {
            if (!$this->context->getContainer()->has(TenantProvisioner::class)) {
                return $this->status(); // provider not booted here yet — caller must re-invoke fresh
            }
            $migrate = $this->activation->migrate();
            if ($migrate['failed'] !== []) {
                $this->store->recordFailure(
                    EnablementStep::MIGRATING_EXTENSION,
                    'Extension migration failed: ' . implode(', ', $migrate['failed'])
                );
                return $this->status();
            }
            $this->store->setStep(EnablementStep::AWAITING_CONFIRM);
            return $this->status();
        }

        return $this->status(); // AWAITING_CONFIRM / later steps: no-op
    });
}
```
> `migrate()` runs only AFTER `TenantProvisioner` is confirmed bound — the same gate `confirm()` uses (Task 16 `resolveRetrofit()`), so both the migration and the retrofit see a genuinely-booted provider.

- [ ] **Step 3b: Cache-driver gate test (rev-14 sweep, cache P1)** — with `CacheTransition` stubbed so `supportsPatternPurge()` returns `false` (memcached shape), `begin()` from `OFF` records a failure and stays `OFF` (never installs/activates). With a pattern-capable driver it advances normally.
- [ ] **Step 4: Run → PASS.** phpcs clean.
- [ ] **Step 5: Commit** — `feat(tenancy): begin() install → activate → provider-boot boundary → migrate`.

---

### Task 16: confirm() — retrofit → RELOADING (barrier stays up; never on)

**Files:** Modify `Enablement/TenancyEnablement.php`. Test `tests/Integration/Tenancy/TenancyConfirmReloadingTest.php` (single-boot harness).

**Fix (finding #2):** `confirm()` does NOT reach `on`. It preflights (collections BLOCK), runs the retrofit (barrier up), purges caches, writes `tenancy.enabled=1`, persists **RELOADING**, and **leaves the barrier up**. `finalize()` (Task 17) reaches `on`.

- [ ] **Step 1: Failing test**
```php
public function testConfirmLandsInReloadingWithBarrierUpAndEnabledFlag(): void
{
    $svc = self::$engineApp->getContainer()->get(TenancyEnablement::class);
    $svc->begin(); $svc->begin();                         // → AWAITING_CONFIRM
    $status = $svc->confirm('tenant-1', 'Tenant 1', 'user00000001');

    self::assertSame(EnablementStep::RELOADING, $status->step);
    self::assertFalse($status->step === EnablementStep::ON, 'confirm must NOT reach on');

    $flags = self::$engineApp->getContainer()->get(SystemFlags::class); $flags->clearCache();
    self::assertTrue($flags->tenancyEnabled());
    self::assertSame('widened', $flags->schemaState());

    $guard = self::$engineApp->getContainer()->get(RetrofitMaintenanceGuard::class); $guard->refresh();
    self::assertTrue($guard->active(), 'barrier stays UP through RELOADING');
}
```

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement confirm()**
```php
public function confirm(string $slug, string $name, string $ownerUserUuid): EnablementStatus
{
    return $this->lock->withLock(function () use ($slug, $name, $ownerUserUuid): EnablementStatus {
        $step = $this->store->step();
        if ($step !== EnablementStep::AWAITING_CONFIRM && $step !== EnablementStep::RETROFITTING) {
            throw new StaleStateException(EnablementStep::AWAITING_CONFIRM, $step);
        }
        if ($this->hasCollections()) {
            throw new EnablementException(
                'Enable blocked: collection_definitions rows exist (collections tenancy unsupported in SP1).'
            );
        }

        // Lazily resolve the retrofit ONLY here, after confirming the extension is booted (finding P1 #1).
        // This keeps status()/begin() constructible while tenancy is disabled.
        $retrofit = $this->resolveRetrofit();

        $this->store->setPendingTenant($slug, $name);
        $this->store->setStep(EnablementStep::RETROFITTING);

        try {
            $retrofit->run($slug, $name, $ownerUserUuid); // raises barrier + exclusive lock; idempotent
        } catch (\Throwable $e) {
            $this->store->recordFailure(EnablementStep::RETROFITTING, $e->getMessage());
            return $this->status();
        }

        // Ordering (finding #7): PURGE first (barrier still up), THEN flip the runtime gate. A purge
        // failure then leaves the runtime unambiguously disabled and retryable — never enabled-with-stale.
        // DO NOT lower the barrier and DO NOT reach on.
        $this->cacheTransition->purge();
        $this->flags->put('tenancy.enabled', '1');
        $this->store->setStep(EnablementStep::RELOADING); // barrier stays UP until finalize()
        return $this->status();
    });
}
```
(`hasCollections()` as rev 1 Task 13.)

- [ ] **Step 4: Run → PASS.** phpcs clean.
- [ ] **Step 5: Commit** — `feat(tenancy): confirm() runs retrofit and lands in RELOADING (barrier up)`.

---

### Task 17: finalize() — fresh-boot verification → on (crash-safe, atomic barrier+step)

**Files:** Modify `Enablement/TenancyEnablement.php`. Test `tests/Integration/Tenancy/TenancyFinalizeTest.php` (two-boot: confirm on boot1, finalize on boot2).

**Fix (finding #2 + #4 crash-safety):** `finalize()` runs on a fresh boot that actually registered the extension's tables. The central invariant is **never barrier-down while step ≠ on**. So finalize():
1. **CLAIMS** the transition with a *checked* CAS `RELOADING → FINALIZING` (barrier still up). Losing the CAS means another finalize won — observe and return.
2. Runs `FinalizationProbe`. On failure it **reverts** `FINALIZING → RELOADING` (barrier still up) and returns — retryable.
3. **Commits atomically**: lowers the barrier AND sets `ON` **inside one system-channel DB transaction**, with a *checked* inner CAS. Either both land or neither — a crash/rollback leaves `FINALIZING` with the barrier **still up** (recoverable: the next finalize re-runs from FINALIZING). The CAS result is **never ignored**.

- [ ] **Step 1: Failing test** (two-boot + crash-safety)
```php
final class TenancyFinalizeTest extends RetrofittedTenantTestCase
{
    public function testFinalizeReachesOnFromReloadingOnFreshBoot(): void
    {
        $c = self::$onApp->getContainer();
        (new EnablementStore($c->get(SystemFlags::class)))->setStep(EnablementStep::RELOADING);
        $c->get(SystemFlags::class)->put('tenancy.enabled', '1');

        $status = $c->get(TenancyEnablement::class)->finalize();

        self::assertSame(EnablementStep::ON, $status->step);
        $guard = $c->get(RetrofitMaintenanceGuard::class); $guard->refresh();
        self::assertFalse($guard->active(), 'barrier lowered atomically WITH the on transition');
    }

    // Crash-safety: a FINALIZING state left by a crash still has the barrier UP and recovers to ON.
    public function testFinalizingWithBarrierUpRecoversToOn(): void
    {
        $c = self::$onApp->getContainer();
        (new EnablementStore($c->get(SystemFlags::class)))->setStep(EnablementStep::FINALIZING);
        $c->get(SystemFlags::class)->put('tenancy.enabled', '1');
        $c->get(RetrofitMaintenanceGuard::class)->begin(); // barrier still up, as a crash would leave it

        self::assertSame(EnablementStep::ON, $c->get(TenancyEnablement::class)->finalize()->step);
        $guard = $c->get(RetrofitMaintenanceGuard::class); $guard->refresh();
        self::assertFalse($guard->active());
    }
}
```
> The base performs the two-boot with the extension active + a default tenant seeded, so boot2 is a genuine fresh-process finalize.

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement finalize()** (checked CAS + atomic commit)
```php
public function finalize(): EnablementStatus
{
    return $this->lock->withLock(function (): EnablementStatus {
        $step = $this->store->step();
        if ($step === EnablementStep::ON) {
            return $this->status();
        }
        // 1. Claim the transition. From RELOADING we must WIN the CAS to FINALIZING (barrier stays up).
        if ($step === EnablementStep::RELOADING) {
            if (!$this->store->compareAndSet(EnablementStep::RELOADING, EnablementStep::FINALIZING)) {
                return $this->status(); // lost the race to a concurrent finalize
            }
            $step = EnablementStep::FINALIZING;
        }
        if ($step !== EnablementStep::FINALIZING) {
            throw new StaleStateException(EnablementStep::RELOADING, $step);
        }

        // 2. Verify enforcement on THIS fresh boot. On failure, revert the claim (barrier still up).
        if (!$this->finalizeProbe->passes($this->context)) {
            // Checked CAS (finding P2 #6 — no ignored CAS). If the revert loses, another actor already
            // moved the state; record it and report current truth rather than assuming RELOADING.
            if (!$this->store->compareAndSet(EnablementStep::FINALIZING, EnablementStep::RELOADING)) {
                $this->store->recordFailure(
                    $this->store->step(),
                    'finalize probe failed and FINALIZING→RELOADING revert lost a race; state observed.'
                );
            }
            return $this->status(); // reloading (or observed state) + the probe gap; retryable
        }

        // 3. Atomic commit: lower barrier AND set `on` in ONE transaction; never ignore the CAS.
        try {
            $this->db->transaction(function (): void {
                $this->guard->end(); // forget tenancy.retrofit_active (barrier down) — in this TX
                if (!$this->store->compareAndSet(EnablementStep::FINALIZING, EnablementStep::ON)) {
                    throw new EnablementException('finalize CAS FINALIZING→ON failed; rolling back.');
                }
                $this->store->clearPending();
            });
        } catch (\Throwable $e) {
            // Rolled back: persisted barrier + step are restored to (up, FINALIZING). Re-sync this
            // process's in-memory barrier and stay FINALIZING — the next finalize() retries safely.
            $this->guard->refresh();
            $this->store->recordFailure(EnablementStep::FINALIZING, $e->getMessage());
            return $this->status();
        }
        return $this->status();
    });
}
```
> Inject `FinalizationProbe $finalizeProbe`, `Connection $db`, `RetrofitMaintenanceGuard $guard`. `guard->end()` and both CAS writes are builder writes on the un-owned `thallo_system_flags` — so they participate in `$db->transaction()` and roll back together. `guard->refresh()` on rollback re-reads the (restored) persisted barrier so the in-memory flag isn't left stale.

- [ ] **Step 4: Run → PASS** (both cases; register file in `phpunit.xml`). phpcs clean.
- [ ] **Step 5: Commit** — `feat(tenancy): finalize() — crash-safe FINALIZING claim + atomic barrier/step commit`.

---

### Task 18: retry() + cancel()

Same as rev 1 Task 14 — `retry()` from `FAILED` → `failedFrom()` + `recordFailureCleared()`; `cancel()` allowed only pre-retrofit (`INSTALLING/AWAITING_INSTALL/ENABLING_EXTENSION/AWAITING_PROVIDER_BOOT/MIGRATING_EXTENSION/AWAITING_CONFIRM`) → `OFF`; **rejects at/after `RETROFITTING`, `RELOADING`, `FINALIZING`, `ON`** (post-retrofit states are not cancelable — the barrier/schema are already committed). Commit `feat(tenancy): enablement retry() + cancel()`.

---

### Task 19: HTTP surface + CLI

**Files:** `Http/Controllers/TenancyEnablementController.php`, `routes/enablement.php`, `Console/TenancyEnableCommand.php`, `Console/TenancyStatusCommand.php`; Modify `TenancyServiceProvider`. Tests `tests/Integration/Tenancy/TenancyEnablementApiTest.php`.

**Fixes (finding #5):** routes use **no tenant middleware** (the alias doesn't exist until the extension boots) and gate on the existing **`content_permission:system.access`**. Adds a `finalize` route + the controller calls `finalize()` after `confirm()`/on poll.

- [ ] **Step 1: Failing test** — routes registered: `GET /v1/admin/tenancy/status`, `POST .../begin|confirm|retry|cancel|finalize`.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Controller** — as rev 1 Task 16 but add `finalize()` action; `guarded()` also catches `RequestResolutionNotReadyException` → 409.
- [ ] **Step 4: Routes**
```php
$router->group(['prefix' => '/v1/admin', 'middleware' => ['auth']], function (Router $router): void {
    $router->get('/tenancy/status', [TenancyEnablementController::class, 'status'])
        ->middleware('tenant_system')->middleware('content_permission:system.access');
    foreach (['begin', 'confirm', 'retry', 'cancel', 'finalize'] as $action) {
        $router->post('/tenancy/' . $action, [TenancyEnablementController::class, $action])
            ->middleware('tenant_system')->middleware('content_permission:system.access');
    }
});
```
> **`tenant_system`, never `tenant_bootstrap`** — the controller is Thallo-owned (`Thallo\Tenancy\…`), so the coverage test requires a marker; these are system routes reachable while tenancy is off, so the marker is `tenant_system`. Also mark the setup-exempt Thallo routes `GET /admin/config` and `POST /admin/setup` (`admin_spa.php`, `App\…` handlers) with `tenant_system` so they satisfy the exactly-one-marker rule without being bootstrap-scoped.

- [ ] **Step 5: CLI — INSPECT status first, then pick the action (finding P1 #3 + P2 #6)** — the rev-5 bug: calling `begin()` first at `RELOADING` returned `RELOADING` unchanged, then `needsFreshBoot()` exited **before** the `finalize()` branch — so a fresh invocation could never advance from `RELOADING`. Fix: read `status()` FIRST and branch on the CURRENT step; only stop when the action **newly** produced a boundary:
```php
$before = $this->enablement->status()->step;

if ($before === EnablementStep::RELOADING || $before === EnablementStep::FINALIZING) {
    // Fresh boot already at the reload boundary (or crash-left) → advance toward ON.
    $status = $this->enablement->finalize();
} elseif ($before === EnablementStep::AWAITING_CONFIRM && $this->hasConfirmInput()) {
    $status = $this->enablement->confirm($slug, $name, $ownerUuid); // → RELOADING (needs fresh boot)
} else {
    $status = $this->enablement->begin();                          // advance one hop
}

// Stop ONLY when THIS action newly produced a boundary (was not already there before the call).
if ($status->step->needsFreshBoot() && $status->step !== $before) {
    $this->io->warning(sprintf(
        'Reached %s. Re-run `php glueful tenancy:enable` in a FRESH process to continue.',
        $status->step->value
    ));
    return self::SUCCESS;
}
if ($status->step === EnablementStep::ON) {
    $this->io->success('Multi-tenancy is now ON.');
}
```
> `thallo:tenancy:status` prints the DTO (incl. `mode`, `reloading`, `needsFreshBoot()`) and, for `FINALIZING`, notes a crash-recovery finalize is pending. Because the action is chosen from the *current* step, the fresh invocation after `RELOADING` lands in the `finalize()` branch and reaches `ON` — no longer stuck.
- [ ] **Step 6: Register controller + `loadRoutesFrom` + `discoverCommands`.** Run → PASS. phpcs clean.
- [ ] **Step 7: Commit** — `feat(tenancy): enablement HTTP + CLI (system.access, finalize)`.

---

### Task 20: End-to-end acceptance (two-boot: single-tenant reaches ON, two-tenant refuses) + regression

**Files:** Create `tests/Integration/Tenancy/EnableToOnAcceptanceTest.php` (two-boot); Modify `phpunit.xml`. Run full regression.

**Fixes (finding #6 + #7 + corrections):** the acceptance is **two-boot** (fresh-process finalize) and carries **three named cases** — (A) happy path reaching `on` with one tenant + a real scoped request; (B) safety: a **second tenant** makes bootstrap ambiguous so `finalize()` **refuses**; and (C — finding #7) a **full-machine** run `begin → confirm → reboot → finalize` driven through the real state machine with an **already-installed** extension fixture (no Composer subprocess), proving the whole path, not just finalization.

- [ ] **Step 1: Write the acceptance (BOTH cases)**
```php
final class EnableToOnAcceptanceTest extends RetrofittedTenantTestCase
{
    // CASE A — happy path: ONE tenant matching the pointer → finalize reaches ON, barrier down,
    // a bare tenant-data request is scoped to the default tenant, segment non-empty.
    public function testTwoBootEnableReachesOnWithSingleTenant(): void
    {
        $c = self::$onApp->getContainer();
        (new EnablementStore($c->get(SystemFlags::class)))->setStep(EnablementStep::RELOADING);
        $c->get(SystemFlags::class)->put('tenancy.enabled', '1'); // boot2 registered owned tables

        // Exactly one active tenant == the default pointer (RetrofittedTenantTestCase single-tenant mode).
        $probeReport = $c->get(FinalizationProbe::class)->report(self::$onApp);
        self::assertTrue($probeReport['blobPolicy'], 'fresh boot must have the real blob policy bound');
        $status = $c->get(TenancyEnablement::class)->finalize();
        self::assertSame(EnablementStep::ON, $status->step, 'single-tenant finalize must reach ON');

        $guard = $c->get(RetrofitMaintenanceGuard::class); $guard->refresh();
        self::assertFalse($guard->active(), 'barrier lowered at finalize');

        // A bare tenant-data request scopes to the default tenant via the bootstrap middleware.
        $mw = $c->get(\Thallo\Tenancy\Runtime\BootstrapDefaultTenantMiddleware::class);
        $resolver = $c->get(CurrentTenantResolver::class);
        $seen = '';
        $mw->handle($this->jsonRequest('GET', '/'), function () use ($resolver, &$seen) {
            $seen = $resolver->tenantUuid(self::$onApp);      // resolved inside runAsTenant(default)
            return new \Symfony\Component\HttpFoundation\Response('ok');
        });
        self::assertSame((string) self::$onApp->getContainer()->get(SystemFlags::class)->defaultTenantUuid(), $seen);
    }

    // CASE B — safety: a SECOND tenant makes bootstrap NOT single-tenant → finalize refuses ON.
    public function testTwoBootFinalizeRefusesOnWithTwoTenants(): void
    {
        $c = self::$twoTenantApp->getContainer();               // base seeds tenants A + B on boot2
        (new EnablementStore($c->get(SystemFlags::class)))->setStep(EnablementStep::RELOADING);
        $c->get(SystemFlags::class)->put('tenancy.enabled', '1');

        self::assertSame(EnablementStep::RELOADING, $c->get(TenancyEnablement::class)->finalize()->step);

        // And the two tenants' cache segments are genuinely distinct (isolation).
        $seg = $c->get(TenantCacheSegment::class);
        $runner = $c->get(TenantContextRunner::class);
        $a = $runner->runAsTenant(self::$tenantAUuid, fn (): string => $seg->segment(self::$twoTenantApp, 'render'));
        $b = $runner->runAsTenant(self::$tenantBUuid, fn (): string => $seg->segment(self::$twoTenantApp, 'render'));
        self::assertNotSame('', $a);
        self::assertNotSame($a, $b);
    }
}
```
> The base `RetrofittedTenantTestCase` exposes a single-tenant boot (`self::$onApp`, one tenant == pointer) and a two-tenant boot (`self::$twoTenantApp`, tenants A/B). If only one is present today, extend the base with the missing fixture as part of this task.

- [ ] **Step 1b: CASE C — full machine over THREE ACTUAL BOOTS (finding P2 #4)** — the provider-boot boundary only means something across real processes; doing all three `begin()` calls in one boot (rev 5) proves the transitions but not the *reason* `AWAITING_PROVIDER_BOOT` exists. `FullMachineEnableTestCase` must expose **three** genuinely-separate boots and, crucially, boot1 must **NOT** have the provider bound (it is activated during boot1, becoming visible only from boot2):
```php
final class EnableFullMachineAcceptanceTest extends FullMachineEnableTestCase
{
    public function testThreeBootBeginActivateMigrateConfirmFinalizeReachesOn(): void
    {
        // ---- BOOT 1: provider NOT yet activated → install-skip, then activate, STOP at provider-boot ----
        $svc1 = self::$boot1->getContainer()->get(TenancyEnablement::class);
        self::assertFalse(self::$boot1->getContainer()->has(TenantProvisioner::class), 'provider absent boot1');
        self::assertSame(EnablementStep::ENABLING_EXTENSION, $svc1->begin()->step);      // install-skip
        self::assertSame(EnablementStep::AWAITING_PROVIDER_BOOT, $svc1->begin()->step);  // activate → boundary
        // begin() again in the SAME boot must NOT advance — provider is still unbound here:
        self::assertSame(EnablementStep::AWAITING_PROVIDER_BOOT, $svc1->begin()->step);

        // ---- BOOT 2: fresh process; config now lists the provider → it is bound. Migrate + confirm ----
        self::rebootWithActivatedProvider();
        self::assertTrue(self::$boot2->getContainer()->has(TenantProvisioner::class), 'provider bound boot2');
        $svc2 = self::$boot2->getContainer()->get(TenancyEnablement::class);
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $svc2->begin()->step);        // verify-bound + migrate
        self::assertSame(EnablementStep::RELOADING, $svc2->confirm('acme', 'Acme', self::OWNER_UUID)->step);

        // ---- BOOT 3: fresh process with tenancy.enabled=1 → table registration armed → finalize → ON ----
        self::rebootWithTenancyEnabled();
        self::assertTrue(self::$boot3->getContainer()->has(BlobCreatedHook::class), 'blob hook bound boot3');
        self::assertTrue(self::$boot3->getContainer()->has(BlobAccessPolicy::class), 'blob policy bound boot3');
        $status = self::$boot3->getContainer()->get(TenancyEnablement::class)->finalize();
        self::assertSame(EnablementStep::ON, $status->step);
        $guard = self::$boot3->getContainer()->get(RetrofitMaintenanceGuard::class); $guard->refresh();
        self::assertFalse($guard->active());
    }
}
```
> `FullMachineEnableTestCase` provides three boots: **boot1** does NOT list `glueful/tenancy` in its `enabled` providers (so `TenantProvisioner` is genuinely unbound — the boundary blocks); `rebootWithActivatedProvider()` re-boots with the provider now enabled (as the config write from boot1's activation would produce); `rebootWithTenancyEnabled()` re-boots after confirm set `tenancy.enabled=1`, arming `registerTenantTables()`. No Composer subprocess — the dev candidate is installed, only `config/extensions.php` `enabled` differs across boots. This is the ONLY test that proves the provider-boot boundary is load-bearing, not decorative.

- [ ] **Step 2: Run → PASS** (`--testsuite tenancy-retrofit --filter 'EnableToOnAcceptanceTest|EnableFullMachineAcceptanceTest'` — all three cases green).
- [ ] **Step 3: Full retrofit-suite regression** → all green.
- [ ] **Step 4: OFF `composer test`** → baseline unchanged; **`composer phpcs`** clean.
- [ ] **Step 5: Commit** — `test(tenancy): two-boot enable acceptance + two-tenant cache isolation`.

---

### Task 21: Release gate — publish `glueful/tenancy`, drop the dev path repo (finding #8)

**Why (finding #8):** the on-demand model in Task 3 (a **path repository + `require-dev`** pointing at the sibling `../extensions/tenancy`) is a **development** convenience. Production Composer must never depend on local repo layout. This task is the **release checklist** that flips the distribution from dev-path to a published package — run it ONLY at the SP1 release, after the framework + contracts + extension changes are tagged.

**Files:** Modify root `composer.json`; Test `tests/Unit/Enablement/TenancyReleaseDistributionTest.php`.

- [ ] **Step 1: Failing test** (guards the release invariant — RED until the gate is applied at release)
```php
public function testTenancyIsPublishedNotAPathRepoAtRelease(): void
{
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/composer.json'), true);
    // No sibling path repository for tenancy.
    foreach (($composer['repositories'] ?? []) as $repo) {
        self::assertStringNotContainsString('extensions/tenancy', (string) ($repo['url'] ?? ''));
    }
    // require-dev pins a real published version constraint, not '*' against a path repo.
    $constraint = $composer['require-dev']['glueful/tenancy'] ?? null;
    self::assertNotNull($constraint);
    self::assertNotSame('*', $constraint, 'pin a published version at release');
}
```

- [ ] **Step 2: Run → FAIL** (Task 3 left the path repo + `"glueful/tenancy": "*"`).
- [ ] **Step 3: Apply the gate**
  - Remove the `{ "type": "path", "url": "../extensions/tenancy", … }` entry from `repositories`.
  - Change the `require-dev` constraint from `"*"` to a **published** version (e.g. `"^1.0"`) resolvable from the registry (Packagist / private repo).
  - Keep `glueful/tenancy` in **`require-dev`** (NOT `require`) — production still installs it on-demand via the enablement flow; dev/CI keep it for the two-boot suite.
  - `composer update glueful/tenancy --no-interaction` to repin `composer.lock`.
- [ ] **Step 4: Run → PASS.** Confirm `Glueful\Extensions\Tenancy\TenancyServiceProvider` is still **absent** from `config/extensions.php` `enabled` (ships disabled). phpcs clean.
- [ ] **Step 5: Commit (release)** — `chore(tenancy): pin published glueful/tenancy, drop dev path repository`.

> **Prerequisite ordering:** the held framework changes (Task 11a execution-wrapper seam; any `Connection::newPdo()` seam from Task 11b) and the held contracts (Task 4) + extension `Bridge\ContractEnforcementProbe` binding must be **released and pinned FIRST**, per the standing "release framework before pinning dependents" rule. This task is the LAST step and is gated on those releases existing.

---

## Self-Review (rev 15)

**Rev-15 — consolidated framework-contract verification sweep (Tasks 11a/11b/11c + cache 6/8/9/10).** Four parallel subagents audited every framework/pack assumption in the still-unverified tasks against actual source. Result: **11a, 11b, 11c verified with zero design-breakers and no line-number drift**; the cache sweep found one substantive gap. Fixes folded in:
- **Cache P1 (substantive) — Memcached silently defeats purge.** Verified `MemcachedCacheDriver::deletePattern()` returns `false` unconditionally (`:304`) and `getKeys()` returns `[]` (`:320`), so `CacheTransition::purge()` no-ops there and un-segmented legacy keys would leak into the default tenant. Added `CacheTransition::supportsPatternPurge()` (a behavioural probe, not a config-name allowlist) and a **fail-closed gate in `begin()`** that refuses to start enabling on a pattern-incapable driver, plus a Global-Constraints note and tests. ✅
- **11a (doc):** the static-registry reset lives in per-test `setUp()` (`QueryInterceptorTest`/`InsertHookTest`/`ConnectionTableHookTest`), not a central bootstrap — corrected the Step 5 / Global-Constraints wording; `ExecutionWrapperTest::setUp()` resets it. (All six execution-wrapper contract claims — `executeStatement()` chokepoint at `:216`, prepare `:233`/execute `:239` in one try, `PDOStatement` return, static-registry pattern — verified exact.) ✅
- **11b (doc):** `newPdo()` verified compilable and side-effect-free; added a docblock note that it returns a **non-pooled** independent session (the desired lock-session property). ✅
- **11c (doc):** no drift — every cited raw-writer line and per-file wrapper count is exact. Corrected the `EnsureFilterIndexesJob` descriptor (5 boundary gates = 2 raw CONCURRENTLY DDL + 3 builder writes on the global `filter_indexes` registry, not "5 owned-table mutations") and the `active()` attribution (guard, not interceptor). ✅
- **Cache (doc):** noted `config('cache.prefix')` is currently dead (so un-prefixed globs match) and that legacy `preview:*`/`db:*` keys are intentionally not purged (ephemeral / immutable-versioned). §10 audit rows spot-checked accurate. ✅

**Round-14 findings addressed (rev 14) — blob-policy binding is an on-gate + Glueful user shape is correct:**
- **P1 (enabled runtime could silently use generic null blob fallbacks)** → Task 13 includes a distinct `blobPolicy` gate in `FinalizationProbe`; `ON` is unreachable unless both generic seams resolve to the same external implementation. Task 10b also adds an enabled-boot assertion in `ThalloServiceProvider`. The framework binds no default under either shared ID. ✅
- **P2 (`Utils::getUser()` treated as an object; `$userUuid` undefined)** → verified Glueful returns `array{uuid,...}|null`. `UploadController` now normalizes that array once per action and reuses the resulting `?string` for `onBlobCreated()` plus every `BlobAccessContext`; anonymous signed access carries `null`. Tests assert the exact authenticated UUID and no warning/null regression. ✅

**Round-13 findings addressed (rev 13) — Task 10b authorization fails closed + thumbnail truly best-effort:**
- **P1 (ownerless blob still served via public/signed shortcut; quarantine bool ignored)** → `authorizeAccess` now resolves the owner FIRST and denies any blob with no `media_assets` row, BEFORE the public/signed VIEW shortcuts — so hard-delete AND quarantine failure can no longer expose an ownerless public/signed blob (this is the authoritative guard; quarantine is defense-in-depth). The compensation helper also now checks `updateStatus()`'s bool (not just the re-read). Step 8 adds ownerless-public + ownerless-signed denial tests combined with hard-delete-`false` + quarantine-`false` stubs. ✅
- **P2 (post-attribution thumbnail not reliably best-effort — media catches only `\Exception`)** → verified `ThumbnailGenerator::generate()` catches `\Exception`, not `\Error`; the controller now wraps `generateThumbnailFor()` in `catch (\Throwable)`, logs `upload.thumbnail.deferred_failed`, sets `thumb_url=null`, and returns 201 — a committed upload never 500s on a thumbnail `\Error`. Step 8 tests a `\TypeError`-throwing processor → 201 + null thumb + intact blob/ownership. ✅
- **P3 (stale "critical-log fail-safe" self-review line)** → the rev-11 P1 #4 entry re-marked: the fallback is a verified quarantine + the authoritative ownership-first deny, not a log. ✅

**Round-12 findings addressed (rev 12) — Task 10b ordering + true atomicity + fail-safe compensation:**
- **P1 #1 (ordering: `runWritable()` used before Task 11c adds it)** → verified the `WriteBarrier` contract exposes only `assertWritable()` pre-11c. Task 10b now gates `onBlobCreated` on `assertWritable()` (matching every other raw writer at that point) and classifies `TenantBlobPolicy`→`SCOPED`; Task 11c migrates it to `runWritable()` with the other writers and adds it to `RUNWRITABLE_SITES`. No forward reference. ✅
- **P1 #2 (`onBlobCreated` not truly atomic — INSERT then separate verify-SELECT could throw after a write)** → rewrote to `INSERT … ON CONFLICT (blob_uuid) DO NOTHING RETURNING tenant_uuid`: a RETURNING row proves we inserted (owner is us → return); only the no-write conflict path does a read-only owner check that throws WITHOUT writing. Every throwing path is now write-free. Step 8 adds a pre-seeded-foreign-owner case asserting the existing row is untouched. ✅
- **P1 #3 (checking hard-delete `false` without remediation leaves the ownerless blob active)** → compensation now, on a `false` hard delete, **quarantines** the blob (`status='deleted'`, unreachable via the serve path), **verifies** the transition (re-read with `findByUuidWithDeleteFilter(…, includeDeleted: true)`), and **records** it (`upload.compensation.blob_quarantined` critical). Quarantine is the fail-safe; the log is the record. Step 8 asserts the quarantine + non-servability. ✅
- **P2 #4 (storage-delete failure returns `false`, was swallowed by try/catch)** → verified `FlysystemStorage::delete()` catches and returns `false` (never throws); compensation checks the boolean and emits `upload.compensation.object_orphaned` critical. Step 8 asserts it. ✅
- **Stale self-review** → the rev-10 "primary orphan-guard is a DB transaction" entry re-marked superseded by rev-12 (write-free attribution + quarantine fallback); the Step 4 FK comment/note and the deferral paragraph no longer reference the now-impossible "verify-SELECT throws after a successful INSERT" case. ✅

**Round-11 findings addressed (rev 11) — Task 10b attribution flow corrected (transaction removed):**
- **P1 #1 (retryable transaction performs storage side effects)** → removed the `Connection::transaction()` wrapper entirely (verified `TransactionManager::transaction()` retries deadlocks up to `maxRetries`=3 at `:63`). Storage happens once in `uploadMedia()`; the thumbnail is generated once after attribution. A Step 8 regression asserts the stored object is written exactly once. ✅
- **P1 #2 (rollback not guaranteed for every failure — `catch (Exception)`)** → no `TransactionManager` in the flow; the compensation `catch (\Throwable)` covers `Error` (e.g. `TypeError`) too. Step 8 adds a policy-throws-`\Error` case → still 500 + blob removed. ✅
- **P1 #3 (broad catch changed upload error semantics)** → `uploadMedia()` is OUTSIDE the try; the `catch` wraps ONLY `onBlobCreated`, so `ValidationException`/`UploadException` reach the existing handlers. Step 8 asserts an ordinary upload error is NOT relabeled "could not be attributed". ✅
- **P1 #4 (thumb_path unobtainable from the media contract)** → chose the reviewer's "generate thumbnails after successful attribution": no media-contract change, no `thumb_path`. `uploadMedia(generate_thumbnail:false)` + a new `FileUploader::generateThumbnailFor()` called post-attribution. A failed attribution never creates a thumbnail → no orphan, no leak. `onBlobCreated` is atomic by construction, so the blob-row cleanup is a **checked** hard-delete (log `critical`+500 on `false`) with the FK cascade as backstop — the reviewer-sanctioned "at minimum" path. ✅
- **P1 #5 (raw ownership write bypasses the mutation boundary)** → `TenantBlobPolicy::onBlobCreated`'s raw INSERT gates on the boundary — `assertWritable()` in Task 10b (matching the other raw writers pre-11c), migrated to `runWritable()` in Task 11c (added to `RUNWRITABLE_SITES => 1`). Classified `SCOPED`+`REQUIRED_FRAGMENTS` in 10b; `MediaOwnershipBackfill` classified `RETROFIT_ENGINE` (runs under the exclusive lock — must NOT `runWritable()`). *(Ordering corrected in rev-12 P1 #1.)* ✅
- **P2 (thumb_path exposure)** → `thumb_path` is never added to the `uploadMedia()` result; `UploadResultData`/OpenAPI unchanged. ✅
- **Stale language** → line 666 "hard-compensate" and line 2431 "hard-compensation" reworded to "deterministic compensation / post-create attribution with deterministic compensation". ✅

**Round-9 findings addressed (rev 10) — Task 10b compensation path pinned:**
- **P1 #1 (wrong upload identifier)** → attribution + cleanup use `$result['blob_uuid']` throughout (verified `FileUploader::uploadMedia()` returns `blob_uuid`, not `uuid`); Step 8 asserts `onBlobCreated` receives that exact value. ✅
- **P1 #2 (signature computed twice)** → `show()` computes `hasValidSignature($request)` ONCE and passes the boolean into both `checkBlobAccess($request, $blob, $signatureValid)` (new param) and `BlobAccessContext`; `checkBlobAccess()` no longer recomputes it. ✅
- **P1 #3 (compensation missed the thumbnail)** → *(Superseded by rev-11 P1 #4: the thumbnail is now DEFERRED to after attribution, so a failed attribution never creates one — no thumb_path, no cleanup needed.)* ✅
- **P1 #4 (ignored hard-delete bool → possible ownerless blob)** → *(Superseded by rev-12/rev-13: no transaction; the ownerless blob is cleaned by a checked hard-delete, falling back to a **verified quarantine**, and — authoritatively — `authorizeAccess` denies every ownerless blob (rev-13 P1) so no cleanup-path failure can expose one. `onBlobCreated` is atomic by construction.)* ✅

**Round-8 findings addressed (rev 9) — Task 10b seam finalized:**
- **P1 #1 (signed-grant derived twice / non-uniform)** → only `show()` computes `signatureValid` (the sole caller of `checkBlobAccess`/`hasValidSignature`); `info`/`delete`/`signedUrl` pass `false`. Tests cover expired/tampered grants and that a valid grant authorizes VIEW only. *(Superseded by rev-10 P1 #2: the flag is now computed once and passed into both `checkBlobAccess()` and the context.)* ✅
- **P1 #2 (compensation can orphan ownership)** → `media_assets` gains `FOREIGN KEY (blob_uuid) REFERENCES blobs(uuid) ON DELETE CASCADE` as defense-in-depth. *(Superseded by rev-12: `onBlobCreated` is write-free on every throw path (`ON CONFLICT DO NOTHING RETURNING`), so no ownership row is ever orphaned; the FK backstops normal hard purges. Compensation prefers a hard delete of the ownerless blob, falling back to a verified quarantine.)* ✅
- **P2 #3 (factory edit missing)** → Files + Step 2 now modify `src/Container/Providers/StorageProvider.php` (the existing `UploadController` `FactoryDefinition`) and the framework controller-test constructor call sites. ✅
- **P2 #4 (action was an unchecked string)** → backed `BlobAction` enum (`view|info|delete|sign`); `BlobAccessContext::$action` and `TenantBlobPolicy` use it. ✅

**Round-7 findings addressed (rev 8) — Task 10b policy hardened:**
- **P1 #1 (currentTenantUuid hardcoded default)** → resolution order: resolved `CurrentTenantResolver` tenant wins; default fallback ONLY in `bootstrap_default`; `full_resolution`/none fails closed. `onBlobCreated` THROWS when enabled-but-unresolved (no silent ownerless blob). SP2-must-resolve-blob-routes noted. ✅
- **P1 #2 (access contract lacked request/signature facts)** → `authorizeAccess(array $blob, BlobAccessContext $ctx)` with `{action, authenticatedUserUuid, signatureValid}`, run AFTER the framework's visibility/auth/signature checks; a validated signed grant may `view` a private blob, but `sign`/`delete`/`info` require ownership. ✅
- **P1 #3 (attribution failure → ownerless blob)** → the seam defines compensation: `UploadController` wraps `onBlobCreated` and, on throw, returns 500 with no residue. *(Superseded by rev-11: no transaction — the ownerless blob is removed via a checked hard-delete of the blob row + delete of the single stored object; the thumbnail is deferred so none exists on failure.)* ✅
- **P1 #4 (framework default binding → provider-order precedence)** → framework binds nothing; `UploadController` independently soft-resolves the generic hook and policy to null implementations; Thallo is the only binder. ✅
- **P2 #5 (backfill referenced a not-yet-built report)** → uses the local `$tenantUuid` from `DefaultTenant::ensure()` (`:68`); pinned sequence `$mediaBackfill->run($tenantUuid); $additive->apply('media_assets')`; test proves pre-enablement blobs stay owned+visible. ✅

**Round-6 findings addressed (rev 7) — Task 10b redesigned around framework seams:**
- **P1 #1 (hook sees no tenant on real uploads)** → attribution moved to a **held generic `BlobCreatedHook::onBlobCreated` seam** called by `UploadController` after upload; `MediaOwnershipTest` drives the real `POST /v1/blobs`. ✅
- **P1 #2 (JOIN not auto-scoped)** → `MediaAdminController` queries **root at `media_assets`** (the primary table the tenancy hook scopes), then join `blobs`. ✅
- **P1 #3 (upsert incompatible + takeover-prone)** → ownership written via raw `INSERT … ON CONFLICT (blob_uuid) DO NOTHING` + owner-mismatch check; **never** `upsert` (which is `ON CONFLICT (id)`), **never** transfer ownership. ✅
- **P1 #4 (retrofit drops global unique + omits existing blobs)** → media uniques **not widened** (empty `widened_uniques` preserves the global `blob_uuid` unique); `MediaOwnershipBackfill` (`special_backfill = 'media_assets'`) seeds every existing blob → default tenant before the NOT NULL promotion. ✅
- **P1 #5 (private-blob authz can't be a follow-up)** → the generic `BlobAccessPolicy::authorizeAccess` seam ships now; `UploadController::show/info/delete/signedUrl` consult it; `TenantBlobPolicy` requires owner==current-tenant for private actions. ✅

**Round-5 findings addressed (rev 6, retained):**
- **P1 #2 (fail-closed test spanned the whole 288-route framework router)** → Task 12 Step 7 filters to **Thallo-owned handlers** (`App\`/`Thallo\`) and requires **exactly one** marker (`tenant_bootstrap`/`tenant_system`/`collections_disabled_when_tenant`); new no-op `TenantSystemMiddleware` marker; framework/extension routes ignored. ✅
- **P1 #3 (CLI stuck at RELOADING)** → Task 19 Step 5 inspects `status()` FIRST and branches (finalize at RELOADING/FINALIZING, confirm at AWAITING_CONFIRM, else begin), stopping only when an action *newly* creates a boundary — a fresh invocation at RELOADING now finalizes to ON. ✅
- **P2 #4 (full-machine test didn't exercise the boundary)** → Task 20 CASE C uses **three actual boots** with `TenantProvisioner` genuinely unbound on boot1 (asserted), proving `AWAITING_PROVIDER_BOOT` is load-bearing. ✅
- **P2 #5 (scheduled-tasks + icons misclassified)** → moved to `tenant_system` in the Step 6 inventory. ✅

**Round-4 findings addressed (rev 5, retained):**
- **P1 #1 (enablement service can't resolve while extension off)** → Task 14/16: `TenancyEnablement` no longer injects `SchemaRetrofit`; a `resolveRetrofit()` helper lazily resolves it inside `confirm()` after `container->has(TenantProvisioner::class)`. `status()`/`begin()` are now constructible while off. ✅
- **P1 #2 (provider activation needs its own fresh boot)** → Task 1 adds `AWAITING_PROVIDER_BOOT` + `needsFreshBoot()`; Task 15 splits activate (→ boundary, return) from verify-bound + migrate; Task 19 CLI stops at both `AWAITING_PROVIDER_BOOT` and `RELOADING`; Task 20 CASE C drives all three `begin()` hops. ✅
- **P1 #3 (RouteCoverageTest missed missing middleware; inventory incomplete)** → Task 12 Step 7 is now **fail-closed** (`$mw[0] ?? null === 'tenant_bootstrap'` for every non-allow-listed route); inventory expanded to ALL render public routes (`/custom.css`, `/theme-assets`, `/_preview/*`) and every `/v1/admin` tenant-data segment (settings, regions, locales, scheduled-tasks, form-submissions, import-export, redirects, media, icons). ✅
- **P1 #4 (collections not fenced after ON)** → Task 12 Step 6b `CollectionsDisabledWhenTenantMiddleware` (503 while `tenancyEnabled()`) applied to both collections route files; coverage test requires the fence. ✅
- **P2 #5 (failed-tx regression assertions unreachable)** → Task 11b Step 4b uses a manual `try/catch` + `assertTrue($threw)` so the post-failure exclusive-acquire assertions actually run. ✅
- **P2 #6 (recovery incomplete)** → Task 19 CLI finalizes for BOTH `RELOADING` and `FINALIZING`; Task 17 checks the `FINALIZING → RELOADING` revert CAS (no ignored CAS). ✅
- **P2 #7 (raw-writer lint file-level)** → Task 11c Step 1 pins **per-file `runWritable(` counts** (`RUNWRITABLE_SITES`) + a bare-`assertWritable();`-line regression + retains the unclassified-`getPDO()` sweep. ✅

**Round-3 findings addressed (rev 4, retained):**
- **P1 #1 (raw-PDO writers bypass quiescence)** → Task **11c** extends `WriteBarrier` with `runWritable(callable)` (holds the shared lock around the actual raw op), migrates all ~14 classified raw writers off bare `assertWritable()`, and tightens the B2 lint (`RawPdoScopingLintTest`) to require `runWritable(`. ✅
- **P1 #2 (shared lock leaks after a failed transaction)** → Task 11b `MutationBoundaryLock` holds the shared lock on a **dedicated participant `newPdo()` session** (and the exclusive on a distinct maintenance session), never the app statement PDO; regression proves a failed mutation transaction still releases the boundary and permits a later exclusive acquire. ✅
- **P1 #3 (route inventory wrong files + ordering)** → Task 12 Step 6 rewritten against the REAL files (`routes/*.php`, `packages/*/routes/*-routes.php`) with `tenant_bootstrap` **outermost** (esp. wrapping `RenderPageCache`); `RouteCoverageTest` enumerates **every** registered route and asserts index-0 placement. ✅
- **P1 #4 (finalize lowers barrier before transition secured)** → Task 1 adds `FINALIZING`; Task 17 CLAIMS via checked CAS `RELOADING→FINALIZING`, verifies, then lowers barrier + sets `on` in **one transaction** with a checked inner CAS; crash leaves `FINALIZING` barrier-up (recoverable); CAS never ignored. ✅
- **P1 #5 (execution-wrapper missing from two-boot cleanup)** → Task 11a Step 5 adds `QueryExecutor::clearExecutionWrappers()` to the Thallo harness `resetTenancyGlobals()` + framework test bootstrap. ✅
- **P2 #6 (lock test proved rejection not draining)** → Task 11b Step 8 three-session drain test: shared holder in-flight ⇒ exclusive try FAILS; shared releases ⇒ exclusive succeeds. ✅
- **P2 #7 (acceptance manually staged RELOADING)** → Task 20 CASE C: full-machine `begin → confirm → reboot → finalize` via `FullMachineEnableTestCase` with an already-installed extension fixture (no Composer subprocess). ✅
- **P2 #8 (full-resolution readiness checked before enabled)** → Task 5 `mode()` gates `tenancyEnabled()` **first** → off always returns `MODE_NONE`. ✅

**Round-1 P1s (still addressed, unchanged):** ON-unsafe-pre-SP2 → readiness/bootstrap (Tasks 4/5/12) + finalize gate (17); same-boot-can't-arm → `RELOADING` two-boot (Tasks 1/16/17); segment fail-open → fail-closed (Task 6); install autoloader boundary → on-demand `require-dev` + cross-request activate (Tasks 3/15); routes-depend-on-extension → system routes on `content_permission:system.access`, no tenant mw (Task 19).

**Round-2 findings addressed (rev 3, retained):**
- **#1 (mutation lock released before execute)** → Task **11a** adds the framework `ExecutionWrapperInterface` + `QueryExecutor::addExecutionWrapper()` around-execution seam; Task **11b** `MutationQuiescenceWrapper` **holds the shared lock across `$proceed()`** (the real execute) so the retrofit's exclusive acquire is a genuine drain. Two-session test. ✅
- **#2 (bootstrap middleware can't construct while off)** → Task 12 middleware ctor makes `TenantContextRunner`/`CurrentTenantResolver` **nullable + soft-resolved** via factory; off short-circuits before touching them, on-but-unbound fails closed (503). ✅
- **#3 (route coverage incomplete)** → Task 12 Step 6 full **route inventory table** (every package `routes/*.php` + the MIXED `/v1/admin` per-route split) + a `RouteCoverageTest`. ✅
- **#4 (SP2 rebinding readiness → provider-order conflict)** → Task 4 adds distinct `FullTenantResolutionReadiness` capability; Task 5 SP1 **composite** `TenancyRuntimeReadiness` is the single `TenantRuntimeReadiness` binding and **soft-resolves** the SP2 capability. SP2 never overrides the shared ID. Pinned in Global Constraints (DI-binding rule). ✅
- **#5 (finalize didn't prove registration)** → Task 4 `TenantEnforcementProbe` + Task 13 `FinalizationProbe` verifies every owned table is registered and both generic blob seams resolve to the same implementation before lowering the barrier. ✅
- **#6 (acceptance never reaches ON)** → Task 20 has **two named cases**: single-tenant → `finalize()` reaches **ON** + real scoped request; two-tenant → `finalize()` **refuses** ON. ✅
- **#7 (flag written before purge)** → Task 16 confirm() reordered: **purge first**, then flip `tenancy.enabled=1`. Pinned in Global Constraints. ✅
- **#8 (path-repo release gate)** → Task **21** release checklist removes the dev path repo + repins a published `glueful/tenancy`, gated on the framework/contracts releases. Pinned in Global Constraints (release gate). ✅

**Corrections (round 1, retained):** §10 audit table; purge globs cover `tenant:*:`; Install exception namespace `Glueful\Extensions\Install`; `base_path($context)`.

**Type/behavior consistency:** `EnablementStep` (incl. `AWAITING_PROVIDER_BOOT`, `RELOADING`, `FINALIZING`, `needsFreshBoot()`), `EnablementStore`, `EnablementStatus(+mode,+reloading)`, `TenantRuntimeReadiness::{isReady,mode,MODE_*}`, `FullTenantResolutionReadiness::isReady`, `TenantEnforcementProbe::{isRegistered,registeredTables}`, `BlobCreatedHook`, `BlobAccessPolicy`, `ExecutionWrapperInterface::around`, `WriteBarrier::{assertWritable,runWritable}`, `Connection::newPdo`, `TenantCacheSegment::segment`, `MutationBoundaryLock`(participant+maintenance)/`MutationQuiescenceWrapper`, `FinalizationProbe.report{+enforcement,+blobPolicy}`, `TenancyEnablement::{status,begin,confirm,finalize,retry,cancel,resolveRetrofit}` (SchemaRetrofit resolved lazily, not injected), and the `tenant_bootstrap`/`collections_disabled_when_tenant` aliases are used identically across tasks. `SchemaRetrofit::run`/`RetrofitReport`/`RetrofitMaintenanceGuard(+runWritable)` match Phase C.

**Sequencing:** contracts (4) → readiness/bootstrap (5, 12), cache (6, 8–10), and **media ownership (10b)** land BEFORE confirm/finalize (16–17), so `on` cannot be reached without request-resolution safety, cache isolation, AND media isolation in place. Task 10b MUST precede confirm because the retrofit reads `ThalloTenantTables` at run time — `media_assets`/`media_meta`/`media_usage` must be registered before it widens. Framework seam (11a) → quiescence (11b/11c) land before confirm. Release gate (21) is LAST, gated on the framework + contracts releases.

**Framework/cross-repo changes (held; pin at release):** Task 11a execution-wrapper seam + `clearExecutionWrappers` test-bootstrap reset + CHANGELOG; `Connection::newPdo()` seam (Task 11b, confirmed required); generic `BlobCreatedHook`/`BlobAccessPolicy` + `BlobAccessContext` + `BlobAction` enum seams + `UploadController`/`StorageProvider` soft-resolve + post-create attribution with deterministic compensation + `FileUploader::generateThumbnailFor()` + per-method authorization + generic null fallbacks (Task 10b); contracts `TenantRuntimeReadiness`/`FullTenantResolutionReadiness`/`TenantEnforcementProbe` (Task 4); pack-contracts `WriteBarrier::runWritable` (Task 11c); extension `Bridge\ContractEnforcementProbe` binding.

**Deferred by scope (documented, not gaps):** disable path / `disabled_widened`, seed/sync + `starter_provenance`, the broad `thallo:tenancy:diagnose`, SP2 full multi-tenant resolution (mode `full_resolution`) and framework `glueful/media` URL-cache segmentation.
