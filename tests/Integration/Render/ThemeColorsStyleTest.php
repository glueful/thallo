<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Psr\Log\NullLogger;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Contracts\Style\PaletteProvider;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeAppearanceSource;

final class ThemeColorsStyleTest extends AppTestCase
{
    private function ext(
        string $savedAccent,
        string $savedNeutral,
        ?\Thallo\Contracts\Style\Palette $palette = null,
    ): RenderContextExtension {
        $provider = new class ($savedAccent, $savedNeutral) implements ThemeAppearanceProvider {
            public function __construct(private string $a, private string $n)
            {
            }
            public function accent(): string
            {
                return $this->a;
            }
            public function neutral(): string
            {
                return $this->n;
            }
            public function radius(): string
            {
                return 'round';
            }
            public function font(): string
            {
                return 'sans';
            }
            public function background(): string
            {
                return 'plain';
            }
            public function fontFamilies(): array
            {
                return [];
            }
        };
        return new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            appearance: new ThemeAppearanceSource($provider, new NullLogger()),
            palettes: $palette === null ? null : new class ($palette) implements PaletteProvider {
                public function __construct(private \Thallo\Contracts\Style\Palette $p)
                {
                }
                public function palette(): \Thallo\Contracts\Style\Palette
                {
                    return $this->p;
                }
            },
        );
    }

    public function testDefaultPairEmitsEmpty(): void
    {
        $out = (string) $this->ext('blue', 'slate')->themeColorsStyle();
        self::assertSame('', $out);
    }

    public function testNonDefaultSavedPairEmitsOverride(): void
    {
        $out = (string) $this->ext('emerald', 'zinc')->themeColorsStyle();
        self::assertStringContainsString(':root{', $out);
        self::assertStringContainsString('html[data-theme="dark"]{', $out);
        self::assertStringContainsString('--accent:#047857', $out);
    }

    public function testABrandColourIsEmittedAndCanBePreviewedBeforeItIsSaved(): void
    {
        $out = (string) $this->ext('#0a7c66', 'slate')->themeColorsStyle();
        self::assertStringContainsString('--accent:#0a7c66;', $out);

        $ext = $this->ext('blue', 'slate');
        $ext->setThemeAppearanceOverride('#7C3AED', 'slate');
        self::assertStringContainsString('--accent:#7c3aed;', (string) $ext->themeColorsStyle());
        // Junk is junk whatever it looks like: never written into the stylesheet.
        $ext->setThemeAppearanceOverride('#fff;}body{display:none', 'slate');
        self::assertSame('', (string) $ext->themeColorsStyle());
    }

    public function testPreviewOverrideBeatsSaved(): void
    {
        $ext = $this->ext('rose', 'zinc');                 // saved non-default
        $ext->setThemeAppearanceOverride('blue', 'slate'); // preview = default
        self::assertSame('', (string) $ext->themeColorsStyle(), 'preview default over saved non-default emits nothing');
    }

    public function testInvalidOverrideFallsBackNotThrows(): void
    {
        $ext = $this->ext('blue', 'slate');
        $ext->setThemeAppearanceOverride('banana', 'slate');
        self::assertSame('', (string) $ext->themeColorsStyle()); // banana -> blue -> default -> empty
    }

    public function testASiteWithNoPaletteKeysEmitsByteIdenticalAppearanceCss(): void
    {
        $out = (string) $this->ext('rose', 'stone')->themeColorsStyle();
        $expected = '<style>' . \Thallo\Render\Theme\ThemeColors::css('rose', 'stone')
            . \Thallo\Render\Theme\ThemeDesign::css('round', 'sans', 'plain', 'stone') . '</style>';
        self::assertSame($expected, $out);
    }

    public function testTheSavedPaletteReachesTheStylesheet(): void
    {
        $six = [
            'bg' => '#f8f4ec', 'surface' => '#ffffff', 'surface_2' => '#efe7d8',
            'ink' => '#1b1712', 'muted' => '#6b6156', 'line' => '#e2d8c6',
        ];
        $palette = new \Thallo\Contracts\Style\Palette(
            $six,
            'stone',
            [1 => new \Thallo\Contracts\Style\BrandSlot('Gold', '#8a6a2a'), 2 => null, 3 => null],
        );
        $out = (string) $this->ext('blue', 'custom', $palette)->themeColorsStyle();
        self::assertStringContainsString('--bg:#f8f4ec;', $out);
        self::assertStringContainsString('--brand-1:#8a6a2a;', $out);
        // `custom` without stored values falls back to the default family
        self::assertSame('', (string) $this->ext('blue', 'custom')->themeColorsStyle());
    }
}
