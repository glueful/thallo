<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Commerce\Http\Admin\OrderProjection;
use Glueful\Helpers\Utils;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Orders\AdminOrderSearchFilter;
use Thallo\Commerce\Orders\AdminOrderSearchQuery;

/**
 * Task 3 (orders-invoices-receipts plan): the app-owned filtered orders search endpoint
 * (`GET /v1/admin/commerce/orders/search`, TEMPORARY ownership — see
 * {@see AdminOrderSearchQuery}'s docblock).
 *
 * Two drivers, matching this codebase's established convention for this kind of surface
 * ({@see AdminAuthorizationMatrixTest}'s own docblock):
 *
 *  - Query/filter behavior (tenant scoping, enum/date/`q` validation, the half-open date
 *    boundary, sort tie-break, the framework-vocabulary-leak proof) is driven DIRECTLY against
 *    {@see AdminOrderSearchQuery} + {@see AdminOrderSearchFilter} — both are deliberately public,
 *    directly-constructible seams (`builder()`/`applyOrder()`/`apply()`) precisely so this is
 *    possible without a full HTTP round trip or without arming tenancy enforcement.
 *  - The route-level concerns (authorization matrix, the exact `OrderProjection::FIELDS`
 *    response shape) are driven through the REAL kernel via `seedApiKeyUser()`-style actors,
 *    mirroring {@see AdminAuthorizationMatrixTest} exactly. The default test harness leaves
 *    tenancy resolution at sentinel mode ('' — {@see \Thallo\Commerce\Tenancy\
 *    ThalloCommerceTenantResolution} mode (a)), so every kernel-driven fixture below seeds
 *    `tenant_uuid => ''` to match what the controller will actually resolve.
 *
 * The suite's real database driver is PostgreSQL ({@see \App\Tests\Support\AppTestCase}'s own
 * `phpunit.xml`, `DB_DRIVER=pgsql`) — the `q` literal-escape assertions below exercise that
 * driver for real; SQLite is not part of this repository's CI matrix.
 */
final class AdminOrderSearchTest extends AppTestCase
{
    private const ROUTE = '/v1/admin/commerce/orders/search';

    /** @var list<string> */
    private array $apiKeyUserUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];

    protected function tearDown(): void
    {
        $db = $this->connection();
        $db->getPDO()->exec('DELETE FROM commerce_orders');
        if ($this->apiKeyUserUuids !== []) {
            $db->table('api_keys')->whereIn('user_uuid', $this->apiKeyUserUuids)->forceDelete();
            $db->table('user_roles')->whereIn('user_uuid', $this->apiKeyUserUuids)->forceDelete();
            $db->table('users')->whereIn('uuid', $this->apiKeyUserUuids)->forceDelete();
        }
        if ($this->roleUuids !== []) {
            $db->table('role_permissions')->whereIn('role_uuid', $this->roleUuids)->forceDelete();
            $db->table('roles')->whereIn('uuid', $this->roleUuids)->forceDelete();
        }
        $this->provider()->invalidateAllCache();
        parent::tearDown();
    }

    // ==================================================================
    // Tenant isolation
    // ==================================================================

    public function testBuilderScopesStrictlyToTheGivenTenant(): void
    {
        $tenantA = Utils::generateNanoID();
        $tenantB = Utils::generateNanoID();

        $this->seedOrder($tenantA, ['placed_at' => '2026-01-10 10:00:00']);
        $this->seedOrder($tenantA, ['placed_at' => null, 'created_at' => '2026-01-11 10:00:00']);
        $this->seedOrder($tenantB, ['placed_at' => '2026-01-10 10:00:00']);

        $rows = (new AdminOrderSearchQuery($this->appContext()))->builder($tenantA)->get();

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($tenantA, $row['tenant_uuid']);
        }
    }

    // ==================================================================
    // Draft-blindness (admin-order-creation cycle 2, Task 12, hardened from Task 8's engine
    // review): `AdminOrderSearchQuery::builder()` applies the engine's ONE finalized-order
    // predicate, `OrderScope::excludeDrafts()` — proven here at the choke point itself (the bare
    // builder, no filter applied at all) and again under representative filter combinations, so a
    // draft can never surface through this endpoint by construction, not merely by omission from
    // today's filter set.
    // ==================================================================

    public function testBareBuilderExcludesADraftOrder(): void
    {
        $tenant = Utils::generateNanoID();
        $draft = $this->seedDraftOrder($tenant);
        $finalized = $this->seedOrder($tenant, ['status' => 'paid']);

        $rows = (new AdminOrderSearchQuery($this->appContext()))->builder($tenant)->get();

        self::assertSame([$finalized], array_column($rows, 'uuid'));
        self::assertNotContains($draft, array_column($rows, 'uuid'));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function filterCombinationsProvider(): iterable
    {
        yield 'no filter' => [[]];
        yield 'status filter' => [['status' => 'paid']];
        yield 'fulfillment_status filter' => [['fulfillment_status' => 'unfulfilled']];
        yield 'date range filter' => [['placed_from' => '2020-01-01', 'placed_to' => '2030-01-01']];
        yield 'q filter' => [['q' => 'ORD']];
        yield 'combined filters' => [[
            'status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'placed_from' => '2020-01-01',
            'placed_to' => '2030-01-01',
            'q' => 'ORD',
        ]];
    }

    /**
     * @dataProvider filterCombinationsProvider
     * @param array<string, mixed> $params
     */
    public function testDraftOrderIsNeverReturnedUnderAnyFilterCombination(array $params): void
    {
        $tenant = Utils::generateNanoID();
        $draft = $this->seedDraftOrder($tenant);
        $this->seedOrder($tenant, ['status' => 'paid']);

        $rows = $this->applyDirect($tenant, $params)->get();

        self::assertNotContains($draft, array_column($rows, 'uuid'));
    }

    public function testFilteringByDraftStatusIs422NotABypass(): void
    {
        $tenant = Utils::generateNanoID();
        $this->seedDraftOrder($tenant);

        $this->expectValidationException(fn () => $this->applyDirect($tenant, ['status' => 'draft']));
    }

    public function testRouteResponseNeverIncludesASeededDraftOrder(): void
    {
        $this->seedDraftOrder('');
        $finalized = $this->seedOrder('', ['status' => 'paid']);
        $key = $this->seedApiKeyUser(['commerce.view'], ['commerce.view']);

        $response = $this->handle($this->apiKeyRequest('GET', self::ROUTE, $key));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['total'] ?? null, 'the draft must not be counted');
        self::assertCount(1, $decoded['data']);
        self::assertSame($finalized, $decoded['data'][0]['uuid'] ?? null);
    }

    // ==================================================================
    // status / fulfillment_status enum filters
    // ==================================================================

    public function testStatusFilterNarrowsToTheExactStatus(): void
    {
        $tenant = Utils::generateNanoID();
        $paid = $this->seedOrder($tenant, ['status' => 'paid']);
        $this->seedOrder($tenant, ['status' => 'canceled']);

        $rows = $this->applyDirect($tenant, ['status' => 'paid'])->get();

        self::assertCount(1, $rows);
        self::assertSame($paid, $rows[0]['uuid']);
    }

    public function testInvalidStatusIs422(): void
    {
        $tenant = Utils::generateNanoID();

        $this->expectValidationException(fn () => $this->applyDirect($tenant, ['status' => 'bogus']));
    }

    public function testFulfillmentStatusFilterNarrowsToTheExactValue(): void
    {
        $tenant = Utils::generateNanoID();
        $partial = $this->seedOrder($tenant, ['fulfillment_status' => 'partial']);
        $this->seedOrder($tenant, ['fulfillment_status' => 'unfulfilled']);

        $rows = $this->applyDirect($tenant, ['fulfillment_status' => 'partial'])->get();

        self::assertCount(1, $rows);
        self::assertSame($partial, $rows[0]['uuid']);
    }

    public function testInvalidFulfillmentStatusIs422(): void
    {
        $tenant = Utils::generateNanoID();

        $this->expectValidationException(
            fn () => $this->applyDirect($tenant, ['fulfillment_status' => 'bogus']),
        );
    }

    // ==================================================================
    // Date validation: shape-valid-but-impossible + malformed
    // ==================================================================

    public function testShapeValidButImpossibleDateIs422(): void
    {
        $tenant = Utils::generateNanoID();

        $this->expectValidationException(
            fn () => $this->applyDirect($tenant, ['placed_from' => '2026-02-31']),
        );
    }

    public function testMalformedDateIs422(): void
    {
        $tenant = Utils::generateNanoID();

        $this->expectValidationException(
            fn () => $this->applyDirect($tenant, ['placed_to' => 'not-a-date']),
        );
    }

    public function testPlacedFromAfterPlacedToIs422(): void
    {
        $tenant = Utils::generateNanoID();

        $this->expectValidationException(fn () => $this->applyDirect($tenant, [
            'placed_from' => '2026-02-10',
            'placed_to' => '2026-02-01',
        ]));
    }

    // ==================================================================
    // Half-open UTC date boundary
    // ==================================================================

    public function testHalfOpenBoundaryIncludesFromExcludesTo(): void
    {
        $tenant = Utils::generateNanoID();
        $included = $this->seedOrder($tenant, ['placed_at' => '2026-01-01 00:00:00']);
        $this->seedOrder($tenant, ['placed_at' => '2026-01-02 00:00:00']); // == toExclusive -> excluded

        $rows = $this->applyDirect($tenant, [
            'placed_from' => '2026-01-01',
            'placed_to' => '2026-01-01',
        ])->get();

        self::assertCount(1, $rows);
        self::assertSame($included, $rows[0]['uuid']);
    }

    public function testPlacedAtNullRowIsHonoredViaCreatedAtBranch(): void
    {
        $tenant = Utils::generateNanoID();
        $inRange = $this->seedOrder($tenant, ['placed_at' => null, 'created_at' => '2026-01-01 08:00:00']);
        $this->seedOrder($tenant, ['placed_at' => null, 'created_at' => '2026-02-01 08:00:00']);
        // A placed_at-carrying row inside the same window must NOT also match via created_at —
        // proving the two branches are mutually exclusive (IS NOT NULL / IS NULL), not additive.
        $this->seedOrder($tenant, ['placed_at' => '2026-06-01 00:00:00', 'created_at' => '2026-01-01 08:00:00']);

        $rows = $this->applyDirect($tenant, [
            'placed_from' => '2026-01-01',
            'placed_to' => '2026-01-01',
        ])->get();

        self::assertCount(1, $rows);
        self::assertSame($inRange, $rows[0]['uuid']);
    }

    // ==================================================================
    // `q` prefix search + literal escape contract (real driver: PostgreSQL)
    // ==================================================================

    public function testQPrefixMatchesOrderNumberCaseSensitively(): void
    {
        $tenant = Utils::generateNanoID();
        $match = $this->seedOrder($tenant, ['order_number' => 'ORD-1000']);
        $this->seedOrder($tenant, ['order_number' => 'ord-1000']); // different case -> no match
        $this->seedOrder($tenant, ['order_number' => 'ORD-2000']); // different prefix -> no match

        $rows = $this->applyDirect($tenant, ['q' => 'ORD-1'])->get();

        self::assertCount(1, $rows);
        self::assertSame($match, $rows[0]['uuid']);
    }

    public function testQPrefixMatchesEmailCaseInsensitively(): void
    {
        $tenant = Utils::generateNanoID();
        $match = $this->seedOrder($tenant, ['email' => 'Buyer@Example.com']);
        $this->seedOrder($tenant, ['email' => 'other@example.com']);

        $rows = $this->applyDirect($tenant, ['q' => 'buyer@ex'])->get();

        self::assertCount(1, $rows);
        self::assertSame($match, $rows[0]['uuid']);
    }

    public function testLiteralPercentInQMatchesOnlyLiterally(): void
    {
        $tenant = Utils::generateNanoID();
        $literal = $this->seedOrder($tenant, ['order_number' => 'PCT-50%OFF']);
        $this->seedOrder($tenant, ['order_number' => 'PCT-50XOFF']); // would match if % were a wildcard

        $rows = $this->applyDirect($tenant, ['q' => 'PCT-50%'])->get();

        self::assertCount(1, $rows);
        self::assertSame($literal, $rows[0]['uuid']);
    }

    public function testLiteralUnderscoreInQMatchesOnlyLiterally(): void
    {
        $tenant = Utils::generateNanoID();
        $literal = $this->seedOrder($tenant, ['order_number' => 'SKU_123']);
        $this->seedOrder($tenant, ['order_number' => 'SKUX123']); // would match if _ were a wildcard

        $rows = $this->applyDirect($tenant, ['q' => 'SKU_1'])->get();

        self::assertCount(1, $rows);
        self::assertSame($literal, $rows[0]['uuid']);
    }

    public function testLiteralBangInQMatchesOnlyLiterally(): void
    {
        $tenant = Utils::generateNanoID();
        $literal = $this->seedOrder($tenant, ['order_number' => 'HOT!DEAL']);
        $this->seedOrder($tenant, ['order_number' => 'HOTXDEAL']);

        $rows = $this->applyDirect($tenant, ['q' => 'HOT!'])->get();

        self::assertCount(1, $rows);
        self::assertSame($literal, $rows[0]['uuid']);
    }

    public function testQOver200CharsIs422(): void
    {
        $tenant = Utils::generateNanoID();

        $this->expectValidationException(
            fn () => $this->applyDirect($tenant, ['q' => str_repeat('a', 201)]),
        );
    }

    // ==================================================================
    // Framework-vocabulary leak: direct sort / search / filter[...] are inert
    // ==================================================================

    public function testFrameworkSortSearchAndFilterParamsAreIgnored(): void
    {
        $tenant = Utils::generateNanoID();
        $this->seedOrder($tenant, ['status' => 'paid']);
        $this->seedOrder($tenant, ['status' => 'fulfilled']);
        $this->seedOrder($tenant, ['status' => 'canceled']);

        $clean = $this->applyDirect($tenant, [])->get();
        $vocab = $this->applyDirect($tenant, [
            'sort' => '-status',
            'search' => 'nonsense-term',
            'filter' => ['status' => 'canceled'],
        ])->get();

        // If `filter[status]=canceled` had leaked through the framework parser, this would
        // collapse from 3 rows to 1; if `sort`/`search` had thrown or reordered/dropped rows,
        // the uuid sets (not just counts) would differ.
        self::assertCount(3, $vocab);
        self::assertEqualsCanonicalizing(
            array_column($clean, 'uuid'),
            array_column($vocab, 'uuid'),
        );
    }

    // ==================================================================
    // Sort: report-time DESC, id tie-break
    // ==================================================================

    public function testSortIsReportTimeDescendingWithIdTieBreak(): void
    {
        $tenant = Utils::generateNanoID();
        $earlier = $this->seedOrder($tenant, ['placed_at' => '2026-01-01 00:00:00']);
        // Two rows sharing the SAME report time, inserted in this order -> higher id must win.
        $tiedFirst = $this->seedOrder($tenant, ['placed_at' => '2026-02-01 00:00:00']);
        $tiedSecond = $this->seedOrder($tenant, ['placed_at' => '2026-02-01 00:00:00']);

        $searchQuery = new AdminOrderSearchQuery($this->appContext());
        $query = $searchQuery->builder($tenant);
        $searchQuery->applyOrder($query);

        $rows = $query->get();

        self::assertSame([$tiedSecond, $tiedFirst, $earlier], array_column($rows, 'uuid'));
    }

    // ==================================================================
    // Kernel-driven: response shape (OrderProjection boundary) + authority matrix
    // ==================================================================

    public function testRouteResponseRowsHaveExactlyTheProjectionFields(): void
    {
        $this->seedOrder('', []);
        $key = $this->seedApiKeyUser(['commerce.view'], ['commerce.view']);

        $response = $this->handle($this->apiKeyRequest('GET', self::ROUTE, $key));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['success'] ?? false);
        self::assertCount(1, $decoded['data']);
        self::assertEqualsCanonicalizing(OrderProjection::FIELDS, array_keys($decoded['data'][0]));
        self::assertArrayHasKey('total', $decoded);
        self::assertArrayHasKey('current_page', $decoded);
        self::assertArrayHasKey('per_page', $decoded);
    }

    public function testRouteIsRegisteredWithViewAuthority(): void
    {
        $route = $this->findRoute('GET', self::ROUTE);
        self::assertNotNull($route, 'GET ' . self::ROUTE . ' must be registered');
        self::assertContains('content_permission:commerce.view,commerce.manage', (array) $route['middleware']);
    }

    public function testAnonymousRequestIsRejectedWith401(): void
    {
        $response = $this->handle($this->jsonRequest('GET', self::ROUTE));
        self::assertSame(401, $response->getStatusCode());
    }

    public function testNoPermissionActorIsRejectedWith403(): void
    {
        $key = $this->seedApiKeyUser([], []);

        $response = $this->handle($this->apiKeyRequest('GET', self::ROUTE, $key));
        self::assertSame(403, $response->getStatusCode());
    }

    public function testViewOnlyActorIsAllowed(): void
    {
        $this->seedOrder('', []);
        $key = $this->seedApiKeyUser(['commerce.view'], ['commerce.view']);

        $response = $this->handle($this->apiKeyRequest('GET', self::ROUTE, $key));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testManageActorIsAllowed(): void
    {
        $this->seedOrder('', []);
        $key = $this->seedApiKeyUser(['commerce.manage'], ['commerce.manage']);

        $response = $this->handle($this->apiKeyRequest('GET', self::ROUTE, $key));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    // ==================================================================
    // drivers
    // ==================================================================

    /** @param array<string,mixed> $queryParams */
    private function applyDirect(string $tenant, array $queryParams): QueryBuilder
    {
        $query = (new AdminOrderSearchQuery($this->appContext()))->builder($tenant);
        $request = Request::create(self::ROUTE, 'GET', $queryParams);
        (new AdminOrderSearchFilter($request))->apply($query);

        return $query;
    }

    private function expectValidationException(callable $call): void
    {
        try {
            $call();
            self::fail('Expected a ValidationException (422) to be thrown.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
    }

    // ==================================================================
    // seeding
    // ==================================================================

    /**
     * @param array<string,mixed> $overrides
     * @return string the seeded order's uuid
     */
    private function seedOrder(string $tenant, array $overrides): string
    {
        $uuid = Utils::generateNanoID();
        $defaults = [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'fulfillment_revision' => 0,
            'refund_revision' => 0,
            'email' => 'buyer@example.com',
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'placed_at' => '2026-01-15 12:00:00',
            'created_at' => '2026-01-15 12:00:00',
        ];
        $this->connection()->table('commerce_orders')->insert(array_merge($defaults, $overrides, ['uuid' => $uuid]));

        return $uuid;
    }

    /**
     * A draft order per the engine's walk-in-order schema (migration
     * `AddWalkInOrderFieldsAndDraftAttemptLedger`): nullable `order_number`/`email`/
     * `guest_token_hash`, `origin='admin'`, `fulfillment_mode='in_store'`, `draft_revision=0` —
     * mirrors `glueful/commerce`'s own `DraftIsolationTest::seedOrder()` draft branch.
     *
     * @return string the seeded draft's uuid
     */
    private function seedDraftOrder(string $tenant): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => null,
            'status' => 'draft',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'fulfillment_revision' => 0,
            'refund_revision' => 0,
            'email' => null,
            'user_uuid' => null,
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
            'draft_revision' => 0,
            'placed_at' => null,
            'created_at' => '2026-01-15 12:00:00',
        ]);

        return $uuid;
    }

    /** @param list<string> $scopes */
    private function seedApiKeyUser(array $grantedPermissionSlugs, array $scopes): string
    {
        $userUuid = Utils::generateNanoID();
        $this->apiKeyUserUuids[] = $userUuid;

        $this->connection()->table('users')->insert([
            'uuid' => $userUuid,
            'username' => 'ordsearch_' . substr($userUuid, 0, 8),
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
            'name' => 'order-search-test',
            'scopes' => $scopes,
        ]);

        return (string) $created['plain'];
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'ordsearch_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'order search test role',
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

    /** Real X-API-Key header, mirrors AdminAuthorizationMatrixTest::apiKeyRequest(). */
    private function apiKeyRequest(string $method, string $path, string $key): Request
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
        );
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }
}
