<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Motion;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * An entrance hides a block until it scrolls into view — so the page has to say, BEFORE that
 * block is parsed, that the script which will reveal it is on its way; otherwise the block paints
 * and then vanishes. The renderer notes, while it renders, that a block enters; the finished page
 * then gets the flag in its head, once. It never sits among the blocks — a script beside a block
 * is a sibling, and a theme's `:first-child` or `+` rule would see it. A page with no entrance
 * pays nothing, and the editor's canvas never gets the flag (nothing is hidden while editing).
 */
final class MotionEmissionTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private const PAGE = '<html><head><title>T</title></head><body><main>{{ blocks(l) }}</main></body></html>';

    /** @param list<array<string,mixed>> $blocks */
    private function render(array $blocks, bool $canvas = false, string $page = self::PAGE): string
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setBlockAnnotations($canvas);
        try {
            $env = (new TwigFactory(
                new ThemeLocator('default', $base . '/themes'),
                $extension,
                $base . '/storage/cache/twig',
            ))->environment();
            return $extension->finish($env->createTemplate($page)->render(['l' => $blocks]));
        } finally {
            $extension->setBlockAnnotations(false);
        }
    }

    /** @param array<string,mixed> $style */
    private function heading(string $id, array $style): array
    {
        return ['id' => $id, 'type' => 'heading', 'data' => ['text' => 'Hi', 'level' => 'h2'],
            'settings' => ['style' => $style]];
    }

    private const ENTERS = ['motion' => ['entrance' => ['type' => 'choice', 'value' => 'fade-up']]];

    public function testAPageWithABlockThatEntersGetsTheFlagInItsHeadOnce(): void
    {
        $html = $this->render([
            $this->heading('head00000001', []),
            $this->heading('head00000002', self::ENTERS),
            $this->heading('head00000003', self::ENTERS),
        ]);
        self::assertSame(1, substr_count($html, Motion::tags()));
        self::assertStringContainsString('<title>T</title>' . Motion::tags() . '</head>', $html);
        // The script itself is deferred and fetched once per page, by its stable name.
        self::assertStringContainsString('<script defer src="/_thallo/runtime/block-motion.js"></script>', $html);
    }

    public function testNoScriptEverSitsAmongTheBlocks(): void
    {
        // A theme may style "the first block" (`main > :first-child`) or "a block after another"
        // (`* + *`): a script put beside the block it protects would be that first child.
        $html = $this->render([$this->heading('head00000001', self::ENTERS)]);
        self::assertSame(1, preg_match('~<main>(.*)</main>~s', $html, $m));
        self::assertStringNotContainsString('<script', $m[1]);
        self::assertMatchesRegularExpression('~^\s*<h2~', $m[1]);
    }

    public function testAPageWithNoEntrancePaysNothing(): void
    {
        $none = ['motion' => ['entrance' => ['type' => 'choice', 'value' => 'none']]];
        $html = $this->render([$this->heading('head00000001', []), $this->heading('head00000002', $none)]);
        self::assertStringNotContainsString('data-thallo-motion', $html);
        self::assertStringNotContainsString('block-motion.js', $html);
    }

    public function testABlockInsideAnotherIsNoticedToo(): void
    {
        $html = $this->render([[
            'id' => 'cont00000001', 'type' => 'container',
            'data' => ['content' => [$this->heading('head00000001', self::ENTERS)]],
            'settings' => [],
        ]]);
        self::assertSame(1, substr_count($html, Motion::tags()));
    }

    public function testAThemeWithNoHeadStillHasItsBlocksProtected(): void
    {
        // The flag has to come before the block whatever the theme's markup is.
        $blocks = [$this->heading('head00000001', self::ENTERS)];
        $bodyOnly = $this->render($blocks, page: '<body class="x">{{ blocks(l) }}</body>');
        self::assertStringStartsWith(Motion::tags() . '<body class="x">', $bodyOnly);
        // Never ahead of the doctype: anything before it puts the page in quirks mode.
        $implied = $this->render($blocks, page: "<!DOCTYPE html>\n<title>T</title><main>{{ blocks(l) }}</main>");
        self::assertStringStartsWith('<!DOCTYPE html>' . Motion::tags(), $implied);
        $bare = $this->render($blocks, page: '{{ blocks(l) }}');
        self::assertStringStartsWith(Motion::tags(), $bare);
    }

    public function testAnEntranceThatComesFromAStyleClassIsProtectedLikeTheBlocksOwn(): void
    {
        // "Reveal" as a class on twenty blocks is how motion is really used. The block's own
        // settings say nothing, so the flag has to read the resolved cascade, not the instance.
        $reveal = $this->container()->get(StyleClassRepository::class)
            ->create(['name' => 'Reveal', 'style' => self::ENTERS])['id'];
        $block = $this->heading('head00000001', []);
        $block['settings'] = ['classes' => [$reveal]];
        $html = $this->render([$block]);
        self::assertSame(1, preg_match('~<h2[^>]*t-enter-fade-up~', $html), $html);
        self::assertSame(1, substr_count($html, Motion::tags()));
    }

    public function testTheEditorsCanvasNeverGetsIt(): void
    {
        $html = $this->render([$this->heading('head00000001', self::ENTERS)], canvas: true);
        self::assertStringContainsString('t-enter-fade-up', $html, 'the setting still renders its class');
        self::assertStringNotContainsString('data-thallo-motion', $html);
        self::assertStringNotContainsString('block-motion.js', $html);
    }

    public function testTheInlineFlagIsByteStableAndItsHashIsPublished(): void
    {
        // A site with a strict Content-Security-Policy allows this one inline script by hash (as
        // it does the colour-mode resolver). Blocked, it sets no flag and nothing is ever hidden.
        self::assertSame(
            base64_encode(hash('sha256', Motion::FLAG_JS, true)),
            Motion::FLAG_SHA256,
        );
        self::assertStringContainsString('<script>' . Motion::FLAG_JS . '</script>', Motion::tags());
        // It refuses to hide anything it could not later reveal, and gives up if the script
        // that reveals never reports in.
        self::assertStringContainsString('IntersectionObserver', Motion::FLAG_JS);
        self::assertStringContainsString('prefers-reduced-motion: reduce', Motion::FLAG_JS);
        self::assertStringContainsString('removeAttribute', Motion::FLAG_JS);
    }
}
