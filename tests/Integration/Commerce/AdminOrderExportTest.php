<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Thallo\Commerce\Http\AdminOrderExportController;
use Thallo\Commerce\Orders\AdminOrderSearchFilter;
use Thallo\Commerce\Orders\AdminOrderSearchQuery;
use Thallo\Commerce\Orders\OrderCsvWriter;

/**
 * Task 4 (orders-invoices-receipts plan): the bounded streamed CSV export
 * (`GET /v1/admin/commerce/orders/export`) that composes the SAME
 * {@see AdminOrderSearchQuery}/{@see AdminOrderSearchFilter} classes {@see AdminOrderSearchTest}
 * drives directly -- no second query path. Content-shape tests below call
 * {@see AdminOrderExportController::export()} directly (mirrors
 * `FormSubmissionsAdminTest`'s `ob_start()`/`sendContent()`/`ob_get_clean()` idiom for reading a
 * `StreamedResponse` body in a test); authority-matrix tests go through the real kernel, matching
 * {@see AdminOrderSearchTest}'s own split.
 */
final class AdminOrderExportTest extends AppTestCase
{
    private const ROUTE = '/v1/admin/commerce/orders/export';

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
    // Draft-blindness (admin-order-creation cycle 2, Task 12): the CSV export composes the SAME
    // `AdminOrderSearchQuery::builder()` choke point search does, so a seeded draft must never
    // appear in the exported rows — proven with no filter at all AND under a representative
    // filter, so exclusion is structural, not an artifact of today's filter set.
    // ==================================================================

    public function testExportedCsvExcludesASeededDraftOrder(): void
    {
        $tenant = '';
        $draft = $this->seedDraftOrder($tenant);
        $included = $this->seedOrder($tenant, ['status' => 'paid']);

        $csv = $this->exportCsv($tenant, []);
        $orderNumbers = array_column($this->csvDataRows($csv), 0);

        self::assertContains('ORD-' . $included, $orderNumbers);
        self::assertNotContains('ORD-' . $draft, $orderNumbers);
        // A draft's order_number is NULL, so it could never render as a matching cell anyway —
        // the real proof is the row COUNT: exactly the one finalized order, never two.
        self::assertCount(1, $this->csvDataRows($csv));
    }

    public function testExportedCsvExcludesASeededDraftOrderUnderAFilter(): void
    {
        $tenant = '';
        $this->seedDraftOrder($tenant);
        $included = $this->seedOrder($tenant, ['status' => 'paid']);

        $csv = $this->exportCsv($tenant, ['status' => 'paid']);
        $orderNumbers = array_column($this->csvDataRows($csv), 0);

        self::assertSame(['ORD-' . $included], $orderNumbers);
    }

    // ==================================================================
    // Shared filter proof: export narrows exactly like search does
    // ==================================================================

    public function testFiltersBindIdenticallyToListAndExport(): void
    {
        $tenant = '';
        $included = $this->seedOrder($tenant, ['status' => 'paid']);
        $excluded = $this->seedOrder($tenant, ['status' => 'canceled']);

        $csv = $this->exportCsv($tenant, ['status' => 'paid']);
        $orderNumbers = array_column($this->csvDataRows($csv), 0);

        self::assertContains('ORD-' . $included, $orderNumbers);
        self::assertNotContains('ORD-' . $excluded, $orderNumbers);
    }

    // ==================================================================
    // Order parity with the search endpoint's own ordering
    // ==================================================================

    public function testListAndExportProduceSameReportTimeIdOrder(): void
    {
        $tenant = '';
        $this->seedOrder($tenant, ['placed_at' => '2026-01-01 00:00:00']);
        // Two rows sharing the SAME report time -> higher id must sort first, in BOTH surfaces.
        $this->seedOrder($tenant, ['placed_at' => '2026-02-01 00:00:00']);
        $this->seedOrder($tenant, ['placed_at' => '2026-02-01 00:00:00']);

        $expected = $this->expectedOrderNumbers($tenant, []);
        $csv = $this->exportCsv($tenant, []);
        $actual = array_column($this->csvDataRows($csv), 0);

        self::assertSame($expected, $actual);
    }

    // ==================================================================
    // The 10,000-row cap: 422 BEFORE any CSV response is constructed
    // ==================================================================

    public function testOver10000RowsIs422WithNoCsvHeadersOrBody(): void
    {
        $tenant = '';
        $this->seedManyOrders($tenant, 10_001, ['status' => 'paid']);

        $controller = $this->exportController();
        $response = $controller->export(Request::create(self::ROUTE, 'GET', ['status' => 'paid']));

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(422, $response->getStatusCode());
        self::assertNull($response->headers->get('Content-Disposition'));
        self::assertStringNotContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);
        self::assertSame('Export exceeds 10,000 rows — narrow your filters.', $decoded['message'] ?? null);
        self::assertStringNotContainsString('order_number', (string) $response->getContent());
    }

    // ==================================================================
    // Under the cap: header row + exactly the allowlisted columns, in order
    // ==================================================================

    public function testWithinCapReturnsHeaderRowAndAllowlistedColumnsInOrder(): void
    {
        $tenant = '';
        $this->seedOrder($tenant, []);

        $csv = $this->exportCsv($tenant, []);
        $rows = $this->csvRows($csv);

        self::assertSame(OrderCsvWriter::COLUMNS, $rows[0]);
    }

    // ==================================================================
    // Formula-injection neutralization
    // ==================================================================

    public function testNeutralizationTriggersArePrefixedWithApostrophe(): void
    {
        $tenant = '';
        $triggers = ['=SUM(A1)', '+x', '-x', '@x', "\tx", "\rx"];
        foreach ($triggers as $trigger) {
            $this->seedOrder($tenant, ['discount_code' => $trigger]);
        }
        // Brief also names `email` as a possible carrier -- prove the same protection there.
        $emailTrigger = $this->seedOrder($tenant, ['email' => '=cmd|open']);

        $csv = $this->exportCsv($tenant, []);
        $dataRows = $this->csvDataRows($csv);

        $discountCodeIndex = array_search('discount_code', OrderCsvWriter::COLUMNS, true);
        $emailIndex = array_search('email', OrderCsvWriter::COLUMNS, true);
        self::assertIsInt($discountCodeIndex);
        self::assertIsInt($emailIndex);

        $discountCodes = array_column($dataRows, $discountCodeIndex);
        foreach ($triggers as $trigger) {
            self::assertContains("'" . $trigger, $discountCodes);
        }

        $emails = array_column($dataRows, $emailIndex);
        self::assertContains("'=cmd|open", $emails);
        unset($emailTrigger);
    }

    // ==================================================================
    // Batch-boundary correctness: 1,201 rows, equal report timestamps AT the boundary
    // ==================================================================

    public function testBatchBoundaryWith1201RowsHasNoDuplicateOrGap(): void
    {
        $tenant = '';
        // 700 rows sharing the SAME placed_at (T1): batch 1 (500) ends and batch 2 (next 500)
        // starts INSIDE this group (rows 500/501 of the sort share report time), proving the
        // cursor's equality+id tie-break branch, not just the strict "<" branch.
        $this->seedManyOrders($tenant, 700, ['placed_at' => '2026-03-01 00:00:00']);
        // 501 rows sharing a DIFFERENT, earlier placed_at (T2): batch 2 ends and batch 3 starts
        // INSIDE this second group too (global rows 1000/1001 both fall in T2).
        $this->seedManyOrders($tenant, 501, ['placed_at' => '2026-01-01 00:00:00']);

        $expected = $this->expectedOrderNumbers($tenant, []);
        self::assertCount(1201, $expected);

        $csv = $this->exportCsv($tenant, []);
        $actual = array_column($this->csvDataRows($csv), 0);

        self::assertCount(1201, $actual);
        self::assertSame(count(array_unique($actual)), count($actual), 'no duplicate rows');
        self::assertSame($expected, $actual);
    }

    // ==================================================================
    // Minor-unit money values, verbatim (no locale/currency formatting)
    // ==================================================================

    public function testMinorUnitMoneyValuesAreVerbatim(): void
    {
        $tenant = '';
        $uuid = $this->seedOrder($tenant, [
            'subtotal' => 123456,
            'discount_total' => 500,
            'shipping_total' => 1299,
            'tax_total' => 987,
            'refunded_total' => 111,
            'grand_total' => 125142,
        ]);

        $csv = $this->exportCsv($tenant, []);
        $row = $this->rowFor($csv, 'ORD-' . $uuid);

        self::assertSame('123456', $row[array_search('subtotal', OrderCsvWriter::COLUMNS, true)]);
        self::assertSame('500', $row[array_search('discount_total', OrderCsvWriter::COLUMNS, true)]);
        self::assertSame('1299', $row[array_search('shipping_total', OrderCsvWriter::COLUMNS, true)]);
        self::assertSame('987', $row[array_search('tax_total', OrderCsvWriter::COLUMNS, true)]);
        self::assertSame('111', $row[array_search('refunded_total', OrderCsvWriter::COLUMNS, true)]);
        self::assertSame('125142', $row[array_search('grand_total', OrderCsvWriter::COLUMNS, true)]);
    }

    // ==================================================================
    // Filename disposition
    // ==================================================================

    public function testFilenameDispositionIsAttachmentSafe(): void
    {
        $tenant = '';
        $this->seedOrder($tenant, []);

        $controller = $this->exportController();
        $response = $controller->export(Request::create(self::ROUTE, 'GET', []));

        self::assertInstanceOf(StreamedResponse::class, $response);
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('orders-export.csv', $disposition);
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
    }

    // ==================================================================
    // Authority matrix (real kernel)
    // ==================================================================

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
        self::assertSame(200, $response->getStatusCode());
    }

    public function testManageActorIsAllowed(): void
    {
        $this->seedOrder('', []);
        $key = $this->seedApiKeyUser(['commerce.manage'], ['commerce.manage']);

        $response = $this->handle($this->apiKeyRequest('GET', self::ROUTE, $key));
        self::assertSame(200, $response->getStatusCode());
    }

    // ==================================================================
    // drivers
    // ==================================================================

    private function exportController(): AdminOrderExportController
    {
        return $this->container()->get(AdminOrderExportController::class);
    }

    /** @param array<string,mixed> $queryParams */
    private function exportCsv(string $tenant, array $queryParams): string
    {
        $controller = $this->exportController();
        $response = $controller->export(Request::create(self::ROUTE, 'GET', $queryParams));
        self::assertInstanceOf(StreamedResponse::class, $response, (string) $tenant);

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /** @return list<list<string>> every CSV row (including the header) as parsed cells */
    private function csvRows(string $csv): array
    {
        $lines = preg_split('/\r\n|\n/', rtrim($csv, "\n")) ?: [];

        return array_map(
            static fn (string $line): array => (array) str_getcsv($line, ',', '"', ''),
            $lines,
        );
    }

    /** @return list<list<string>> every CSV row EXCEPT the header */
    private function csvDataRows(string $csv): array
    {
        $rows = $this->csvRows($csv);
        array_shift($rows);

        return $rows;
    }

    /** @return list<string> */
    private function rowFor(string $csv, string $orderNumber): array
    {
        foreach ($this->csvDataRows($csv) as $row) {
            if (($row[0] ?? null) === $orderNumber) {
                return $row;
            }
        }
        self::fail("No exported row for order_number {$orderNumber}");
    }

    /**
     * Ground-truth expected `order_number` sequence, computed the SAME way the search endpoint
     * itself does (builder -> filter -> applyOrder), independent of the export controller's own
     * batching -- so a match against this proves both "same order as list" AND "no dup/gap".
     *
     * @param array<string,mixed> $queryParams
     * @return list<string>
     */
    private function expectedOrderNumbers(string $tenant, array $queryParams): array
    {
        $searchQuery = new AdminOrderSearchQuery($this->appContext());
        $query = $searchQuery->builder($tenant);
        (new AdminOrderSearchFilter(Request::create(self::ROUTE, 'GET', $queryParams)))->apply($query);
        $searchQuery->applyOrder($query);

        return array_column($query->get(), 'order_number');
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
        $row = $this->orderDefaults($tenant, $uuid, $overrides);
        $this->connection()->table('commerce_orders')->insert($row);

        return $uuid;
    }

    /**
     * A draft order per the engine's walk-in-order schema — mirrors
     * {@see AdminOrderSearchTest::seedDraftOrder()} exactly (same fixture shape, both suites
     * exercise the same `AdminOrderSearchQuery::builder()` choke point).
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
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'refunded_total' => 0,
            'discount_code' => null,
            'shipping_method' => null,
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
            'draft_revision' => 0,
            'placed_at' => null,
            'created_at' => '2026-01-15 12:00:00',
        ]);

        return $uuid;
    }

    /**
     * Bulk-seeds $count matching rows sharing $overrides -- each gets its own fresh
     * uuid/order_number. Inserted via `insertBatch()` in fixed-size chunks.
     *
     * @param array<string,mixed> $overrides
     */
    private function seedManyOrders(string $tenant, int $count, array $overrides = []): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = $this->orderDefaults($tenant, Utils::generateNanoID(), $overrides);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->connection()->table('commerce_orders')->insertBatch($chunk);
        }
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function orderDefaults(string $tenant, string $uuid, array $overrides): array
    {
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
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'refunded_total' => 0,
            'discount_code' => null,
            'shipping_method' => null,
            'placed_at' => '2026-01-15 12:00:00',
            'created_at' => '2026-01-15 12:00:00',
        ];

        return array_merge($defaults, $overrides, ['uuid' => $uuid]);
    }

    /** @param list<string> $scopes */
    private function seedApiKeyUser(array $grantedPermissionSlugs, array $scopes): string
    {
        $userUuid = Utils::generateNanoID();
        $this->apiKeyUserUuids[] = $userUuid;

        $this->connection()->table('users')->insert([
            'uuid' => $userUuid,
            'username' => 'ordexport_' . substr($userUuid, 0, 8),
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
            'name' => 'order-export-test',
            'scopes' => $scopes,
        ]);

        return (string) $created['plain'];
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'ordexport_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'order export test role',
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
