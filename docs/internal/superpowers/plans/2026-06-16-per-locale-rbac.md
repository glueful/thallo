# Per-Locale RBAC — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** ✅ Shipped (2026-06-17) — implemented, reviewed, and merged. Steps left as `[ ]` for historical reference.

**Goal:** Authorize a locale-specific admin action (publish `fr`, save the `de` draft, …) against the **locale it targets**, not just the bare permission — so an editor whose grant is filtered to `locale:fr` may act on `fr` and is denied (403) on every other locale, while an unscoped (globally granted) user acts on every locale exactly as today. Achieved entirely through Aegis's native `resource_filter` matching; **no permission renames, no new permissions/roles/routes/events/tables, no data migration.**

**Architecture:** A single focused code change. `RequireLemmaPermission` gains a private `resourceFor(Request): string` that reads the resolved `{locale}` route parameter from `$request->attributes->get('_route_params')` and returns `locale:<code>` when present (non-empty string) else the coarse `lemma`. `handle()` passes that derived resource into `PermissionManager::can($uuid, $permission, $resource, $context)` instead of the literal `'lemma'`. Because the seeded Lemma role grants carry **no** `resource_filter` (they match any resource string, acting as `*`), HTTP authorization is unchanged for globally granted users — only the audit-log resource string changes on locale routes. Per-locale restriction is an **opt-in grant pattern** (assign a locale-filtered role instead of the coarse role); Aegis's permissive OR model means a single global grant overrides any locale-scoped grant, so the supported shape is **one role per locale**. Locale-restricted editors are configured via documented operator recipe — no Lemma assignment endpoint is built.

**Tech Stack:** PHP 8.3, PostgreSQL, PHPUnit 10, Glueful framework, Aegis (`glueful/aegis`) RBAC provider. Tests run on Postgres via `LemmaTestCase` (Aegis migrations + the Lemma seed roles/permissions run in the suite via `scripts/run-test-migrations.php`). Conventions: `declare(strict_types=1)`, `final` classes, PSR-4 `App\`, phpcs 120-col.

**Spec:** `docs/superpowers/specs/2026-06-16-per-locale-rbac-design.md`

---

## File map

- Modify: `app/Content/Http/RequireLemmaPermission.php` — add private `resourceFor(Request): string` (derive `locale:<code>` from `_route_params['locale']`, else `lemma`); change the single `can(...)` call to pass it. The ONLY production code change in this feature.
- Modify: `tests/Unit/Http/RequireLemmaPermissionTest.php` — add unit assertions on `resourceFor` (via reflection) for the `{locale}` → `locale:fr` and no-locale → `lemma` derivation; keep the existing fail-closed cases.
- Create: `tests/Integration/Http/LocaleRbacApiTest.php` — drives the real `RequireLemmaPermission` (resolved against the booted container's real `PermissionManager` + active Aegis provider) with real Aegis grants seeded through Aegis's own API in the one-role-per-locale shape: backward-compat, locale allow/deny, scoped-read, discovery boundary, locale-agnostic deny, OR-semantics caveat, and fail-closed-unchanged.
- Create: `docs/PER_LOCALE_RBAC.md` — the operator recipe for configuring a locale-restricted editor (the discovery/visibility tradeoff, the one-role-per-locale caveat).

> **No migration, no seed, no new permission.** Per-locale RBAC reuses the existing `lemma.entries.read|write|publish` permissions; operators create locale-filtered roles. The tests create those locale roles at runtime via Aegis's API, not via a migration.

> **Why the integration tests drive the middleware directly (not a full HTTP round-trip via `handle()`):** the Lemma test harness deliberately provides no way to mint a valid bearer JWT for a seeded user (see `FoundationFlowTest`'s docblock — a full authenticated round-trip was explicitly out of scope). So `LemmaTestCase::handle()` cannot exercise a real `can()` decision. Instead we instantiate `RequireLemmaPermission` with the booted `appContext()` (whose container holds the real `PermissionManager` with the active Aegis provider), set the `auth.user` `UserIdentity` and `_route_params` on a plain `Request`, seed real Aegis grants, and call `handle()` directly — asserting whether the `$next` callable is reached (allow) or a 403 is returned (deny). This exercises the genuine Aegis `resource_filter` path end-to-end without a JWT, which is exactly the unit of behavior this feature changes.

---

### Task 1: `resourceFor` derivation (unit) + wire it into `handle()`

**Files:**
- Modify: `app/Content/Http/RequireLemmaPermission.php`
- Modify: `tests/Unit/Http/RequireLemmaPermissionTest.php`

- [ ] **Step 1: Write the failing derivation unit tests.** Append two methods to `tests/Unit/Http/RequireLemmaPermissionTest.php`. They call the (to-be-added) private `resourceFor` via reflection — no DB, no container needed, since `resourceFor` only reads `_route_params`:

```php
public function testResourceForDerivesLocaleScopedResourceFromRouteParam(): void
{
    $mw = new RequireLemmaPermission($this->contextWithoutContainer());
    $request = new Request();
    $request->attributes->set('_route_params', ['uuid' => 'e1abcdefghij', 'locale' => 'fr']);

    self::assertSame('locale:fr', $this->resourceFor($mw, $request));
}

public function testResourceForFallsBackToCoarseLemmaWithoutLocaleParam(): void
{
    $mw = new RequireLemmaPermission($this->contextWithoutContainer());

    // No _route_params at all -> coarse 'lemma'.
    self::assertSame('lemma', $this->resourceFor($mw, new Request()));

    // _route_params present but no 'locale' key (e.g. DELETE /entries/{uuid}) -> coarse.
    $noLocale = new Request();
    $noLocale->attributes->set('_route_params', ['uuid' => 'e1abcdefghij']);
    self::assertSame('lemma', $this->resourceFor($mw, $noLocale));

    // Empty-string locale is treated as absent -> coarse.
    $empty = new Request();
    $empty->attributes->set('_route_params', ['locale' => '']);
    self::assertSame('lemma', $this->resourceFor($mw, $empty));
}

/** Invoke the private resourceFor() under test. */
private function resourceFor(RequireLemmaPermission $mw, Request $request): string
{
    $method = new \ReflectionMethod($mw, 'resourceFor');
    $method->setAccessible(true);
    return $method->invoke($mw, $request);
}
```

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter RequireLemmaPermissionTest`
Expected: FAIL — `ReflectionException: Method App\Content\Http\RequireLemmaPermission::resourceFor() does not exist`.

- [ ] **Step 3: Add `resourceFor` and wire it into `handle()`** in `app/Content/Http/RequireLemmaPermission.php`.

Change the `can()` call (currently line ~60). Replace:
```php
        if (!$manager->can($principal['uuid'], $permission, 'lemma', $context)) {
            return $this->forbidden();
        }
```
with:
```php
        if (!$manager->can($principal['uuid'], $permission, $this->resourceFor($request), $context)) {
            return $this->forbidden();
        }
```

Add this private method (place it directly after `handle()`, before `resolvePrincipal()`):
```php
    /**
     * Derive the authorization resource from the matched route. A locale-specific route
     * carries a `{locale}` parameter (set on the request by the router as `_route_params`
     * before the middleware pipeline runs); such an action is scoped to `locale:<code>`.
     * Every other (locale-agnostic) route — content-model management, entry create/destroy,
     * the locale/route inventory endpoints — keeps the coarse `lemma` resource.
     *
     * A grant with no resource_filter matches both (acts as `*`), so globally granted users
     * are unaffected; a `locale:fr`-filtered grant matches only `fr` actions.
     */
    private function resourceFor(Request $request): string
    {
        $params = (array) $request->attributes->get('_route_params');
        $locale = $params['locale'] ?? null;

        return is_string($locale) && $locale !== '' ? "locale:{$locale}" : 'lemma';
    }
```

Also update the class-level docblock line that says "scoped to the `lemma` resource" to reflect the derivation (optional but recommended for accuracy):
```php
 * the same `PermissionManager::can()` that Aegis backs, scoped to the resource the route
 * targets — `locale:<code>` for a route carrying a `{locale}` parameter, else the coarse
 * `lemma` resource (see {@see resourceFor()}).
```

- [ ] **Step 4: Run; verify pass.**

Run: `composer test:phpunit -- --filter RequireLemmaPermissionTest`
Expected: PASS — the two derivation cases plus the three pre-existing fail-closed cases (`testEmptyPermissionParamIsForbidden`, `testNoAuthUserIsForbidden`, `testUnresolvedPermissionManagerIsForbidden`) all green. The fail-closed cases are unaffected: they deny before `can()`/`resourceFor()` is ever reached.

- [ ] **Step 5: phpcs + commit.**
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
composer phpcs
git add app/Content/Http/RequireLemmaPermission.php tests/Unit/Http/RequireLemmaPermissionTest.php
git commit -m "Derive per-locale authorization resource in the Lemma permission middleware"
```

---

### Task 2: Integration — real Aegis grants exercise locale-scoped authorization

**Files:**
- Create: `tests/Integration/Http/LocaleRbacApiTest.php`

This test drives the real `RequireLemmaPermission` against the booted container's real `PermissionManager` (active Aegis provider) using grants seeded through Aegis's own API. The seed helpers and Aegis-row cleanup are the load-bearing infrastructure; the assertions then map 1:1 onto the spec's testing matrix.

- [ ] **Step 1: Write the full failing test class.** Create `tests/Integration/Http/LocaleRbacApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\RequireLemmaPermission;
use App\Tests\Support\LemmaTestCase;
use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Aegis\Services\PermissionAssignmentService;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves per-locale RBAC end-to-end through the REAL Aegis provider: the middleware derives
 * the resource from the matched route's {locale} and `PermissionManager::can()` matches it
 * against each grant's resource_filter. Grants are seeded via Aegis's own API in the
 * spec-mandated one-role-per-locale shape (Aegis dedupes per (principal, permission), so a
 * single role/user can hold a permission with exactly one resource filter).
 *
 * The harness cannot mint a bearer JWT (see FoundationFlowTest), so we invoke the middleware
 * directly with the real booted container's PermissionManager: set `auth.user` + `_route_params`
 * on the request, then assert whether `$next` is reached (allow) or a 403 is returned (deny).
 */
final class LocaleRbacApiTest extends LemmaTestCase
{
    /** Every principal uuid this suite mints, so scrub touches ONLY our users (Aegis tables
     *  are outside LemmaTestCase::TABLES and do not truncate between tests). */
    private array $createdUserUuids = [];

    /** Aegis RBAC tables are NOT in LemmaTestCase::TABLES, so scrub our test grants ourselves. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->createdUserUuids = [];
        $this->scrubAegisGrants();
    }

    protected function tearDown(): void
    {
        $this->scrubAegisGrants();
        parent::tearDown();
    }

    // --- Backward compatibility -------------------------------------------------------

    public function testGlobalRoleCanPublishAnyLocale(): void
    {
        // The seeded lemma_editor role has UNSCOPED publish -> matches every locale.
        $user = $this->newUser();
        $this->provider()->assignRole($user, 'lemma_editor');

        self::assertTrue($this->allows($user, 'lemma.entries.publish', '/entries/{uuid}/publish/{locale}', ['locale' => 'fr']));
        self::assertTrue($this->allows($user, 'lemma.entries.publish', '/entries/{uuid}/publish/{locale}', ['locale' => 'de']));
    }

    // --- Locale restriction (allow / deny) --------------------------------------------

    public function testLocaleScopedPublishAllowsTargetLocale(): void
    {
        $user = $this->newUser();
        $this->assignLocaleRole($user, 'lemma_editor_fr', ['lemma.entries.publish'], 'fr');

        self::assertTrue($this->allows($user, 'lemma.entries.publish', '/entries/{uuid}/publish/{locale}', ['locale' => 'fr']));
    }

    public function testLocaleScopedPublishDeniesOtherLocale(): void
    {
        $user = $this->newUser();
        $this->assignLocaleRole($user, 'lemma_editor_fr', ['lemma.entries.publish'], 'fr');

        self::assertFalse($this->allows($user, 'lemma.entries.publish', '/entries/{uuid}/publish/{locale}', ['locale' => 'de']));
    }

    // --- Scoped read on locale routes -------------------------------------------------

    public function testLocaleScopedReadAllowsOwnLocaleDraft(): void
    {
        $user = $this->newUser();
        $this->assignLocaleRole($user, 'lemma_editor_fr', ['lemma.entries.read'], 'fr');

        // GET .../draft/fr requires read AND carries {locale} -> a scoped read is enough.
        self::assertTrue($this->allows($user, 'lemma.entries.read', '/entries/{uuid}/draft/{locale}', ['locale' => 'fr']));
        self::assertFalse($this->allows($user, 'lemma.entries.read', '/entries/{uuid}/draft/{locale}', ['locale' => 'de']));
    }

    // --- Discovery / visibility boundary ----------------------------------------------

    public function testLocaleScopedReadCannotDiscoverCoarseInventory(): void
    {
        $user = $this->newUser();
        $this->assignLocaleRole($user, 'lemma_editor_fr', ['lemma.entries.read'], 'fr');

        // Coarse routes (resource 'lemma') need an UNSCOPED read -> the fr-scoped read fails.
        self::assertFalse($this->allows($user, 'lemma.entries.read', '/entries/{uuid}/locales', []));
        self::assertFalse($this->allows($user, 'lemma.entries.read', '/entries/{uuid}', []));
    }

    public function testCoarseReadRestoresDiscovery(): void
    {
        $user = $this->newUser();
        // A coarse (unscoped) read grant matches the 'lemma' resource of the inventory routes.
        $this->assignLocaleRole($user, 'lemma_reader_global', ['lemma.entries.read'], '*');

        self::assertTrue($this->allows($user, 'lemma.entries.read', '/entries/{uuid}/locales', []));
        self::assertTrue($this->allows($user, 'lemma.entries.read', '/entries/{uuid}', []));
    }

    // --- Locale-agnostic deny ---------------------------------------------------------

    public function testLocaleOnlyUserIsDeniedLocaleAgnosticDestroy(): void
    {
        $user = $this->newUser();
        // fr-scoped write does NOT match DELETE /entries/{uuid} (resource 'lemma').
        $this->assignLocaleRole($user, 'lemma_editor_fr', ['lemma.entries.write'], 'fr');

        self::assertFalse($this->allows($user, 'lemma.entries.write', '/entries/{uuid}', []));
    }

    public function testCoarseUserCanDestroy(): void
    {
        $user = $this->newUser();
        $this->provider()->assignRole($user, 'lemma_editor'); // unscoped write

        self::assertTrue($this->allows($user, 'lemma.entries.write', '/entries/{uuid}', []));
    }

    // --- OR-semantics caveat (global grant wins) --------------------------------------

    public function testGlobalGrantOverridesLocaleScopeOnOtherLocale(): void
    {
        $user = $this->newUser();
        $this->provider()->assignRole($user, 'lemma_editor');                     // unscoped publish
        $this->assignLocaleRole($user, 'lemma_editor_fr', ['lemma.entries.publish'], 'fr'); // + fr scope

        // Permissive OR: the unscoped grant matches every locale, so 'de' is NOT restricted.
        self::assertTrue($this->allows($user, 'lemma.entries.publish', '/entries/{uuid}/publish/{locale}', ['locale' => 'de']));
    }

    // --- Fail-closed unchanged --------------------------------------------------------

    public function testNoGrantsStillDenies(): void
    {
        $user = $this->newUser(); // no roles, no grants
        self::assertFalse($this->allows($user, 'lemma.entries.publish', '/entries/{uuid}/publish/{locale}', ['locale' => 'fr']));
    }

    // --- Framework seam: the router populates _route_params before middleware ----------

    public function testRouterPopulatesLocaleRouteParamForRealLemmaRoutes(): void
    {
        // The `allows()` harness injects `_route_params` by hand (it can't mint a JWT to drive
        // the full kernel), so the direct-middleware tests do NOT prove the framework actually
        // sets that attribute. This characterizes the real seam `resourceFor()` depends on:
        // a real Lemma `{locale}` admin route resolves with a 'locale' param via Router::match(),
        // which Router::dispatch() sets as `_route_params` BEFORE the middleware pipeline runs.
        // If routing ever stops populating it, THIS fails (not the injected-attribute tests).
        $localeMatch = $this->router()->match(
            Request::create('/v1/admin/entries/abcd1234efgh/draft/fr', 'GET')
        );
        self::assertNotNull($localeMatch, 'the {locale} draft route must match');
        self::assertSame('fr', $localeMatch['params']['locale'] ?? null, 'router resolves {locale} into params');

        // A locale-agnostic admin route yields NO 'locale' param → resourceFor() returns 'lemma'.
        $agnosticMatch = $this->router()->match(
            Request::create('/v1/admin/entries/abcd1234efgh/locales', 'GET')
        );
        self::assertNotNull($agnosticMatch, 'the agnostic locales route must match');
        self::assertArrayNotHasKey('locale', $agnosticMatch['params'] ?? [], 'no {locale} segment → no locale param');
    }

    // === Harness ======================================================================

    /**
     * Run the real middleware for a principal + permission + route, returning true when the
     * request is allowed through (the $next callable fires) and false on a 403.
     *
     * @param array<string,string> $routeParams the resolved {…} segments, e.g. ['locale' => 'fr']
     */
    private function allows(string $userUuid, string $permission, string $path, array $routeParams): bool
    {
        $mw = new RequireLemmaPermission($this->appContext());

        $request = Request::create($path, 'POST');
        $request->attributes->set('_route_params', $routeParams);
        $request->attributes->set('auth.user', new UserIdentity(
            uuid: $userUuid,
            roles: [],
            username: 'tester',
        ));

        $reached = false;
        $response = $mw->handle($request, function (Request $r) use (&$reached): Response {
            $reached = true;
            return new Response('ok', Response::HTTP_OK);
        }, $permission);

        if ($reached) {
            return true;
        }
        self::assertSame(403, $response->getStatusCode(), 'a denied check must return 403');
        return false;
    }

    /** A fresh, unique principal id per test (12-char nano id, like every other principal). */
    private function newUser(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->createdUserUuids[] = $uuid; // tracked so scrub only touches our users
        return $uuid;
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }

    /**
     * Create (idempotently) a locale-filtered role granting $permissionSlugs at $resource and
     * assign $userUuid to it. $resource is the literal Aegis resource ('locale:fr', or '*' for
     * an unscoped/coarse grant). One role per locale — never stack two resource filters of the
     * same permission on one role (Aegis dedupes per (role, permission), the second no-ops).
     *
     * @param list<string> $permissionSlugs
     */
    private function assignLocaleRole(string $userUuid, string $roleSlug, array $permissionSlugs, string $resource): void
    {
        $roleUuid = $this->ensureRole($roleSlug);
        // Pin the Aegis repos to THIS suite's Connection. BaseRepository's constructor takes an
        // optional Connection; relying on the static shared connection is non-deterministic (it
        // only works if some earlier container/repo construction happened to initialize it).
        // Aegis's own tests do the same: new PermissionRepository($this->connection).
        $permRepo = new PermissionRepository($this->connection());
        $rolePermRepo = new RolePermissionRepository($this->connection());

        foreach ($permissionSlugs as $slug) {
            $permission = $permRepo->findPermissionBySlug($slug);
            self::assertNotNull($permission, "seeded permission {$slug} must exist");
            $options = $resource === '*' ? [] : ['resource_filter' => ['resource' => $resource]];
            $rolePermRepo->assignPermissionToRole($roleUuid, $permission->getUuid(), $options);
        }

        // Assign the user to the role through the provider (clears decision caches).
        $this->provider()->assignRole($userUuid, $roleSlug);
        $this->provider()->invalidateAllCache();
    }

    /** Insert the test role if absent; return its uuid. */
    private function ensureRole(string $slug): string
    {
        $existing = $this->connection()->table('roles')->select(['uuid'])->where('slug', '=', $slug)->first();
        if ($existing !== null) {
            return (string) $existing['uuid'];
        }
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('roles')->insert([
            'uuid' => $uuid,
            'name' => $slug,
            'slug' => $slug,
            'description' => 'per-locale RBAC test role',
            'level' => 30,
            'is_system' => false,
            'status' => 'active',
        ]);
        return $uuid;
    }

    /**
     * Remove ONLY the grants/roles this suite creates, so Aegis state never leaks AND we never
     * erase another test's assignments (Aegis tables are intentionally outside
     * LemmaTestCase::TABLES, so a full-suite run shares them across tests).
     */
    private function scrubAegisGrants(): void
    {
        $db = $this->connection();

        // 1. Delete every role assignment WE made, scoped to OUR principal uuids only — this is
        //    what covers our users' assignment to the SEEDED `lemma_editor` role without
        //    touching user_roles rows created by any other test.
        if ($this->createdUserUuids !== []) {
            $db->table('user_roles')->whereIn('user_uuid', $this->createdUserUuids)->delete();
        }

        // 2. Drop the test-only roles this suite creates (and their role_permissions). These
        //    slugs are unique to this suite, so deleting by role is safe.
        $testRoles = $db->table('roles')->select(['uuid'])
            ->whereIn('slug', ['lemma_editor_fr', 'lemma_editor_de', 'lemma_reader_global'])->get();
        $roleUuids = array_map(static fn (array $r): string => (string) $r['uuid'], $testRoles);
        if ($roleUuids !== []) {
            $db->table('role_permissions')->whereIn('role_uuid', $roleUuids)->delete();
            $db->table('roles')->whereIn('uuid', $roleUuids)->delete();
        }

        // 3. Clear Aegis's process-level caches so a fresh principal isn't shadowed by a prior run.
        $this->provider()->invalidateAllCache();
    }
}
```

- [ ] **Step 2: Run; verify fail.**

Run: `composer test:phpunit -- --filter LocaleRbacApiTest`
Expected: FAIL — the locale-restriction assertions fail because `resourceFor` is wired (Task 1) but, if Task 1 were skipped, the literal `'lemma'` resource would let the fr-scoped grant deny everything. With Task 1 in place the EXPECTED pre-implementation failure for THIS task is a setup/wiring error (e.g. a missing helper or an un-truncated Aegis row) — run it and confirm a red bar before relying on green. (If Task 1 and Task 2 are committed together, Step 2 collapses into "all red until both land"; keep them as two commits.)

> Note: because Task 1 already changed the production code, the integration assertions should largely PASS once the test compiles. Treat Step 2 here as "confirm the harness itself is sound" — if any case is red, debug the seed/scrub helpers (most likely an Aegis cache or an un-scrubbed `user_roles` row), not the production code.

- [ ] **Step 3: Make it pass.** No production change — only the test harness. Debug iteratively against the spec matrix:
  - If `testGlobalRoleCanPublishAnyLocale` fails: confirm the seeded `lemma_editor` role and its unscoped `lemma.entries.publish` grant exist (they are seeded by `database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php`, which runs in the suite).
  - If a deny case wrongly allows: a stale Aegis decision/role cache — ensure `invalidateAllCache()` runs in `scrubAegisGrants()` and after each `assignRole`.
  - If an allow case wrongly denies: confirm the `resource_filter` JSON shape is exactly `{"resource":"locale:fr"}` (Aegis stores `json_encode(['resource' => $resource])` via `assignPermissionToRole`'s `resource_filter` option).

- [ ] **Step 4: Run; verify pass.**

Run: `composer test:phpunit -- --filter LocaleRbacApiTest`
Expected: PASS — all eleven cases green (backward-compat ×2, allow, deny, scoped-read, discovery-deny, discovery-allow, locale-agnostic deny, coarse destroy, OR-caveat, fail-closed).

- [ ] **Step 5: phpcs + commit.**
```bash
composer phpcs
git add tests/Integration/Http/LocaleRbacApiTest.php
git commit -m "Cover per-locale RBAC with real Aegis resource-filtered grants"
```

---

### Task 3: Operator recipe documentation

**Files:**
- Create: `docs/PER_LOCALE_RBAC.md`

- [ ] **Step 1: Write the recipe doc.** Create `docs/PER_LOCALE_RBAC.md`:

```markdown
# Per-Locale RBAC

Lemma authorizes a locale-specific admin action (publish `fr`, save the `de` draft, assign
the `en` route) against the **locale it targets** — not just the bare permission. The
`lemma_permission` middleware derives the resource from the matched route: a route carrying a
`{locale}` parameter is checked against `locale:<code>`; every other (locale-agnostic) route
keeps the coarse `lemma` resource. This rides entirely on Aegis's native `resource_filter`
matching — the permission names (`lemma.entries.read|write|publish`, `lemma.models.manage`)
never change.

## Backward compatibility

The seeded roles (`lemma_admin`, `lemma_editor`, `lemma_viewer`) grant their permissions with
**no** resource filter, so they match every resource string (acting as `*`). A user holding a
seeded role authorizes every locale exactly as before; only the audit-log resource string
changes (locale routes now log `locale:fr` instead of `lemma`).

## The one hard rule — global grants win

Aegis authorization is a permissive **OR** over a user's matching grants, with no deny rule.
A single unscoped (global) grant therefore **overrides** any locale-scoped grant. Per-locale
restriction is an opt-in grant pattern: restrict a user by assigning **only** locale-filtered
grants and **not** the coarse seeded role.

## Recipe — a French-only editor

1. Do **not** assign the global `lemma_editor` role (its grants are unscoped → full access).
2. Create a **locale role** `lemma_editor_fr` whose `role_permissions` rows are each
   `resource_filter = {"resource":"locale:fr"}` for the locale-scoped routes:
   - `lemma.entries.read` @ `locale:fr` — read of `GET …/draft/fr`, `GET …/versions/fr`,
     `POST …/preview/fr` (these carry `{locale}`, so a scoped read suffices and keeps fr
     isolation);
   - `lemma.entries.write` @ `locale:fr` — draft save/discard, locale-draft create, route
     assign/remove for fr;
   - `lemma.entries.publish` @ `locale:fr` — publish/unpublish/rollback for fr.
   Assign the user to `lemma_editor_fr`.
3. **One role per locale.** Aegis dedupes assignments by `(role, permission)` ignoring the
   resource filter, so a role/user can hold a permission with exactly one filter — a second
   resource-scoped grant of the same permission silently no-ops. A French+German editor gets
   **both** `lemma_editor_fr` and `lemma_editor_de`; never stack `locale:fr` + `locale:de` of
   the same permission on one role.

## The discovery / visibility tradeoff

The coarse routes — `GET /entries/{uuid}` (show), `GET …/locales`, `GET …/routes`, content-type
listing, `POST /entries` (create), `DELETE /entries/{uuid}` — have no single target locale and
are checked against `lemma`. A user holding only `locale:fr` grants can edit a known
`…/draft/fr` URL but **cannot** list which locales/routes exist or open the entry-show view
without a **coarse** `lemma.entries.read` grant. Granting that coarse read restores full admin
discovery UX but also lets the user **read** every locale's content (write/publish stay
fr-scoped). Strict per-locale read isolation and full admin discovery UX are mutually
exclusive for a locale-restricted editor — choose per editor.

## Out of scope

- A Lemma admin UI / API for assigning per-locale grants (frontend follow-up).
- Per-content-type scoping (`content-type:<slug>`) — same mechanism, needs an entry→type
  lookup in the middleware; additive later.
```

- [ ] **Step 2: Commit.** (Markdown — no test/phpcs to run.)
```bash
git add docs/PER_LOCALE_RBAC.md
git commit -m "Document the per-locale RBAC operator recipe"
```

---

### Task 4: Final verification

**Files:** none (verification only).

- [ ] **Step 1: Full suite + phpcs.**

Run: `composer ci`
Expected: green — the unit derivation tests, the integration `LocaleRbacApiTest`, and the entire pre-existing suite all pass; phpcs clean.

- [ ] **Step 2: Confirm no unintended production change.** `git diff main --stat` should show exactly one production file changed (`app/Content/Http/RequireLemmaPermission.php`), two test files, and two docs files (this plan + `docs/PER_LOCALE_RBAC.md`). No migration, no route, no DTO, no provider change.

---

## Self-review notes

- **Spec coverage (testing matrix → tasks).** Every spec "Testing" bullet is covered:
  backward-compat (`testGlobalRoleCanPublishAnyLocale`), locale allow (`testLocaleScopedPublishAllowsTargetLocale`),
  locale deny (`testLocaleScopedPublishDeniesOtherLocale`), scoped-read on locale routes
  (`testLocaleScopedReadAllowsOwnLocaleDraft` — fr 200 / de 403), discovery boundary
  (`testLocaleScopedReadCannotDiscoverCoarseInventory` + `testCoarseReadRestoresDiscovery`),
  resource derivation unit test (`testResourceForDerivesLocaleScopedResourceFromRouteParam`
  + `testResourceForFallsBackToCoarseLemmaWithoutLocaleParam`), locale-agnostic deny
  (`testLocaleOnlyUserIsDeniedLocaleAgnosticDestroy` + `testCoarseUserCanDestroy`),
  OR-semantics caveat (`testGlobalGrantOverridesLocaleScopeOnOtherLocale`), fail-closed
  unchanged (`testNoGrantsStillDenies` + the three pre-existing unit cases retained).
- **Scope-decision fidelity.** Resource is auto-derived from `_route_params['locale']`
  (decision 1/2); locale-agnostic routes stay coarse `lemma` (decision 3); backward-compatible
  by construction — only the resource string changes, and the seeded unscoped grants still
  match (decision 4). The discovery/visibility boundary (scope-decision 3) is both tested and
  documented. No new permissions/roles/routes/events/tables (decision 6) — the only production
  diff is the middleware. Aegis still LOGS the new resource (HTTP authz unchanged) — noted in
  the doc and the `resourceFor` docblock; not asserted (it is a log-content change, not a
  behavior change).
- **Assignment caveat honored.** Grants are seeded one-role-per-locale via Aegis's API
  (`RolePermissionRepository::assignPermissionToRole` with the `resource_filter` option +
  `AegisPermissionProvider::assignRole`); the harness comment and the doc both warn against
  stacking two resource filters of the same permission (Aegis dedupes per (role, permission)).
- **Signature consistency (verified against source).** `resourceFor(Request): string` matches
  the spec's pseudocode exactly. `PermissionManager::can(string $userUuid, string $permission,
  string $resource, array $context = []): bool` (PermissionManager.php:124) — the new
  `$this->resourceFor($request)` slots into the `$resource` arg with the existing `$context`
  unchanged. `RolePermissionRepository::assignPermissionToRole(string $roleUuid, string
  $permissionUuid, array $options = [])` reads `$options['resource_filter']` and `json_encode`s
  it (RolePermissionRepository.php:47-73). `AegisPermissionProvider::assignRole(string, string,
  array): bool` and `invalidateAllCache(): void` exist (AegisPermissionProvider.php:641, 560).
  `roles` columns (uuid/name/slug/description/level/is_system/status) and the `user_roles` /
  `role_permissions` shapes match the Aegis migrations (001/002). `Utils::generateNanoID(12)`
  is the same id helper the seed migration uses.
- **Harness rationale.** The middleware is invoked directly (not via `handle()`) because the
  Lemma test harness provides no JWT minting (documented in `FoundationFlowTest`); the booted
  `appContext()` container holds the real `PermissionManager` with the active Aegis provider, so
  `can()` runs the genuine `resource_filter` path.
- **Deterministic Aegis test state (CI reliability).** Aegis RBAC tables are not in
  `LemmaTestCase::TABLES`, so they are shared across a full-suite run. The harness therefore
  (a) pins the Aegis repos to this suite's `Connection` (`new PermissionRepository($this->connection())`)
  rather than relying on the static shared connection, and (b) tracks every principal uuid it
  mints (`$createdUserUuids`) and scrubs `user_roles` **only for those uuids** — it never
  bulk-deletes assignments on the shared seeded `lemma_editor` role, so it cannot erase another
  test's state. Test-only roles (`lemma_editor_fr` etc.) are unique to this suite and dropped by
  slug. Aegis caches are flushed in `setUp`/`tearDown`.
- **Framework seam characterized.** `testRouterPopulatesLocaleRouteParamForRealLemmaRoutes`
  asserts a real Lemma `{locale}` admin route resolves a `locale` param via `Router::match()`
  (which `Router::dispatch()` sets as `_route_params` before the middleware pipeline), and an
  agnostic route does not — protecting the exact seam `resourceFor()` reads, which the
  injected-attribute tests can't prove on their own.
- **Placeholder scan.** No `TBD`/`similar to`/`...`-as-omission in any code block; every test
  body and the production method are complete and copy-pasteable.
- **Commit messages.** Plain, imperative; no Claude/Anthropic attribution, no Co-Authored-By.
```
