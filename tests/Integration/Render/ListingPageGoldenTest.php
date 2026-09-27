<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;

/**
 * Today's listing and archive pages, byte for byte (type layouts plan B, B0): the markup
 * `listing.twig` and `archive.twig` render for the seeded posts — page 1, page 2 and the Pottery
 * archive — recorded before any Release B change and held through every one: with no layout, these
 * pages render exactly as they did.
 *
 * A captured page is normalized by one explicit map before comparison, each substitution counted:
 * the ids the store generates, blob URLs (whatever the API prefix) and fingerprinted or versioned
 * asset URLs become named placeholders. Markup parity and the page's asset references are reported
 * separately: a changed fingerprint is legitimate; a missing or added asset is not.
 *
 * Record: THALLO_RECORD_LISTING_GOLDEN=1 vendor/bin/phpunit tests/Integration/Render/ListingPageGoldenTest.php
 */
final class ListingPageGoldenTest extends AppTestCase
{
    private const GOLDEN = __DIR__ . '/../../fixtures/render/listing-page-%s.html';

    /** Page => how many ids, blob URLs and versioned asset URLs it normalizes. */
    private const SUBSTITUTIONS = [
        'listing' => ['ids' => 0, 'blobs' => 6, 'assets' => 6],
        'page2' => ['ids' => 0, 'blobs' => 0, 'assets' => 6],
        'archive' => ['ids' => 0, 'blobs' => 6, 'assets' => 6],
    ];

    private const PAGES = [
        'listing' => '/post',
        'page2' => '/post/page/2',
        'archive' => '/post/categories/pottery',
    ];

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    public function testTheListingPagesRenderTheirGoldenMarkup(): void
    {
        $pages = $this->pages();
        if (getenv('THALLO_RECORD_LISTING_GOLDEN') === '1') {
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

    /** New ids on a second seeding normalize to the same pages. */
    public function testTheGoldenSurvivesAFreshRebuild(): void
    {
        // The first seeding is rolled back whole — types, blobs, entries, routes and the projection —
        // so the second writes everything again, with new ids.
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
     * Seed the posts and render the three pages, normalized.
     *
     * @return array<string, array{markup: string, assets: list<string>, counts: array<string,int>}>
     */
    private function pages(): array
    {
        $seeded = (new ListingPageSeed($this->container(), $this->appContext()))->seed();
        $ids = [
            $seeded['post_type'] => '{type:post}',
            $seeded['category_type'] => '{type:category}',
            $seeded['pottery'] => '{category:1}',
        ];
        $n = 0;
        foreach ($seeded['posts'] as $uuid) {
            $ids[$uuid] = '{post:' . ++$n . '}';
        }

        $pages = [];
        foreach (self::PAGES as $name => $path) {
            // Never a cached page: each is rendered from this seeding.
            $this->container()->get(CacheStore::class)->deletePattern('render:*');
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
        self::assertFileExists($file, 'record the golden first (THALLO_RECORD_LISTING_GOLDEN=1)');
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
        $counts = ['ids' => 0, 'blobs' => 0, 'assets' => 0];
        foreach (array_keys($ids) as $id) {
            $counts['ids'] += substr_count($html, (string) $id);
        }
        $html = strtr($html, $ids);
        // Blob URLs, whatever the API prefix: the blob uuids are the seed's own fixed ones.
        $html = (string) preg_replace(
            '~[^"\s()]*/blobs/(listblob\d+)[^"\s(),]*~',
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
