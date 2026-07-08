<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\Theme\ThemeColors;

/** Style-block spec §4.1: scoped, partial, mode-aware token emission. */
final class ThemeColorsScopedTest extends AppTestCase
{
    public function testSkinClassEncodesBothDimensionsAndUnsetAsNone(): void
    {
        self::assertSame('thallo-skin-rose-zinc', ThemeColors::skinClass('rose', 'zinc'));
        self::assertSame('thallo-skin-rose-none', ThemeColors::skinClass('rose', null));
        self::assertSame('thallo-skin-rose-none', ThemeColors::skinClass('rose', ''));
        self::assertSame('thallo-skin-none-slate', ThemeColors::skinClass(null, 'slate'));
    }

    public function testSkinClassIsEmptyWhenNeitherResolves(): void
    {
        self::assertSame('', ThemeColors::skinClass(null, null));
        self::assertSame('', ThemeColors::skinClass('banana', 'notacolor'));
        self::assertSame('', ThemeColors::skinClass('inherit', 'inherit'));
    }

    public function testScopedCssEmitsLightAndDarkForBothDimensions(): void
    {
        $css = ThemeColors::scopedCss('rose', 'zinc', 'thallo-skin-rose-zinc');
        self::assertStringContainsString('.thallo-skin-rose-zinc{', $css);
        self::assertStringContainsString('html[data-theme="dark"] .thallo-skin-rose-zinc{', $css);
        self::assertStringContainsString('--accent:#e11d48;', $css);   // rose light
        self::assertStringContainsString('--accent:#f43f5e;', $css);   // rose dark
        self::assertStringContainsString('--bg:#ffffff;', $css);       // zinc light bg
    }

    public function testScopedCssAccentOnlyOmitsNeutralVars(): void
    {
        $css = ThemeColors::scopedCss('rose', null, 'thallo-skin-rose-none');
        self::assertStringContainsString('--accent:#e11d48;', $css);
        self::assertStringNotContainsString('--bg:', $css);
        self::assertStringNotContainsString('--surface:', $css);
    }

    public function testScopedCssNeutralOnlyOmitsAccentVars(): void
    {
        $css = ThemeColors::scopedCss(null, 'zinc', 'thallo-skin-none-zinc');
        self::assertStringContainsString('--bg:#ffffff;', $css);
        self::assertStringNotContainsString('--accent:', $css);
    }

    public function testScopedCssIsEmptyWhenNeitherResolves(): void
    {
        self::assertSame('', ThemeColors::scopedCss(null, null, 'x'));
        self::assertSame('', ThemeColors::scopedCss('banana', 'notacolor', 'x'));
    }
}
