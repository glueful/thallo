<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * CSS ownership + no-JS floor for the v1 elements (web-components spec §5):
 * aliases live with the stylesheet that owns each component; the three structural
 * elements compute to block; the toggle stays inline-compatible and hides when the
 * feature is off.
 */
final class ElementCssAliasTest extends AppTestCase
{
    private function css(string $file): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath()
            . '/packages/thallo-render/themes/default/assets/' . $file
        );
    }

    public function testBlocksCssOwnsCarouselTabsAndToggleAliases(): void
    {
        $css = $this->css('blocks.css');
        self::assertStringContainsString(':where(.thallo-block-carousel, thallo-carousel)', $css);
        self::assertStringContainsString(':where(.thallo-block-tabs, thallo-tabs)', $css);
        self::assertStringContainsString(
            '.layout--full :where(.thallo-block-tabs, thallo-tabs)',
            $css,
        );
        self::assertStringContainsString(
            '.layout--full :where(.thallo-block-carousel, thallo-carousel)',
            $css,
        );
        self::assertStringContainsString(
            ':where(.thallo-block-color_mode, thallo-color-mode-toggle)',
            $css,
        );
        self::assertStringContainsString('thallo-carousel, thallo-tabs { display: block; }', $css);
        // A bare type-selector display rule (specificity 0,0,1) would beat the
        // :where() alias (0,0,0) and break the segmented-control inline-flex
        // layout; the alias already supplies the inline-compatible display.
        self::assertStringNotContainsString('thallo-color-mode-toggle { display: inline-block;', $css);
        self::assertStringContainsString(
            'html:not([data-color-mode-enabled="true"]) thallo-color-mode-toggle { display: none; }',
            $css,
        );
        self::assertStringNotContainsString('thallo-navigation', $css);
    }

    public function testNavigationCssOwnsNavigationAliases(): void
    {
        $css = $this->css('navigation.css');
        self::assertStringContainsString(':where(.thallo-block-navigation, thallo-navigation)', $css);
        self::assertStringContainsString(
            '.site-header__inner :where(.thallo-block-navigation, thallo-navigation)',
            $css,
        );
        self::assertStringContainsString(
            ':where(.thallo-block-navigation, thallo-navigation):has(',
            $css,
        );
        self::assertStringContainsString(
            '.thallo-region :where(.thallo-block-navigation, thallo-navigation)',
            $css,
        );
        self::assertStringContainsString('thallo-navigation { display: block; }', $css);
    }
}
