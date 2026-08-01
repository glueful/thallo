# SP2a — Full Tenant Resolution + Tenant Management — Implementation Plan (rev 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take the runtime from `bootstrap_default` to `full_resolution` — verified-host public resolution (subdomain + custom domains), `X-Tenant-Id` admin selection, blob routes in the resolution surface, and tenant lifecycle management — with SP1 undisturbed while SP2a is deployed-but-inactive.

**Architecture:** Framework ships two generic blob seams plus `auth:optional`; contracts 1.2.0 adds three neutral administration/probe interfaces; extension 1.2.0 owns `tenant_domains`, host normalization, `DomainResolver`, resolver profiles, and administration bridges; Thallo owns atomic full-resolution activation, the shared readiness predicate, route wiring, management surfaces, canonical-origin URLs, and cache purges. Spec: `docs/superpowers/specs/multi-tenancy/2026-07-10-sp2a-resolution-tenant-management-design.md`; invariants: `SP2-README.md` §3.

**Tech Stack:** PHP 8.3, Glueful framework 1.67.0→1.68.0, PostgreSQL (Thallo) / engine-portable DDL (extension tests run SQLite `:memory:`), Vue 3 + Nuxt UI + Pinia + @pinia/colada (admin SPA), PHPUnit, vitest.

## Global Constraints

- **Repos:** framework `/Users/michaeltawiahsowah/Sites/glueful/framework` (→1.68.0), contracts `/Users/michaeltawiahsowah/Sites/glueful/extensions/contracts` (→1.2.0), extension `/Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy` (→1.2.0), Thallo `/Users/michaeltawiahsowah/Sites/glueful/thallo`. Release order framework→contracts→extension→Thallo; pin only published versions (SP2 index §3.10).
- **HOLD ALL COMMITS** in every repo until explicit go-ahead; work on `dev`; NO AI/Anthropic attribution anywhere.
- `declare(strict_types=1)`, `final class`, constructor DI, `use`-imports (no inline FQCNs); `composer phpcs` clean per repo (120-char lines, warnings fail).
- **Contract-only cross-package rule** (SP2 index §3.2): Thallo never imports `Glueful\Extensions\Tenancy\*` OR queries extension-owned tables such as `tenant_domains`; implementers bind shared IDs, consumers soft-resolve through the neutral contracts.
- **Inert-before-activation** (spec §5.5): every profile middleware consults the shared `FullTenantResolutionReadiness` and passes through unless `isReady()`. SP1 suites stay green with SP2a deployed-but-inactive.
- **Fail closed** (SP2 index §3.1): no default-tenant fallback outside `bootstrap_default`; unknown/unverified/disabled host → 404; provisioning/suspended tenants never resolve publicly.
- **Lifecycle** (SP2 index §3.11): `provisioning → active ⇄ suspended`; `markActive` accepts `provisioning` only; final ACTIVE owner protected transactionally; suspension never rewrites domain rows.
- **Domain state** (SP2 index §3.12): independent `verification_status` (pending|verified) + `status` (active|disabled); public resolution requires verified+active+tenant-active. Required `default_hosts` cannot be disabled/removed while full resolution is active.
- **PINNED INTERPRETATION (from source verification — reviewer note):** `SignedUrl` signs **path+query only, never host/scheme** (`framework src/Support/SignedUrl.php:62-82`), so "wrong host fails" (spec §10) cannot come from the signature. The EXISTING ownership-first `TenantBlobPolicy` already enforces resolved-request-tenant === blob owner for every access; Task 13 tests that invariant and does NOT add a signature shortcut or modify the policy.
- **VIEW authentication:** framework VIEW uses generic `auth:optional`: valid credentials populate the user, absent credentials pass through for signed grants, invalid credentials still 401. `UploadController::checkBlobAccess()` remains authoritative and treats every private alias (`private|true|'true'|1`) consistently.
- **Middleware param form:** the router parses `name:args` once then splits args on commas (`Router.php:1050-1051`), so the canonical route syntax is `tenant:public`, `tenant:admin`, `tenant:public,soft` — the spec's `tenant:public:soft` arrives as the single param `public:soft` and MUST also be accepted (split on `:` inside the middleware).
- Thallo test locations: app-root `tests/{Unit,Integration}` only (packages/*/tests are NOT discovered). Extension tests: extension `tests/` on SQLite `:memory:` — `tenant_domains` DDL must stay portable.
- Framework/extension/contracts changes are HELD and released via their own repos' conventions before Thallo pins (Task 17 is the release gate; mirrors SP1 Task 21).

## File Structure

**Framework (held → 1.68.0):** Create `src/Uploader/Contracts/{BlobRouteAction,BlobRouteMiddlewareProvider,BlobPublicUrlProvider}.php`; Modify `src/Routing/Middleware/AuthMiddleware.php` (`optional` mode), `routes/blobs.php` (soft-resolve provider; VIEW uses `auth:optional`), `src/Controllers/UploadController.php` (private-alias parity + signedUrl base override); Tests `tests/Unit/Controllers/BlobRouteSeamsTest.php` + auth middleware coverage.

**Contracts (→1.2.0):** Create `src/Tenancy/{TenantAdministration,TenantDomainAdministration,TenantResolutionProbe}.php`; CHANGELOG.

**Extension (→1.2.0):** Create `migrations/003_CreateTenantDomainsTable.php`, `src/Models/TenantDomain.php`, `src/Resolution/HostNormalizer.php`, `src/Resolution/Resolvers/DomainResolver.php`, `src/Resolution/ResolutionProfile.php`, `src/Bridge/{ContractTenantAdministration,ContractTenantDomainAdministration,ContractTenantResolutionProbe}.php`; Modify `src/Resolution/{ResolverFactory,TenantResolutionPipeline}.php`, `src/Resolution/Resolvers/SubdomainResolver.php`, `src/Http/TenantMiddleware.php`, `src/TenancyServiceProvider.php`, `config/tenancy.php`; Tests `tests/{HostNormalizerTest,DomainResolverTest,ResolutionProfilesTest,TenantAdministrationTest,TenantDomainAdministrationTest}.php` + `tests/Support/TenancyTestCase.php` (add 003).

**Thallo:** Create `packages/thallo-tenancy/src/Resolution/{FullResolutionActivation,ResolutionActivationStep,ResolutionActivationStore,ThalloFullResolutionReadiness}.php`, `packages/thallo-tenancy/src/Http/Controllers/{TenantDirectoryController,TenantManagementController,TenantDomainController,TenantMembershipController}.php`, `packages/thallo-tenancy/src/Console/{ResolutionActivateCommand,ResolutionStatusCommand,TenantManageCommand,DomainManageCommand,MemberManageCommand}.php`, `app/Content/Media/TenantBlobRouteMiddlewareProvider.php`, `app/Content/Media/TenantBlobPublicUrlProvider.php`; Modify `packages/thallo-tenancy/routes/enablement.php`, `packages/thallo-tenancy/src/TenancyServiceProvider.php`, `app/Providers/ThalloServiceProvider.php`, `routes/content.php` + `packages/*/routes/*.php`, `routes/admin.php` (webhooks → `tenant_system`), app tenancy config, `tests/Integration/Tenancy/RouteCoverageTest.php`; SPA `admin/src/{queries/tenants.ts,stores/tenant.ts,components/TenantSwitcher.vue,api/authFetch.ts}`; Tests `tests/Integration/Tenancy/{ResolutionActivationTest,TenantManagementApiTest,BlobResolutionTest,FullResolutionAcceptanceTest}.php`, `tests/Unit/Tenancy/Resolution/*`, `admin/src/__tests__/tenantSwitcher.spec.ts`. `TenantBlobPolicy` and `EngineMediaUrlResolver` are verification-only in SP2a: their existing owner check / host-relative no-cache behavior already satisfies the design.

**Sequencing:** T1 (framework) → T2 (contracts) → T3–T7 (extension) → T8–T16 (Thallo) → T17 (release gate). Verified boot order guarantees route-registration soft-resolve is safe (`Framework.php:541` providers boot before `:380` RouteManifest::load).

---

### Task 1: Framework blob seams (held → 1.68.0)

**Files:**
- Create: `src/Uploader/Contracts/BlobRouteAction.php`, `src/Uploader/Contracts/BlobRouteMiddlewareProvider.php`, `src/Uploader/Contracts/BlobPublicUrlProvider.php`
- Modify: `src/Routing/Middleware/AuthMiddleware.php`, `routes/blobs.php` (whole file body, currently 58 lines), `src/Controllers/UploadController.php:1021,381` (private-alias parity + signedUrl base URL)
- Test: `tests/Unit/Controllers/BlobRouteSeamsTest.php`

**Interfaces:**
- Consumes: `Router::group/get/post/delete` + `->middleware(array)` (verified `routes/blobs.php:42-57`); `has_service($context, $id)` helper (`src/helpers.php:403`); `container($context)` (`:242`); `SignedUrl::make($ctx)->generate(string $baseUrl, int $expiresIn, array $params = []): string` (`SignedUrl.php:35,56`).
- Produces: `BlobRouteAction` enum (UPLOAD|VIEW|INFO|DELETE|SIGN), `BlobRouteMiddlewareProvider::middlewareFor(BlobRouteAction): array`, `BlobPublicUrlProvider::publicBaseUrl(array $blob): ?string`. Task 13 binds both in Thallo.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use Glueful\Uploader\Contracts\BlobPublicUrlProvider;
use Glueful\Uploader\Contracts\BlobRouteAction;
use Glueful\Uploader\Contracts\BlobRouteMiddlewareProvider;
use PHPUnit\Framework\TestCase;

final class BlobRouteSeamsTest extends TestCase
{
    public function testBlobRouteActionCoversAllFiveActions(): void
    {
        self::assertSame(
            ['upload', 'view', 'info', 'delete', 'sign'],
            array_map(fn (BlobRouteAction $a) => $a->value, BlobRouteAction::cases())
        );
    }

    public function testProviderContractsExist(): void
    {
        self::assertTrue(interface_exists(BlobRouteMiddlewareProvider::class));
        self::assertTrue(interface_exists(BlobPublicUrlProvider::class));
        $m = new \ReflectionMethod(BlobRouteMiddlewareProvider::class, 'middlewareFor');
        self::assertSame(BlobRouteAction::class, (string) $m->getParameters()[0]->getType());
        $u = new \ReflectionMethod(BlobPublicUrlProvider::class, 'publicBaseUrl');
        self::assertTrue($u->getReturnType()?->allowsNull());
    }
}
```

- [ ] **Step 2: Run → FAIL** — `vendor/bin/phpunit tests/Unit/Controllers/BlobRouteSeamsTest.php` → "Class ... not found".

- [ ] **Step 3: Create the three contracts**

```php
<?php // src/Uploader/Contracts/BlobRouteAction.php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

/** The five blob endpoints an external middleware provider may contribute to. */
enum BlobRouteAction: string
{
    case UPLOAD = 'upload';
    case VIEW = 'view';
    case INFO = 'info';
    case DELETE = 'delete';
    case SIGN = 'sign';
}
```

```php
<?php // src/Uploader/Contracts/BlobRouteMiddlewareProvider.php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

/**
 * Generic extension seam consulted at blob ROUTE REGISTRATION time (providers boot before
 * framework routes load, so a registration-time soft-resolve is safe). Contributed middleware
 * is inserted after authentication and before rate limiting. VIEW uses OPTIONAL authentication:
 * credentials are validated when present, but absence passes through for signed grants. The
 * framework binds no default and never inspects what contributed middleware does.
 *
 * VIEW ordering guarantee: contributed VIEW middleware runs after `auth:optional` and MUST NOT
 * reject requests that may carry a valid signed grant before UploadController::show() validates
 * it. Authenticated unsigned private reads therefore keep working too.
 */
interface BlobRouteMiddlewareProvider
{
    /** @return list<string> middleware aliases (router `name:params` syntax allowed) */
    public function middlewareFor(BlobRouteAction $action): array;
}
```

```php
<?php // src/Uploader/Contracts/BlobPublicUrlProvider.php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

/**
 * Base URL (scheme + host) to compose a blob's public/signed URLs on, or null to keep the
 * request host. Signed URLs remain valid across hosts (signatures cover path + query only),
 * so overriding the base host never invalidates a grant.
 */
interface BlobPublicUrlProvider
{
    /** @param array<string,mixed> $blob */
    public function publicBaseUrl(array $blob): ?string;
}
```

- [ ] **Step 4: Rewire `routes/blobs.php`** — replace lines 27-57 (keep the file head; `$context` already resolved at `:20`):

```php
// Access control: 'private' | 'public' | 'upload_only' | true | false
$access = config($context, 'uploads.access', 'private');
$requireAuthAll = $access === 'private' || $access === true || $access === 'true' || $access === 1;
$uploadOnlyAuth = $access === 'upload_only';

// Generic seam: an application-bound provider may contribute middleware per blob action,
// inserted after auth and before rate limiting. Unbound => no contribution.
$provider = has_service($context, \Glueful\Uploader\Contracts\BlobRouteMiddlewareProvider::class)
    ? container($context)->get(\Glueful\Uploader\Contracts\BlobRouteMiddlewareProvider::class)
    : null;
$contrib = static fn (\Glueful\Uploader\Contracts\BlobRouteAction $a): array =>
    $provider?->middlewareFor($a) ?? [];

$uploadsPerMin = (int) config($context, 'uploads.rate_limits.uploads_per_minute', 30);
$retrievalPerMin = (int) config($context, 'uploads.rate_limits.retrieval_per_minute', 200);

use Glueful\Uploader\Contracts\BlobRouteAction as BlobAct;

// Compose: [auth?] + contributed + [rate_limit]. VIEW uses optional auth: credentials populate
// the user when valid, absence reaches signed-grant validation, invalid credentials still 401.
// INFO keeps strict route-level auth.
$postMw = array_merge(
    $requireAuthAll || $uploadOnlyAuth ? ['auth'] : [],
    $contrib(BlobAct::UPLOAD),
    ["rate_limit:{$uploadsPerMin},60"]
);
$viewMw = array_merge(['auth:optional'], $contrib(BlobAct::VIEW), ["rate_limit:{$retrievalPerMin},60"]);
$infoMw = array_merge(
    $requireAuthAll ? ['auth'] : [],
    $contrib(BlobAct::INFO),
    ["rate_limit:{$retrievalPerMin},60"]
);
$deleteMw = array_merge(
    $requireAuthAll || $uploadOnlyAuth ? ['auth'] : [],
    $contrib(BlobAct::DELETE),
    ['rate_limit:20,60']
);
$signMw = array_merge(['auth'], $contrib(BlobAct::SIGN));

$router->group(['prefix' => '/blobs'], function (Router $router) use ($postMw, $viewMw, $infoMw, $deleteMw, $signMw) {
    $router->post('', [UploadController::class, 'upload'])->middleware($postMw);
    $router->get('/{uuid}', [UploadController::class, 'show'])->middleware($viewMw);
    $router->get('/{uuid}/info', [UploadController::class, 'info'])->middleware($infoMw);
    $router->delete('/{uuid}', [UploadController::class, 'delete'])->middleware($deleteMw);
    $router->post('/{uuid}/signed-url', [UploadController::class, 'signedUrl'])->middleware($signMw);
});
```

> NOTE: `use` statements must sit at file top in PHP — move `use Glueful\Uploader\Contracts\BlobRouteAction as BlobAct;` up beside the existing `use` imports at the head of `routes/blobs.php`; shown inline above only for reading order.

- [ ] **Step 4a: Add generic optional authentication** — in `AuthMiddleware::handle()`, recognize
  the `optional` param before the missing-token failure: no credentials → `$next($request)`;
  credentials present → run the exact existing authentication/expiry/attribute population path;
  malformed/invalid credentials still return 401. Add focused tests for all three branches.

- [ ] **Step 4b: Make controller access aliases consistent** — in `checkBlobAccess()`, replace
  the public shortcut's `$globalAccess !== 'private'` check with
  `!$this->requiresAuthFor('retrieve')`. Keep authenticated-user and valid-signature checks before
  the final 401, so `private`, `true`, `'true'`, and `1` behave identically without blocking a
  valid signed grant.

- [ ] **Step 5: `signedUrl()` base-URL override** — in `src/Controllers/UploadController.php`, add ctor param `?BlobPublicUrlProvider $publicUrlProvider = null` (property `$this->publicUrlProvider = $publicUrlProvider;`, mirror the existing nullable `BlobCreatedHook` pattern at the ctor) and replace line 381:

```php
$base = $this->publicUrlProvider?->publicBaseUrl($blob) ?? $request->getSchemeAndHttpHost();
$baseUrl = rtrim($base, '/') . '/blobs/' . $uuid;
```

Also thread the new param through `src/Container/Providers/StorageProvider.php`'s `UploadController` factory with the established soft-resolve shape (`$c->has(BlobPublicUrlProvider::class) ? $c->get(...) : null`), and update direct `new UploadController(...)` test call sites (nullable → pass nothing).

- [ ] **Step 6: Behavior tests** — extend `BlobRouteSeamsTest` (or the existing `UploadControllerVariantTest` harness) with: (a) provider bound → VIEW order is `auth:optional`, contributed alias, `rate_limit:*`; (b) INFO keeps strict `auth`; (c) absent credentials + valid signed VIEW succeeds; valid bearer credentials + unsigned private VIEW succeeds; invalid credentials fail 401; (d) anonymous unsigned public blobs under every private alias (`private`, `true`, `'true'`, `1`) fail 401; (e) `publicBaseUrl` returning `https://acme.example` → `signed_url` starts with it; null → request host. Run full framework suite: `composer test` → PASS; `composer phpcs` clean.

- [ ] **Step 7: CHANGELOG `[Unreleased]`** — add the two seams + generic optional-auth mode + VIEW correction (signed grants and authenticated unsigned reads both preserved; private aliases normalized).

- [ ] **Step 8: Commit step SKIPPED (HELD).** Record in ledger.

---

### Task 2: Contracts 1.2.0 — three neutral interfaces

**Files:**
- Create: `src/Tenancy/TenantAdministration.php`, `src/Tenancy/TenantDomainAdministration.php`, `src/Tenancy/TenantResolutionProbe.php`
- Modify: `CHANGELOG.md`, `README.md` (Tenancy section), `composer.json` (`extra.glueful.version` → 1.2.0)
- Test: `tests/Unit/Tenancy/AdministrationContractsTest.php`

**Interfaces (Produces — verbatim from spec §4; every later task consumes these exact signatures):**

```php
<?php // src/Tenancy/TenantAdministration.php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tenancy;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Tenant lifecycle + membership administration. The implementation (not callers) owns the
 * invariants: create() lands in PROVISIONING; markActive() accepts provisioning only;
 * reactivate() accepts suspended only; roles validate against the configured allowlist;
 * the final ACTIVE owner cannot be removed or demoted (transactionally, under row locks).
 */
interface TenantAdministration
{
    /** Creates in state PROVISIONING (never active); returns tenant uuid. */
    public function create(ApplicationContext $c, string $slug, string $name, string $ownerUserUuid): string;

    public function suspend(ApplicationContext $c, string $tenantUuid): void;

    public function reactivate(ApplicationContext $c, string $tenantUuid): void;

    /** Seed-success boundary: PROVISIONING -> ACTIVE. The seeder is the intended caller. */
    public function markActive(ApplicationContext $c, string $tenantUuid): void;

    /** @return list<array{uuid:string,slug:string,name:string,status:string}> */
    public function listTenants(ApplicationContext $c, ?string $status = null): array;

    /** @return array{uuid:string,slug:string,name:string,status:string}|null */
    public function getTenant(ApplicationContext $c, string $tenantUuid): ?array;

    /**
     * Active memberships joined to ACTIVE tenants for one user.
     * @return list<array{uuid:string,slug:string,name:string,status:string}>
     */
    public function listTenantsForUser(ApplicationContext $c, string $userUuid): array;

    /** @return list<array{uuid:string,user_uuid:string,role:string,status:string}> */
    public function listMembers(ApplicationContext $c, string $tenantUuid): array;

    public function addMember(ApplicationContext $c, string $tenantUuid, string $userUuid, string $role): void;

    public function removeMember(ApplicationContext $c, string $tenantUuid, string $userUuid): void;

    public function setMemberRole(ApplicationContext $c, string $tenantUuid, string $userUuid, string $role): void;
}
```

```php
<?php // src/Tenancy/TenantDomainAdministration.php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tenancy;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Domain administration over the tenant_domains surface. Hosts are normalized + validated on
 * every write; verification (DNS fact) and status (operator choice) are independent columns.
 * While full resolution is active, disable/remove MUST refuse to dismantle the last active
 * mapping of any required default host.
 */
interface TenantDomainAdministration
{
    /** @return array{uuid:string,token:string} domain uuid + DNS TXT verification token */
    public function addDomain(ApplicationContext $c, string $tenantUuid, string $host): array;

    /** Performs the DNS TXT check now; returns the new verification_status. */
    public function verifyDomain(ApplicationContext $c, string $domainUuid): string;

    public function disableDomain(ApplicationContext $c, string $domainUuid): void;

    public function enableDomain(ApplicationContext $c, string $domainUuid): void;

    public function removeDomain(ApplicationContext $c, string $domainUuid): void;

    /** @return list<array{uuid:string,host:string,verification_status:string,status:string}> */
    public function listDomains(ApplicationContext $c, string $tenantUuid): array;

    /** @return array{uuid:string,tenant_uuid:string,host:string,verification_status:string,status:string}|null */
    public function getDomain(ApplicationContext $c, string $domainUuid): ?array;

    /** Pre-verified operator-controlled host (activation auto-mapping only); returns domain uuid. */
    public function addPreverifiedDomain(ApplicationContext $c, string $tenantUuid, string $host): string;
}
```

```php
<?php // src/Tenancy/TenantResolutionProbe.php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tenancy;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Resolve one normalized host through the public profile's REAL resolver/validation pipeline
 * without consulting the deployment activation gate. Used only by activation verifiers; it
 * does NOT bypass normalization, precedence, existence, or active-status checks.
 */
interface TenantResolutionProbe
{
    /** @return ?string tenant uuid, or null when the host does not resolve */
    public function probePublicHost(ApplicationContext $c, string $host): ?string;
}
```

- [ ] **Step 1: Failing test** — `tests/Unit/Tenancy/AdministrationContractsTest.php`: `interface_exists` for all three; reflection-assert `create()` returns `string`, `getTenant()` and `getDomain()` allow null, `addDomain()` returns `array`, `probePublicHost()` allows null. Run → FAIL.
- [ ] **Step 2: Create the three interfaces exactly as above.** Run → PASS. `composer test` (21+new) PASS; `composer phpcs` clean.
- [ ] **Step 3: CHANGELOG `[Unreleased]` → cut `[1.2.0]`-ready entries** (do NOT cut the version heading yet — Task 17 releases); README Tenancy section gains three terse paragraphs matching the existing per-contract style.
- [ ] **Step 4: Commit step SKIPPED (HELD).**

---

### Task 3: Extension — `tenant_domains` migration + `TenantDomain` model

**Files:**
- Create: `migrations/003_CreateTenantDomainsTable.php`, `src/Models/TenantDomain.php`
- Modify: `tests/Support/TenancyTestCase.php` (run 003 in setUp, after 002)
- Test: `tests/TenantDomainSchemaTest.php`

**Interfaces:**
- Consumes: schema-builder API exactly as `migrations/002_CreateTenantMembershipsTable.php` (`$schema->createTable`, `$table->string/unique/index/foreign(...)->cascadeOnDelete()`); **namespace `Glueful\Migrations`** (verified `001:5`); Model base as `src/Models/TenantMembership.php:30` (`timestamps=false`, int PK).
- Produces: table `tenant_domains`; `TenantDomain` model (`fillable=['uuid','tenant_uuid','host','verification_token']`; **`verification_status`/`status`/`verified_at` NOT fillable** — bridges mutate via raw `db()` updates, matching the codebase's non-fillable-status convention); constants `TenantDomain::VERIFICATION_PENDING='pending'`, `VERIFICATION_VERIFIED='verified'`, `STATUS_ACTIVE='active'`, `STATUS_DISABLED='disabled'`; helper `isPubliclyResolvable(): bool` (verified && active — tenant status checked by callers who hold the Tenant).

- [ ] **Step 1: Failing schema test** — create tenant + domain rows in the SQLite harness, assert unique host violation raises, assert defaults (`verification_status='pending'`, `status='active'`).
- [ ] **Step 2: Migration** (portable DDL — extension tests run SQLite):

```php
<?php // migrations/003_CreateTenantDomainsTable.php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

class CreateTenantDomainsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('tenant_domains')) {
            return;
        }
        $schema->createTable('tenant_domains', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->string('host', 255);                                  // normalized (lowercase, ASCII)
            $table->string('verification_status', 16)->default('pending'); // pending|verified — DNS fact
            $table->string('status', 16)->default('active');               // active|disabled — operator choice
            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->unique('uuid');
            $table->unique('host');                                        // GLOBAL host uniqueness
            $table->index('tenant_uuid');
            $table->index('status');
            $table->foreign('tenant_uuid')->references('uuid')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('tenant_domains');
    }

    public function getDescription(): string
    {
        return 'Tenant domain surface: normalized globally-unique host with independent verification/status columns';
    }
}
```

- [ ] **Step 3: Model** — mirror `TenantMembership` (`table='tenant_domains'`, `timestamps=false`, constants + `isPubliclyResolvable()`); bridge mutations explicitly update `updated_at`; add 003 to `TenancyTestCase::setUp()` after 002. Run → PASS; phpcs clean.
- [ ] **Step 4: Commit SKIPPED (HELD).**

---

### Task 4: Extension — `HostNormalizer`

**Files:** Create `src/Resolution/HostNormalizer.php`; Modify `config/tenancy.php` (add `public_origin` block); Test `tests/HostNormalizerTest.php`.

**Interfaces:**
- Produces: `HostNormalizer::normalize(string $host): string` (throws `InvalidHostException` on rejects) and `HostNormalizer::validateForRegistration(string $normalized, array $publicOrigin, bool $allowBaseDomain = false): void`. Config `tenancy.public_origin` = `['scheme'=>'https','base_domain'=>env('TENANCY_BASE_DOMAIN'),'default_hosts'=>[],'reserved_labels'=>['www','api','admin']]` (spec §5.2 shape; merged into the extension config so activation, resolvers, normalization, and Thallo's blob URL provider all read ONE source).
- Consumes: nothing new.

- [ ] **Step 1: Failing table-test** covering the spec §5.2 matrix: `ACME.Example.COM.` → `acme.example.com`; `foo.test:8080` → `foo.test`; IDN `münchen.de` → `xn--mnchen-3ya.de`; rejects: `192.168.0.1`, `[::1]`, `*.example.com`, `bad_host!`, empty label, reserved label `www.{base_domain}` (when validating for registration), base domain itself unless `$allowBaseDomain`.
- [ ] **Step 2: Implement** — pure static class: lowercase → `rtrim('.')` → strip `:port` suffix → `idn_to_ascii(..., IDNA_NONTRANSITIONAL_TO_ASCII)` → regex `^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$` → reject `filter_var(FILTER_VALIDATE_IP)` and literal `*`. `validateForRegistration` additionally rejects `{reserved_label}.{base_domain}` collisions and the bare base domain unless allowed. New exception `src/Exceptions/InvalidHostException.php extends \InvalidArgumentException`.
- [ ] **Step 3: Run → PASS; phpcs clean. Commit SKIPPED (HELD).**

---

### Task 5: Extension — `DomainResolver` + precedence

**Files:** Create `src/Resolution/Resolvers/DomainResolver.php`; Modify `src/Resolution/Resolvers/SubdomainResolver.php` (shared origin config + normalization), `src/Resolution/ResolverFactory.php:32,39` (add `'domain'` FIRST in `DEFAULT_ORDER` and MAP), `config/tenancy.php` (`resolvers` list gains `'domain'` first; retire duplicated `subdomain.base_domain`); Test `tests/DomainResolverTest.php`.

**Interfaces:**
- Consumes: `TenantResolverInterface::resolve(Request $request, ApplicationContext $context): ?string` (verified `TenantResolverInterface.php:17`); `Request::getHost()` (trusted-proxy aware via framework `RequestProvider` — no extension proxy config, spec §5.3); `TenantDomain` (Task 3); `HostNormalizer` (Task 4).
- Produces: `'domain'` resolver name; resolution = exact normalized-host match where `verification_status='verified' AND status='active'` joined to `tenants.status='active'` → returns the tenant **uuid**. `SubdomainResolver` reads ONLY `tenancy.public_origin.base_domain` and normalizes both request host and configured base through `HostNormalizer`, so every host path shares one source and normalization rule.

- [ ] **Step 1: Failing test** — seed tenant+verified/active domain → resolves uuid; unverified → null; disabled → null; tenant suspended → null; subdomain fallback still works from `public_origin.base_domain` when no domain matches; deleting/contradicting the legacy `subdomain.base_domain` value does not affect resolution; normalized IDN/case/trailing-dot hosts behave identically in both resolvers; precedence test: a custom domain that also matches the subdomain pattern resolves via the DOMAIN row's tenant, not slug inference.
- [ ] **Step 2: Implement**

```php
<?php // src/Resolution/Resolvers/DomainResolver.php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution\Resolvers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Exceptions\InvalidHostException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantDomain;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/** Exact verified+active host match; precedes SubdomainResolver in every public profile. */
final class DomainResolver implements TenantResolverInterface
{
    public function resolve(Request $request, ApplicationContext $context): ?string
    {
        try {
            $host = HostNormalizer::normalize($request->getHost());
        } catch (InvalidHostException) {
            return null; // malformed host: let the chain (and pipeline) fail closed downstream
        }
        $domain = TenantDomain::query($context)
            ->where('host', $host)
            ->where('verification_status', TenantDomain::VERIFICATION_VERIFIED)
            ->where('status', TenantDomain::STATUS_ACTIVE)
            ->first();
        if ($domain === null) {
            return null;
        }
        $tenant = Tenant::query($context)->where('uuid', $domain->tenant_uuid)->first();
        return $tenant !== null && $tenant->isActive() ? $domain->tenant_uuid : null;
    }
}
```

- [ ] **Step 3: Registration + subdomain migration** — MAP `'domain' => DomainResolver::class` first; `DEFAULT_ORDER` and `config/tenancy.php` `resolvers` both gain `'domain'` at index 0. Refactor `SubdomainResolver` to consume `public_origin.base_domain` + `HostNormalizer`; remove the duplicated config key (document the 1.2 migration in CHANGELOG). Run → PASS; full extension suite green; phpcs clean. Commit SKIPPED.

---

### Task 6: Extension — resolver profiles + `TenantMiddleware` param routing + inert gate

**Files:** Create `src/Resolution/ResolutionProfile.php`; Modify `config/tenancy.php` (`profiles` block per spec §5.5), `src/Resolution/TenantResolutionPipeline.php` (profile-aware), `src/Http/TenantMiddleware.php` (param routing), `src/TenancyServiceProvider.php` (pipeline factory passes profile registry); Test `tests/ResolutionProfilesTest.php`.

**Interfaces:**
- Consumes (all verified): `TenantMiddleware::handle(Request, callable, mixed ...$params): mixed` with router params from `tenant:public,soft` → `['public','soft']` and `tenant:public:soft` → `['public:soft']` (`Router.php:1050-1051`); current `$params` handling interprets only `'optional'` (`TenantMiddleware.php:48`); `TenantResolutionPipeline::resolve(Request, ApplicationContext, bool $required)` (`:43`); `ResolverFactory::chain()` config-driven build; `FullTenantResolutionReadiness::isReady(ApplicationContext): bool` (contracts).
- Produces: `ResolutionProfile` value object `{name, resolvers: list<string>, requireMembership: bool, requireAuthenticated: bool, uuidOnly: bool, conflictReject: bool}`; new pipeline signature `resolve(Request $request, ApplicationContext $context, bool $required, ?ResolutionProfile $profile = null): void` (null profile = SP1 behavior, fully backward compatible); middleware param grammar: first token = profile name or `optional`; token `soft` = soft flag; tokens may arrive colon-joined (split each param on `:` before interpreting).

- [ ] **Step 1: Failing tests** — (a) `tenant:public` with an unmapped host → 404, with a verified host → tenant attached, no membership demanded for anonymous; (b) `tenant:admin` header `X-Tenant-Id: {uuid}` + active membership → attached; slug in header → rejected (uuid_only); header + differing JWT claim → 403/409; header + agreeing claim → attached; (c) `tenant:public,soft` unmapped host → passes through with NO tenant attached and NO exception; (d) **inert gate**: bind a `FullTenantResolutionReadiness` stub returning `false` → all profile middleware pass through without resolving (and WITHOUT touching the pipeline); returning `true` → active. (e) legacy `tenant` / `tenant:optional` unchanged.
- [ ] **Step 2: `ResolutionProfile` + config**

```php
'profiles' => [
    'public' => ['resolvers' => ['domain', 'subdomain'], 'require_membership' => false,
                 'require_authenticated' => false, 'uuid_only' => false, 'conflict' => 'ignore'],
    'admin'  => ['resolvers' => ['header', 'jwt'], 'require_membership' => true,
                 'require_authenticated' => true, 'uuid_only' => true, 'conflict' => 'reject'],
],
```

`ResolutionProfile::fromConfig(ApplicationContext $c, string $name): self` reads `tenancy.profiles.{name}` (unknown profile → `\InvalidArgumentException`, fail loud at request time in dev, 500 — a misconfigured route is a deploy error).

- [ ] **Step 3: Pipeline profile-awareness** — inside `resolve()`: when `$profile !== null`, build the chain from `$profile->resolvers` (via `ResolverFactory` name map) instead of the global list; when `$profile->conflictReject`, collect BOTH header and jwt candidates (run each resolver individually rather than first-wins) and throw `TenantAccessDeniedException('Conflicting tenant selectors')` when both present and different; when `$profile->uuidOnly`, skip the slug fallback lookup (`:61`); membership/auth enforcement keyed off the profile flags instead of the global `tenancy.enforcement.require_authenticated` (public profile: skip auth+membership entirely after the tenant is active-validated); `hide_existence` behavior unchanged. Null profile ⇒ exact current code path (SP1 regression safety).
- [ ] **Step 4: Middleware param routing + inert gate** — in `TenantMiddleware::handle()`, parse
  parameters and execute the inert early-return BEFORE `CurrentContext::set()` or any request-state
  mutation. This prevents an inactive profile from leaking process/request tenant state by
  returning ahead of the existing `finally`. Then use:

```php
$tokens = [];
foreach ($params as $p) {
    foreach (explode(':', (string) $p) as $t) {   // accept colon-joined tokens
        $tokens[] = trim($t);
    }
}
$soft = in_array('soft', $tokens, true);
$required = !in_array('optional', $tokens, true) && !$soft;
$profileName = null;
foreach ($tokens as $t) {
    if ($t !== '' && $t !== 'soft' && $t !== 'optional') {
        $profileName = $t;
        break;
    }
}

// Inert-before-activation (spec §5.5): profiles consult the SAME readiness predicate the
// runtime composite uses. Unbound or not ready => pass through without resolving.
if ($profileName !== null) {
    $container = $this->context->getContainer();
    $ready = $container->has(FullTenantResolutionReadiness::class)
        && $container->get(FullTenantResolutionReadiness::class)->isReady($this->context);
    if (!$ready) {
        return $next($request);
    }
    $profile = ResolutionProfile::fromConfig($this->context, $profileName);
}
```

then enter the existing context-owning `try/finally` and pass `$profile ?? null` into `pipeline->resolve($request, $this->context, $required, $profile ?? null)`; on `soft`, catch `TenantNotFoundException|TenantAccessDeniedException` and pass through (attach nothing) instead of 404/403. Add an assertion that an inert request leaves `CurrentContext` and `tenancy.user_uuid` empty. Legacy no-param calls keep the existing behavior byte-for-byte.
- [ ] **Step 5: Run → PASS (new + full extension suite); phpcs clean. Commit SKIPPED.**

---

### Task 7: Extension — administration bridges + resolution probe + bindings

**Files:** Create `src/Bridge/ContractTenantAdministration.php`, `src/Bridge/ContractTenantDomainAdministration.php`, `src/Bridge/ContractTenantResolutionProbe.php`; Modify `src/TenancyServiceProvider.php` `services()` (three bindings, shape verified `:55-76`), `composer.json` (contracts `^1.2.0`, `extra.glueful.version` 1.2.0); Test `tests/TenantAdministrationTest.php`, `tests/TenantDomainAdministrationTest.php`.

**Interfaces:**
- Consumes: contracts from Task 2; neutral `FullTenantResolutionReadiness` soft-resolved ONLY at method time inside disable/remove (never a constructor dependency, avoiding readiness→domains→readiness DI recursion); models; role allowlist; `HostNormalizer` + `tenancy.public_origin`; driver name for row locking; profile pipeline; injectable DNS lookup.
- Produces: bindings `TenantAdministration::class => ['class' => ContractTenantAdministration::class, 'shared' => true, 'autowire' => true]` (same for the other two) consumed by Thallo Tasks 9/11/12.

- [ ] **Step 1: Failing tests** — lifecycle: `create()` atomically creates provisioning tenant + active owner; install a temporary SQLite trigger that raises on `tenant_memberships` INSERT, call create, and assert NO tenant remains; invalid/reserved slug rejected; transition/role/get tests. Final-owner protection: one owner remove/demote throws; two owners permits one mutation; SQLite branch emits no `FOR UPDATE`. Domains: timestamps/get/idempotency/foreign-owner tests; method-time readiness stub blocks required-host disable/remove while ordinary `listDomains()` resolves without constructing readiness (explicit cycle regression). Probe uses the real public profile without activation.
- [ ] **Step 2: `ContractTenantAdministration`** — key implementation points (full class in the extension's bridge style, no-nonsense):
  - `create()`: validate one-label slug grammar and reject `public_origin.reserved_labels` by validating `{slug}.{base_domain}` through `HostNormalizer`; wrap tenant insert/status update + owner membership insert/role update in ONE DB transaction. A failure leaves neither row. Keep DB unique constraints as the race backstop. (Mode gating remains Thallo's guard at HTTP/CLI seams.)
  - `markActive()`: single `UPDATE tenants SET status='active' WHERE uuid=? AND status='provisioning'`; 0 rows → `\RuntimeException('markActive requires provisioning state')`. Same one-statement CAS pattern for `suspend` (from active) and `reactivate` (from suspended).
  - final-owner rule: wrap in `db($c)->transaction(...)`; on PostgreSQL/MySQL append `FOR UPDATE`, while SQLite deliberately omits it and relies on its single-writer transaction. Count===1 and the mutation targets that row → throw `\DomainException('Cannot remove or demote the final active owner')`. The true two-connection race runs in Task 16 on PostgreSQL.
  - `getTenant()`, `listTenantsForUser()`, and `listMembers()` return neutral array projections; no concrete model crosses the contract.
- [ ] **Step 3: `ContractTenantDomainAdministration`** — `addDomain`: normalize + validate; token `bin2hex(random_bytes(32))`. `verifyDomain`: use injectable `DnsTxtLookup`; update verification timestamps. `getDomain()`/`listDomains()` expose neutral projections. Required-host protection is a private method invoked only by disable/remove; it uses the method's `ApplicationContext` container to soft-resolve `FullTenantResolutionReadiness` lazily, then checks normalized `default_hosts`. The constructor MUST NOT depend on readiness. `addPreverifiedDomain` is idempotent by normalized host and rejects foreign ownership; every mutation updates `updated_at`.
- [ ] **Step 4: `ContractTenantResolutionProbe`** — build the `public` `ResolutionProfile` + chain directly (bypassing ONLY the middleware readiness gate), synthesize `Request::create('https://'.$host.'/')`, run the same DomainResolver→SubdomainResolver chain + existence/active validation, return uuid or null.
- [ ] **Step 5: Bindings + config bump.** Run full extension suite → PASS; phpcs clean. CHANGELOG `[Unreleased]` entries. Commit SKIPPED.

---

### Task 8: Thallo — `tenancy.public_origin` overlay + `ThalloFullResolutionReadiness` (the shared predicate)

**Files:** Create `packages/thallo-tenancy/src/Resolution/ThalloFullResolutionReadiness.php`; Modify `packages/thallo-tenancy/src/TenancyServiceProvider.php` (bind `FullTenantResolutionReadiness`), Thallo config overlay (`config/tenancy.php` app-side: `public_origin` values for the deployment); Test `tests/Unit/Tenancy/Resolution/FullResolutionReadinessTest.php`.

**Interfaces:**
- Consumes: `FullTenantResolutionReadiness::isReady(ApplicationContext): bool`; `SystemFlags::get/put`; new flag keys `tenancy.resolution` + `tenancy.resolution_step`; `SystemFlags::defaultTenantUuid()`; neutral `TenantDomainAdministration::listDomains(defaultTenantUuid)` from Task 2. Thallo never imports extension models or queries `tenant_domains` directly.
- Produces: the ONE predicate (SP2 index §3.13) consumed by: extension profile middleware (Task 6 soft-resolve), `TenancyRuntimeReadiness::mode()` (existing soft-resolve, verified `:36-40`), creation guard tightening (Task 9), blob origin provider (Task 13).

- [ ] **Step 1: Failing tests** — unbound flag → false; flag `full` + every required host present in `listDomains(defaultTenant)` as verified+active → true; a required host mapped to another tenant is absent from that list and therefore false; missing/disabled host → false and composite mode `none`; tenancy disabled or domain contract unavailable → false.
- [ ] **Step 2: Implement** — ctor `(SystemFlags $flags, ?TenantDomainAdministration $domains)`; `isReady()` short-circuits enabled + flag + default tenant, reads only `listDomains($defaultTenant)`, and proves every normalized required host is verified+active. The factory soft-resolves the contract so deployed-but-unavailable integration fails closed. Bind one shared `FullTenantResolutionReadiness` instance.
- [ ] **Step 3: `BootstrapTenantCreationGuard` tightening (spec §6)** — modify `assertCanCreateTenant()`: throw unless `readiness->mode() === MODE_FULL_RESOLUTION` (both `bootstrap_default` AND `none` refuse; message names the activation CLI). Update its SP1 unit test accordingly.
- [ ] **Step 4: Inert regression** — run the full SP1 tenancy-on suite with the binding present but flag unset → green (proves binding existence alone never flips the mode: `isReady()` false ⇒ composite still returns `bootstrap_default`). Run → PASS; phpcs clean. Commit SKIPPED.

---

### Task 9: Thallo — `FullResolutionActivation` (gated, resumable, fresh-boot proof) + CLI

**Files:**
- Create: `packages/thallo-tenancy/src/Resolution/ResolutionActivationStep.php`, `.../ResolutionActivationStore.php`, `.../FullResolutionActivation.php`, `packages/thallo-tenancy/src/Console/ResolutionActivateCommand.php`, `.../ResolutionStatusCommand.php`
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php` (bindings)
- Test: `tests/Integration/Tenancy/ResolutionActivationTest.php`

**Interfaces:**
- Consumes (all verified): `SystemFlags` (`get/put/forget/clearCache`); `EnablementLock::withLock(callable): mixed` (PG advisory, `EnablementLock.php:19` — REUSE the same lock service, activation and enablement must not interleave); `TenantDomainAdministration::addPreverifiedDomain()` (Task 7); `TenantResolutionProbe::probePublicHost()` (Task 7); `SystemFlags::defaultTenantUuid()`; route-cache rebuild via the framework CLI (`php glueful route:cache:clear` — same mechanism `ClearRenderCacheCommand` shells or the RouteCache service; probe the route table via `Router::getStaticRoutes()/getDynamicRoutes()` as `RouteCoverageTest` does); `config('tenancy.public_origin.default_hosts')`.
- Produces: `ResolutionActivationStep` enum: `INACTIVE`, `MAPPING_HOSTS`, `VERIFYING_WIRING`, `REBUILDING_ROUTES`, `AWAITING_FRESH_BOOT`, `FULL`, `FAILED`; `ResolutionActivationStore` with ordinary CAS methods plus transactional `completeFull(expected)` that writes BOTH `tenancy.resolution='full'` and step `FULL` atomically; `FullResolutionActivation::{status,advance,retry}`.

- [ ] **Step 1: Failing integration test** (PG harness): exercise every hop and real second boot. On successful probe call `completeFull(AWAITING_FRESH_BOOT)` and assert step+flag become visible together. Add a store-level rollback test that injects/fires a failpoint between the two writes: transaction rolls back, leaving step `AWAITING_FRESH_BOOT` and flag unset; retry then succeeds. Probe mismatch records failure and never writes the flag. After FULL: composite mode is full.
- [ ] **Step 2: Implement the store + step enum** — ordinary methods mirror `EnablementStore`, but `completeFull()` runs on the shared Connection transaction under `EnablementLock`: verify expected step, write resolution flag, invoke an optional constructor-injected `?Closure $afterResolutionWrite` failpoint (tests only; provider passes null), write FULL step, commit, then clear `SystemFlags` cache. It returns false on stale state; callers MUST check it. The failpoint gives the rollback test a deterministic crash between writes without production side effects.
- [ ] **Step 3: Implement `FullResolutionActivation`** — ctor `(ApplicationContext, ResolutionActivationStore, EnablementLock, SystemFlags, TenantDomainAdministration, TenantResolutionProbe, TenantRuntimeReadiness)`; every hop runs under the shared advisory lock. The final hop probes, calls checked `completeFull()`, and never performs a separate flag write. Guard: refuse to start unless tenancy is enabled and SP1 step is ON.
- [ ] **Step 4: CLI** — `#[AsCommand('thallo:tenancy:resolution:activate')]`: read `status()` first; if `AWAITING_FRESH_BOOT` and step unchanged after `advance()` → warn "Re-run in a fresh process"; FAILED → `self::FAILURE`. `resolution:status` prints the status JSON. Both extend `BaseCommand`, resolve via `$this->getService(...)`.
- [ ] **Step 5: Run → PASS; phpcs clean. Commit SKIPPED.**

---

### Task 10: Thallo — route wiring + `RouteCoverageTest` evolution

**Files:**
- Modify: `routes/content.php:20` (group middleware), `packages/thallo-render/routes/public-routes.php`, `packages/thallo-seo/routes/public-routes.php`, `packages/thallo-navigation/routes/public-routes.php`, `packages/thallo-search/routes/public-routes.php` (public groups → prepend `tenant:public`), `routes/admin.php` + `packages/*/routes/admin-routes.php` (tenant-data groups → `['auth','tenant:admin','tenant_bootstrap']` order), `tests/Integration/Tenancy/RouteCoverageTest.php`
- Test: the evolved `RouteCoverageTest` itself + `tests/Integration/Tenancy/BootstrapResolutionTest.php` (extend)

**Interfaces:**
- Consumes (verified): group middleware runs BEFORE route middleware (`Router.php:116-118`, `Route.php:56-59`); current `RouteCoverageTest` asserts `$middleware[0]==='tenant_bootstrap'` (`:47-49`) — must evolve; current groups: `routes/content.php:20` `['tenant_bootstrap','optional_api_key']`, `routes/admin.php:40,204,279,384` `['tenant_bootstrap','auth']`.
- Produces: public groups `['tenant:public','tenant_bootstrap','optional_api_key']`; admin tenant-data groups `['auth','tenant:admin','tenant_bootstrap']`; evolved coverage rule.

- [ ] **Step 1: Evolve `RouteCoverageTest` FIRST (RED)** — replace the index-0 assertion with:

```php
$mw = $route->getMiddleware();
$idx = array_search('tenant_bootstrap', $mw, true);
if ($idx !== false) {
    // tenant_bootstrap sits at 0 (no resolver) OR immediately after its authoritative
    // resolver profile; auth may precede the admin resolver. Nothing else may come between.
    $allowedPrefixes = [
        [],                              // bare: index 0 (SP1 shape, public)
        ['tenant:public'],               // public delivery
        ['auth', 'tenant:admin'],        // admin tenant-data
    ];
    $prefix = array_slice($mw, 0, $idx);
    self::assertContains($prefix, $allowedPrefixes, sprintf(
        '%s %s: tenant_bootstrap must directly follow its resolver (prefix was [%s])',
        $method,
        $path,
        implode(',', $prefix)
    ));
}
```

Keep: exactly-one-marker rule (`tenant:*` profiles are NOT markers — exclude them from the marker intersection), the `/v1/collections` fence rule, `assertGreaterThan(40, $checked)`.
- [ ] **Step 2: Classify the webhook group explicitly** — source verification resolved the prior unknown: `routes/admin.php:322` registers framework `WebhookController` handlers over global webhook tables, all gated by `system.access`. Change the group to `['tenant_system', 'auth']`. The handler-based coverage test would otherwise ignore it, but route ownership/policy is system-wide and should remain explicit in the route table.
- [ ] **Step 3: Wire the groups** (mechanical; exact arrays above). Public example — `routes/content.php:20`:

```php
$router->group(['prefix' => '/v1/content', 'middleware' => ['tenant:public', 'tenant_bootstrap', 'optional_api_key']], function (Router $router): void {
```

Admin example — `routes/admin.php:40`:

```php
$router->group(['middleware' => ['auth', 'tenant:admin', 'tenant_bootstrap']], function (Router $router): void {
```

- [ ] **Step 4: Inert regression** — with the flag unset, profiles pass through (Task 6), `tenant_bootstrap` still bootstraps: full SP1 on-suite green. With a readiness stub active in a test boot: public request on unmapped host → 404 BEFORE `tenant_bootstrap` (profile rejects); mapped host → resolved tenant, bootstrap defers. Run → PASS; phpcs clean. Commit SKIPPED.

---

### Task 11: Thallo — management HTTP surface

**Files:**
- Create: `packages/thallo-tenancy/src/Http/Controllers/TenantDirectoryController.php` (my-tenants), `.../TenantManagementController.php`, `.../TenantDomainController.php`, `.../TenantMembershipController.php`
- Modify: `packages/thallo-tenancy/routes/enablement.php` (append management routes), `packages/thallo-tenancy/src/TenancyServiceProvider.php` (controller bindings, autowired like `TenancyEnablementController` at `:111`)
- Test: `tests/Integration/Tenancy/TenantManagementApiTest.php`

**Interfaces:**
- Consumes: administration contracts; creation guard; `PermissionManager::can(actorUuid, 'system.access', 'thallo', context)` for the un-gated `my-tenants` branch (soft-resolution failure means ordinary-member behavior, never bypass); established controller/Response/UserIdentity/route patterns. Platform mutation/list-all routes still carry `content_permission:system.access`.
- Produces routes (all under `/v1/admin/tenancy`, all `tenant_system` — management is cross-tenant by nature; the SELECTED tenant is payload/param, not request context):
  - `GET  /my-tenants` — auth only (+`tenant_system`): ordinary user → `listTenantsForUser(auth uuid)`; caller holding `system.access` → `listTenants('active')`.
  - `POST /tenants` `{slug,name}` → guard → `create(..., owner: auth uuid)` → 201 `{uuid,status:'provisioning'}`.
  - `POST /tenants/{uuid}/suspend`, `POST /tenants/{uuid}/reactivate`, `GET /tenants`.
  - `POST /tenants/{uuid}/domains` `{host}` → 201 `{uuid,token,txt_record:"_thallo-verify.{host}"}`; `POST /domains/{uuid}/verify`; `POST /domains/{uuid}/enable|disable`; `DELETE /domains/{uuid}`; `GET /tenants/{uuid}/domains`.
  - `GET /tenants/{uuid}/members`; `POST /tenants/{uuid}/members` `{user_uuid,role}`; `DELETE /tenants/{uuid}/members/{userUuid}`; `PATCH /tenants/{uuid}/members/{userUuid}` `{role}`.

- [ ] **Step 1: Failing API test** — boot tenancy-on harness; assert: create in `bootstrap_default` → 422 (guard); with readiness stub `full` → 201 provisioning; my-tenants excludes the provisioning tenant for its owner? NO — pinned: `listTenantsForUser` joins ACTIVE tenants only, so the creating owner does NOT see it in my-tenants until markActive (assert exactly that); operator with `system.access` sees it via `GET /tenants?status=provisioning`; final-owner removal → 422 `DomainException` mapped; domain add returns token + TXT name; disable required host while full → 409/422; membership revocation → subsequent `tenant:admin` request 403 (defer full request-path assert to Task 16).
- [ ] **Step 2: Controllers** — thin: validate input (`Response::validation`), call the contract inside `guarded()` (extend the exception map: `\DomainException` → 422, `InvalidHostException` → 422, required-host refusal → 409). No business logic in controllers.
- [ ] **Step 3: Routes + bindings.** Run → PASS; phpcs clean; RouteCoverageTest still green (new routes carry `tenant_system`). Commit SKIPPED.

---

### Task 12: Thallo — management CLI

**Files:** Create `packages/thallo-tenancy/src/Console/TenantManageCommand.php`, `.../DomainManageCommand.php`, `.../MemberManageCommand.php`; Test `tests/Integration/Tenancy/TenantManagementCliTest.php`.

**Interfaces:** Consumes the same two administration contracts PLUS `BootstrapTenantCreationGuard`; CLI pattern verified (`BaseCommand`, `#[AsCommand]`, `$this->getService()`, `self::SUCCESS/FAILURE`, discovered via `discoverCommands('Thallo\Tenancy\Console', ...)` — already wired `TenancyServiceProvider.php:368`).

- [ ] **Step 1: Failing test** — `thallo:tenancy:tenant create --slug=beta --name=Beta --owner={uuid}` under full-resolution stub → provisioning row; `domain add|verify|list`, `member add|set-role|remove|list`, `tenant suspend|reactivate|list` — one happy path + the guard refusal each (CommandTester).
- [ ] **Step 2: Implement** — three commands with subaction arguments; `tenant create` MUST call `BootstrapTenantCreationGuard::assertCanCreateTenant()` before the administration contract (all other subactions do not). Output JSON lines for scriptability. Run → PASS; phpcs clean. Commit SKIPPED.

---

### Task 13: Thallo — blob surface (route provider, canonical origin, owner-rule proof)

**Files:**
- Create: `app/Content/Media/TenantBlobRouteMiddlewareProvider.php`, `app/Content/Media/TenantBlobPublicUrlProvider.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (bind both framework seams beside the existing hook bindings)
- Verify without editing: `app/Content/Media/TenantBlobPolicy.php`, `app/Content/Delivery/EngineMediaUrlResolver.php`
- Test: `tests/Integration/Tenancy/BlobResolutionTest.php`

**Interfaces:**
- Consumes: framework seams; existing `TenantBlobPolicy` ownership-first comparison; `config('tenancy.public_origin')`; Thallo-owned `media_assets` lookup for blob owner; neutral `TenantAdministration::getTenant()` + `TenantDomainAdministration::listDomains()` for slug/domain reads. No extension model import or `tenant_domains` SQL.
- Produces: `BlobRouteMiddlewareProvider` binding → `VIEW: ['tenant:public,soft']`, `UPLOAD/INFO/DELETE/SIGN: ['tenant:admin']`; `BlobPublicUrlProvider` binding → canonical origin (spec §7.3 deterministic rule).

- [ ] **Step 1: Failing tests** — (a) route provider slots; (b) canonical origin via neutral contracts: default host, earliest verified custom domain, subdomain fallback, preactivation null, full-mode underivable throws; (c) signed replay proof: valid signature on owner host succeeds, same signature on another/unmapped host fails because existing policy compares resolved tenant to owner; bootstrap/inert behavior remains unchanged; (d) audit `EngineMediaUrlResolver`: it emits host-relative URLs and owns NO cache, so tenant host resolution provides natural isolation and there is no cache key to segment.
- [ ] **Step 2: `TenantBlobRouteMiddlewareProvider`** — pure match: `BlobRouteAction::VIEW => ['tenant:public,soft']`, default `['tenant:admin']`. Inert safety comes from the profiles themselves (Task 6), so binding it pre-activation is harmless — assert in the inert regression.
- [ ] **Step 3: `TenantBlobPublicUrlProvider`** — resolve owner via Thallo's `media_assets`, then obtain tenant/domain data exclusively through the neutral contracts. Apply the three-step origin rule; throw when full-ready and underivable.
- [ ] **Step 4: Preserve and prove `TenantBlobPolicy`** — DO NOT add a signature branch. The current method already resolves the request tenant, loads the owner, and returns only `hash_equals(owner, tenant)`. Controller signature validation merely allows the request to reach that policy. Add regression assertions around the unchanged implementation; note `CurrentTenantResolver::tenantUuid()` always takes `ApplicationContext`.
- [ ] **Step 5: Bind both providers in `ThalloServiceProvider`; record the media URL audit** — `EngineMediaUrlResolver` has no cache and emits host-relative URLs, so no code change or fictitious `tenant:*:media:*` key is needed there. Run → PASS; phpcs clean. Commit SKIPPED.

---

### Task 14: Thallo — host-sensitive cache purges on domain mutations

**Files:** Create `packages/thallo-tenancy/src/Cache/TenantHostCachePurger.php`; Modify `packages/thallo-tenancy/src/Http/Controllers/TenantDomainController.php` + `DomainManageCommand` (invoke after each mutation); Test `tests/Unit/Tenancy/Cache/TenantHostCachePurgerTest.php`.

**Interfaces:**
- Consumes: `CacheStore::deletePattern/invalidateTags`; segment shape `tenant:{uuid}:`; neutral `TenantDomainAdministration::getDomain()` so UUID-only mutation routes can identify the owning tenant without querying extension tables.
- Produces: `TenantHostCachePurger::purgeForTenant(string $tenantUuid): void` — deletePattern `tenant:{uuid}:render:*`, `tenant:{uuid}:thallo:seo:sitemap:*` + `invalidateTags(['thallo:render:page'])`. There is no media URL cache (Task 13 audit), so no fictitious media pattern.

- [ ] **Step 1: Failing test** (array cache driver): seed segmented + foreign-tenant keys → purge → own segment gone, foreign segment intact, tag-carried render entries gone.
- [ ] **Step 2: Implement + wire** — add knows tenant UUID from its route; verify/enable/disable fetch `getDomain()` before mutation; remove MUST fetch before deletion, then purge only after successful mutation. CLI follows the same order. Missing domain fails before purge. Run → PASS; phpcs clean. Commit SKIPPED.

---

### Task 15: Thallo — admin SPA: switcher, header injection, 403 recovery

**Files:**
- Create: `admin/src/queries/tenants.ts`, `admin/src/stores/tenant.ts`, `admin/src/components/TenantSwitcher.vue`
- Modify: `admin/src/api/authFetch.ts` + `admin/src/api/client.ts` (X-Tenant-Id injection + 403 handling), `admin/src/layouts/default.vue` (mount switcher in the sidebar header slot, beside `AppLogo` at `:91-94`)
- Test: `admin/src/__tests__/tenantSwitcher.spec.ts`, `admin/src/__tests__/tenantStore.spec.ts`

**Interfaces:**
- Consumes (verified): `authFetch(path, init)` — single choke point for untyped calls; `client`/`core` openapi-fetch instances with authMiddleware (`admin/src/api/client.ts`); Pinia setup-store style (`stores/session.ts`, `stores/capabilities.ts` are the ONLY existing stores — match their shape incl. persist plugin usage); `@pinia/colada` query pattern (`queries/locales.ts`: `qk`, fetch fn, `useX()`, mutations with `onSettled` invalidate); layout `UDashboardSidebar` header slot; vitest harness (`setup.ts` resets modules; assert via `data-testid`, never Nuxt-UI internals — see repo memory).
- Produces: `useTenantStore()` — state `{selectedUuid: string|null, tenants: TenantSummary[]}`; actions `select(uuid)`, `clearSelection()`, `ensureLoaded()` (validates persisted uuid against my-tenants at startup, clears when absent); header injection: BOTH `authFetch` and the openapi-fetch `authMiddleware` add `X-Tenant-Id: {selectedUuid}` when set (centralized — individual queries never add it); 403 from a tenant-scoped call → `clearSelection()` + my-tenants refetch + emit `tenant-switch-required` (switcher opens).

- [ ] **Step 1: Failing specs** — store: `select` persists to localStorage; `ensureLoaded` drops a stale persisted uuid; 403 handler clears selection. Switcher: renders my-tenants entries (`data-testid="tenant-switcher-item"`), emits select, hides for single-tenant users (bootstrap/off — my-tenants length ≤ 1 and no `system.access`). Header: authFetch attaches `X-Tenant-Id` iff selected.
- [ ] **Step 2: Implement** query (`qkMyTenants=['tenancy','my-tenants']`, `GET ${apiBase}/tenancy/my-tenants` via authFetch), store (setup style + persist), switcher (Nuxt UI `USelectMenu` or `UDropdownMenu` in the sidebar header; `data-testid` hooks), injection in the two API choke points, 403 recovery in the client middleware (only for requests that CARRIED the header — a 403 without it is ordinary permission denial).
- [ ] **Step 3: Run** — `pnpm vitest run` (full admin suite) + `pnpm type-check`. PASS. Commit SKIPPED.

---

### Task 16: Thallo — end-to-end acceptance + inert/regression gates

**Files:** Create `tests/Integration/Tenancy/FullResolutionAcceptanceTest.php`; extend `tests/Support/` harness only if the two-boot helpers need an activation-aware variant; Test = this task.

**Interfaces:** Consumes everything above through public surfaces only (HTTP kernel requests + CLI + flags), plus the SP1 two-boot harness (`RetrofitHarnessTestCase` / `EnableFullMachineAcceptanceTest` patterns — real process separation).

- [ ] **Step 1: The acceptance script (spec §10, RED then green as tasks integrate):**
  1. SP1 path to ON (existing helpers) → activate full resolution across TWO real boots (fresh-boot proof runs in boot 2) → default tenant serves on `default_hosts[0]` exactly as before activation.
  2. Create tenant two (API, operator) → `provisioning`: public subdomain 404; absent from owner's my-tenants; visible to operator via `?status=provisioning`.
  3. Test-seed stub → `markActive` → subdomain serves isolated content; add + DNS-stub-verify a custom domain → custom domain serves; exact-domain precedence over subdomain asserted.
  4. Admin: `X-Tenant-Id` switch shows only the selected tenant's content; header+conflicting JWT claim → rejected; membership revocation mid-session → 403.
  5. Signed blob URL generated from the central admin host → URL carries tenant B's canonical origin; anonymous GET on that host → 200; same signature replayed on tenant A's host → denied; tampered signature → denied. Private INFO stays route-authenticated.
  6. Suspension: public 404 on both hosts; domain rows byte-identical before/after (assert full row equality); reactivation restores serving; required-host disable attempt while full → refused.
  7. Concurrent final-owner race (PG): start with TWO active owners; two parallel transactions remove/demote DIFFERENT owners → exactly one succeeds, one fails, and exactly one active owner remains. Separate same-sole-owner attempts both fail.
- [ ] **Step 2: Gate runs (the user's standing directive — all four before any release pinning):**
  - tenancy-OFF full suite; tenancy-ON full suite; **inert-mode suite** (SP2a deployed, flag unset — the SP1 acceptance tests must pass unmodified); cache-driver gate (`supportsPatternPurge` behavioral probe still green).
  - Extension suite (SQLite) + contracts suite + framework suite, each in its repo.
- [ ] **Step 3: Record all counts in the ledger. Commit SKIPPED.**

---

### Task 17: Release gate — framework 1.68.0 → contracts 1.2.0 → extension 1.2.0 → Thallo pins

**Why:** mirrors SP1 Task 21 (`TenancyReleaseDistributionTest` precedent). Run ONLY after Task 16's gates are green and the user gives the release go-ahead; the user tags/publishes.

- [ ] **Step 1:** Framework release prep (its own repo conventions: CHANGELOG cut, `Version.php` → 1.68.0 + next codename after Adhil from ROADMAP uniqueness check, ROADMAP entry, docs `releases.md` + `app.config.ts`, skeleton constraint) — **commits only on user go-ahead; no tags.**
- [ ] **Step 2:** Contracts 1.2.0 cut (CHANGELOG heading, `extra.glueful.version`); extension 1.2.0 cut (CHANGELOG, `composer.json`: contracts `^1.2.0`, framework floor `^1.68.0` — `BlobRouteMiddlewareProvider` is a 1.68 API; `extra.glueful.requires.glueful >= 1.68.0`).
- [ ] **Step 3:** After the user publishes all three: Thallo `composer.json` framework `^1.68.0`; `composer update glueful/tenancy glueful/framework` resolves PUBLISHED versions (extension already pinned `^1.1.0` → bump `^1.2.0`); extend `TenancyReleaseDistributionTest` to also assert the tenancy constraint floor is `>=1.2`.
- [ ] **Step 4:** Full off/on/inert gates once more against published packages. Record in ledger. **Commits remain held until the user's go-ahead; batch at logical groupings (enablement/resolution, management, blob, SPA, tests) mirroring SP1's five-commit shape.**

---

## Self-Review (rev 2)

**Spec coverage:** §1 objective → T8-T10 (mode flip + wiring); §2 ownership → task-per-layer (T1 framework, T2 contracts, T3-7 extension, T8-16 Thallo); §3.1 seam + VIEW correction → T1; §3.2 URL provider → T1+T13; §4 contracts incl. lifecycle invariants + final-owner locking → T2+T7; §5.1 schema/two-column state → T3; §5.2 normalization + public_origin shape → T4; §5.3 trusted proxies (reuse, no new config) → T5 consumes `Request::getHost()` only; §5.4 precedence → T5; §5.5 profiles + soft + inert gate → T6; §5.6 bridges → T7; §6 activation five steps + probe + one-way → T9 (+T8 predicate); §7.1 wiring + coverage evolution → T10; §7.2 HTTP/CLI/SPA (no creation UI) → T11/T12/T15; §7.3 blob (route provider, canonical origin, soft VIEW, owner rule) → T13; §7.4 purges → T14; §8 lifecycle → T7 (impl) + T11/T16 (behavior); §9 failure modes → distributed into T6/T9/T11/T13/T16 tests; §10 acceptance → T16; §11 out-of-scope respected (no deletion, no TLS, no background verify, no creation UI).

**Verified-contract basis:** router parameter parsing, middleware ordering, provider boot order, SignedUrl path/query scope, current `TenantBlobPolicy` owner equality, `CurrentTenantResolver::tenantUuid(ApplicationContext)`, current SubdomainResolver config source, SQLite's rejection of `FOR UPDATE`, framework optional-auth insertion point, and the webhook handlers at `routes/admin.php:322` were all checked against source/runtime. The revision removes raw access to extension-owned tables and carries tenant/domain reads only through Task 2 contracts.

**Known risks carried forward (explicit, not placeholders):** T7's real row-lock race is asserted only in T16's PostgreSQL harness; SQLite takes the explicit no-`FOR UPDATE` branch. `EngineMediaUrlResolver` has been audited as host-relative and cache-free, so T13 records natural isolation rather than inventing a cache edit.

**Type consistency check:** `TenantAdministration`/`TenantDomainAdministration`/`TenantResolutionProbe` signatures (including `getTenant`/`getDomain`) appear identically in T2/T7 and all consumers. `ResolutionProfile` is consumed by the probe. One shared `FullTenantResolutionReadiness` drives profile activation, runtime mode, creation guard, required-host protection, and origin fail-closed behavior. Flag keys are consistent; `completeFull()` commits flag+step atomically. `BlobRouteAction` values and canonical comma-form middleware are consistent T1/T13.
