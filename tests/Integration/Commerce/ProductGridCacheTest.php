<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Shop\CatalogGeneration;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\SeedsShopCatalog;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Cache\RenderCacheHints;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\RenderContextExtension;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Product grid spec §3.2 end to end: a page holding a grid carries the workspace's catalog storage
 * tag and the catalog generation as a guard, so a catalog change refreshes it — also when the grid
 * showed nothing — and two grids straddling a change leave the page uncached.
 */
final class ProductGridCacheTest extends AppTestCase
{
    use SeedsShopCatalog;
    use SyncsBlockStyleDeclarations;

    private const TENANT = 'gridcachetna';
    private const OTHER = 'gridcachetnb';

    private static int $productSeq = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$app?->getContainer()->get(\Glueful\Database\Connection::class)->getPDO()->exec(
            "ALTER TABLE entries ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(191) NOT NULL DEFAULT ''"
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$app?->getContainer()->get(\Glueful\Database\Connection::class)->getPDO()->exec(
            'ALTER TABLE entries DROP COLUMN IF EXISTS tenant_uuid'
        );
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->clearCatalog();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT);
    }

    protected function tearDown(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        $this->cache()->deletePattern('render:*');
        $this->cache()->deletePattern('shop:*');
        $this->cache()->delete(CatalogGeneration::key(self::TENANT));
        $this->clearCatalog();
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

    /** A published landing page whose body is one Product grid; returns its path. */
    private function gridPage(array $grid = []): string
    {
        $type = (string) $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'landing', 'name' => 'Landing pages', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        (new ListingPageSeed($this->container(), $this->container()->get(ApplicationContext::class)))->publish(
            $type,
            'grid',
            ['title' => 'Grid', 'body' => [['id' => 'cachegrid001', 'type' => 'product-grid', 'data' => $grid]]],
            '2026-10-01 09:00:00',
        );
        return '/landing/grid';
    }

    /** A product created through the engine, so StorefrontCatalogChanged fires and purges. */
    private function createProduct(string $name): void
    {
        $seq = ++self::$productSeq;
        $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => 'cached-' . $seq, 'name' => $name, 'status' => 'active', 'type' => 'digital',
            'variants' => [
                ['sku' => 'cached-sku-' . $seq, 'price' => 1000, 'currency' => 'USD', 'option_values' => []],
            ],
        ]);
    }

    private function renderKey(string $path): string
    {
        return 'render:default:' . $this->appearanceFingerprint() . ':' . rawurlencode($path);
    }

    private function get(string $path): Response
    {
        return $this->handle(Request::create($path, 'GET'));
    }

    public function testAGridPageIsGuardedAndACatalogChangeRefreshesIt(): void
    {
        $this->createProduct('First lamp');
        $path = $this->gridPage();
        $first = $this->get($path);
        self::assertStringContainsString('First lamp', (string) $first->getContent());
        $entry = $this->cache()->get($this->renderKey($path));
        self::assertIsArray($entry, 'the page is cached');
        self::assertArrayHasKey(CatalogGeneration::key(self::TENANT), $entry['guards']);
        self::assertSame((string) $first->headers->get('ETag'), (string) $this->get($path)->headers->get('ETag'));
        self::assertStringNotContainsString(self::TENANT, (string) $first->headers->get('Cache-Tag'));

        $this->createProduct('Second lamp');
        self::assertStringContainsString('Second lamp', (string) $this->get($path)->getContent());
    }

    public function testAnEmptyGridIsRefreshedByTheFirstMatchingProduct(): void
    {
        $path = $this->gridPage();
        self::assertStringContainsString('thallo-block-product-grid--empty', (string) $this->get($path)->getContent());
        self::assertIsArray($this->cache()->get($this->renderKey($path)), 'the empty grid page is cached');

        $this->createProduct('Only lamp');
        $after = (string) $this->get($path)->getContent();
        self::assertStringContainsString('Only lamp', $after);
        self::assertStringNotContainsString('thallo-block-product-grid--empty', $after);
    }

    public function testAWorkspacesChangeInvalidatesOnlyItsOwnGridPages(): void
    {
        // Two cached grid pages, one per workspace (the private storage tag each grid adds),
        // and the real purge listener for workspace A's change.
        $mw = new RenderPageCache($this->cache(), 'default', static fn (): string => 'iso', true, 3600);
        foreach ([self::TENANT => '/iso-a', self::OTHER => '/iso-b'] as $tenant => $path) {
            $mw->handle(Request::create($path, 'GET'), static function (Request $r) use ($tenant): Response {
                $hints = new RenderCacheHints(['thallo:shop:catalog:' . $tenant]);
                $r->attributes->set(RenderCacheHints::ATTRIBUTE, $hints);
                return new Response('grid of ' . $tenant, 200, ['Content-Type' => 'text/html']);
            });
        }
        $this->container()->get(EventService::class)->dispatch(
            new StorefrontCatalogChanged(self::TENANT, StorefrontCatalogChanged::REASON_PRODUCT_UPDATED, null),
        );
        self::assertNull($this->cache()->get('render:default:iso:' . rawurlencode('/iso-a')), 'A refreshed');
        self::assertIsArray($this->cache()->get('render:default:iso:' . rawurlencode('/iso-b')), 'B untouched');
    }

    public function testTwoGridsStraddlingAChangeMakeTheRenderUncacheable(): void
    {
        /** @var RenderContextExtension $extension */
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetTags();
        $extension->productGridView([]);
        (new CatalogGeneration($this->cache()))->rotate(self::TENANT); // a change lands between them
        $extension->productGridView([]);
        self::assertTrue($extension->drainCacheHints()->uncacheable);

        $extension->resetTags();
        $extension->productGridView([]);
        $extension->productGridView([]);
        $hints = $extension->drainCacheHints();
        self::assertFalse($hints->uncacheable, 'two grids that agree keep the page cacheable');
        self::assertCount(1, $hints->guards, 'one value per guard key');
    }
}
