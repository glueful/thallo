<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\ProductLinkController;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 8: admin product<->entry linkage API (design spec §5.3). Mirrors the established
 * convention in this codebase (NavigationApiTest/WorkflowApiTest): resolve the controller
 * directly from the container and drive it with hand-built Request objects, rather than going
 * through the full HTTP auth/tenant middleware pipeline — the middleware WIRING itself (auth,
 * admin_tenant_binding, content_permission) is checked structurally, mirroring
 * AdminRouteBindingTest/AdminRoutesGatedTest, and the disabled-capability 404 case mirrors
 * WorkflowRemovabilityTest's bootAppWithConfigOverride convention.
 */
final class ProductLinkApiTest extends AppTestCase
{
    private const TENANT_A = 'plapitenanta';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
        $this->connection()->getPDO()->exec(
            "ALTER TABLE entries ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(191) NOT NULL DEFAULT ''"
        );
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        $this->connection()->getPDO()->exec('ALTER TABLE entries DROP COLUMN IF EXISTS tenant_uuid');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // PUT .../link — 201 create / 200 relink / 404 / 422 / 409
    // ------------------------------------------------------------------

    public function testLinkReturns201OnFirstLinkAnd200OnRelink(): void
    {
        $product = $this->seedProduct('api-link-201');
        $entryOne = $this->seedEntry();
        $entryTwo = $this->seedEntry();

        $res = $this->admin()->link($this->req(['entry_uuid' => $entryOne]), $product);
        self::assertSame(201, $res->getStatusCode());
        self::assertSame($entryOne, $this->data($res)['entry_uuid']);

        $res = $this->admin()->link(
            $this->req(['entry_uuid' => $entryTwo, 'expected_entry_uuid' => $entryOne]),
            $product,
        );
        self::assertSame(200, $res->getStatusCode());
        self::assertSame($entryTwo, $this->data($res)['entry_uuid']);
    }

    public function testLinkReturns404ForUnknownProduct(): void
    {
        $entry = $this->seedEntry();

        $res = $this->admin()->link($this->req(['entry_uuid' => $entry]), 'noSuchProduct');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testLinkReturns404ForUnknownEntry(): void
    {
        $product = $this->seedProduct('api-link-404-entry');

        $res = $this->admin()->link($this->req(['entry_uuid' => 'noSuchEntry01']), $product);
        self::assertSame(404, $res->getStatusCode());
    }

    public function testLinkReturns422ForMissingEntryUuid(): void
    {
        $product = $this->seedProduct('api-link-422');

        $this->expectException(\Glueful\Validation\ValidationException::class);
        $this->admin()->link($this->req([]), $product);
    }

    public function testLinkReturns422ForNonStringEntryUuid(): void
    {
        $product = $this->seedProduct('api-link-422b');

        $this->expectException(\Glueful\Validation\ValidationException::class);
        $this->admin()->link($this->req(['entry_uuid' => ['not' => 'a string']]), $product);
    }

    public function testLinkReturns409WhenAlreadyLinkedWithoutExpectation(): void
    {
        $product = $this->seedProduct('api-link-409');
        $entryOne = $this->seedEntry();
        $entryTwo = $this->seedEntry();

        self::assertSame(201, $this->admin()->link($this->req(['entry_uuid' => $entryOne]), $product)->getStatusCode());

        $res = $this->admin()->link($this->req(['entry_uuid' => $entryTwo]), $product);
        self::assertSame(409, $res->getStatusCode());
    }

    // ------------------------------------------------------------------
    // DELETE .../link
    // ------------------------------------------------------------------

    public function testUnlinkReturns200AndRemovesTheLink(): void
    {
        $product = $this->seedProduct('api-unlink-200');
        $entry = $this->seedEntry();
        $this->admin()->link($this->req(['entry_uuid' => $entry]), $product);

        $res = $this->admin()->unlink(Request::create('/x', 'DELETE'), $product);
        self::assertSame(200, $res->getStatusCode());

        $show = $this->admin()->showByProduct(Request::create('/x', 'GET'), $product);
        self::assertSame(404, $show->getStatusCode());
    }

    public function testUnlinkOfAnUnlinkedProductIsAlsoAnIdempotent200(): void
    {
        $product = $this->seedProduct('api-unlink-noop');

        $res = $this->admin()->unlink(Request::create('/x', 'DELETE'), $product);
        self::assertSame(200, $res->getStatusCode());
    }

    // ------------------------------------------------------------------
    // GET .../link (by product / by entry)
    // ------------------------------------------------------------------

    public function testShowByProductAndByEntryReturn200ThenTheRow(): void
    {
        $product = $this->seedProduct('api-show-both');
        $entry = $this->seedEntry();
        $this->admin()->link($this->req(['entry_uuid' => $entry]), $product);

        $byProduct = $this->admin()->showByProduct(Request::create('/x', 'GET'), $product);
        self::assertSame(200, $byProduct->getStatusCode());
        self::assertSame($entry, $this->data($byProduct)['entry_uuid']);

        $byEntry = $this->admin()->showByEntry(Request::create('/x', 'GET'), $entry);
        self::assertSame(200, $byEntry->getStatusCode());
        self::assertSame($product, $this->data($byEntry)['product_uuid']);
    }

    public function testShowByProductAndByEntryReturn404WhenAbsent(): void
    {
        $product = $this->seedProduct('api-show-404');
        $entry = $this->seedEntry();

        self::assertSame(404, $this->admin()->showByProduct(Request::create('/x', 'GET'), $product)->getStatusCode());
        self::assertSame(404, $this->admin()->showByEntry(Request::create('/x', 'GET'), $entry)->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Route wiring — structural checks (mirrors AdminRouteBindingTest/AdminRoutesGatedTest)
    // ------------------------------------------------------------------

    public function testRoutesAreRegisteredBehindAdminTenantBindingAndContentPermission(): void
    {
        $routes = [
            ['PUT', '/v1/admin/commerce/products/{productUuid}/link'],
            ['DELETE', '/v1/admin/commerce/products/{productUuid}/link'],
            ['GET', '/v1/admin/commerce/products/{productUuid}/link'],
            ['GET', '/v1/admin/commerce/entries/{entryUuid}/link'],
        ];
        foreach ($routes as [$method, $path]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path} must be registered");
            $middleware = $route['middleware'] ?? [];
            self::assertContains('admin_tenant_binding', $middleware, "{$method} {$path} must bind the workspace");
            $hasPermissionCheck = false;
            foreach ($middleware as $entry) {
                if (str_starts_with((string) $entry, 'content_permission:commerce.manage')) {
                    $hasPermissionCheck = true;
                }
            }
            self::assertTrue($hasPermissionCheck, "{$method} {$path} must require commerce.manage");
        }
    }

    public function testRoutesAbsentWhenCapabilityDisabled(): void
    {
        // This test triggers a SECOND full app boot via bootAppWithConfigOverride().
        // TenancyServiceProvider::boot() reads LIVE SystemFlags at THAT boot to decide whether
        // to register its process-GLOBAL, static compat-write insert hook
        // (Connection::addInsertHook() — never scoped per-boot, never auto-cleared). This
        // class's setUp() left `schema_state=widened` — reset it back to 'none' BEFORE the
        // second boot so it never sees compat mode and never adds a hook that would outlive
        // this test and corrupt every later test's inserts for the rest of the phpunit process
        // (mirrors TenantOracleTestCase::tearDownAfterClass()'s own explicit-cleanup discipline
        // for the same static registries).
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);

        $hit = static fn (string $method, string $path): int => (new Application($disabledApp))->handle(
            Request::create($path, $method, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ]),
        )->getStatusCode();

        self::assertSame(404, $hit('PUT', '/v1/admin/commerce/products/p-1/link'));
        self::assertSame(404, $hit('DELETE', '/v1/admin/commerce/products/p-1/link'));
        self::assertSame(404, $hit('GET', '/v1/admin/commerce/products/p-1/link'));
        self::assertSame(404, $hit('GET', '/v1/admin/commerce/entries/e-1/link'));

        self::resetSharedRepositoryConnection();
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function admin(): ProductLinkController
    {
        return $this->container()->get(ProductLinkController::class);
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    /** @param array<string,mixed> $body */
    private function req(array $body): Request
    {
        return Request::create(
            '/x',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
    }

    /** @return array<string,mixed> */
    private function data(Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true)['data'];
    }

    private function seedProduct(string $slug): string
    {
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug . '-' . (++self::$seq),
            'name' => ucfirst($slug),
            'status' => 'active',
            'type' => 'external',
            'metadata' => ['external_url' => 'https://example.test/' . $slug],
        ]);

        return (string) $product['uuid'];
    }

    /** Raw-seeds `entries` directly (see ProductLinkServiceTest's identical helper docblock). */
    private function seedEntry(): string
    {
        self::$seq++;
        $uuid = 'plapie' . str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('entries')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => 'plapitype001',
            'status' => 'active',
            'tenant_uuid' => self::TENANT_A,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }
}
