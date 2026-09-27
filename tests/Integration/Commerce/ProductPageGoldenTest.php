<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ProductPageSeed;

/**
 * Today's product page, byte for byte (type layouts plan C1, P2): the markup `shop/product.twig`
 * renders for a showcase product, a multi-variant product and one with a required add-on, recorded
 * before any C1 change and held through every one — the parts of the template C1 moves into
 * partials must render exactly what they rendered in place.
 *
 * A captured page is normalized by one explicit map before comparison, so the golden is the same in
 * every run and every environment: the ids the catalog generates, blob URLs (whatever the API
 * prefix), fingerprinted asset URLs and the wishlist scope token become named placeholders. Markup
 * parity and the page's asset references are reported separately: a changed fingerprint is
 * legitimate; a missing or added asset is not.
 *
 * Record: THALLO_RECORD_PRODUCT_GOLDEN=1 vendor/bin/phpunit tests/Integration/Commerce/ProductPageGoldenTest.php
 */
final class ProductPageGoldenTest extends AppTestCase
{
    private const GOLDEN = __DIR__ . '/../../fixtures/commerce/product-page-%s.html';

    private ProductPageSeed $seed;

    /** Page => how many ids, blob URLs, versioned asset URLs and scope tokens it normalizes. */
    private const SUBSTITUTIONS = [
        'simple' => ['ids' => 2, 'blobs' => 6, 'assets' => 6, 'scope' => 1],
        'multi' => ['ids' => 3, 'blobs' => 0, 'assets' => 6, 'scope' => 1],
        'addon' => ['ids' => 1, 'blobs' => 0, 'assets' => 6, 'scope' => 1],
    ];

    /** @var array<string,?string> */
    private array $previousTenant = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed = new ProductPageSeed($this->container(), $this->appContext());
        $this->seed->clear();
        $this->previousTenant = $this->seed->useTenant();
    }

    protected function tearDown(): void
    {
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        parent::tearDown();
    }

    public function testTheProductPageRendersItsGoldenMarkup(): void
    {
        $pages = $this->pages();
        if (getenv('THALLO_RECORD_PRODUCT_GOLDEN') === '1') {
            foreach ($pages as $name => $page) {
                file_put_contents(sprintf(self::GOLDEN, $name), $page['markup']);
            }
            self::markTestIncomplete('recorded ' . implode(', ', array_keys($pages)));
        }
        // Every normalization rule did its work, exactly as often as when the golden was recorded: a
        // rule that silently stopped matching (or started matching more) cannot hide behind parity.
        self::assertSame(
            self::SUBSTITUTIONS,
            array_map(static fn (array $page): array => $page['counts'], $pages),
        );
        foreach ($pages as $name => $page) {
            $this->assertGolden($name, $page);
        }
    }

    /** New catalog ids on a second seeding normalize to the same page. */
    public function testTheGoldenSurvivesAFreshRebuild(): void
    {
        // The first seeding is rolled back whole — products, blobs, the story and its link — so the
        // second writes everything again, with new catalog ids.
        $tm = $this->connection()->getTransactionManager();
        $tm->begin();
        $first = $this->pages();
        $tm->rollback();
        $second = $this->pages();
        foreach ($first as $name => $page) {
            self::assertSame($page['markup'], $second[$name]['markup'], "{$name}: the rebuild renders the same page");
        }
    }

    /**
     * Seed the three products and render their pages, normalized.
     *
     * @return array<string, array{markup: string, assets: list<string>, counts: array<string,int>}>
     */
    private function pages(): array
    {
        $ids = [];
        $showcase = $this->seed->showcase();
        $ids[$showcase['product']] = '{product:showcase}';
        $ids[$showcase['variant']] = '{variant:showcase}';
        $ids[$showcase['entry']] = '{entry:story}';
        $multi = $this->seed->multiVariant();
        $ids[$multi['product']] = '{product:multi}';
        foreach ($multi['variants'] as $i => $variant) {
            $ids[$variant] = '{variant:multi-' . ($i + 1) . '}';
        }
        $addon = $this->seed->withRequiredAddon();
        $ids[$addon['product']] = '{product:addon}';
        $ids[$addon['variant']] = '{variant:addon}';

        $pages = [];
        foreach (['simple' => 'linen-lamp', 'multi' => 'stoneware-mug', 'addon' => 'gift-set'] as $name => $slug) {
            // A foreign query parameter keeps the shop page cache out of the way.
            $response = $this->handle(Request::create("/shop/products/{$slug}?golden=1", 'GET'));
            $body = (string) $response->getContent();
            self::assertSame(200, $response->getStatusCode(), "{$name}: " . substr($body, 0, 300));
            $pages[$name] = self::normalize((string) $response->getContent(), $ids);
        }
        return $pages;
    }

    /** @param array{markup: string, assets: list<string>, counts: array<string,int>} $page */
    private function assertGolden(string $name, array $page): void
    {
        $file = sprintf(self::GOLDEN, $name);
        self::assertFileExists($file, 'record the golden first (THALLO_RECORD_PRODUCT_GOLDEN=1)');
        $golden = (string) file_get_contents($file);
        self::assertSame(
            self::assetNames($golden),
            $page['assets'],
            "{$name}: the page references the same assets (fingerprints may change; names may not)",
        );
        self::assertSame($golden, $page['markup'], "{$name}: markup parity with the golden");
    }

    /**
     * @param array<string,string> $ids generated id => placeholder
     * @return array{markup: string, assets: list<string>, counts: array<string,int>}
     */
    private static function normalize(string $html, array $ids): array
    {
        $counts = ['ids' => 0, 'blobs' => 0, 'assets' => 0, 'scope' => 0];
        foreach (array_keys($ids) as $id) {
            $counts['ids'] += substr_count($html, (string) $id);
        }
        $html = strtr($html, $ids);
        // Blob URLs, whatever the API prefix: the blob uuids are the seed's own fixed ones.
        $html = (string) preg_replace(
            '~[^"\s()]*/blobs/(proofblob\d+)[^"\s()]*~',
            '{blob:$1}',
            $html,
            -1,
            $counts['blobs'],
        );
        // Fingerprinted and versioned assets: the path with its hash and version taken out.
        // (`?v=` and `&v=` carry a file's modification time, which differs between checkouts.)
        $html = (string) preg_replace_callback(
            '~(?<=["(])(/[^"()\s]*?(?:-[0-9a-f]{8,}|[?&](?:amp;)?v=)[^"()\s]*)~',
            static function (array $m): string {
                $path = (string) preg_replace(['~(?:\?|&amp;|&)v=[^"()\s&]+~', '~-[0-9a-f]{8,}~'], '', $m[1]);
                return '{asset:' . $path . '}';
            },
            $html,
            -1,
            $counts['assets'],
        );
        $html = (string) preg_replace(
            '~data-shop-scope="[^"]*"~',
            'data-shop-scope="{scope}"',
            $html,
            -1,
            $counts['scope'],
        );
        return ['markup' => $html, 'assets' => self::assetNames($html), 'counts' => $counts];
    }

    /** @return list<string> */
    private static function assetNames(string $html): array
    {
        preg_match_all('~\{asset:([^}]+)\}~', $html, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);
        return $names;
    }
}
