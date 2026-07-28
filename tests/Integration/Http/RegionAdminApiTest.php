<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Regions\RegionRepository;
use App\Http\Controllers\RegionAdminController;
use App\Http\DTOs\UpdateRegionData;
use App\Tests\Support\AppTestCase;
use App\Content\Validation\ValidationException;
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

    private function dto(array $body): UpdateRegionData
    {
        /** @var UpdateRegionData */
        return (new RequestDataHydrator())->hydrate(UpdateRegionData::class, $body);
    }

    private function previewDto(array $body): \App\Http\DTOs\PreviewRegionsData
    {
        /** @var \App\Http\DTOs\PreviewRegionsData */
        return (new RequestDataHydrator())->hydrate(\App\Http\DTOs\PreviewRegionsData::class, $body);
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
        self::assertSame(['sticky', 'width'], $regions[0]['settings_keys']);
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
        try {
            $this->controller()->update($this->dto([
                'blocks' => [['id' => 'apibadblock1', 'type' => 'gallery', 'data' => ['images' => []]]],
                'settings' => [],
            ]), 'header');
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('blocks.0.type', $e->errors());
        }
    }

    public function testUnknownSlugIs404(): void
    {
        $resp = $this->controller()->update($this->dto(['blocks' => [], 'settings' => []]), 'sidebar');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testPreviewRendersPostedChromeWithoutSaving(): void
    {
        $resp = $this->controller()->preview($this->previewDto([
            'regions' => [
                'header' => [
                    'blocks' => [
                        ['id' => 'prevhdrnav01', 'type' => 'navigation', 'data' => ['menu' => 'main']],
                    ],
                    'settings' => ['sticky' => true, 'width' => 'full'],
                ],
            ],
        ]), \Symfony\Component\HttpFoundation\Request::create('https://admin.test/v1/admin/regions/preview'));
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getContent());
        $html = json_decode((string) $resp->getContent(), true)['data']['html'];
        self::assertStringContainsString('thallo-block-navigation', $html);
        self::assertStringContainsString('thallo-region-header--sticky', $html);
        self::assertStringContainsString('thallo-region-header--full', $html);
        self::assertStringContainsString('/theme-assets/site.css', $html);
        self::assertStringContainsString('/theme-assets/blocks.css', $html);
        // Blob-doc anchor (P1): absolute base so host-relative assets resolve.
        self::assertStringContainsString('<base href="https://admin.test/">', $html);
        self::assertStringNotContainsString('thallo-preview-block', $html); // never annotated
        self::assertStringNotContainsString('<footer', $html);            // no footer posted, none saved

        // NOTHING was written.
        self::assertNull((new RegionRepository($this->connection()))->find('header'));
    }

    public function testPreviewFallsBackToTheSavedRowForAnUnpostedRegion(): void
    {
        $controller = $this->controller(); // seeds block types
        (new RegionRepository($this->connection()))->save('footer', [
            ['id' => 'prevftrrich1', 'type' => 'rich_text', 'data' => ['body' => '<p>Saved footer</p>']],
        ], [], null);

        $resp = $controller->preview($this->previewDto(['regions' => [
            'header' => ['blocks' => [], 'settings' => []],
        ]]), \Symfony\Component\HttpFoundation\Request::create('https://admin.test/x'));
        $html = json_decode((string) $resp->getContent(), true)['data']['html'];
        self::assertStringContainsString('Saved footer', $html);
        // Posted-but-empty header previews as absent — no header element at all.
        self::assertStringNotContainsString('<header', $html);
    }

    public function testPreviewSurfacesPaletteErrorsBeforeAnythingGoesLive(): void
    {
        try {
            $this->controller()->preview($this->previewDto(['regions' => [
                'header' => ['blocks' => [
                    ['id' => 'prevbadblk01', 'type' => 'gallery', 'data' => ['images' => []]],
                ], 'settings' => []],
            ]]), \Symfony\Component\HttpFoundation\Request::create('https://admin.test/x'));
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('regions.header.blocks.0.type', $e->errors());
        }
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
