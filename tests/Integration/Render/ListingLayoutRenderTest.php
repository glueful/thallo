<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Listing and archive pages rendered through their layout (type layouts spec §7.2, plan B, B4): the
 * frame renders the layout's blocks around the page the resolver answers, whatever per-type template
 * the theme ships; with no layout the page is today's. The resolver still decides which pages exist.
 * Every listing and archive page carries its surface tag, with a layout or without (§7.4).
 */
final class ListingLayoutRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        (new ListingPageSeed($this->container(), $this->appContext()))->seed();
    }

    protected function tearDown(): void
    {
        foreach ([['listing', 'post'], ['archive', 'post:categories'], ['listing', 'docs']] as [$surface, $target]) {
            $this->container()->get(LayoutResolver::class)->forget($surface, $target);
        }
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function get(string $path): Response
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        return $this->handle(Request::create($path, 'GET'));
    }

    /**
     * @param array<string,mixed> $settings
     * @param list<array<string,mixed>> $afterTitle blocks placed after the starter's title
     */
    private function saveStarter(string $surface, string $target, array $settings = [], array $afterTitle = []): void
    {
        $starter = $this->container()->get(LayoutSurfaceRegistry::class)->get($surface)->starter($target);
        array_splice($starter, 1, 0, $afterTitle);
        $blocks = self::withIds($starter);
        $repo = $this->container()->get(LayoutRepository::class);
        $this->container()->get(LayoutWriteLock::class)->within(
            $surface,
            $target,
            fn (): int => $repo->saveExpected(
                $surface,
                $target,
                $blocks,
                $settings,
                $repo->version($surface, $target),
                null,
            ),
        );
        $this->container()->get(LayoutResolver::class)->forget($surface, $target);
    }

    private function remove(string $surface, string $target): void
    {
        $repo = $this->container()->get(LayoutRepository::class);
        $this->container()->get(LayoutWriteLock::class)->within(
            $surface,
            $target,
            fn (): int => $repo->tombstone($surface, $target, $repo->version($surface, $target), null),
        );
        $this->container()->get(LayoutResolver::class)->forget($surface, $target);
    }

    /** @return list<string> */
    private static function tags(Response $response): array
    {
        return array_map('trim', explode(',', (string) $response->headers->get('Cache-Tag')));
    }

    public function testWithoutALayoutThePagesAreTodaysAndCarryTheirSurfaceTag(): void
    {
        $listing = $this->get('/post');
        self::assertStringContainsString('<ul class="listing-rows">', (string) $listing->getContent());
        self::assertStringNotContainsString('thallo-layout--listing', (string) $listing->getContent());
        self::assertContains('thallo:layout:listing:post', self::tags($listing));
        self::assertContains('thallo:type:post', self::tags($listing), 'today\'s tags are kept');
        self::assertContains('thallo:layout:listing:post', self::tags($this->get('/post/page/2')));
        $archive = $this->get('/post/categories/pottery');
        self::assertContains('thallo:layout:archive:post:categories', self::tags($archive));
        self::assertNotContains('thallo:layout:listing:post', self::tags($archive));
    }

    public function testTheListingRendersThroughItsLayout(): void
    {
        $this->saveStarter('listing', 'post');
        $html = (string) $this->get('/post')->getContent();
        self::assertStringContainsString('<article class="thallo-layout thallo-layout--listing">', $html);
        self::assertMatchesRegularExpression(
            '~<h1 class="thallo-block thallo-block-listing_title[^"]*">Posts</h1>~',
            $html,
        );
        $kiln = strpos($html, 'The kiln at dawn');
        $glazing = strpos($html, 'Glazing by hand');
        self::assertNotFalse($kiln);
        self::assertNotFalse($glazing);
        self::assertLessThan($glazing, $kiln, 'newest first');
        self::assertStringNotContainsString('First firing', $html, 'page 2\'s');
        self::assertStringContainsString('<a href="/post/page/2" rel="next">Older</a>', $html);
        self::assertStringContainsString('<title>Posts — ', $html, 'the title today\'s page has');

        $page2 = $this->get('/post/page/2');
        self::assertSame(200, $page2->getStatusCode());
        self::assertStringContainsString('First firing', (string) $page2->getContent());
        self::assertStringContainsString('<a href="/post" rel="prev">Newer</a>', (string) $page2->getContent());
        self::assertContains('thallo:layout:listing:post', self::tags($page2));
    }

    public function testTheArchiveRendersItsTermAndMembers(): void
    {
        // The starter is today's archive page; its Term description is added from the palette.
        $this->saveStarter('archive', 'post:categories', [], [
            ['type' => 'term_description', 'data' => [], 'settings' => []],
        ]);
        $response = $this->get('/post/categories/pottery');
        $html = (string) $response->getContent();
        self::assertStringContainsString('<article class="thallo-layout thallo-layout--archive">', $html);
        self::assertMatchesRegularExpression(
            '~<h1 class="thallo-block thallo-block-listing_title[^"]*">Pottery</h1>~',
            $html,
        );
        self::assertStringContainsString('<p>Wheel-thrown and hand-built work from the studio.</p>', $html);
        self::assertStringContainsString('The kiln at dawn', $html);
        self::assertStringContainsString('/post/categories/pottery/page/2', $html);
        self::assertContains('thallo:layout:archive:post:categories', self::tags($response));
        // The listing's pages are untouched by the archive's layout.
        self::assertStringNotContainsString('thallo-layout--archive', (string) $this->get('/post')->getContent());
    }

    public function testALayoutNeverMakesAPageExist(): void
    {
        $this->saveStarter('listing', 'post');
        $this->saveStarter('archive', 'post:categories');
        self::assertSame(404, $this->get('/post/page/9')->getStatusCode());
        self::assertSame(404, $this->get('/post/categories/glass')->getStatusCode());
        $first = $this->get('/post/page/1');
        self::assertSame(301, $first->getStatusCode());
        self::assertSame('/post', parse_url((string) $first->headers->get('Location'), PHP_URL_PATH));
    }

    public function testTheFrameSettingsApply(): void
    {
        $this->saveStarter('listing', 'post', ['width' => 'full', 'header' => 'hidden']);
        $html = (string) $this->get('/post')->getContent();
        self::assertStringContainsString('layout--full', $html);
        self::assertStringNotContainsString('<header class="site-header', $html);
    }

    /** The theme's per-type listing template (the shipped listing/docs.twig) gives way to a layout. */
    public function testALayoutWinsOverTheThemesTypeTemplateUntilRemoved(): void
    {
        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'docs', 'name' => 'Docs', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $this->container()->get(GeneralSettings::class)->save(['listing_types' => ['post', 'docs']]);
        self::assertStringContainsString('class="docs-index"', (string) $this->get('/docs')->getContent());

        $this->saveStarter('listing', 'docs');
        $html = (string) $this->get('/docs')->getContent();
        self::assertStringContainsString('thallo-layout--listing', $html);
        self::assertStringNotContainsString('class="docs-index"', $html);

        $this->remove('listing', 'docs');
        self::assertStringContainsString('class="docs-index"', (string) $this->get('/docs')->getContent());
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $tree, string $prefix = 'r'): array
    {
        foreach ($tree as $i => $block) {
            $tree[$i]['id'] = str_pad($prefix . $i, 12, '0');
            foreach ($block['data'] as $key => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $tree[$i]['data'][$key] = self::withIds($value, $prefix . $i . 'n');
                }
            }
        }
        return $tree;
    }
}
