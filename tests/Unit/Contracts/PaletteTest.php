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
        self::assertNull(Palette::slotOf('color.brand-10000'));
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

    public function testAnyIdUpTo9999IsABrandSlot(): void
    {
        self::assertSame(12, Palette::slotOf('color.brand-12'));
        self::assertSame(9999, Palette::slotOf('color.brand-9999-contrast'));
        self::assertNull(Palette::slotOf('color.brand-0'));
        self::assertNull(Palette::slotOf('color.brand-10000'));
        self::assertNull(Palette::slotOf('color.brand-01'));
        self::assertTrue(Palette::isContrastToken('color.brand-12-contrast'));
    }

    public function testColoursKeepTheAuthorsOrderAndRemovedIdsKeepTheirNames(): void
    {
        $p = new Palette(
            null,
            null,
            [7 => new BrandSlot('Rose', '#c98a8a'), 2 => new BrandSlot('Gold', '#8a6a2a')],
            [3 => 'Teal'],
        );
        self::assertSame([7, 2], $p->ids());
        self::assertSame('Rose', $p->labelOf(7));
        self::assertSame('Teal', $p->labelOf(3));
        self::assertSame('Brand 9', $p->labelOf(9));
        self::assertTrue($p->isRemoved(3));
        self::assertFalse($p->isRemoved(7));
        self::assertSame(7, $p->highestIssued());
        self::assertTrue($p->isUnavailable('color.brand-3'));
        self::assertFalse($p->isUnavailable('color.brand-7-contrast'));
    }

    public function testLimitZeroConfiguresNothingButKeepsTheStoredColours(): void
    {
        $p = new Palette(null, null, [1 => new BrandSlot('Gold', '#8a6a2a')], [], 0);
        self::assertSame([], $p->configured());
        self::assertFalse($p->isConfigured(1));
        self::assertTrue($p->isUnavailable('color.brand-1'));
        self::assertSame([1 => 'Gold'], array_map(static fn (BrandSlot $b): string => $b->name, $p->brands));
        self::assertTrue($p->isEmpty());
        self::assertSame('', $p->fingerprint());
    }

    public function testColoursAboveALoweredLimitStillApply(): void
    {
        // lowering the limit removes nothing (§2.3): the four made under a higher limit keep rendering
        $four = [];
        foreach ([1, 2, 3, 4] as $id) {
            $four[$id] = new BrandSlot("C{$id}", '#123456');
        }
        self::assertSame([1, 2, 3, 4], (new Palette(null, null, $four, [], 3))->ids());
    }

    public function testTheFingerprintFollowsOrderAndValuesButNotRemovedNames(): void
    {
        $a = new Palette(null, null, [1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')]);
        $b = new Palette(null, null, [2 => new BrandSlot('Rose', '#c98a8a'), 1 => new BrandSlot('Gold', '#8a6a2a')]);
        $c = new Palette(
            null,
            null,
            [1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')],
            [3 => 'Teal'],
        );
        self::assertNotSame($a->fingerprint(), $b->fingerprint());
        self::assertSame($a->fingerprint(), $c->fingerprint());
        self::assertSame('', Palette::empty()->fingerprint());
    }
}
