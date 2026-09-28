<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ShopPageSeed;

/**
 * Today's shop home and category pages, byte for byte (type layouts plan C2, S0): the markup
 * `shop/index.twig`, `shop/category.twig` and `shop/_product_card.twig` render for the seeded shop —
 * the home's two pages, a category with products and one without — recorded before any C2 change
 * and held through every one: with no layout, these pages render exactly as they did, and the
 * parts of the card C2 moves into a partial render exactly what they rendered in place.
 *
 * A captured page is normalized by one explicit map before comparison, so the golden is the same in
 * every run and every environment: the ids the catalog generates, blob URLs (whatever the API
 * prefix), fingerprinted asset URLs and the wishlist scope token become named placeholders. Markup
 * parity and the page's asset references are reported separately: a changed fingerprint is
 * legitimate; a missing or added asset is not.
 *
 * Record: THALLO_RECORD_SHOP_GOLDEN=1 vendor/bin/phpunit tests/Integration/Commerce/ShopPagesGoldenTest.php
 */
final class ShopPagesGoldenTest extends AppTestCase
{
    private const GOLDEN = __DIR__ . '/../../fixtures/commerce/shop-page-%s.html';

    /**
     * A foreign query parameter keeps the shop page cache out of the way; the controller still reads
     * `page`.
     */
    private const PAGES = [
        'index' => '/shop?golden=1',
        'index-page2' => '/shop?page=2&golden=1',
        'category' => '/shop/categories/mugs?golden=1',
        'category-empty' => '/shop/categories/vases?golden=1',
    ];

    /**
     * Page => how many ids, blob URLs, versioned asset URLs and scope tokens it normalizes. A card prints
     * its product's uuid (the wishlist toggle), and a direct-mode card its variant's too (the cart form).
     */
    private const SUBSTITUTIONS = [
        'index' => ['ids' => 46, 'blobs' => 1, 'assets' => 6, 'scope' => 1],
        'index-page2' => ['ids' => 4, 'blobs' => 0, 'assets' => 6, 'scope' => 1],
        'category' => ['ids' => 3, 'blobs' => 1, 'assets' => 6, 'scope' => 1],
        'category-empty' => ['ids' => 0, 'blobs' => 0, 'assets' => 6, 'scope' => 1],
    ];

    private ShopPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed = new ShopPageSeed($this->container(), $this->appContext());
        $this->seed->clear();
        $this->previousTenant = $this->seed->useTenant();
    }

    protected function tearDown(): void
    {
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        parent::tearDown();
    }

    public function testTheShopPagesRenderTheirGoldenMarkup(): void
    {
        $pages = $this->pages();
        if (getenv('THALLO_RECORD_SHOP_GOLDEN') === '1') {
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

    /** New catalog ids on a second seeding normalize to the same pages. */
    public function testTheGoldenSurvivesAFreshRebuild(): void
    {
        // The first seeding is rolled back whole — categories, products, the blob — so the second
        // writes everything again, with new catalog ids.
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
     * Seed the shop and render the four pages, normalized.
     *
     * @return array<string, array{markup: string, assets: list<string>, counts: array<string,int>}>
     */
    private function pages(): array
    {
        $seeded = $this->seed->seed();
        $ids = [];
        foreach ($seeded['products'] as $i => $uuid) {
            $ids[$uuid] = '{product:' . ($i + 1) . '}';
            foreach ($seeded['variants'][$i] as $j => $variant) {
                $ids[$variant] = '{variant:' . ($i + 1) . '-' . ($j + 1) . '}';
            }
        }

        $pages = [];
        foreach (self::PAGES as $name => $path) {
            $response = $this->handle(Request::create($path, 'GET'));
            $body = (string) $response->getContent();
            self::assertSame(200, $response->getStatusCode(), "{$name}: " . substr($body, 0, 300));
            $pages[$name] = self::normalize($body, $ids);
        }
        return $pages;
    }

    /** @param array{markup: string, assets: list<string>, counts: array<string,int>} $page */
    private function assertGolden(string $name, array $page): void
    {
        $file = sprintf(self::GOLDEN, $name);
        self::assertFileExists($file, 'record the golden first (THALLO_RECORD_SHOP_GOLDEN=1)');
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
            '~[^"\s()]*/blobs/(shopblob\d+)[^"\s()]*~',
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
