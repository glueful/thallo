<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Psr\Log\NullLogger;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Render\ThemeAppearanceSource;

final class ThemeAppearanceSourceTest extends AppTestCase
{
    private function provider(string $a, string $n): ThemeAppearanceProvider
    {
        return new class ($a, $n) implements ThemeAppearanceProvider {
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
    }

    public function testTheCustomNeutralPassesThroughWithoutAWarning(): void
    {
        $logged = [];
        $logger = new class ($logged) extends \Psr\Log\AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged)
            {
            }
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = (string) $message;
            }
        };
        $src = new ThemeAppearanceSource($this->provider('blue', 'custom'), $logger);
        self::assertSame('custom', $src->neutral());
        self::assertSame([], $logged);
    }

    public function testReturnsSavedPair(): void
    {
        $src = new ThemeAppearanceSource($this->provider('emerald', 'zinc'), new NullLogger());
        self::assertSame('emerald', $src->accent());
        self::assertSame('zinc', $src->neutral());
    }

    public function testUnboundProviderFallsBackToDefault(): void
    {
        $src = new ThemeAppearanceSource(null, new NullLogger());
        self::assertSame('blue', $src->accent());
        self::assertSame('slate', $src->neutral());
    }

    public function testInvalidStoredValueFallsBackToDefault(): void
    {
        $src = new ThemeAppearanceSource($this->provider('banana', 'slate'), new NullLogger());
        self::assertSame('blue', $src->accent());
        self::assertSame('slate', $src->neutral());
    }

    public function testAnEmptyPaletteLeavesTheFingerprintUnchangedAndAPaletteEntersIt(): void
    {
        $palette = \Thallo\Contracts\Style\Palette::empty();
        $make = function () use (&$palette): ThemeAppearanceSource {
            return new ThemeAppearanceSource(
                $this->provider('blue', 'slate'),
                new NullLogger(),
                paletteFingerprint: static fn (): string => $palette->fingerprint(),
            );
        };
        $before = $make()->fingerprint();
        $without = new ThemeAppearanceSource($this->provider('blue', 'slate'), new NullLogger());
        self::assertSame($without->fingerprint(), $before);
        $palette = new \Thallo\Contracts\Style\Palette(
            brands: [1 => new \Thallo\Contracts\Style\BrandSlot('Gold', '#8a6a2a'), 2 => null, 3 => null],
        );
        $tag = '-p' . substr($palette->fingerprint(), 0, 8);
        self::assertStringContainsString($tag, $make()->fingerprint());
        self::assertStringContainsString($tag, $make()->appearanceFingerprint(), 'open stages refresh too');
    }
}
