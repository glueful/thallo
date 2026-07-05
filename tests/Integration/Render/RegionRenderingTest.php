<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Regions\RegionRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Lemma\Render\RenderContextExtension;
use Glueful\Lemma\Render\ThemeLocator;
use Glueful\Lemma\Render\TwigFactory;
use Symfony\Component\HttpFoundation\Request;

/**
 * Global chrome regions (global-regions spec): saved regions render through
 * region_blocks() with settings classes; EVERY null path (unbound is covered
 * by construction, absent row, saved-empty list) falls back to the hardcoded
 * chrome; _presentation hides both; region chrome is never canvas-annotated.
 */
final class RegionRenderingTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function regions(): RegionRepository
    {
        return $this->container()->get(RegionRepository::class);
    }

    /** Render the homepage through the real controller with homepage_entry set. */
    private function renderHome(string $entry, ?array $presentation = null): string
    {
        $app = self::bootAppWithConfigOverride('lemma_render', ['homepage_entry' => $entry]);
        $controller = $app->getContainer()
            ->get(\Glueful\Lemma\Render\Http\Controllers\RenderController::class);
        $res = $controller->home(Request::create('/', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        return (string) $res->getContent();
    }

    public function testSavedHeaderRegionRendersThroughBlocksWithSettingsClasses(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->regions()->save('header', [
            ['id' => 'reghdrlogo01', 'type' => 'logo', 'data' => ['size' => 'medium', 'link_home' => true]],
            ['id' => 'reghdrnavi01', 'type' => 'navigation', 'data' => ['menu' => 'main']],
        ], ['sticky' => true, 'width' => 'full'], null);

        $html = $this->renderHome($entry);
        self::assertStringContainsString('lemma-region-header', $html);
        self::assertStringContainsString('lemma-region-header--sticky', $html);
        self::assertStringContainsString('lemma-region-header--full', $html);
        self::assertStringContainsString('lemma-block-navigation', $html);
        self::assertStringContainsString('lemma-block-logo', $html);
        // The hardcoded fallback header is gone.
        self::assertStringNotContainsString('class="site-name"', $html);
    }

    public function testAbsentAndSavedEmptyRegionsBothRenderFallbackChrome(): void
    {
        $entry = $this->seedBilingualPublishedEntry();

        // (a) No region rows at all.
        $absent = $this->renderHome($entry);
        self::assertStringContainsString('class="site-name"', $absent);
        self::assertStringNotContainsString('lemma-region-header', $absent);

        // (b) Saved-but-empty region: SAME null, SAME fallback (pinned rule).
        $this->regions()->save('header', [], [], null);
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $empty = $this->renderHome($entry);
        self::assertStringContainsString('class="site-name"', $empty);
        self::assertStringNotContainsString('lemma-region-header', $empty);

        // Footer fallback is present in both.
        self::assertStringContainsString('<footer class="site-footer">', $empty);
    }

    /**
     * The P1 canonical-grammar proof (nav-v2 spec §3): entry menu-item urls are
     * CanonicalPathBuilder outputs; current_path is the page-cache normalizer's
     * view of the request — they meet in canonical space across all three
     * grammars: default-locale COLLAPSED, non-default PREFIXED, ROOT-MOUNTED.
     */
    public function testNavigationActiveStateAcrossLocaleGrammars(): void
    {
        // Block types for the navigation block.
        $repo = new \App\Content\Blocks\BlockTypeRepository($this->connection());
        foreach (\App\Content\Blocks\StarterBlockTypes::definitions() as $definition) {
            if ($repo->findBySlug($definition['slug']) === null) {
                $repo->create($definition);
            }
        }
        // Bilingual entry: en /blog/hello (collapsed), fr /fr/blog/bonjour (prefixed).
        $entry = $this->seedBilingualPublishedEntry();
        // Root-mounted type + entry: /landing-page.
        $types = new \App\Content\Repositories\ContentTypeRepository($this->connection());
        $rootType = $types->create([
            'slug' => 'rootpages', 'name' => 'Root pages',
            'public_delivery' => true, 'mount_at_root' => true,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = new \App\Content\Repositories\EntryRepository(
            $this->connection(),
            $this->appContext(),
            $types,
        );
        $rootEntry = $entries->createEntry($rootType, 'en', 1, 'user00000001');
        $entries->saveDraft($rootEntry, 'en', ['title' => 'Landing'], 1, 0, 'user00000001');
        (new \App\Content\Repositories\RouteRepository($this->connection()))
            ->assign($rootEntry, $rootType, 'en', 'landing-page');
        $this->publishSvc()->publish($rootEntry, 'en', 'user00000001');

        // A menu of TWO entry items + a header region rendering it.
        $menus = $this->container()->get(\Glueful\Lemma\Navigation\MenuRepository::class);
        $menu = $menus->createMenu('main', 'Main');
        $now = gmdate('Y-m-d H:i:s');
        $item = static fn (string $uuid, int $pos, string $target): array => [
            'uuid' => $uuid, 'parent_uuid' => null, 'position' => $pos, 'kind' => 'entry',
            'entry_uuid' => $target, 'url' => null, 'labels' => json_encode([]),
            'created_at' => $now, 'updated_at' => $now,
        ];
        $menus->replaceTree((string) $menu['uuid'], 0, [
            $item('navgrammar01', 0, $entry),
            $item('navgrammar02', 1, $rootEntry),
        ]);
        $this->regions()->save('header', [
            ['id' => 'reghdrnavgr1', 'type' => 'navigation', 'data' => ['menu' => 'main']],
        ], [], null);

        $controller = $this->container()
            ->get(\Glueful\Lemma\Render\Http\Controllers\RenderController::class);
        $render = function (string $path) use ($controller): string {
            $this->container()->get(CacheStore::class)->deletePattern('render:*');
            $res = $controller->page(Request::create('/' . ltrim($path, '/')), $path);
            self::assertSame(200, $res->getStatusCode(), $path);
            return (string) $res->getContent();
        };

        // Collapsed default locale: /blog/hello — the blog item is active, root isn't.
        $en = $render('blog/hello');
        self::assertMatchesRegularExpression(
            '#__item--active[^>]*>\s*<a href="[^"]*/blog/hello"#',
            $en,
        );
        self::assertStringNotContainsString('__item--active"><a href="/landing-page"', $en);

        // Prefixed non-default locale: /fr/blog/bonjour — fr url resolves + matches.
        $fr = $render('fr/blog/bonjour');
        self::assertMatchesRegularExpression(
            '#__item--active[^>]*>\s*<a href="[^"]*/fr/blog/bonjour"#',
            $fr,
        );

        // Root-mounted: /landing-page.
        $root = $render('landing-page');
        self::assertMatchesRegularExpression(
            '#__item--active[^>]*>\s*<a href="[^"]*/landing-page"#',
            $root,
        );
    }

    public function testRegionChromeIsNeverCanvasAnnotated(): void
    {
        // Drive the extension directly with annotations ON (the canvas mode):
        // entry-level blocks() annotates; region_blocks() must not.
        $this->regions()->save('footer', [
            ['id' => 'regftrsoc001', 'type' => 'social_links', 'data' => ['items' => [
                ['id' => 'regftrsoc01a', 'type' => 'social_link',
                    'data' => ['icon' => 'brand:github', 'url' => 'https://github.com/acme']],
            ]]],
        ], [], null);
        // The block types must exist for blocks() to render them.
        $repo = new \App\Content\Blocks\BlockTypeRepository($this->connection());
        foreach (\App\Content\Blocks\StarterBlockTypes::definitions() as $definition) {
            if ($repo->findBySlug($definition['slug']) === null) {
                $repo->create($definition);
            }
        }

        $ext = $this->container()->get(RenderContextExtension::class);
        $ext->setBlockAnnotations(true);
        try {
            $base = $this->appContext()->getBasePath();
            $env = (new TwigFactory(
                new ThemeLocator('default', $base . '/themes'),
                $ext,
                $base . '/storage/cache/twig',
            ))->environment();

            $entryHtml = $env->createTemplate('{{ blocks(list) }}')->render(['list' => [
                ['id' => 'entryblock01', 'type' => 'quote', 'data' => ['text' => 'Entry']],
            ]]);
            self::assertStringContainsString('lemma-preview-block', $entryHtml); // canvas mode is ON

            $regionHtml = $env->createTemplate("{{ region_blocks('footer') }}")->render([]);
            self::assertStringContainsString('lemma-block-social_links', $regionHtml);
            self::assertStringNotContainsString('lemma-preview-block', $regionHtml);
            self::assertStringNotContainsString('regftrsoc001', $regionHtml); // no id markers either

            // …and suppression is scoped: blocks() AFTER a region render still annotates.
            $after = $env->createTemplate('{{ blocks(list) }}')->render(['list' => [
                ['id' => 'entryblock02', 'type' => 'quote', 'data' => ['text' => 'After']],
            ]]);
            self::assertStringContainsString('lemma-preview-block', $after);
        } finally {
            $ext->setBlockAnnotations(false);
        }
    }
}
