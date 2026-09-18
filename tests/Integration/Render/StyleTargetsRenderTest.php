<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * Visual builder spec §2.5: the shipped templates emit the style helpers on their declared
 * targets, so a block's settings land on the right element and nowhere else.
 */
final class StyleTargetsRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private function env(): Environment
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param list<array<string,mixed>> $list */
    private function render(array $list): string
    {
        $this->container()->get(RenderContextExtension::class)->resetPerRenderState();
        return $this->env()->createTemplate('{{ blocks(l) }}')->render(['l' => $list]);
    }

    /** The opening tag that carries the class. */
    private function tagWithClass(string $html, string $class): string
    {
        $pattern = '~<[a-z0-9]+[^>]*\b' . preg_quote($class, '~') . '\b[^>]*>~';
        self::assertSame(1, preg_match($pattern, $html, $m), $class);
        return $m[0];
    }

    public function testSlotAttributesAppearInCanvasModeOnly(): void
    {
        $container = [['id' => 'cont00000001', 'type' => 'container', 'data' => ['content' => []], 'settings' => []]];
        self::assertStringNotContainsString('data-thallo-slot', $this->render($container));
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setBlockAnnotations(true);
        try {
            $html = $this->env()->createTemplate('{{ blocks(l) }}')->render(['l' => $container]);
        } finally {
            $extension->setBlockAnnotations(false);
        }
        self::assertStringContainsString('data-thallo-slot="content"', $html);
    }

    public function testAHeadingCarriesAnMdOnlyPaddingAndItsAnchorOnTheHeadingElement(): void
    {
        $html = $this->render([[
            'id' => 'h1', 'type' => 'heading',
            'data' => ['text' => 'Hi', 'level' => 'h3'],
            'settings' => [
                'style' => ['spacing' => ['padding' => ['top' => [
                    'md' => ['type' => 'token', 'value' => 'spacing.lg'],
                ]]]],
                'advanced' => ['anchor' => 'intro', 'attributes' => ['data-track' => 'a"b']],
            ],
        ]]);

        $tag = $this->tagWithClass($html, 'thallo-block-heading');
        self::assertStringStartsWith('<h3 ', $tag);
        self::assertStringContainsString(' md:t-pt-lg"', $tag, 'md-only padding, nothing at base');
        self::assertStringNotContainsString(' t-pt-', $tag);
        self::assertStringContainsString(' id="intro"', $tag);
        self::assertStringContainsString(' data-track="a&quot;b"', $tag, 'attributes are escaped centrally');
    }

    public function testAButtonPutsAlignmentOnTheRootAndRadiusOnTheControl(): void
    {
        $html = $this->render([[
            'id' => 'b1', 'type' => 'button',
            'data' => ['label' => 'Go', 'url' => '/go'],
            'settings' => [
                'style' => [
                    'alignment' => ['content' => ['base' => ['type' => 'choice', 'value' => 'center']]],
                    'radius' => ['type' => 'token', 'value' => 'radius.full'],
                    'colors' => ['surface' => ['type' => 'token', 'value' => 'color.accent']],
                ],
                'advanced' => ['accessibility' => ['label' => 'Go now'], 'css_classes' => ['mine']],
            ],
        ]]);

        $root = $this->tagWithClass($html, 'thallo-block-button');
        $control = $this->tagWithClass($html, 'thallo-block-button__link');
        self::assertStringContainsString(' t-content-center', $root);
        self::assertStringContainsString(' mine"', $root);
        self::assertStringNotContainsString('t-radius-full', $root);
        self::assertStringContainsString(' t-radius-full t-bg-accent"', $control);
        self::assertStringContainsString(' aria-label="Go now"', $control);
        self::assertStringNotContainsString('aria-label', $root);
    }

    public function testAHeroMediaTargetIsDormantWithoutMediaAndLiveWithAnAside(): void
    {
        $settings = ['style' => ['radius' => ['type' => 'token', 'value' => 'radius.lg']]];
        $without = $this->render([
            ['id' => 'h', 'type' => 'hero', 'data' => ['title' => 'T'], 'settings' => $settings],
        ]);
        self::assertStringNotContainsString('t-radius-lg', $without, 'an absent optional target emits nothing');

        $with = $this->render([['id' => 'h', 'type' => 'hero', 'settings' => $settings, 'data' => [
            'title' => 'T',
            'aside' => [['id' => 'i', 'type' => 'rich_text', 'data' => ['body' => '<p>x</p>']]],
        ]]]);
        self::assertStringContainsString(' t-radius-lg"', $this->tagWithClass($with, 'thallo-block-hero__media'));
        self::assertStringNotContainsString('t-radius-lg', $this->tagWithClass($with, 'thallo-block-hero'));
    }

    public function testVideoCornersLandOnTheFrameAndCtaColoursOnItsPanel(): void
    {
        // Corners and shadow are painted on the video's frame (iframe or player), never its root.
        $video = $this->render([['id' => 'v', 'type' => 'video', 'data' => [
            'source' => 'embed', 'url' => 'https://www.youtube.com/watch?v=abcdefghijk',
        ], 'settings' => ['style' => [
            'radius' => ['type' => 'token', 'value' => 'radius.none'],
            'shadow' => ['base' => ['type' => 'token', 'value' => 'shadow.none']],
        ]]]]);
        $frame = $this->tagWithClass($video, 'thallo-block-video__frame');
        self::assertStringContainsString(' t-radius-none', $frame);
        self::assertStringContainsString(' t-shadow-none', $frame);
        self::assertStringNotContainsString('t-radius-none', $this->tagWithClass($video, 'thallo-block-video'));

        // The cta's variants paint its inner box: colours, border, corners and shadow land there,
        // spacing stays on the band.
        $cta = $this->render([['id' => 'c', 'type' => 'cta', 'data' => ['title' => 'Go', 'variant' => 'outline'],
            'settings' => ['style' => [
                'colors' => ['surface' => ['type' => 'token', 'value' => 'color.accent']],
                'radius' => ['type' => 'token', 'value' => 'radius.none'],
                'spacing' => ['padding' => ['top' => ['base' => ['type' => 'token', 'value' => 'spacing.xl']]]],
            ]]]]);
        $panel = $this->tagWithClass($cta, 'thallo-block-cta__inner');
        self::assertStringContainsString(' t-bg-accent', $panel);
        self::assertStringContainsString(' t-radius-none', $panel);
        self::assertStringNotContainsString('t-pt-xl', $panel);
        $root = $this->tagWithClass($cta, 'thallo-block-cta');
        self::assertStringContainsString(' t-pt-xl', $root);
        self::assertStringNotContainsString('t-bg-accent', $root);
    }

    public function testTokenClassEmitsTheCompilersUtilitiesAndNothingForAnUnknownValue(): void
    {
        $env = $this->env();
        $render = static fn (string $twig): string => $env->createTemplate($twig)->render([]);
        self::assertSame(' t-fg-accent', $render("{{ token_class('colors.text', 'color.accent') }}"));
        self::assertSame(' t-weight-bold', $render("{{ token_class('typography.weight', 'bold') }}"));
        self::assertSame('', $render("{{ token_class('colors.text', 'color.nope') }}"));
        self::assertSame('', $render("{{ token_class('colors.text', null) }}"));
        self::assertSame('', $render("{{ style_classes('root') }}"), 'no block frame, no classes');
    }
}
