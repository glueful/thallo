<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Commerce\Http\Routing\AdminRouteCatalog;
use Glueful\Extensions\Commerce\Http\Routing\AdminRouteEntry;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\AdminMountAllowlist;

/**
 * Task 6 (admin-commerce-area plan, slice 3): the Commerce admin catalog is mounted at
 * `/v1/admin/commerce` behind a fail-closed, explicit allowlist ({@see AdminMountAllowlist}).
 * This is the approved-inventory PARITY test that keeps the mount honest against an upgraded
 * vendored `glueful/commerce` copy:
 *
 *  (a) the checked-in fixture (`tests/fixtures/commerce_admin_mount_inventory.json` — the
 *      approved `[{key, mode}]` inventory) must cover every key
 *      {@see AdminRouteCatalog::entries()} declares, with matching modes. A catalog entry the
 *      fixture doesn't know about fails loudly, naming the key — "a new Commerce endpoint
 *      awaiting conscious approval" — rather than silently going unmounted (or, if someone
 *      "fixes" the failure by wildcarding the allowlist, silently becoming reachable).
 *  (b) every fixture key must resolve to a route actually registered on the live router at
 *      `/v1/admin/commerce`, named `thallo.commerce.admin.<key>`, with the method/path/
 *      controller the catalog declares and a middleware stack ending in the correct per-mode
 *      `content_permission:...` requirement.
 *  (c) every one of those route names is globally unique across the ENTIRE route table — not
 *      just unique among Commerce's own mounted routes.
 *
 * A 4th test drives a representative endpoint per reachable domain through the REAL kernel
 * (Application::handle, not a direct controller call) as a seeded admin-with-`commerce.manage`
 * user, proving `auth` -> `tenant_profile`/`tenant_bootstrap`/`admin_tenant_binding` (all inert
 * in this harness's default bootstrap/single-store state, per
 * AdminTenantBindingMiddlewareTest) -> `content_permission` -> controller is wired end to end,
 * not just declared. The harness cannot mint bearer JWTs (see LocaleRbacApiTest's docblock), so
 * the credential is a real X-API-Key header — ApiKeyAuthenticationProvider's real
 * authentication path — for a user seeded with the `administrator` RBAC role, which already
 * holds `commerce.manage` (see database/dependent-migrations/014_GrantCommercePermissionsToAdministrator.php);
 * PermissionRequirementAuthority requires BOTH a matching key scope AND a live RBAC grant for
 * API-key requests (candidate-wise intersection), so the key also carries the `commerce.manage`
 * scope directly.
 */
final class AdminMountParityTest extends AppTestCase
{
    private const NAME_PREFIX = 'thallo.commerce.admin.';

    private ?string $smokeUserUuid = null;

    protected function tearDown(): void
    {
        if ($this->smokeUserUuid !== null) {
            $db = $this->connection();
            $db->table('api_keys')->where('user_uuid', '=', $this->smokeUserUuid)->forceDelete();
            $db->table('user_roles')->where('user_uuid', '=', $this->smokeUserUuid)->forceDelete();
            $db->table('users')->where('uuid', '=', $this->smokeUserUuid)->forceDelete();
            $this->provider()->invalidateAllCache();
            $this->smokeUserUuid = null;
        }
        parent::tearDown();
    }

    // -- (a) fixture <-> catalog drift ------------------------------------------------------

    public function testFixtureCoversEveryCatalogEntryWithMatchingModes(): void
    {
        $fixture = $this->loadFixture();
        $catalog = [];
        foreach (AdminRouteCatalog::entries() as $entry) {
            $catalog[$entry->key] = $entry->mode;
        }

        $awaitingApproval = array_values(array_diff(array_keys($catalog), array_keys($fixture)));
        self::assertSame(
            [],
            $awaitingApproval,
            'New Commerce admin catalog endpoint(s) awaiting conscious approval into '
                . 'tests/fixtures/commerce_admin_mount_inventory.json and AdminMountAllowlist: '
                . implode(', ', $awaitingApproval),
        );

        $staleFixtureKeys = array_values(array_diff(array_keys($fixture), array_keys($catalog)));
        self::assertSame(
            [],
            $staleFixtureKeys,
            'Fixture references catalog key(s) no longer declared by AdminRouteCatalog: '
                . implode(', ', $staleFixtureKeys),
        );

        $filteredCatalog = array_intersect_key($catalog, $fixture);
        ksort($filteredCatalog);
        ksort($fixture);
        self::assertSame($fixture, $filteredCatalog, 'Fixture mode(s) have drifted from the catalog.');
    }

    // -- (b) fixture <-> live mounted route table --------------------------------------------

    public function testEveryFixtureKeyIsMountedWithTheCatalogsMethodPathControllerAndPermission(): void
    {
        $fixture = $this->loadFixture();
        $catalogByKey = $this->catalogByKey();
        $routesByName = $this->mountedRoutesByName();

        foreach ($fixture as $key => $mode) {
            self::assertArrayHasKey($key, $catalogByKey, "fixture key '{$key}' must exist in the catalog");
            $entry = $catalogByKey[$key];
            $name = self::NAME_PREFIX . $key;

            self::assertArrayHasKey($name, $routesByName, "expected a mounted route named '{$name}'");
            $route = $routesByName[$name];

            self::assertSame(
                strtoupper($entry->method),
                strtoupper((string) $route['method']),
                "route '{$name}' method must match the catalog",
            );
            self::assertSame(
                '/v1/admin/commerce' . $entry->path,
                $route['path'],
                "route '{$name}' path must match the catalog",
            );
            self::assertSame(
                [$entry->controller, $entry->action],
                $route['handler'],
                "route '{$name}' handler must match the catalog controller/action",
            );

            $expectedPermission = $mode === 'view'
                ? 'content_permission:commerce.view,commerce.manage'
                : 'content_permission:commerce.manage';
            /** @var list<string> $middleware */
            $middleware = $route['middleware'];
            self::assertSame(
                $expectedPermission,
                end($middleware),
                "route '{$name}' middleware must end in the mode-{$mode} permission requirement",
            );
            self::assertContains('auth', $middleware, "route '{$name}' must carry the base auth middleware");
            self::assertContains(
                'admin_tenant_binding',
                $middleware,
                "route '{$name}' must carry admin_tenant_binding",
            );
        }
    }

    // -- (c) route-name uniqueness ------------------------------------------------------------

    public function testMountedRouteNamesAreNamespacedAndGloballyUnique(): void
    {
        $fixture = $this->loadFixture();
        $allNames = [];
        foreach ($this->router()->getAllRoutes() as $route) {
            $name = $route['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $allNames[] = $name;
            }
        }

        foreach (array_keys($fixture) as $key) {
            $expected = self::NAME_PREFIX . $key;
            self::assertContains($expected, $allNames, "expected route name '{$expected}' to be registered");
        }

        $duplicates = array_keys(array_filter(array_count_values($allNames), static fn (int $c): bool => $c > 1));
        self::assertSame([], $duplicates, 'Route name(s) registered more than once: ' . implode(', ', $duplicates));
    }

    // -- mount smoke: representative endpoints through the real kernel -----------------------

    public function testRepresentativeMountedEndpointsAreReachableThroughTheRealKernelForASeededAdminManageUser(): void
    {
        $key = $this->seedAdminManageApiKey();

        // One no-path-param GET per domain that has one (design spec domains without a
        // parameterless read — `downloads`, `grants`, `inventory` — have no viable
        // representative here; their wiring is already exhaustively checked structurally by
        // testEveryFixtureKeyIsMountedWithTheCatalogsMethodPathControllerAndPermission above).
        $paths = [
            'products'   => '/v1/admin/commerce/products',
            'customers'  => '/v1/admin/commerce/customers',
            'taxonomy'   => '/v1/admin/commerce/categories',
            'discounts'  => '/v1/admin/commerce/discounts',
            'orders'     => '/v1/admin/commerce/orders',
            'reviews'    => '/v1/admin/commerce/reviews',
            'shipping'   => '/v1/admin/commerce/shipping/zones',
            'tax'        => '/v1/admin/commerce/tax/rates',
            'reports'    => '/v1/admin/commerce/reports/sales',
        ];

        foreach ($paths as $domain => $path) {
            $response = $this->handle($this->apiKeyRequest('GET', $path, $key));
            self::assertSame(
                200,
                $response->getStatusCode(),
                "domain '{$domain}' ({$path}) expected 200, got {$response->getStatusCode()}: "
                    . $response->getContent(),
            );

            $body = json_decode((string) $response->getContent(), true);
            self::assertIsArray($body, "domain '{$domain}' ({$path}) must return a JSON object");
            self::assertTrue(
                $body['success'] ?? false,
                "domain '{$domain}' ({$path}) must return a success envelope: " . $response->getContent(),
            );
        }
    }

    public function testAManageModeGateIsTraversedLiveThroughTheRealKernel(): void
    {
        // The GET smoke above only crosses view-mode gates. Drive one manage-mode route
        // (products.store) end-to-end: the empty body may 422 on DTO validation, but the
        // request must get PAST auth + workspace binding + content_permission:commerce.manage —
        // i.e. never 401/403/404. (T7's authorization matrix adds the valid-DTO success case.)
        $key = $this->seedAdminManageApiKey();

        $response = $this->handle($this->apiKeyRequest('POST', '/v1/admin/commerce/products', $key));
        self::assertNotContains(
            $response->getStatusCode(),
            [401, 403, 404],
            'manage-mode gate must be traversed (auth + binding + permission passed): '
                . $response->getContent(),
        );
    }

    // -- helpers --------------------------------------------------------------------------------

    /** @return array<string,string> catalog key => mode */
    private function loadFixture(): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures/commerce_admin_mount_inventory.json';
        $raw = file_get_contents($path);
        self::assertNotFalse($raw, "unable to read fixture at {$path}");

        $rows = json_decode($raw, true);
        self::assertIsArray($rows, "fixture at {$path} must decode to a JSON array");

        $byKey = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('key', $row);
            self::assertArrayHasKey('mode', $row);
            $byKey[(string) $row['key']] = (string) $row['mode'];
        }
        ksort($byKey);

        return $byKey;
    }

    /** @return array<string, AdminRouteEntry> */
    private function catalogByKey(): array
    {
        $byKey = [];
        foreach (AdminRouteCatalog::entries() as $entry) {
            $byKey[$entry->key] = $entry;
        }

        return $byKey;
    }

    /** @return array<string, array<string,mixed>> route name => route record */
    private function mountedRoutesByName(): array
    {
        $byName = [];
        foreach ($this->router()->getAllRoutes() as $route) {
            $name = $route['name'] ?? null;
            if (is_string($name) && str_starts_with($name, self::NAME_PREFIX)) {
                $byName[$name] = $route;
            }
        }

        return $byName;
    }

    /** Seeds a user with the `administrator` RBAC role and mints a `commerce.manage`-scoped key. */
    private function seedAdminManageApiKey(): string
    {
        $userUuid = Utils::generateNanoID();
        $this->smokeUserUuid = $userUuid;

        $this->connection()->table('users')->insert([
            'uuid'               => $userUuid,
            'username'           => 'admin_mount_parity_' . substr($userUuid, 0, 8),
            'email'              => $userUuid . '@example.test',
            'password'           => 'x',
            'status'             => 'active',
            'two_factor_enabled' => false,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        self::assertTrue(
            $this->provider()->assignRole($userUuid, 'administrator'),
            'seed user must be assignable the administrator role',
        );
        $this->provider()->invalidateAllCache();

        $created = ApiKeyService::create($this->appContext(), [
            'user_uuid' => $userUuid,
            'name'      => 'admin-mount-parity-test',
            'scopes'    => ['commerce.manage'],
        ]);

        return (string) $created['plain'];
    }

    /**
     * Builds a real-kernel request authenticated via API key. `Glueful\Routing\Middleware\
     * AuthMiddleware` only ATTEMPTS provider authentication when its own outer token
     * extraction (Bearer-header or `?token=` only — see `TokenManager::extractTokenFromRequest()`)
     * finds something, so a bare `X-API-Key` header alone never reaches any provider: a
     * throwaway `Authorization: Bearer` value clears that outer gate (the JWT provider then
     * fails cleanly on it — invalid session — and `AuthenticationManager::authenticateWithProviders()`
     * falls through to the api_key provider, which authenticates for real off `X-API-Key`).
     */
    private function apiKeyRequest(string $method, string $path, string $key): Request
    {
        return Request::create($path, $method, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer unused-clears-the-auth-middleware-bearer-gate',
            'HTTP_X_API_KEY' => $key,
        ]);
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }
}
