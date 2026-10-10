<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Palette\BrandColorsRefused;
use Thallo\Core\Content\Palette\PaletteConflict;
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
        // the entries that no longer parse keep their ids reserved (an id was issued for them)
        self::assertSame([5 => 'Teal', 2 => 'Brand 2', 4 => 'Brand 4'], $removed);
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

    private static function held(array $colors, array $removed = [], int $limit = 3): Palette
    {
        return new Palette(null, null, $colors, $removed, $limit);
    }

    private static function row(?int $id, string $name, string $hex = '#123456'): array
    {
        return ['id' => $id, 'name' => $name, 'hex' => $hex];
    }

    /** Applied from a list edited at the stored revision (0), unless a test says otherwise. */
    private static function apply(
        Palette $held,
        array $rows,
        ?\Closure $replacing = null,
        int $base = 0,
        int $revision = 0,
    ): string {
        return BrandColors::applied($held, $revision, $base, $rows, $replacing ?? static fn (): bool => false);
    }

    public function testNewColoursTakeIdsAboveEveryIdEverIssued(): void
    {
        $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a')], [3 => 'Teal']);
        [$colors, $removed] = BrandColors::parse(self::apply(
            $held,
            [self::row(null, 'Rose'), self::row(1, 'Gold', '#8a6a2a'), self::row(null, 'Sky')],
        ));
        self::assertSame([4, 1, 5], array_keys($colors));
        self::assertSame([3 => 'Teal'], $removed);
    }

    public function testRenameRecolourAndReorderKeepIds(): void
    {
        $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')]);
        [$colors] = BrandColors::parse(self::apply(
            $held,
            [self::row(2, 'Blush', '#d9a0a0'), self::row(1, 'Gold', '#8a6a2a')],
        ));
        self::assertSame([2, 1], array_keys($colors));
        self::assertSame('Blush', $colors[2]->name);
    }

    public function testASaveThatOmitsAColourIsRefused(): void
    {
        $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Teal', '#0f766e')]);
        $this->expectException(BrandColorsRefused::class);
        $this->expectExceptionMessage('Remove a brand colour with Clear: Teal is missing from this save');
        self::apply($held, [self::row(1, 'Gold', '#8a6a2a')]);
    }

    public function testAnIdThatIsNotConfiguredIsRefusedByItsName(): void
    {
        $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a')], [3 => 'Teal']);
        $this->expectExceptionMessage("Teal isn't in the palette");
        self::apply($held, [self::row(1, 'Gold', '#8a6a2a'), self::row(3, 'Teal')]);
    }

    public function testAddingPastTheLimitIsRefusedButEditingAboveItIsNot(): void
    {
        $four = [];
        foreach ([1, 2, 3, 4] as $id) {
            $four[$id] = new BrandSlot("C{$id}", '#123456');
        }
        // the limit was lowered to 3 after four were made: they stay editable
        $held = self::held($four);
        $rows = array_map(static fn (int $id): array => self::row($id, "C{$id}"), [1, 2, 3, 4]);
        $rows[0]['name'] = 'Renamed';
        self::assertStringContainsString('Renamed', self::apply($held, $rows));
        // …but nothing can be added until the count is under the limit
        $this->expectException(BrandColorsRefused::class);
        $this->expectExceptionMessage('This site allows 3 brand colours');
        self::apply($held, [...$rows, self::row(null, 'New')]);
    }

    public function testTheLimitMessageSaysOneColour(): void
    {
        $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a')], [], 1);
        $this->expectExceptionMessage('This site allows 1 brand colour');
        self::apply($held, [self::row(1, 'Gold', '#8a6a2a'), self::row(null, 'New')]);
    }

    public function testLimitZeroRefusesAnySave(): void
    {
        $this->expectExceptionMessage('Brand colours are turned off on this site');
        self::apply(self::held([], [], 0), []);
    }

    public function testAColourBeingReplacedCannotChangeButMayMove(): void
    {
        $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')]);
        $replacing = static fn (int $id): bool => $id === 1;
        // moving it is a label-order change only
        self::apply($held, [self::row(2, 'Rose', '#c98a8a'), self::row(1, 'Gold', '#8a6a2a')], $replacing);
        $this->expectException(PaletteConflict::class);
        $this->expectExceptionMessage('Gold is being replaced');
        self::apply($held, [self::row(1, 'Gold', '#000000'), self::row(2, 'Rose', '#c98a8a')], $replacing);
    }

    public function testAListEditedFromAnOlderRevisionIsRefused(): void
    {
        // A renamed Gold to Amber (revision 4 → 5); B, holding revision 4, re-colours Rose and would
        // send Gold back. Whatever changed — a rename, a re-colour, a reorder, an add, a Clear — the
        // stale list is refused and nothing it carries is written.
        $held = self::held([1 => new BrandSlot('Amber', '#8a6a2a'), 2 => new BrandSlot('Rose', '#c98a8a')]);
        $this->expectException(PaletteConflict::class);
        $this->expectExceptionMessage('Brand colours changed since you opened this page');
        self::apply($held, [self::row(1, 'Gold', '#8a6a2a'), self::row(2, 'Rose', '#000000')], null, 4, 5);
    }

    public function testTheStoredValueIsAtTheNextRevision(): void
    {
        $held = self::held([1 => new BrandSlot('Gold', '#8a6a2a')]);
        self::assertSame(8, BrandColors::parse(self::apply($held, [self::row(1, 'Gold', '#8a6a2a')], null, 7, 7))[2]);
    }

    public function testSubmittedListsAreParsedStrictly(): void
    {
        self::assertSame(
            ['base' => 3, 'rows' => [
                ['id' => 2, 'name' => 'Gold', 'hex' => '#8a6a2a'],
                ['id' => null, 'name' => 'Rose', 'hex' => '#aabbcc'],
            ]],
            PaletteSettings::parseSubmitted(
                '{"base":3,"colors":[{"id":2,"name":" Gold ","hex":"#8A6A2A"},{"name":"Rose","hex":"#abc"}]}',
            ),
        );
        $bads = [
            '', 'nope', '{"base":0,"colors":"x"}', '{"base":0,"colors":[{"id":2,"name":"","hex":"#123456"}]}',
            '{"base":0,"colors":[{"id":0,"name":"A","hex":"#123456"}]}',
            '{"base":0,"colors":[{"id":2,"name":"A","hex":"#123456"},{"id":2,"name":"B","hex":"#123456"}]}',
            '{"base":0,"colors":[{"id":"2","name":"A","hex":"#123456"}]}',
            '{"colors":[]}', '{"base":-1,"colors":[]}', '{"base":"1","colors":[]}',
        ];
        foreach ($bads as $bad) {
            self::assertNull(PaletteSettings::parseSubmitted($bad), $bad);
        }
    }

    public function testAStoredEntryThatNoLongerParsesKeepsItsIdReserved(): void
    {
        // Hand-edited: it reads as unset, but its id was issued — it is never given to another colour.
        [$colors, $removed] = BrandColors::parse(
            '{"colors":[{"id":1,"name":"Gold","hex":"#8a6a2a"},{"id":5,"hex":"nope"}],"removed":[]}',
        );
        self::assertSame([1], array_keys($colors));
        self::assertSame([5 => 'Brand 5'], $removed);
        self::assertSame(5, (new Palette(null, null, $colors, $removed))->highestIssued());
    }
}
