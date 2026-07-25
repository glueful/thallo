<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Content\Http\RequirePermission;
use App\Http\Middleware\AdminTenantBindingMiddleware;
use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Task 7 (Thallo admin-commerce-area plan, slice 3): the authorization matrix for the Commerce
 * admin mount, parameterized over one VIEW route (`GET /v1/admin/commerce/products`, requirement
 * `commerce.view,commerce.manage`) and one MANAGE route (`POST /v1/admin/commerce/products`,
 * requirement `commerce.manage`) -- both real, already-mounted routes (Task 6,
 * {@see AdminMountParityTest}), not synthetic stand-ins.
 *
 * Two mechanisms are used, matching this codebase's established constraints:
 *
 *  - `content_permission` decisions (the bulk of this matrix) are driven by resolving the REAL,
 *    production-wired middleware straight from the container (`container()->get('content_permission')`
 *    -- the SAME instance `Router::resolveMiddleware()` uses, wired with the real
 *    `CapabilityCatalog` implication source, per {@see \App\Providers\ThalloServiceProvider::
 *    makeRequirePermission()}), then invoked directly with a hand-built `Request` carrying the
 *    post-auth `'user'` attribute array (never `'auth.user'`, which only an optional enricher
 *    populates) and, for API-key cases, the `auth_method`/`api_key_scopes` attributes a real
 *    api_key-authenticated request would carry. This mirrors
 *    {@see \App\Tests\Integration\Http\RequirePermissionAnyOfTest} and
 *    {@see \App\Tests\Integration\Http\LocaleRbacApiTest}'s identical, established convention --
 *    the test harness cannot mint bearer JWTs, so a full-kernel dispatch for a JWT-authenticated
 *    principal is not available; testing the REAL authority directly against REAL seeded Aegis
 *    RBAC is the established substitute.
 *  - Cases that need more than the gate decision (unauthenticated 401, and the manage-only
 *    valid-DTO POST success + downstream controller integration) drive the REAL kernel via a
 *    genuine `X-API-Key` header (`ApiKeyAuthenticationProvider`'s real authentication path),
 *    mirroring {@see AdminMountParityTest}'s own `apiKeyRequest()`/`seedAdminManageApiKey()`
 *    idiom -- whose own docblock flags the valid-DTO POST case as this task's addition.
 *  - The wrong-workspace case is probed directly against
 *    {@see AdminTenantBindingMiddleware::selectTenant()} (a pure decision function, no I/O): the
 *    default test harness's `admin_tenant_binding` stays an inert passthrough for a full-kernel
 *    dispatch until full tenant resolution is armed
 *    ({@see \App\Tests\Integration\Tenancy\AdminTenantBindingMiddlewareTest}), matching
 *    {@see \App\Tests\Integration\Commerce\TenantResolutionModesTest}'s identical mode-(c)
 *    self-skip (`THALLO_TENANCY_DEV_LINK=1`) -- so this pins the real production decision without
 *    that heavier, opt-in setup.
 *
 * `commerce.view`/`commerce.manage` permission rows already exist (the pack's own seed
 * migration, `packages/thallo-commerce/migrations/002_SeedCommercePermissions.php`); this class
 * only seeds roles/grants/users, mirroring RequirePermissionAnyOfTest's identical
 * roles+role_permissions+user_roles+invalidateAllCache() seeding idiom -- and never touches the
 * shared `permissions` rows themselves.
 */
final class AdminAuthorizationMatrixTest extends AppTestCase
{
    private const VIEW_ROUTE = '/v1/admin/commerce/products';
    /** @var list<string> */
    private const VIEW_REQUIREMENTS = ['commerce.view', 'commerce.manage'];
    /** @var list<string> */
    private const MANAGE_REQUIREMENTS = ['commerce.manage'];

    /** @var list<string> */
    private array $userUuids = [];
    /** @var list<string> */
    private array $apiKeyUserUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];

    protected function tearDown(): void
    {
        $db = $this->connection();
        if ($this->userUuids !== []) {
            $db->table('user_roles')->whereIn('user_uuid', $this->userUuids)->forceDelete();
        }
        if ($this->apiKeyUserUuids !== []) {
            $db->table('api_keys')->whereIn('user_uuid', $this->apiKeyUserUuids)->forceDelete();
            $db->table('user_roles')->whereIn('user_uuid', $this->apiKeyUserUuids)->forceDelete();
            $db->table('users')->whereIn('uuid', $this->apiKeyUserUuids)->forceDelete();
        }
        if ($this->roleUuids !== []) {
            $db->table('role_permissions')->whereIn('role_uuid', $this->roleUuids)->forceDelete();
            $db->table('roles')->whereIn('uuid', $this->roleUuids)->forceDelete();
        }
        // The manage-only valid-DTO test creates a real product; truncate rather than track the
        // one uuid, mirroring ProductLinkApiTest/ShopCacheTest/TenantResolutionModesTest's
        // identical per-suite commerce_products cleanup.
        $db->getPDO()->exec('DELETE FROM commerce_products');
        $this->provider()->invalidateAllCache();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Structural pin: the hardcoded requirement constants above must match the LIVE routes.
    // ------------------------------------------------------------------

    public function testRequirementConstantsMatchTheLiveRouteMiddleware(): void
    {
        $get = $this->findRoute('GET', self::VIEW_ROUTE);
        self::assertNotNull($get, 'GET ' . self::VIEW_ROUTE . ' must be registered');
        self::assertContains(
            'content_permission:' . implode(',', self::VIEW_REQUIREMENTS),
            (array) $get['middleware'],
        );

        $post = $this->findRoute('POST', self::VIEW_ROUTE);
        self::assertNotNull($post, 'POST ' . self::VIEW_ROUTE . ' must be registered');
        self::assertContains(
            'content_permission:' . implode(',', self::MANAGE_REQUIREMENTS),
            (array) $post['middleware'],
        );
    }

    // ------------------------------------------------------------------
    // Unauthenticated -> 401
    // ------------------------------------------------------------------

    public function testUnauthenticatedRequestsAreRejectedWith401(): void
    {
        $get = $this->handle($this->jsonRequest('GET', self::VIEW_ROUTE));
        self::assertSame(401, $get->getStatusCode());

        $post = $this->handle($this->jsonRequest('POST', self::VIEW_ROUTE, []));
        self::assertSame(401, $post->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Wrong-workspace member -> non-revealing (probed + pinned)
    // ------------------------------------------------------------------

    public function testWrongWorkspaceMemberIsDeniedNonRevealingly(): void
    {
        $middleware = $this->container()->get('admin_tenant_binding');
        self::assertInstanceOf(AdminTenantBindingMiddleware::class, $middleware);

        // A member of ONE workspace, selecting a DIFFERENT workspace they don't belong to,
        // without cross-tenant operator authority ('tenancy.access_any'). PINNED: non-revealing
        // means 403 (Response::forbidden()), never a 404 that would confirm the workspace exists.
        $response = $middleware->selectTenant('some-other-workspace', ['my-own-workspace'], false);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(
            403,
            $response->getStatusCode(),
            'admin_tenant_binding must deny a non-member workspace selection with 403, never 404',
        );
    }

    // ------------------------------------------------------------------
    // "user with only commerce.view" / "only commerce.manage" (RBAC-only, no API-key layer)
    // ------------------------------------------------------------------

    public function testViewOnlyUserCanReadButCannotWrite(): void
    {
        $user = $this->userGranted(['commerce.view']);

        self::assertTrue(
            $this->passes($this->userRequest($user), self::VIEW_REQUIREMENTS),
            'commerce.view alone must satisfy the view requirement',
        );
        self::assertFalse(
            $this->passes($this->userRequest($user), self::MANAGE_REQUIREMENTS),
            'commerce.view alone must NOT satisfy the manage requirement',
        );
    }

    public function testManageOnlyUserCanReadViaImplicationAndCanReachTheManageGate(): void
    {
        $user = $this->userGranted(['commerce.manage']);

        self::assertTrue(
            $this->passes($this->userRequest($user), self::VIEW_REQUIREMENTS),
            'commerce.manage must satisfy the view requirement via implication',
        );
        self::assertTrue(
            $this->passes($this->userRequest($user), self::MANAGE_REQUIREMENTS),
            'commerce.manage must satisfy the manage requirement directly',
        );
    }

    /**
     * The manage-only gate decision is proven above; this drives the actual downstream
     * controller/catalog-service integration through the real kernel, so a correctly-authorized
     * request is never confused with one that merely got past the gate and then 422'd on DTO
     * validation. Necessarily API-key-authenticated (see class docblock).
     */
    public function testManageOnlyUserPostingAValidProductSucceedsThroughTheRealKernel(): void
    {
        $key = $this->seedApiKeyUser(['commerce.manage'], ['commerce.manage']);

        $get = $this->handle($this->apiKeyRequest('GET', self::VIEW_ROUTE, $key));
        self::assertSame(200, $get->getStatusCode(), (string) $get->getContent());

        $suffix = substr(Utils::generateNanoID(), 0, 8);
        $body = [
            'slug' => 'authz-matrix-product-' . $suffix,
            'name' => 'Authz Matrix Product',
            'type' => 'physical',
            'status' => 'draft',
            'variants' => [
                ['sku' => 'authz-matrix-sku-' . $suffix, 'price' => 1000, 'currency' => 'USD'],
            ],
        ];
        $post = $this->handle($this->apiKeyRequest('POST', self::VIEW_ROUTE, $key, $body));

        self::assertSame(201, $post->getStatusCode(), (string) $post->getContent());
        $decoded = json_decode((string) $post->getContent(), true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['success'] ?? false);
        // Cleanup: tearDown() truncates commerce_products unconditionally.
    }

    // ------------------------------------------------------------------
    // API-key cases: candidate-wise scope x RBAC intersection (spec §4.2)
    // ------------------------------------------------------------------

    public function testApiKeyManageScopeAndManageRoleIsAllowedForBothRequirements(): void
    {
        $user = $this->userGranted(['commerce.manage']);
        $request = $this->apiKeyPrincipalRequest($user, ['commerce.manage']);

        self::assertTrue($this->passes($request, self::VIEW_REQUIREMENTS));
        self::assertTrue($this->passes($request, self::MANAGE_REQUIREMENTS));
    }

    public function testApiKeyViewScopeWithManageRoleSatisfiesAViewRequirement(): void
    {
        // Scope carries view directly; RBAC holds manage, which satisfies view via implication --
        // both factors land on the SAME candidate ('commerce.view') through different grants.
        $user = $this->userGranted(['commerce.manage']);

        self::assertTrue(
            $this->passes($this->apiKeyPrincipalRequest($user, ['commerce.view']), self::VIEW_REQUIREMENTS),
        );
    }

    public function testApiKeyManageScopeWithViewRoleSatisfiesViewButNotManage(): void
    {
        // Scope carries manage (satisfies the view candidate via implication too); RBAC holds
        // only view. Against the VIEW requirement both factors land on the 'commerce.view'
        // candidate -> allowed. Against the MANAGE requirement the scope factor matches
        // ('commerce.manage' is literally granted) but the RBAC factor does NOT (view does not
        // imply manage) -- candidate-wise intersection denies it. This is this system's version
        // of "unrelated cross-match cannot authorize": a key scope alone, without a matching RBAC
        // grant on the SAME candidate, never crosses into a stronger requirement.
        $user = $this->userGranted(['commerce.view']);
        $request = $this->apiKeyPrincipalRequest($user, ['commerce.manage']);

        self::assertTrue($this->passes($request, self::VIEW_REQUIREMENTS));
        self::assertFalse($this->passes($request, self::MANAGE_REQUIREMENTS));
    }

    public function testApiKeyWithEmptyScopeIsDeniedDespiteRbac(): void
    {
        $user = $this->userGranted(['commerce.manage']);

        self::assertFalse(
            $this->passes($this->apiKeyPrincipalRequest($user, []), self::VIEW_REQUIREMENTS),
        );
    }

    // ------------------------------------------------------------------
    // Entry search (GET /v1/admin/commerce/entries) is manage-gated (task 7)
    // ------------------------------------------------------------------

    public function testEntriesSearchRouteRequiresCommerceManage(): void
    {
        $route = $this->findRoute('GET', '/v1/admin/commerce/entries');
        self::assertNotNull($route, 'GET /v1/admin/commerce/entries must be registered');
        self::assertContains('content_permission:commerce.manage', (array) $route['middleware']);

        $viewOnly = $this->userGranted(['commerce.view']);
        self::assertFalse(
            $this->passes($this->userRequest($viewOnly), self::MANAGE_REQUIREMENTS),
            'a view-only user must not reach the entry-search gate',
        );

        $manageOnly = $this->userGranted(['commerce.manage']);
        self::assertTrue($this->passes($this->userRequest($manageOnly), self::MANAGE_REQUIREMENTS));
    }

    // ------------------------------------------------------------------
    // products.stock.index (Task B1, single-page product editor plan): one representative
    // read from Commerce 1.5.0's six new per-product reads, driven through the SAME two
    // mechanisms as the products.index/products.store rows above -- a structural pin that its
    // own live route carries the view-mode requirement, then session (RBAC-only, no API-key
    // layer) and API-key gate checks against that requirement. `commerce.view`/`commerce.manage`
    // are Thallo permissions; Commerce's own native mount uses `commerce:read`/`commerce:write`
    // scopes instead (see AdminRouteCatalog's docblock) -- irrelevant here, since every scope
    // this test grants an API key is a Thallo permission slug, never the native pair.
    // ------------------------------------------------------------------

    private const STOCK_INDEX_ROUTE = '/v1/admin/commerce/products/{uuid}/stock';

    public function testStockIndexRouteRequirementMatchesTheLiveRouteMiddleware(): void
    {
        $route = $this->findRoute('GET', self::STOCK_INDEX_ROUTE);
        self::assertNotNull($route, 'GET ' . self::STOCK_INDEX_ROUTE . ' must be registered');
        self::assertContains(
            'content_permission:' . implode(',', self::VIEW_REQUIREMENTS),
            (array) $route['middleware'],
        );
    }

    // (a) session principal with commerce.view -> allowed (200).
    public function testStockIndexSessionWithViewPermissionIsAllowed(): void
    {
        $user = $this->userGranted(['commerce.view']);

        self::assertTrue(
            $this->passes($this->stockIndexRequest($user), self::VIEW_REQUIREMENTS),
            'commerce.view alone must satisfy products.stock.index, a view-mode read',
        );
    }

    // (b) principal with no commerce permission at all -> denied (403).
    public function testStockIndexSessionWithNoCommercePermissionIsDenied(): void
    {
        $user = $this->userGranted([]);

        self::assertFalse(
            $this->passes($this->stockIndexRequest($user), self::VIEW_REQUIREMENTS),
            'a principal holding no commerce permission must be denied products.stock.index',
        );
    }

    // (c) API key scoped commerce.view whose subject's live RBAC role ALSO satisfies
    // commerce.view -> allowed (200). Mirrors testApiKeyManageScopeAndManageRoleIsAllowed
    // ForBothRequirements above (view/view instead of manage/manage: same candidate,
    // both factors).
    public function testStockIndexApiKeyViewScopeWithMatchingViewRoleIsAllowed(): void
    {
        $user = $this->userGranted(['commerce.view']);
        $request = $this->apiKeyPrincipalRequest($user, ['commerce.view']);

        self::assertTrue($this->passes($request, self::VIEW_REQUIREMENTS));
    }

    /**
     * Drives the gate decision above through the REAL kernel against the actual mounted
     * route, proving the Task B1 mount is genuinely reachable end to end -- not just that the
     * abstract permission gate agrees. The seeded product deliberately carries ZERO variants:
     * `items: []` with a `revision` present is a valid 200 for the stock read (Global
     * Constraints; {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::stockForProduct()}),
     * which sidesteps {@see \Glueful\Extensions\Commerce\Inventory\StockIntegrityException} --
     * the real read throws that (500) for any seeded variant lacking a matching `commerce_stock`
     * row, so a variant-bearing fixture here would need a stock row seeded alongside it.
     */
    public function testStockIndexApiKeyViewScopeIsReachableThroughTheRealKernel(): void
    {
        $key = $this->seedApiKeyUser(['commerce.view'], ['commerce.view']);
        $productUuid = $this->seedZeroVariantProduct();

        $response = $this->handle($this->apiKeyRequest(
            'GET',
            '/v1/admin/commerce/products/' . $productUuid . '/stock',
            $key,
        ));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['success'] ?? false, (string) $response->getContent());
        self::assertEqualsCanonicalizing(['revision', 'items'], array_keys($decoded['data']));
        self::assertSame([], $decoded['data']['items']);
    }

    private function stockIndexRequest(string $userUuid): Request
    {
        $request = Request::create(self::STOCK_INDEX_ROUTE, 'GET');
        $request->attributes->set('user', ['uuid' => $userUuid, 'roles' => [], 'claims' => ['scopes' => []]]);

        return $request;
    }

    /** Zero-variant product: sufficient for the stock read (`items: []`), no stock seeding needed. */
    private function seedZeroVariantProduct(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'slug' => 'authz-matrix-stock-' . $uuid,
            'name' => 'Authz Matrix Stock Product',
        ]);

        return $uuid;
    }

    // ------------------------------------------------------------------
    // drivers
    // ------------------------------------------------------------------

    /** @param list<string> $requirements */
    private function passes(Request $request, array $requirements): bool
    {
        $middleware = $this->container()->get('content_permission');
        self::assertInstanceOf(RequirePermission::class, $middleware);

        $reached = false;
        $response = $middleware->handle(
            $request,
            function (Request $r) use (&$reached): Response {
                $reached = true;
                return Response::success(['ok' => true], 'ok');
            },
            ...$requirements,
        );
        if (!$reached) {
            self::assertSame(403, $response->getStatusCode());
        }

        return $reached;
    }

    private function userRequest(string $userUuid): Request
    {
        $request = Request::create(self::VIEW_ROUTE, 'GET');
        $request->attributes->set('user', ['uuid' => $userUuid, 'roles' => [], 'claims' => ['scopes' => []]]);

        return $request;
    }

    /** @param list<string> $scopes */
    private function apiKeyPrincipalRequest(string $userUuid, array $scopes): Request
    {
        $request = $this->userRequest($userUuid);
        $request->attributes->set('auth_method', 'api_key');
        $request->attributes->set('api_key_scopes', $scopes);

        return $request;
    }

    /** Real X-API-Key header, mirrors AdminMountParityTest::apiKeyRequest() (+ optional JSON body). */
    private function apiKeyRequest(string $method, string $path, string $key, ?array $body = null): Request
    {
        return Request::create(
            $path,
            $method,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer unused-clears-the-auth-middleware-bearer-gate',
                'HTTP_X_API_KEY' => $key,
            ],
            $body === null ? null : (string) json_encode($body),
        );
    }

    // ------------------------------------------------------------------
    // seeding (mirrors RequirePermissionAnyOfTest / AdminMountParityTest)
    // ------------------------------------------------------------------

    /** @param list<string> $grantedPermissionSlugs */
    private function userGranted(array $grantedPermissionSlugs): string
    {
        $userUuid = Utils::generateNanoID(12);
        $this->userUuids[] = $userUuid;

        if ($grantedPermissionSlugs !== []) {
            $this->grantRole($userUuid, $grantedPermissionSlugs);
        }
        $this->provider()->invalidateAllCache();

        return $userUuid;
    }

    /** @param list<string> $scopes */
    private function seedApiKeyUser(array $grantedPermissionSlugs, array $scopes): string
    {
        $userUuid = Utils::generateNanoID();
        $this->apiKeyUserUuids[] = $userUuid;

        $this->connection()->table('users')->insert([
            'uuid' => $userUuid,
            'username' => 'authz_mx_' . substr($userUuid, 0, 8),
            'email' => $userUuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($grantedPermissionSlugs !== []) {
            $this->grantRole($userUuid, $grantedPermissionSlugs);
        }
        $this->provider()->invalidateAllCache();

        $created = ApiKeyService::create($this->appContext(), [
            'user_uuid' => $userUuid,
            'name' => 'authz-matrix-test',
            'scopes' => $scopes,
        ]);

        return (string) $created['plain'];
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'authzmx_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'authorization matrix test role',
            'level' => 30,
            'is_system' => false,
            'status' => 'active',
        ]);

        $permissions = new PermissionRepository($this->connection());
        $rolePermissions = new RolePermissionRepository($this->connection());
        foreach ($permissionSlugs as $slug) {
            $permission = $permissions->findPermissionBySlug($slug);
            self::assertNotNull($permission, "permission {$slug} must exist (pack seed migration)");
            $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid(), []);
        }

        self::assertTrue($this->provider()->assignRole($userUuid, $roleSlug));
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }
}
