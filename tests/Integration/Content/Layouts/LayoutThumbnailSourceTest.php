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
            ['~/_preview/[A-Za-z0-9._-]+~', '~data-thallo-epoch="[^"]*"~'],
            ['/_preview/TOKEN', 'data-thallo-epoch="EPOCH"'],
            $m[0],
        );
    }

    public function testAPictureIsTheStageTheEditorShows(): void
    {
        $targets = layout_thumbnail_targets($this->container());
        $magazine = $this->pattern('entry', $targets['entry'], 'entry-magazine');
        $blocks = PatternLibrary::withIds($magazine['blocks']);
        $html = layout_stage_html(
            $this->container(),
            $this->handler(),
            'entry',
            $targets['entry'],
            $blocks,
            $magazine['settings'],
        );
        $raw = layout_stage_raw($this->container(), 'entry', $targets['entry'], $blocks, $magazine['settings']);
        self::assertSame(self::main($raw), self::main($html), 'made self-contained, the stage is unchanged');
        self::assertStringContainsString('thallo-layout--entry', $html);
        self::assertStringContainsString('data-thallo-placeholder', $html, 'the placeholder sample, named');
        self::assertStringNotContainsString('<script', $html);
    }

    public function testAFullWidthTemplateGetsTheFullWidthFrame(): void
    {
        $targets = layout_thumbnail_targets($this->container());
        $magazine = $this->pattern('entry', $targets['entry'], 'entry-magazine');
        self::assertSame(['width' => 'full'], $magazine['settings']);
        $blocks = PatternLibrary::withIds($magazine['blocks']);
        $contained = layout_stage_raw($this->container(), 'entry', $targets['entry'], $blocks, []);
        $full = layout_stage_raw($this->container(), 'entry', $targets['entry'], $blocks, $magazine['settings']);
        self::assertNotSame(
            self::main($contained),
            self::main($full),
            'the Frame setting reaches the page through the presentation path',
        );
        self::assertSame(
            self::main($full),
            self::main(layout_stage_raw($this->container(), 'entry', $targets['entry'], $blocks, ['width' => 'full'])),
        );
    }

    public function testACommerceTemplateGetsItsOwnFrame(): void
    {
        $targets = layout_thumbnail_targets($this->container());
        $top = $this->pattern('product', $targets['product'], 'product-gallery-top');
        $blocks = PatternLibrary::withIds($top['blocks']);
        $html = layout_stage_html($this->container(), $this->handler(), 'product', '@site', $blocks, $top['settings']);
        self::assertStringContainsString('shop-product shop-product--layout', $html);
        $raw = layout_stage_raw($this->container(), 'product', '@site', $blocks, $top['settings']);
        self::assertSame(self::main($raw), self::main($html));

        $banner = $this->pattern('shop_index', '@site', 'shop-index-banner');
        $shop = layout_stage_html(
            $this->container(),
            $this->handler(),
            'shop_index',
            '@site',
            PatternLibrary::withIds($banner['blocks']),
            $banner['settings'],
        );
        self::assertStringContainsString('shop-index shop-index--layout', $shop);
    }

    public function testThumbnailTargetsShowThePlaceholderEvenWithPublishedContent(): void
    {
        // A database with content every surface could sample: published posts, and products.
        $listing = new ListingPageSeed($this->container(), $this->appContext());
        $listing->seed();
        (new ShopPageSeed($this->container(), $this->appContext()))->seed();

        $targets = layout_thumbnail_targets($this->container());
        $surfaces = $this->container()->get(LayoutSurfaceRegistry::class);
        foreach ($targets as $surface => $target) {
            self::assertSame([], $surfaces->get($surface)?->samples($target, null), "{$surface}: no sample to pick");
            $starter = PatternLibrary::withIds($surfaces->get($surface)->starter($target));
            $html = layout_stage_html($this->container(), $this->handler(), $surface, $target, $starter, []);
            self::assertStringContainsString('data-thallo-placeholder', $html, "{$surface}: the placeholder sample");
        }
    }

    public function testAStageThatWouldShowASampleIsRefused(): void
    {
        $seeded = (new ListingPageSeed($this->container(), $this->appContext()))->seed();
        self::assertNotSame([], $seeded);
        $starter = PatternLibrary::withIds(
            $this->container()->get(LayoutSurfaceRegistry::class)->get('entry')->starter('post'),
        );
        $this->expectExceptionMessageMatches('~would replace the placeholder~');
        layout_stage_html($this->container(), $this->handler(), 'entry', 'post', $starter, []);
    }
}
