<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\Layouts\FramePresentation;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\Style\PageStyle;
use Thallo\Render\Style\RequestPalette;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * Custom palette spec §3.2 on every render path that turns a stored colour token into a class:
 * the style cascade (blocks, the page's own style, a layout's frame, a region's own style) and
 * token_class() (Animated text's three colours). A colour naming an unconfigured brand slot emits
 * nothing; a configured one emits its utility.
 */
final class PagePaletteAvailabilityTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->container()->get(GeneralSettings::class)->save(['theme_brand_1' => '{"name":"Gold","hex":"#8a6a2a"}']);
        $this->container()->get(RequestPalette::class)->refresh();
    }

    /** @return array{type: string, value: string} */
    private static function tok(string $v): array
    {
        return ['type' => 'token', 'value' => $v];
    }

    private function extension(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    private function env(): Environment
    {
        $base = $this->container()->get(ApplicationContext::class)->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->extension(),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    public function testAnimatedTextsColoursRenderOnlyForConfiguredSlots(): void
    {
        $this->extension()->resetPerRenderState();
        $html = $this->env()->createTemplate('{{ blocks(l) }}')->render(['l' => [[
            'id' => 'anim00000001', 'type' => 'animated_text',
            'data' => [
                'prefix' => 'Made', 'rotate_words' => "by hand\nwith care", 'suffix' => 'here',
                'prefix_color' => self::tok('color.brand-1'),
                'rotate_color' => self::tok('color.brand-2'),
                'suffix_color' => self::tok('color.accent'),
            ],
        ]]]);
        self::assertStringContainsString(ClassNames::for('colors.text', 'color.brand-1'), $html);
        self::assertStringNotContainsString(ClassNames::for('colors.text', 'color.brand-2'), $html);
        self::assertStringContainsString(ClassNames::for('colors.text', 'color.accent'), $html);
    }

    public function testTokenClassEmitsNothingForAnUnavailableBrandToken(): void
    {
        $render = fn (string $twig): string => $this->env()->createTemplate($twig)->render([]);
        self::assertSame('', $render("{{ token_class('colors.text', 'color.brand-2') }}"));
        self::assertSame('', $render("{{ token_class('colors.text', 'color.brand-3-contrast') }}"));
        self::assertSame(
            ' ' . ClassNames::for('colors.text', 'color.brand-1'),
            $render("{{ token_class('colors.text', 'color.brand-1') }}"),
        );
    }

    public function testThePagesFrameAndTheRegionsOwnStylesFollowTheSameRule(): void
    {
        $palette = $this->extension()->palette();
        $unset = ['colors' => ['surface' => self::tok('color.brand-2')]];
        $set = ['colors' => ['surface' => self::tok('color.brand-1')]];
        self::assertSame('', PageStyle::classes($unset, null, $palette));
        self::assertSame(ClassNames::for('colors.surface', 'color.brand-1'), PageStyle::classes($set, null, $palette));
        self::assertSame('', FramePresentation::fixed(['style' => $unset], $palette)['style_classes']);
        self::assertSame('', $this->extension()->regionStyleClasses('header', 'root', ['style' => $unset]));
        self::assertStringContainsString(
            ClassNames::for('colors.surface', 'color.brand-1'),
            $this->extension()->regionStyleClasses('header', 'root', ['style' => $set]),
        );
    }
}
