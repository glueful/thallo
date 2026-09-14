<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Psr\Log\NullLogger;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Theme\ThemeDesign;
use Thallo\Render\ThemeAppearanceSource;

/**
 * Website plan phase 1b: three site-wide design settings next to the theme colours —
 * corner radius, typeface pairing, page ground — emitted as token overrides in the same
 * inline style block as the colours. Defaults emit nothing: today's look is the default.
 */
final class ThemeDesignTest extends AppTestCase
{
    private function provider(string $radius, string $font, string $background): ThemeAppearanceProvider
    {
        return new class ($radius, $font, $background) implements ThemeAppearanceProvider {
            public function __construct(private string $r, private string $f, private string $b)
            {
            }
            public function accent(): string
            {
                return 'blue';
            }
            public function neutral(): string
            {
                return 'slate';
            }
            public function radius(): string
            {
                return $this->r;
            }
            public function font(): string
            {
                return $this->f;
            }
            public function background(): string
            {
                return $this->b;
            }
        };
    }

    public function testDefaultsEmitNothing(): void
    {
        self::assertSame('', ThemeDesign::css('round', 'sans', 'plain', 'slate'));
    }

    public function testRadiusWritesTheThreeRadiusTokens(): void
    {
        self::assertSame(
            ':root{--radius:4px;--radius-lg:8px;--radius-btn:4px}',
            ThemeDesign::css('sharp', 'sans', 'plain', 'slate'),
        );
        self::assertSame(
            ':root{--radius:12px;--radius-lg:20px;--radius-btn:8px}',
            ThemeDesign::css('soft', 'sans', 'plain', 'slate'),
        );
    }

    public function testEditorialPairsASerifDisplayWithTheSansBodyAndSerifSetsBoth(): void
    {
        $editorial = ThemeDesign::css('round', 'editorial', 'plain', 'slate');
        self::assertStringContainsString('--font-display:', $editorial);
        self::assertStringContainsString('Georgia,serif', $editorial);
        self::assertStringNotContainsString('--font-body:', $editorial);

        $serif = ThemeDesign::css('round', 'serif', 'plain', 'slate');
        self::assertStringContainsString('--font-display:', $serif);
        self::assertStringContainsString('--font-body:', $serif);
    }

    public function testTintedSwapsTheNeutralsGroundAndSurfaceInLightMode(): void
    {
        // slate: bg #ffffff, surface #f6f7f9 → the page sits on the tint, cards lift to white.
        self::assertSame(':root{--bg:#f6f7f9;--surface:#ffffff}', ThemeDesign::css('round', 'sans', 'tinted', 'slate'));
        self::assertSame(':root{--bg:#fafafa;--surface:#ffffff}', ThemeDesign::css('round', 'sans', 'tinted', 'zinc'));
    }

    public function testUnknownValuesNormalizeToNull(): void
    {
        self::assertNull(ThemeDesign::normalizeRadius('huge'));
        self::assertNull(ThemeDesign::normalizeFont('comic'));
        self::assertNull(ThemeDesign::normalizeBackground('plaid'));
        self::assertSame('sharp', ThemeDesign::normalizeRadius('sharp'));
    }

    public function testTheAppearanceSourceNormalizesDesignValuesAndFingerprintsThem(): void
    {
        $source = new ThemeAppearanceSource($this->provider('sharp', 'editorial', 'tinted'), new NullLogger());
        self::assertSame('sharp', $source->radius());
        self::assertSame('editorial', $source->font());
        self::assertSame('tinted', $source->background());
        self::assertSame('blue-slate-sharp-editorial-tinted', $source->fingerprint());

        $junk = new ThemeAppearanceSource($this->provider('huge', 'comic', 'plaid'), new NullLogger());
        self::assertSame('blue-slate-round-sans-plain', $junk->fingerprint(), 'junk falls back to the defaults');
    }
}
