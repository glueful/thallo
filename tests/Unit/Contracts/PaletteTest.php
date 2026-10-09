<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;

/** Custom palette spec §2, §3.2: slot tokens, availability and the palette's fingerprint. */
final class PaletteTest extends TestCase
{
    public function testSlotOfReadsBrandAndContrastTokens(): void
    {
        self::assertSame(1, Palette::slotOf('color.brand-1'));
        self::assertSame(3, Palette::slotOf('color.brand-3-contrast'));
        self::assertNull(Palette::slotOf('color.accent'));
        self::assertNull(Palette::slotOf('color.brand-4'));
        self::assertTrue(Palette::isContrastToken('color.brand-2-contrast'));
        self::assertFalse(Palette::isContrastToken('color.brand-2'));
    }

    public function testAnUnconfiguredSlotIsUnavailableAndAConfiguredOneIsNot(): void
    {
        $p = new Palette(brands: [1 => new BrandSlot('Gold dark', '#8a6a2a'), 2 => null, 3 => null]);
        self::assertFalse($p->isUnavailable('color.brand-1'));
        self::assertFalse($p->isUnavailable('color.brand-1-contrast'));
        self::assertTrue($p->isUnavailable('color.brand-2'));
        self::assertTrue($p->isUnavailable('color.brand-2-contrast'));
        self::assertFalse($p->isUnavailable('color.accent'));
    }

    public function testAnEmptyPaletteHasNoFingerprint(): void
    {
        self::assertTrue(Palette::empty()->isEmpty());
        self::assertSame('', Palette::empty()->fingerprint());
        $p = new Palette(darkBase: 'stone');
        self::assertFalse($p->isEmpty());
        self::assertSame(40, strlen($p->fingerprint()));
        self::assertNotSame($p->fingerprint(), (new Palette(darkBase: 'zinc'))->fingerprint());
    }
}
