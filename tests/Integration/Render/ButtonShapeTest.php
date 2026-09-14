<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

/**
 * Website plan phase 1b: a button's shape is a choice (pill, rounded, square). The pill was
 * hard-coded; `rounded` follows the theme's radius token and the site-wide radius setting
 * writes `--radius-btn`, so the default keeps reading as a pill until a site changes it.
 */
final class ButtonShapeTest extends AppTestCase
{
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    private function button(array $data): string
    {
        return $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [['id' => 'btn000000001', 'type' => 'button', 'data' => ['label' => 'Go', 'url' => '/go'] + $data]],
        ]);
    }

    public function testWithoutAShapeTheButtonFollowsTheThemeRadius(): void
    {
        // No modifier at all: the base rule reads --radius-btn, which the site-wide radius
        // setting writes. An unknown stored value degrades to the same.
        self::assertStringNotContainsString('__link--shape-', $this->button([]));
        self::assertStringNotContainsString('__link--shape-', $this->button(['shape' => 'blob']));
        self::assertStringContainsString('thallo-block-button__link--shape-pill', $this->button(['shape' => 'pill']));
    }

    public function testRoundedAndSquareAreChoices(): void
    {
        self::assertStringContainsString('thallo-block-button__link--shape-rounded', $this->button(['shape' => 'rounded']));
        self::assertStringContainsString('thallo-block-button__link--shape-square', $this->button(['shape' => 'square']));
    }

    public function testTheThemeReadsTheRadiusTokenAndStylesEveryShape(): void
    {
        $css = (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/themes/default/assets/blocks.css'
        );

        self::assertStringContainsString('border-radius: var(--radius-btn, 999px);', $css);
        self::assertStringContainsString('.thallo-block-button__link--shape-pill { border-radius: 999px; }', $css);
        self::assertStringContainsString('.thallo-block-button__link--shape-rounded { border-radius: var(--radius); }', $css);
        self::assertStringContainsString('.thallo-block-button__link--shape-square { border-radius: 2px; }', $css);
    }
}
