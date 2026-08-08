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
    ];

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
