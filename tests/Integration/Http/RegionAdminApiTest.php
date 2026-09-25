<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Http\Controllers\RegionAdminController;
use Thallo\Core\Http\DTOs\UpdateRegionData;
use Thallo\Core\Tests\Support\AppTestCase;
use Glueful\Validation\RequestDataHydrator;

final class RegionAdminApiTest extends AppTestCase
{
    private function controller(): RegionAdminController
    {
        $repo = new BlockTypeRepository($this->connection());
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($repo->findBySlug($definition['slug']) === null) {
                $repo->create($definition);
            }
        }
        return $this->container()->get(RegionAdminController::class);
    }

    /** A save body, naming both regions' current versions unless the test gives its own. */
    private function dto(array $body): UpdateRegionData
    {
        $repo = new \Thallo\Core\Content\Regions\RegionRepository($this->connection());
        $body += ['expected' => [
            'header' => $repo->find('header')['lock_version'] ?? null,
            'footer' => $repo->find('footer')['lock_version'] ?? null,
        ]];
        /** @var UpdateRegionData */
        return (new RequestDataHydrator())->hydrate(UpdateRegionData::class, $body);
    }

    public function testIndexExposesBothRegionsWithPalettes(): void
    {
        $resp = $this->controller()->index();
        self::assertSame(200, $resp->getStatusCode());
        $regions = json_decode((string) $resp->getContent(), true)['data']['regions'];
        self::assertSame(['header', 'footer'], array_column($regions, 'slug'));
        self::assertContains('navigation', $regions[0]['palette']);
        self::assertNotContains('gallery', $regions[0]['palette']);   // header stays strict
        self::assertContains('html', $regions[1]['palette']);
        self::assertSame(['sticky', 'width', 'style'], $regions[0]['settings_keys']);
        self::assertSame(['width', 'style'], $regions[1]['settings_keys']);
        // What the region's Style tab may offer: declared by the server, never hardcoded client-side.
        foreach ($regions as $region) {
            self::assertSame(
                ['spacing', 'shadow', 'radius', 'colors', 'border', 'backdrop'],
                $region['style_capabilities'],
            );
        }
        self::assertSame([], $regions[0]['blocks']);                   // absent row round-trips empty
    }

    public function testPutRoundTripsBlocksAndSettingsAndPurges(): void
    {
        $resp = $this->controller()->update($this->dto([
            'blocks' => [
                ['id' => 'apihdrlogo01', 'type' => 'logo', 'data' => ['size' => 'small', 'link_home' => true]],
                ['id' => 'apihdrnavi01', 'type' => 'navigation', 'data' => ['menu' => 'main']],
            ],
            'settings' => ['sticky' => true, 'width' => 'contained'],
        ]), 'header');
        self::assertSame(200, $resp->getStatusCode());

        $saved = (new RegionRepository($this->connection()))->find('header');
        self::assertNotNull($saved);
        self::assertSame(['logo', 'navigation'], array_column($saved['blocks'], 'type'));
        self::assertTrue($saved['settings']['sticky']);

        // GET reflects the save.
        $regions = json_decode((string) $this->controller()->index()->getContent(), true)['data']['regions'];
        self::assertCount(2, $regions[0]['blocks']);
    }

    public function testMiniCartIsInBothPalettesAndSavesIntoTheHeader(): void
    {
        // Commerce mini-cart in the chrome (user decision 2026-07-27): the classic
        // cart-in-the-header storefront pattern. The palette entry is app-side policy;
        // the block TYPE itself is commerce-provisioned, seeded here the same way the
        // core starter types are seeded in controller().
        $controller = $this->controller();
        $repo = new BlockTypeRepository($this->connection());
        if ($repo->findBySlug('mini-cart') === null) {
            $repo->create([
                'slug' => 'mini-cart',
                'label' => 'Mini cart',
                'icon' => 'i-lucide-shopping-cart',
                'category' => 'Commerce',
                'description' => 'Live cart count with a drawer.',
                'schema' => [],
            ]);
        }

        $regions = json_decode((string) $controller->index()->getContent(), true)['data']['regions'];
        self::assertContains('mini-cart', $regions[0]['palette'], 'header palette offers the mini cart');
        self::assertContains('mini-cart', $regions[1]['palette'], 'footer palette offers the mini cart');

        $resp = $controller->update($this->dto([
            'blocks' => [
                ['id' => 'apihdrcart01', 'type' => 'mini-cart', 'data' => []],
            ],
            'settings' => [],
        ]), 'header');
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getContent());

        $saved = (new RegionRepository($this->connection()))->find('header');
        self::assertNotNull($saved);
        self::assertSame(['mini-cart'], array_column($saved['blocks'], 'type'));
    }

    public function testWishlistLinkIsInBothPalettesAndSavesIntoTheHeader(): void
    {
        // Storefront-v1 Task 8 (spec §5): the wishlist link is placeable in the chrome exactly
        // like the mini cart — app-side palette policy, commerce-provisioned block TYPE.
        $controller = $this->controller();
        $repo = new BlockTypeRepository($this->connection());
        if ($repo->findBySlug('wishlist-link') === null) {
            $repo->create([
                'slug' => 'wishlist-link',
                'label' => 'Wishlist link',
                'icon' => 'i-lucide-heart',
                'category' => 'Commerce',
                'description' => 'A link to the wishlist page with a live saved-item count.',
                'schema' => [['name' => 'label', 'type' => 'string']],
            ]);
        }

        $regions = json_decode((string) $controller->index()->getContent(), true)['data']['regions'];
        self::assertContains('wishlist-link', $regions[0]['palette'], 'header palette offers the wishlist link');
        self::assertContains('wishlist-link', $regions[1]['palette'], 'footer palette offers the wishlist link');

        $resp = $controller->update($this->dto([
            'blocks' => [
                ['id' => 'apihdrwish01', 'type' => 'wishlist-link', 'data' => []],
            ],
            'settings' => [],
        ]), 'header');
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getContent());

        $saved = (new RegionRepository($this->connection()))->find('header');
        self::assertNotNull($saved);
        self::assertSame(['wishlist-link'], array_column($saved['blocks'], 'type'));
    }

    public function testOutOfPaletteBlockIs422WithDotPath(): void
    {
        $resp = $this->controller()->update($this->dto([
            'blocks' => [['id' => 'apibadblock1', 'type' => 'gallery', 'data' => ['images' => []]]],
            'settings' => [],
        ]), 'header');
        self::assertSame(422, $resp->getStatusCode());
        $errors = json_decode((string) $resp->getContent(), true)['error']['details'] ?? [];
        self::assertArrayHasKey('blocks.0.type', $errors);
    }

    public function testUnknownSlugIs404(): void
    {
        $resp = $this->controller()->update($this->dto(['blocks' => [], 'settings' => []]), 'sidebar');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testRegionSaveDispatchesThePurgeEventThroughTheRealWiring(): void
    {
        // The wiring proof (spec §11): the listener is registered on the REAL
        // EventService, so a save reaches invalidateTags through the event —
        // not just a listener class that exists.
        $store = $this->container()->get(\Glueful\Cache\CacheStore::class);
        $store->set('probe:region:page', 'stale');
        // Tag a probe entry the way RenderPageCache tags pages.
        if (method_exists($store, 'setWithTags')) {
            $store->setWithTags('probe:region:tagged', 'stale', ['thallo:render:page']);
        } else {
            $store->set('probe:region:tagged', 'stale');
            $store->addTags('probe:region:tagged', ['thallo:render:page']);
        }
        self::assertSame('stale', $store->get('probe:region:tagged'));

        $this->controller()->update($this->dto(['blocks' => [], 'settings' => []]), 'footer');

        self::assertNull($store->get('probe:region:tagged'), 'region save must broad-purge thallo:render:page');
        self::assertSame('stale', $store->get('probe:region:page'), 'untagged keys are untouched');
    }
}
