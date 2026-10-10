<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Glueful\Database\Connection;
use Thallo\Core\Content\Palette\Normalized;
use Thallo\Core\Content\Palette\PaletteFence;
use Thallo\Core\Content\Palette\PaletteSnapshot;
use Thallo\Core\Content\Palette\PaletteState;
use Thallo\Core\Tests\Support\AppTestCase;

/** Custom palette spec §4.3; plan rulings 3 and 4. */
final class PaletteFenceTest extends AppTestCase
{
    private function fence(): PaletteFence
    {
        return $this->container()->get(PaletteFence::class);
    }

    private function bumpInItsOwnTransaction(): void
    {
        $this->container()->get(Connection::class)->transaction(function (): void {
            $state = $this->container()->get(PaletteState::class);
            $state->lock();
            $state->bump();
        });
    }

    public function testAnUnfencedWriteTakesNoLockAndNormalisesOnce(): void
    {
        $calls = 0;
        $out = $this->fence()->write(
            function (PaletteSnapshot $s) use (&$calls): Normalized {
                $calls++;
                return new Normalized(['x' => 1], false, false);
            },
            fn (array $doc): array => [$doc, $this->container()->get(PaletteState::class)->heldInThisTransaction()],
        );
        self::assertSame([['x' => 1], false], $out);
        self::assertSame(1, $calls);
    }

    public function testAFencedWriteHoldsThePaletteRowAndReNormalisesFromTheOriginalOnAMismatch(): void
    {
        $seen = [];
        $this->container()->get(PaletteState::class)->afterNextSnapshot(fn () => $this->bumpInItsOwnTransaction());
        $out = $this->fence()->write(
            function (PaletteSnapshot $s) use (&$seen): Normalized {
                $seen[] = $s->generation;
                return new Normalized(['g' => $s->generation], true, false);
            },
            fn (array $doc): array => [$doc, $this->container()->get(PaletteState::class)->heldInThisTransaction()],
        );
        self::assertSame([0, 1], $seen, 'normalised at the read generation, then again at the held one');
        self::assertSame([['g' => 1], true], $out);
    }

    public function testTakingThePaletteRowAfterAnotherLockIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->container()->get(Connection::class)->transaction(function (): void {
            // some other lock first
            $this->connection()->table('settings')->where('key', '=', 'x')->update(['value' => 'y']);
            $this->fence()->write(
                static fn (PaletteSnapshot $s): Normalized => new Normalized([], true, true),
                static fn (array $d) => null,
            );
        });
    }

    public function testWithinTakesTheRowFirstSoNestedFencedWritesAreAllowed(): void
    {
        $result = $this->fence()->within(fn () => $this->fence()->write(
            static fn (PaletteSnapshot $s): Normalized => new Normalized(['ok' => true], true, true),
            static fn (array $doc): array => $doc,
        ));
        self::assertSame(['ok' => true], $result);
    }

    public function testForceFencesAWriteWhosePayloadsNameNoBrandToken(): void
    {
        $held = $this->fence()->write(
            static fn (PaletteSnapshot $s): Normalized => new Normalized([], false, false),
            fn (array $doc): bool => $this->container()->get(PaletteState::class)->heldInThisTransaction(),
            force: true,
        );
        self::assertTrue($held);
    }
}
