<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Patterns\PatternBlocks;
use Thallo\Contracts\Patterns\PatternContributor;
use Thallo\Contracts\Patterns\PatternContributorRegistry;
use Thallo\Contracts\Patterns\PatternSection;
use Thallo\Contracts\Patterns\PatternTemplate;
use Thallo\Core\Content\Blocks\BlockDepth;
use Thallo\Core\Content\Blocks\BlockFactory;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Patterns\DefaultPatternContributorRegistry;
use Thallo\Core\Content\Patterns\PatternLibrary;
use Thallo\Core\Content\Regions\RegionValidator;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * The section and page library: ready-made sections (a hero, a feature grid, pricing, an FAQ…)
 * and starter pages made of them, inserted from the designer's Blocks tab. A pattern is nothing
 * new to the system — it is a tree of ordinary blocks with ordinary settings — so the library's
 * one promise is that what it hands out is a document the system accepts: every pattern passes
 * the validation a page save runs, fits the nesting cap with room to be placed inside a
 * container, and renders through the default theme. The header and footer have sections and
 * templates of their own, which their region accepts, and neither is offered to a page body.
 */
final class PatternLibraryTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private function library(): PatternLibrary
    {
        return $this->container()->get(PatternLibrary::class);
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $blocks, int &$n): array
    {
        return array_map(static function (array $block) use (&$n): array {
            $block['id'] = 'pt' . str_pad((string) ++$n, 10, '0', STR_PAD_LEFT);
            foreach ($block['data'] as $key => $value) {
                if (is_array($value) && $value !== [] && is_array($value[0] ?? null) && isset($value[0]['type'])) {
                    $block['data'][$key] = self::withIds($value, $n);
                }
            }
            return $block;
        }, $blocks);
    }

    /** @param list<array<string,mixed>> $blocks */
    private static function depth(array $blocks): int
    {
        $deepest = 0;
        foreach ($blocks as $block) {
            $below = 0;
            foreach ($block['data'] as $value) {
                if (is_array($value) && is_array($value[0] ?? null) && isset($value[0]['type'])) {
                    $below = max($below, self::depth($value));
                }
            }
            $deepest = max($deepest, 1 + $below);
        }
        return $deepest;
    }

    public function testItOffersSectionsInCategoriesAndPagesMadeOfThem(): void
    {
        $patterns = $this->library()->all();
        $slugs = array_column($patterns, 'slug');
        self::assertSame($slugs, array_values(array_unique($slugs)), 'a slug names one pattern');

        $sections = array_filter($patterns, static fn (array $p): bool => $p['kind'] === 'section');
        $pages = array_filter($patterns, static fn (array $p): bool => $p['kind'] === 'page');
        self::assertGreaterThanOrEqual(15, count($sections));
        self::assertGreaterThanOrEqual(4, count($pages));
        foreach (['Hero', 'Features', 'Pricing', 'FAQ', 'Call to action', 'Contact'] as $category) {
            self::assertContains($category, array_column($sections, 'category'), $category);
        }
        foreach ($patterns as $pattern) {
            self::assertMatchesRegularExpression('/\A[a-z][a-z0-9-]*\z/', $pattern['slug']);
            self::assertNotSame('', $pattern['label']);
            self::assertNotSame('', $pattern['description'], $pattern['slug']);
            self::assertNotSame([], $pattern['blocks'], $pattern['slug']);
        }
        // A section is ONE block — it is inserted, moved and deleted as one; a page is several.
        foreach ($sections as $section) {
            self::assertCount(1, $section['blocks'], $section['slug']);
        }
        foreach ($pages as $page) {
            // A region's template may be a single row; a starter page is several sections.
            if ($page['scope'] === 'page') {
                self::assertGreaterThan(2, count($page['blocks']), $page['slug']);
            }
        }
    }

    public function testTheHeaderAndFooterHaveSectionsAndTemplatesTheirRegionAccepts(): void
    {
        $patterns = $this->library()->all();
        foreach ($patterns as $pattern) {
            self::assertContains($pattern['scope'], ['page', 'region'], $pattern['slug']);
            self::assertSame(
                $pattern['scope'] === 'region',
                in_array($pattern['region'], ['header', 'footer'], true),
                $pattern['slug'] . ': a region pattern names its region, a page one none',
            );
        }
        $validator = $this->container()->get(RegionValidator::class);
        foreach (['header', 'footer'] as $region) {
            $mine = array_filter(
                $patterns,
                static fn (array $p): bool => $p['scope'] === 'region' && $p['region'] === $region,
            );
            $kinds = array_count_values(array_column($mine, 'kind'));
            self::assertGreaterThanOrEqual(3, $kinds['section'] ?? 0, "{$region} sections");
            self::assertGreaterThanOrEqual(2, $kinds['page'] ?? 0, "{$region} templates");
            foreach ($mine as $pattern) {
                $n = 0;
                $blocks = self::withIds($pattern['blocks'], $n);
                try {
                    $clean = $validator->validate($region, $blocks, []);
                } catch (\Thallo\Core\Content\Validation\ValidationException $e) {
                    self::fail($pattern['slug'] . ': ' . json_encode($e->errors()));
                }
                self::assertEquals($blocks, $clean['blocks'], $pattern['slug']);
            }
        }
    }

    public function testEveryPatternIsADocumentAPageSaveAccepts(): void
    {
        $validator = new FieldValidator(
            $this->connection(),
            $this->appContext(),
            new BlockTypeRepository($this->connection()),
        );
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        foreach ($this->library()->all() as $pattern) {
            $n = 0;
            $blocks = self::withIds($pattern['blocks'], $n);
            try {
                $clean = $validator->validate($schema, ['body' => $blocks], true);
            } catch (\Thallo\Core\Content\Validation\ValidationException $e) {
                self::fail($pattern['slug'] . ': ' . json_encode($e->errors()));
            }
            // Nothing the pattern says is dropped or rewritten on the way in (key order aside).
            self::assertEquals($blocks, $clean['body'], $pattern['slug']);
            // Room to be placed inside a container and still fit the cap.
            self::assertLessThan(BlockDepth::MAX, self::depth($blocks), $pattern['slug']);
        }
    }

    public function testEveryPatternRendersThroughTheDefaultTheme(): void
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        // The application's locator: it carries the packs' template paths, which a contributed
        // pattern's blocks (the shop's Product grid) render from.
        $theme = $this->container()->get(ThemeLocator::class);
        self::assertSame('default', $theme->activePaths()['name']);
        $env = (new TwigFactory($theme, $extension, $base . '/storage/cache/twig'))->environment();
        foreach ($this->library()->all() as $pattern) {
            $n = 0;
            $extension->resetPerRenderState();
            $html = $env->createTemplate('{{ blocks(l) }}')
                ->render(['l' => self::withIds($pattern['blocks'], $n)]);
            self::assertStringNotContainsString('Missing block template', $html, $pattern['slug']);
            self::assertGreaterThan(200, strlen($html), $pattern['slug']);
        }
        // What a section's settings say reaches the markup: the centred hero section's grid.
        $n = 0;
        $features = array_values(array_filter(
            $this->library()->all(),
            static fn (array $p): bool => $p['slug'] === 'features-grid',
        ))[0];
        $extension->resetPerRenderState();
        $html = $env->createTemplate('{{ blocks(l) }}')->render(['l' => self::withIds($features['blocks'], $n)]);
        self::assertStringContainsString('lg:t-cols-3', $html);
        self::assertSame(3, substr_count($html, 'thallo-block-feature '));
    }

    public function testTheAdminReadsTheLibraryWithTheRightToSeeContent(): void
    {
        $route = $this->findRoute('GET', '/v1/admin/patterns');
        self::assertNotNull($route);
        self::assertContains('content_permission:content.view', (array) ($route['middleware'] ?? []));

        $res = $this->container()->get(\Thallo\Core\Content\Http\Controllers\PatternController::class)
            ->index(\Symfony\Component\HttpFoundation\Request::create('/x'));
        $data = ((array) json_decode((string) $res->getContent(), true))['data'];
        self::assertSame(array_column($this->library()->all(), 'slug'), array_column($data['patterns'], 'slug'));
        self::assertDataMatchesDtoShape(
            $data,
            \Thallo\Core\Content\Http\DTOs\Responses\Patterns\PatternListData::class,
        );
        self::assertDataMatchesDtoShape(
            $data['patterns'][0],
            \Thallo\Core\Content\Http\DTOs\Responses\Patterns\PatternData::class,
        );
    }

    public function testEveryPatternHasItsThumbnailAndNoThumbnailIsAnOrphan(): void
    {
        // The admin ships the pictures (admin/public/pattern-thumbs), built by
        // scripts/build-pattern-thumbnails from the default theme's real render.
        $dir = \dirname(__DIR__, 3) . '/admin/public/pattern-thumbs';
        $have = array_map(static fn (string $f): string => basename($f, '.jpg'), glob($dir . '/*.jpg') ?: []);
        // Every page and region pattern, and every shipped layout pattern of the surfaces this site has.
        $want = [...array_column($this->library()->all(), 'slug'), ...$this->library()->layoutSlugs()];
        sort($have);
        sort($want);
        self::assertSame($want, $have, 'run scripts/build-pattern-thumbnails');
        // And the sizes the admin reserves their places with are these pictures' own.
        $sizes = (array) json_decode((string) file_get_contents(
            \dirname(__DIR__, 3) . '/admin/src/editor/palette/patternThumbSizes.json',
        ), true);
        foreach ($have as $slug) {
            self::assertLessThan(80_000, filesize("{$dir}/{$slug}.jpg"), "{$slug}: a thumbnail, not a poster");
            [$width, $height] = (array) getimagesize("{$dir}/{$slug}.jpg");
            // Its size, and its content's version: the admin addresses a picture by it, so a rebuilt
            // picture is never served from a cache of the old one.
            $version = substr((string) md5_file("{$dir}/{$slug}.jpg"), 0, 8);
            self::assertSame(
                [$width, $height, $version],
                $sizes[$slug] ?? null,
                "{$slug}: run scripts/build-pattern-thumbnails",
            );
        }
        self::assertSame($want, array_keys($sizes));
    }

    public function testAPatternThatNeedsABlockTypeTheSiteHasSwitchedOffIsNotOffered(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $row = $repo->findBySlug('pricing_plans');
        self::assertNotNull($row);
        $repo->setActive((string) $row['uuid'], false);
        try {
            $slugs = array_column($this->library()->all(), 'slug');
        } finally {
            // The test database is shared: a type left switched off would follow every later test.
            $repo->setActive((string) $row['uuid'], true);
        }
        self::assertNotContains('pricing-plans', $slugs);
        self::assertNotContains('page-pricing', $slugs, 'nor a page that is made of it');
        self::assertContains('features-grid', $slugs);
        self::assertContains('pricing-plans', array_column($this->library()->all(), 'slug'), 'and back again');
    }

    /** A library over a registry holding one test contributor (sections and templates design §3). */
    private function libraryWithContributor(): PatternLibrary
    {
        $registry = new DefaultPatternContributorRegistry();
        $registry->register(new class implements PatternContributor {
            public function id(): string
            {
                return 'test.pack';
            }

            public function sections(): array
            {
                return [
                    new PatternSection('x-banner', 'Banner', 'Test', 'A banner.', PatternBlocks::band([
                        PatternBlocks::heading('A banner', 'h2', 'center'),
                    ])),
                    new PatternSection('x-spot', 'Spot', 'Test', 'A spotlight.', PatternBlocks::band([
                        PatternBlocks::block('featured-product', ['product_slug' => '']),
                    ]), 'product'),
                ];
            }

            public function templates(): array
            {
                return [
                    new PatternTemplate('x-page', 'Page', 'A page.', ['x-banner', 'faq']),
                    new PatternTemplate('x-shop', 'Shop', 'A shop page.', ['x-banner', 'x-spot']),
                ];
            }
        });

        return new PatternLibrary($this->container()->get(BlockFactory::class), null, $registry);
    }

    /** @return array<string,array<string,mixed>> */
    private static function bySlug(array $patterns): array
    {
        return array_column($patterns, null, 'slug');
    }

    public function testTheContainerHoldsTheContributorRegistry(): void
    {
        self::assertInstanceOf(
            DefaultPatternContributorRegistry::class,
            $this->container()->get(PatternContributorRegistry::class),
        );
    }

    public function testContributedPatternsAreOfferedWithTheirPlace(): void
    {
        $patterns = self::bySlug($this->libraryWithContributor()->all());

        self::assertSame(['section', 'page', null, null], [
            $patterns['x-banner']['kind'], $patterns['x-banner']['scope'],
            $patterns['x-banner']['region'], $patterns['x-banner']['requires'],
        ]);
        self::assertSame('page', $patterns['x-page']['kind']);
        self::assertCount(2, $patterns['x-page']['blocks']);
        $slugs = array_keys($patterns);
        self::assertLessThan(
            array_search('header-announcement', $slugs, true),
            array_search('x-banner', $slugs, true),
            'contributed sections come after the page sections and before the header and footer ones',
        );
    }

    public function testATemplateRequiresAProductWhenASectionDoes(): void
    {
        $patterns = self::bySlug($this->libraryWithContributor()->all());

        self::assertSame('product', $patterns['x-spot']['requires']);
        self::assertSame('product', $patterns['x-shop']['requires']);
        self::assertNull($patterns['x-page']['requires']);
    }

    public function testATemplateGoesWhenACoreSectionItNamesGoes(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $row = $repo->findBySlug('accordion');
        self::assertNotNull($row);
        $repo->setActive((string) $row['uuid'], false);
        try {
            $slugs = array_column($this->libraryWithContributor()->all(), 'slug');
        } finally {
            $repo->setActive((string) $row['uuid'], true);
        }
        self::assertNotContains('faq', $slugs);
        self::assertNotContains('x-page', $slugs, 'a contributed template goes whole with the core section it names');
        self::assertContains('x-banner', $slugs);
    }

    public function testCorePatternsAreUnchanged(): void
    {
        $factory = $this->container()->get(BlockFactory::class);
        $without = (new PatternLibrary($factory))->all();
        $withEmpty = (new PatternLibrary($factory, null, new DefaultPatternContributorRegistry()))->all();

        self::assertSame($without, $withEmpty);
        self::assertSame([null], array_values(array_unique(array_column($without, 'requires'))));
    }
}
