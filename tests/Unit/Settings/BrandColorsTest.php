<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Settings\BrandColors;
use Thallo\Core\Settings\PaletteSettings;

/** The stored brand colour list (custom palette spec §2, §2.3): parsed leniently, encoded canonically. */
final class BrandColorsTest extends TestCase
{
    public function testItRoundTripsColoursInOrderRemovedNamesAndTheRevision(): void
    {
        $json = BrandColors::encode(
            [7 => new BrandSlot('Rose', '#c98a8a'), 2 => new BrandSlot('Gold', '#8a6a2a')],
            [3 => 'Teal'],
            5,
        );
        self::assertSame(
            '{"revision":5,"colors":[{"id":7,"name":"Rose","hex":"#c98a8a"},{"id":2,"name":"Gold","hex":"#8a6a2a"}],'
            . '"removed":[{"id":3,"name":"Teal"}]}',
            $json,
        );
        [$colors, $removed, $revision] = BrandColors::parse($json);
        self::assertSame([7, 2], array_keys($colors));
        self::assertSame([3 => 'Teal'], $removed);
        self::assertSame(5, $revision);
        self::assertSame(0, BrandColors::parse('{"colors":[]}')[2]);
    }

    public function testAnEntryThatNoLongerParsesReadsAsUnsetAndTheRestStay(): void
    {
        [$colors, $removed] = BrandColors::parse(
            '{"colors":[{"id":1,"name":"Gold","hex":"#8a6a2a"},{"id":0,"name":"Bad","hex":"#000"},'
            . '{"id":2,"name":"","hex":"#123456"},{"id":4,"name":"Rose","hex":"red"},'
            . '{"id":1,"name":"Dup","hex":"#111111"}],'
            . '"removed":[{"id":1,"name":"Shadowed"},{"id":5,"name":"Teal"},{"id":"x","name":"No"}]}',
        );
        self::assertSame([1], array_keys($colors));
        self::assertSame('Gold', $colors[1]->name);
        self::assertSame([5 => 'Teal'], $removed);
        self::assertSame([[], [], 0], BrandColors::parse('not json'));
        self::assertSame([[], [], 0], BrandColors::parse(''));
    }

    public function testClearingMovesTheColourToRemovedKeepingItsNameAndBumpsTheRevision(): void
    {
        $stored = BrandColors::encode(
            [1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')],
            [],
            4,
        );
        [$colors, $removed, $revision] = BrandColors::parse(BrandColors::cleared($stored, 1));
        self::assertSame([2], array_keys($colors));
        self::assertSame([1 => 'Gold'], $removed);
        self::assertSame(5, $revision);
        self::assertSame($stored, BrandColors::cleared($stored, 9)); // not a colour: nothing written changes
    }

    public function testTheLimitIsClampedAndJunkReadsAsTheDefault(): void
    {
        self::assertSame(3, PaletteSettings::limitFrom(null));
        self::assertSame(3, PaletteSettings::limitFrom('lots'));
        self::assertSame(0, PaletteSettings::limitFrom('0'));
        self::assertSame(12, PaletteSettings::limitFrom(40));
        self::assertSame(0, PaletteSettings::limitFrom(-2));
        self::assertSame(5, PaletteSettings::limitFrom('5'));
    }
}
