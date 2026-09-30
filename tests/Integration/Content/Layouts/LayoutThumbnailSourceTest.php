<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Patterns\PatternLibrary;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\ShopPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * A layout pattern's picture is the layout stage itself (sections and templates design §6): the
 * page the editor's iframe receives for that document — the surface's own frame, the Frame settings
 * through the real presentation path — on the placeholder sample, whatever the database holds.
 */
final class LayoutThumbnailSourceTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once \dirname(__DIR__, 4) . '/scripts/lib/layout-thumbnails.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private function handler(): callable
    {
        return fn (Request $request): Response => $this->handle($request);
    }

    /** @return array<string,mixed> the pattern served for a layout */
    private function pattern(string $surface, string $target, string $slug): array
    {
        $library = $this->container()->get(PatternLibrary::class);
        $entries = array_column($library->forLayout($surface, $target), null, 'slug');
        self::assertArrayHasKey($slug, $entries);
        return $entries[$slug];
    }

    /** The page as rendered markup: its body, without the styles, scripts and links inlining changes. */
    private static function main(string $html): string
    {
        $html = (string) preg_replace(
            ['~<style\b[^>]*>.*?</style>~s', '~<script\b[^>]*>.*?</script>~s', '~<link\b[^>]*>~'],
            '',
            $html,
        );
        self::assertSame(1, preg_match('~<body\b[^>]*>(.*)</body>~s', $html, $m), 'the stage renders a body');
        // Each session has its own token and epoch: the rest is the page.
        return (string) preg_replace(
            // …and its pictures are inlined: where an image comes from is not what the stage shows.
            ['~/_preview/[A-Za-z0-9._-]+~', '~data-thallo-epoch="[^"]*"~', '~\\ssrcset="[^"]*"~', '~\\bsrc="[^"]*"~'],
            ['/_preview/TOKEN', 'data-thallo-epoch="EPOCH"', '', 'src="IMAGE"'],
            $m[0],
        );
    }

    /**
     * A layout's picture for one pattern, on its fixture sample.
     *
     * @param array<string,mixed> $fixtures {@see layout_thumbnail_fixtures()}
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $settings
     */
    private function picture(
        array $fixtures,
        string $surface,
        array $blocks,
        array $settings,
        bool $raw = false,
    ): string {
        return layout_thumbnail_in_place($fixtures, $surface, fn (): string => $raw
            ? layout_stage_raw(
                $this->container(),
                $surface,
                $fixtures['targets'][$surface],
                $blocks,
                $settings,
                $fixtures['samples'][$surface],
            )
            : layout_stage_html(
                $this->container(),
                $this->handler(),
                $surface,
                $fixtures['targets'][$surface],
                $blocks,
                $settings,
                $fixtures['samples'][$surface],
                $fixtures['images'],
            ));
    }

    public function testAPictureIsTheStageTheEditorShowsOnTheFixtureSample(): void
    {
        $fixtures = layout_thumbnail_fixtures($this->container());
        $magazine = $this->pattern('entry', $fixtures['targets']['entry'], 'entry-magazine');
        $blocks = PatternLibrary::withIds($magazine['blocks']);
        $html = $this->picture($fixtures, 'entry', $blocks, $magazine['settings']);
        $raw = $this->picture($fixtures, 'entry', $blocks, $magazine['settings'], true);
        self::assertSame(self::main($raw), self::main($html), 'made self-contained, the stage is unchanged');
        self::assertStringContainsString('thallo-layout--entry', $html);
        self::assertStringContainsString('A lidded jar, start to finish', $html, 'the fixture post');
        self::assertStringContainsString('data:image/png;base64,', $html, 'its cover, inlined');
        self::assertStringNotContainsString('/blobs/', $html, 'no image left that a file:// page cannot load');
        self::assertStringNotContainsString('data-thallo-placeholder', $html, 'never the empty placeholder');
        self::assertStringNotContainsString('<script', $html);
    }

    public function testAFullWidthTemplateGetsTheFullWidthFrame(): void
    {
        $fixtures = layout_thumbnail_fixtures($this->container());
        $magazine = $this->pattern('entry', $fixtures['targets']['entry'], 'entry-magazine');
        self::assertSame(['width' => 'full'], $magazine['settings']);
        $blocks = PatternLibrary::withIds($magazine['blocks']);
        $contained = $this->picture($fixtures, 'entry', $blocks, [], true);
        $full = $this->picture($fixtures, 'entry', $blocks, $magazine['settings'], true);
        self::assertNotSame(self::main($contained), self::main($full), 'the Frame setting reaches the page');
        $again = $this->picture($fixtures, 'entry', $blocks, ['width' => 'full'], true);
        self::assertSame(self::main($full), self::main($again));
    }

    public function testACommerceTemplateGetsItsOwnFrameAndTheFixtureShop(): void
    {
        $fixtures = layout_thumbnail_fixtures($this->container());
        $top = $this->pattern('product', '@site', 'product-gallery-top');
        $blocks = PatternLibrary::withIds($top['blocks']);
        $html = $this->picture($fixtures, 'product', $blocks, $top['settings']);
        self::assertStringContainsString('shop-product shop-product--layout', $html);
        self::assertStringNotContainsString('data-thallo-placeholder', $html);
        self::assertStringNotContainsString('this product has none', $html, 'the fixture product is described');
        self::assertStringNotContainsString('no linked story', $html, 'the fixture product has its story');
        self::assertStringContainsString('Made by hand', $html);
        $raw = $this->picture($fixtures, 'product', $blocks, $top['settings'], true);
        self::assertSame(self::main($raw), self::main($html));

        $banner = $this->pattern('shop_index', '@site', 'shop-index-banner');
        $bannerBlocks = PatternLibrary::withIds($banner['blocks']);
        $shop = $this->picture($fixtures, 'shop_index', $bannerBlocks, $banner['settings']);
        self::assertStringContainsString('shop-index shop-index--layout', $shop);
        self::assertStringContainsString('Tall mug', $shop, 'the fixture shop\'s products');
    }

    public function testAPageTemplateIsPicturedOnAPage(): void
    {
        $fixtures = layout_thumbnail_fixtures($this->container());
        self::assertSame('thumb_page', $fixtures['page']['target']);
        $band = $this->pattern('entry', 'thumb_page', 'entry-page-header');
        $html = layout_stage_html(
            $this->container(),
            $this->handler(),
            'entry',
            'thumb_page',
            PatternLibrary::withIds($band['blocks']),
            $band['settings'],
            $fixtures['page']['sample'],
            $fixtures['images'],
        );
        self::assertStringContainsString('About the studio', $html, 'the fixture page');
        self::assertStringNotContainsString('data-thallo-placeholder', $html);
    }

    public function testAListingPictureShowsRealPageNavigation(): void
    {
        $fixtures = layout_thumbnail_fixtures($this->container());
        $grid = $this->pattern('listing', $fixtures['targets']['listing'], 'listing-card-grid');
        $html = $this->picture($fixtures, 'listing', PatternLibrary::withIds($grid['blocks']), []);
        self::assertStringNotContainsString('Page navigation — one page', $html, 'more than one page of posts');
        // Real navigation: an Older link and the page count (how many pages depends on the listing's
        // page size — phpunit.xml sets 2; the build's default is 10, twelve posts making two pages).
        self::assertStringContainsString('rel="next"', $html);
        self::assertMatchesRegularExpression('~Page 1 of [2-9]~', $html);
    }

    public function testEveryPictureShowsItsOwnFixturesWhateverElseTheDatabaseHolds(): void
    {
        // Other content every surface could sample: someone else's posts.
        (new ListingPageSeed($this->container(), $this->appContext()))->seed();
        $fixtures = layout_thumbnail_fixtures($this->container());
        $surfaces = $this->container()->get(LayoutSurfaceRegistry::class);
        foreach ($fixtures['targets'] as $surface => $target) {
            $starter = PatternLibrary::withIds($surfaces->get($surface)->starter($target));
            $html = $this->picture($fixtures, $surface, $starter, []);
            self::assertStringNotContainsString('data-thallo-placeholder', $html, "{$surface}: a real sample");
            self::assertStringNotContainsString('First firing', $html, "{$surface}: never another type's post");
        }
    }

    public function testAStageOnAnotherSampleIsRefused(): void
    {
        $fixtures = layout_thumbnail_fixtures($this->container());
        $starter = PatternLibrary::withIds(
            $this->container()->get(LayoutSurfaceRegistry::class)->get('entry')->starter($fixtures['targets']['entry']),
        );
        $this->expectExceptionMessageMatches("~not the fixture 'nosuchsample'~");
        layout_stage_html(
            $this->container(),
            $this->handler(),
            'entry',
            $fixtures['targets']['entry'],
            $starter,
            [],
            'nosuchsample',
        );
    }
}
