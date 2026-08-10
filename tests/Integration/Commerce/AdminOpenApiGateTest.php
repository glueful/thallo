<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Commerce\Http\Routing\AdminRouteCatalog;
use Thallo\Commerce\Http\AdminMountAllowlist;

/**
 * Task 8 (Thallo admin-commerce-area plan, slice 3): the OpenAPI generation gate for the
 * `/v1/admin/commerce` mount (design spec §6 — "A schema check asserts that approved
 * `/v1/admin/commerce/*` paths are present, native `/commerce/admin/*` paths are absent, and
 * operation ids are unique").
 *
 * Two layers:
 *
 *  - **Input gate** (router-level, runs against every boot): every route registered under
 *    `/v1/admin/commerce` — both {@see AdminRouteCatalog::mount()}'s catalog entries AND this
 *    pack's own `/meta`/link/entry-search routes — carries a unique `thallo.commerce.admin.<key>`
 *    name, and the NATIVE Commerce mount (`/commerce/admin/*`, registered by Commerce's own
 *    `routes.php`) stays explicitly unnamed. `Glueful\Support\Documentation\
 *    RouteReflectionDocGenerator::buildOperation()` derives an operation id from the route NAME
 *    when present (`thallo.commerce.admin.products.index` -> `thalloCommerceAdminProductsIndex`)
 *    and from method+path otherwise — so a named mounted route and its unnamed native twin can
 *    never collide.
 *  - **Artifact gate** (parses the regenerated `docs/openapi.json` structurally — no grep-only
 *    check): the `/v1/admin/commerce/*` method/path set exactly equals the approved mounted
 *    allowlist ({@see AdminMountAllowlist}) plus this pack's own meta/link/search routes, and
 *    every operation id under that prefix starts with `thalloCommerceAdmin` and is globally
 *    unique across the whole document. This is a docs-drift gate: `composer docs:openapi` must
 *    be re-run and the regenerated file committed whenever the mounted/pack-owned Commerce admin
 *    surface changes, or this test fails.
 */
final class AdminOpenApiGateTest extends AppTestCase
{
    private const NAME_PREFIX = 'thallo.commerce.admin.';

    /** @var list<array{0:string,1:string}> [method, path] pairs this pack owns directly. */
    private const PACK_OWNED_ROUTES = [
        ['PUT', '/v1/admin/commerce/products/{productUuid}/link'],
        ['DELETE', '/v1/admin/commerce/products/{productUuid}/link'],
        ['GET', '/v1/admin/commerce/products/{productUuid}/link'],
        ['GET', '/v1/admin/commerce/entries/{entryUuid}/link'],
        ['GET', '/v1/admin/commerce/entries'],
        ['GET', '/v1/admin/commerce/meta'],
        // Store settings (store-settings spec §3.4).
        ['GET', '/v1/admin/commerce/settings'],
        ['PUT', '/v1/admin/commerce/settings'],
        // Payments settings RETIRED (platform-payments-settings spec, Task 6): moved to the
        // neutral `/v1/admin/settings/payments` (routes/admin.php) — see
        // tests/Integration/Settings/PlatformPaymentsSettingsApiTest.php.
        // Order-email switches (store-settings spec §4.2 follow-up).
        ['GET', '/v1/admin/commerce/emails'],
        ['PUT', '/v1/admin/commerce/emails'],
        // Marketplace settings (store-settings spec §3.6).
        ['GET', '/v1/admin/commerce/marketplace'],
        ['POST', '/v1/admin/commerce/marketplace/activate'],
        ['POST', '/v1/admin/commerce/marketplace/deactivate'],
        ['PUT', '/v1/admin/commerce/marketplace/commission'],
        ['PUT', '/v1/admin/commerce/marketplace/master'],
        // Orders search/export/payments (orders-invoices-receipts plan, Tasks 3-5): app-owned,
        // registered directly in packages/thallo-commerce/routes/admin-routes.php ahead of
        // AdminRouteCatalog::mount(), not vendor catalog keys.
        ['GET', '/v1/admin/commerce/orders/search'],
        ['GET', '/v1/admin/commerce/orders/export'],
        ['GET', '/v1/admin/commerce/orders/{uuid}/payments'],
        // Complete-sale (admin-order-creation cycle 2, Task 13/15): app-owned, registered
        // directly in packages/thallo-commerce/routes/admin-routes.php ahead of
        // AdminRouteCatalog::mount(), same posture as the three orders routes above. Regenerated
        // into docs/openapi.json and moved out of AWAITING_SPEC_REGENERATION at Task 16.
        ['POST', '/v1/admin/commerce/orders/{uuid}/complete-sale'],
    ];

    /**
     * The ONLY permitted gap between the live router and the documented surface: routes that are
     * registered and serving, but whose `composer docs:openapi` regeneration is scheduled for a
     * later task. Every entry is a debt with a named owner, and the gate below is exact in BOTH
     * directions — so this list can neither grow silently (a new undocumented route fails
     * immediately) nor go stale silently (once the spec is regenerated and the route is added to
     * {@see self::PACK_OWNED_ROUTES}, leaving the entry here fails too, forcing its removal).
     *
     * Empty as of Task 16: the ONE outstanding debt this list ever carried — `POST
     * /v1/admin/commerce/orders/{uuid}/complete-sale` — was paid by regenerating
     * `docs/openapi.json` and moving the pair up into {@see self::PACK_OWNED_ROUTES}.
     *
     * @var list<array{0:string,1:string}> [method, path] pairs awaiting a future regeneration.
     */
    private const AWAITING_SPEC_REGENERATION = [];

    // ------------------------------------------------------------------
    // Input gate — live router
    // ------------------------------------------------------------------

    public function testEveryMountedCommerceAdminRouteHasAUniqueNamespacedName(): void
    {
        $mounted = array_values(array_filter(
            $this->router()->getAllRoutes(),
            static fn (array $r): bool => str_starts_with((string) ($r['path'] ?? ''), '/v1/admin/commerce'),
        ));
        self::assertNotSame([], $mounted, 'expected /v1/admin/commerce routes to be registered');

        $names = [];
        foreach ($mounted as $route) {
            $name = $route['name'] ?? null;
            self::assertIsString(
                $name,
                "{$route['method']} {$route['path']} must be named (thallo.commerce.admin.<key>)",
            );
            self::assertStringStartsWith(
                self::NAME_PREFIX,
                $name,
                "{$route['method']} {$route['path']} name '{$name}' must start with '" . self::NAME_PREFIX . "'",
            );
            $names[] = $name;
        }

        $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $c): bool => $c > 1));
        self::assertSame([], $duplicates, 'route name(s) registered more than once: ' . implode(', ', $duplicates));
    }

    public function testPackOwnedRoutesCarryExplicitNames(): void
    {
        foreach (self::PACK_OWNED_ROUTES as [$method, $path]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path} must be registered");
            $name = $route['name'] ?? null;
            self::assertIsString($name, "{$method} {$path} must be named");
            self::assertStringStartsWith(self::NAME_PREFIX, $name);
        }
    }

    /**
     * The drift gate the artifact check alone cannot provide: `docs/openapi.json` can only drift
     * from the LIVE router in one direction on its own (a documented route that no longer exists),
     * because a route registered but never regenerated simply never appears in the file — so a
     * forgotten `composer docs:openapi` is invisible to
     * {@see self::testGeneratedOpenApiDocumentsExactlyTheApprovedCommerceAdminSurface()}.
     *
     * This closes it from the router side: every live `/v1/admin/commerce` route must be in the
     * approved documented surface (the allowlist-derived catalog entries plus
     * {@see self::PACK_OWNED_ROUTES}) or in the explicit, owner-annotated
     * {@see self::AWAITING_SPEC_REGENERATION} carve-out — and the comparison is `assertSame` on a
     * sorted list, so the carve-out must be emptied the moment its debt is paid.
     */
    public function testEveryLiveCommerceAdminRouteIsDocumentedOrExplicitlyAwaitingRegeneration(): void
    {
        $live = [];
        foreach ($this->router()->getAllRoutes() as $route) {
            $path = (string) ($route['path'] ?? '');
            if (str_starts_with($path, '/v1/admin/commerce')) {
                $live[] = strtoupper((string) $route['method']) . ' ' . $path;
            }
        }
        self::assertNotSame([], $live, 'expected /v1/admin/commerce routes to be registered');

        $undocumented = array_values(array_unique(array_diff($live, $this->expectedApprovedSurface())));
        sort($undocumented);

        $expectedDebt = array_map(
            static fn (array $pair): string => strtoupper($pair[0]) . ' ' . $pair[1],
            self::AWAITING_SPEC_REGENERATION,
        );
        sort($expectedDebt);

        self::assertSame(
            $expectedDebt,
            $undocumented,
            'the live /v1/admin/commerce surface and docs/openapi.json have diverged: either run '
                . '`composer docs:openapi` and list the route in PACK_OWNED_ROUTES, or (if its '
                . 'regeneration is deliberately deferred) record it in AWAITING_SPEC_REGENERATION '
                . '— and remove it from there once the spec catches up',
        );
    }

    public function testNativeCommerceAdminRoutesRemainUnnamed(): void
    {
        $native = array_values(array_filter(
            $this->router()->getAllRoutes(),
            static fn (array $r): bool => str_starts_with((string) ($r['path'] ?? ''), '/commerce/admin'),
        ));
        self::assertNotSame([], $native, 'expected native /commerce/admin routes to be registered');

        foreach ($native as $route) {
            $name = $route['name'] ?? null;
            self::assertTrue(
                $name === null || $name === '',
                "native route {$route['method']} {$route['path']} must remain unnamed, got '{$name}'",
            );
        }
    }

    // ------------------------------------------------------------------
    // Artifact gate — the regenerated docs/openapi.json (Step 4)
    // ------------------------------------------------------------------

    public function testGeneratedOpenApiDocumentsExactlyTheApprovedCommerceAdminSurface(): void
    {
        $paths = $this->loadGeneratedSpec()['paths'] ?? [];
        self::assertIsArray($paths);

        $actual = [];
        foreach ($paths as $path => $methods) {
            if (!str_starts_with((string) $path, '/v1/admin/commerce')) {
                continue;
            }
            self::assertIsArray($methods);
            foreach (array_keys($methods) as $verb) {
                $actual[] = strtoupper((string) $verb) . ' ' . $path;
            }
        }
        sort($actual);

        $expected = $this->expectedApprovedSurface();
        sort($expected);

        self::assertSame(
            $expected,
            $actual,
            'docs/openapi.json /v1/admin/commerce surface has drifted from the approved allowlist '
                . '+ pack-owned routes; run `composer docs:openapi` and commit the regenerated file',
        );
    }

    public function testGeneratedOperationIdsUnderTheCommerceMountArePrefixedAndGloballyUnique(): void
    {
        $paths = $this->loadGeneratedSpec()['paths'] ?? [];
        self::assertIsArray($paths);

        $allIds = [];
        $commerceMountIds = [];
        foreach ($paths as $path => $methods) {
            self::assertIsArray($methods);
            foreach ($methods as $verb => $operation) {
                self::assertIsArray($operation);
                $id = $operation['operationId'] ?? null;
                self::assertIsString($id, "{$verb} {$path} must carry an operationId");
                $allIds[] = $id;
                if (str_starts_with((string) $path, '/v1/admin/commerce')) {
                    $commerceMountIds[] = $id;
                }
            }
        }

        self::assertNotSame([], $commerceMountIds);
        foreach ($commerceMountIds as $id) {
            self::assertStringStartsWith(
                'thalloCommerceAdmin',
                $id,
                "operationId '{$id}' under /v1/admin/commerce must start with 'thalloCommerceAdmin'",
            );
        }

        $duplicates = array_keys(array_filter(array_count_values($allIds), static fn (int $c): bool => $c > 1));
        self::assertSame(
            [],
            $duplicates,
            'operationId(s) duplicated in the generated spec: ' . implode(', ', $duplicates),
        );
    }

    public function testGeneratedSpecKeepsTheNativeCommerceMountSeparateFromTheThalloMount(): void
    {
        $paths = array_keys($this->loadGeneratedSpec()['paths'] ?? []);

        // The native mount (Commerce's own routes.php) lives at /commerce/admin/*; the Thallo
        // mount lives at /v1/admin/commerce/*. Neither ever nests inside the other's prefix.
        $crossed = array_values(array_filter(
            $paths,
            static fn (string $p): bool => str_starts_with($p, '/v1/admin/commerce/admin'),
        ));
        self::assertSame([], $crossed);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function loadGeneratedSpec(): array
    {
        $path = dirname(__DIR__, 3) . '/docs/openapi.json';
        self::assertFileExists($path, 'docs/openapi.json must exist (run `composer docs:openapi`)');
        $raw = file_get_contents($path);
        self::assertNotFalse($raw);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'docs/openapi.json must decode to a JSON object');

        return $decoded;
    }

    /** @return list<string> "METHOD /path" */
    private function expectedApprovedSurface(): array
    {
        $catalogByKey = [];
        foreach (AdminRouteCatalog::entries() as $entry) {
            $catalogByKey[$entry->key] = $entry;
        }

        $entries = [];
        foreach (AdminMountAllowlist::keys() as $key) {
            self::assertArrayHasKey($key, $catalogByKey, "allowlist key '{$key}' must exist in the catalog");
            $entry = $catalogByKey[$key];
            $entries[] = strtoupper($entry->method) . ' /v1/admin/commerce' . $entry->path;
        }

        foreach (self::PACK_OWNED_ROUTES as [$method, $path]) {
            $entries[] = strtoupper($method) . ' ' . $path;
        }

        return array_values(array_unique($entries));
    }
}
