<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/** Button corners are a radius setting on the control (visual builder spec §7.2); no shape modifier exists. */
final class ButtonShapeTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    private function button(array $settings): string
    {
        $this->container()->get(RenderContextExtension::class)->resetPerRenderState();
        return $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [[
                'id' => 'btn000000001',
                'type' => 'button',
                'data' => ['label' => 'Go', 'url' => '/go'],
                'settings' => $settings,
            ]],
        ]);
    }

    public function testWithoutARadiusSettingTheButtonFollowsTheThemeRadius(): void
    {
        $this->syncBlockStyleDeclarations();
        $plain = $this->button([]);
        self::assertStringNotContainsString('t-radius-', $plain);
        self::assertStringNotContainsString('__link--shape-', $plain);
        self::assertStringNotContainsString('style=', $plain);
    }

    public function testARadiusTokenLandsOnTheControlNotTheRoot(): void
    {
        $this->syncBlockStyleDeclarations();
        $pill = $this->button(['style' => ['radius' => ['type' => 'token', 'value' => 'radius.full']]]);
        self::assertSame(1, preg_match('~<a class="[^"]*thallo-block-button__link[^"]* t-radius-full"~', $pill));
        self::assertSame(1, preg_match('~<div class="thallo-block thallo-block-button">~', $pill));
    }

    public function testTheThemeReadsTheRadiusTokenAndShipsNoShapeModifiers(): void
    {
        $css = (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/themes/default/assets/blocks.css'
        );
        self::assertStringContainsString('border-radius: var(--radius-btn, 999px);', $css);
        self::assertStringNotContainsString('__link--shape-', $css);
        self::assertStringNotContainsString('.thallo-block-button--center', $css);
    }
}
