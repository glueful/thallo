<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Validation\RequestDataHydrator;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Layouts\ProductSurface;
use Thallo\Commerce\Layouts\ShopLayoutTags;
use Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnLayoutChange;
use Thallo\Contracts\Layouts\LayoutChanged;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\RemoveLayoutData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Tenancy\System\SystemFlags;

/**
 * A product layout change refreshes one workspace's product pages (type layouts plan C1, P4; Review
 * Focus 5 in the default suite): the first save, an edit and the removal each turn the saving
 * workspace's cached product page into a miss that renders the new state, while another workspace's
 * cached product page stays a hit — on a tag-capable driver by the workspace's own tag, and on one
 * without tag invalidation by deleting only that workspace's shop pages.
 *
 * Two workspaces as the storefront tests make them: the widened schema's default tenant, switched.
 */
final class ProductLayoutCacheTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const TENANT_A = 'layoutcachea';
    private const TENANT_B = 'layoutcacheb';

    private int $version = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $this->syncBlockStyleDeclarations();
        $this->clearCatalog();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
    }

    protected function tearDown(): void
    {
        $this->cache()->deletePattern('shop:*');
        $this->clearCatalog();
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        $this->container()->get(LayoutResolver::class)->forget('product', '@site');
        parent::tearDown();
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function clearCatalog(): void
    {
        $pdo = $this->connection()->getPDO();
        $tables = ['commerce_cart_lines', 'commerce_carts', 'commerce_stock', 'commerce_variants', 'commerce_products'];
        foreach ($tables as $t) {
            $pdo->exec("DELETE FROM {$t}");
        }
    }

    private function asTenant(string $tenant, callable $fn): mixed
    {
        $this->flags()->put('tenancy.default_tenant_uuid', $tenant);
        try {
            return $fn();
        } finally {
            $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
        }
    }

    /** The key the product page is cached under now: its workspace's current product layout token (plan C2). */
    private function key(string $tenant): string
    {
        $token = $this->cache()->get(ShopLayoutTags::generationKey(ProductSurface::KEY, $tenant));
        return 'shop:' . $tenant . ':en:default:' . $this->appearanceFingerprint() . ':1:'
            . ProductSurface::KEY . 'g' . (is_string($token) ? $token : 'none') . ':'
            . rawurlencode('/shop/products/lamp');
    }

    /** A product at /shop/products/lamp in both workspaces, each page cached. */
    private function primeBoth(): void
    {
        foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
            $this->asTenant($tenant, function () use ($tenant): void {
                $own = $this->connection()->table('commerce_products')->where('tenant_uuid', '=', $tenant)->count();
                if ($own === 0) {
                    $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
                        'slug' => 'lamp', 'name' => 'Lamp of ' . $tenant, 'status' => 'active', 'type' => 'digital',
                        'variants' => [['sku' => 'lamp-' . $tenant, 'price' => 1000, 'currency' => 'USD',
                            'option_values' => []]],
                    ]);
                }
                self::assertSame(200, $this->handle(Request::create('/shop/products/lamp', 'GET'))->getStatusCode());
            });
            self::assertIsArray($this->cache()->get($this->key($tenant)), "precondition: {$tenant}'s page is cached");
        }
    }

    /** @return array{token: string} a product layout session, minted in workspace A */
    private function session(): array
    {
        return json_decode((string) $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => 'product', 'target' => '@site'],
            ),
        )->getContent(), true)['data'];
    }

    private function save(string $marker): void
    {
        $blocks = [
            ['id' => 'cachemarker1', 'type' => 'heading', 'data' => ['text' => $marker], 'settings' => []],
            ['id' => 'cachebuy0001', 'type' => 'product_buy', 'data' => [], 'settings' => []],
        ];
        $saved = $this->container()->get(LayoutAdminController::class)->save(
            (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
                'token' => $this->session()['token'], 'layout' => ['blocks' => $blocks, 'settings' => []],
                'expected_lock_version' => $this->version,
            ]),
            Request::create('/x', 'PUT'),
            'product',
            '@site',
        );
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
        $this->version++;
    }

    private function assertARefreshedAndBKept(string $expectA, string $step): void
    {
        self::assertNull($this->cache()->get($this->key(self::TENANT_A)), "{$step}: A's product page left the cache");
        $b = $this->cache()->get($this->key(self::TENANT_B));
        self::assertIsArray($b, "{$step}: B's product page is still cached");
        $html = (string) $this->handle(Request::create('/shop/products/lamp', 'GET'))->getContent();
        self::assertStringContainsString($expectA, $html, "{$step}: A renders the new state");
    }

    public function testFirstSaveEditAndRemovalRefreshOnlyTheSavingWorkspace(): void
    {
        $this->primeBoth();
        $bBefore = $this->cache()->get($this->key(self::TENANT_B));

        $this->save('FIRST-LAYOUT');
        $this->assertARefreshedAndBKept('FIRST-LAYOUT', 'first save');

        $this->primeBoth();
        $this->save('EDITED-LAYOUT');
        $this->assertARefreshedAndBKept('EDITED-LAYOUT', 'edit');

        $this->primeBoth();
        $session = $this->session();
        $removed = $this->container()->get(LayoutAdminController::class)->destroy(
            (new RequestDataHydrator())->hydrate(RemoveLayoutData::class, [
                'token' => $session['token'], 'expected_lock_version' => $this->version,
            ]),
            Request::create('/x', 'DELETE'),
            'product',
            '@site',
        );
        self::assertSame(200, $removed->getStatusCode(), (string) $removed->getContent());
        $this->assertARefreshedAndBKept('<article class="shop-product" data-shop-scope=', 'removal');

        self::assertSame(
            $bBefore['body'],
            $this->cache()->get($this->key(self::TENANT_B))['body'],
            "B's page untouched",
        );
    }

    /** A driver without tag invalidation: only the workspace's own shop pages are deleted. */
    public function testATaglessDriverFallsBackToTheWorkspacesOwnShopKeys(): void
    {
        $cache = $this->createMock(CacheStore::class);
        $cache->expects(self::once())->method('invalidateTags')
            ->with([ShopLayoutTags::tenantTag(ProductSurface::KEY, self::TENANT_A)])->willReturn(false);
        $deleted = [];
        $cache->method('deletePattern')->willReturnCallback(static function (string $pattern) use (&$deleted): bool {
            $deleted[] = $pattern;
            return true;
        });
        $real = $this->container();
        $container = new class ($real, $cache) implements ContainerInterface {
            public function __construct(private readonly ContainerInterface $real, private readonly CacheStore $cache)
            {
            }

            public function get(string $id): mixed
            {
                return $id === CacheStore::class ? $this->cache : $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return $id === CacheStore::class || $this->real->has($id);
            }
        };
        (new PurgeShopCacheOnLayoutChange($container))
            ->onLayoutChanged(new LayoutChanged('product', '@site', self::TENANT_A));
        self::assertSame(['shop:' . self::TENANT_A . ':*', 'tenant:*:shop:' . self::TENANT_A . ':*'], $deleted);
    }

    public function testOtherSurfacesAndTenantlessEventsAreHandled(): void
    {
        $this->primeBoth();
        $events = $this->container()->get(EventService::class);

        $events->dispatch(new LayoutChanged('entry', 'post', null));
        self::assertIsArray($this->cache()->get($this->key(self::TENANT_A)), 'an entry layout leaves the shop alone');
        self::assertIsArray($this->cache()->get($this->key(self::TENANT_B)));

        // No workspace named (tenancy off): the shop's own resolution — the default tenant, A.
        self::assertSame(
            self::TENANT_A,
            $this->container()->get(CommerceTenantResolution::class)
                ->tenantUuid($this->container()->get(ApplicationContext::class)),
        );
        $events->dispatch(new LayoutChanged('product', '@site', null));
        self::assertNull($this->cache()->get($this->key(self::TENANT_A)));
        self::assertIsArray($this->cache()->get($this->key(self::TENANT_B)));
        unset($events);
        self::assertNotNull($this->container()->get(LayoutSurfaceRegistry::class)->get('product'));
    }
}
