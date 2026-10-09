<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Glueful\Database\Connection;
use Thallo\Core\Content\Palette\PaletteJobRepository;
use Thallo\Core\Content\Palette\PaletteState;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;

/** Custom palette spec §4.3: one lockable row per workspace; jobs and reservations under it. */
final class PaletteStateTest extends AppTestCase
{
    private function state(): PaletteState
    {
        return $this->container()->get(PaletteState::class);
    }

    public function testTheRowIsCreatedLazilyAndStartsAtGenerationZero(): void
    {
        self::assertSame(0, $this->state()->snapshot()->generation);
        self::assertSame(1, $this->connection()->table('palette_state')->count());
    }

    public function testLockReadsAndBumpIncrementsInsideOneTransaction(): void
    {
        $db = $this->container()->get(Connection::class);
        $g = $db->transaction(function (): int {
            $held = $this->state()->lock();
            self::assertTrue($this->state()->heldInThisTransaction());
            return $this->state()->bump();
        });
        self::assertSame(1, $g);
        self::assertFalse($this->state()->heldInThisTransaction(), 'released at commit');
        self::assertSame(1, $this->state()->snapshot()->generation);
    }

    public function testLockOutsideATransactionIsALogicError(): void
    {
        $this->expectException(\LogicException::class);
        $this->state()->lock();
    }

    public function testTheSnapshotCarriesThePaletteAndActiveJobsWithTheirReservations(): void
    {
        $this->container()->get(GeneralSettings::class)->save([
            'theme_brand_1' => '{"name":"Gold","hex":"#8a6a2a"}',
            'theme_brand_2' => '{"name":"Rose","hex":"#c98a8a"}',
        ]);
        $db = $this->container()->get(Connection::class);
        $id = $db->transaction(function (): string {
            $this->state()->lock();
            return $this->container()->get(PaletteJobRepository::class)
                ->start(1, 'color.brand-2', 'color.brand-2-contrast', 'user00000001', null);
        });
        $snap = $this->state()->snapshot();
        self::assertSame($id, $snap->jobReplacing(1)?->id);
        self::assertSame([2], $snap->reservedSlots());
        $this->container()->get(Connection::class)->transaction(function () use ($id): void {
            $this->state()->lock();
            self::assertTrue($this->container()->get(PaletteJobRepository::class)->transition($id, 'cancelled'));
        });
        self::assertSame([], $this->state()->snapshot()->reservedSlots());
    }

    public function testCompletedAndCancelledAreTerminalAndTransitionsNeedTheLock(): void
    {
        $db = $this->container()->get(Connection::class);
        $jobs = $this->container()->get(PaletteJobRepository::class);
        $id = $db->transaction(function () use ($jobs): string {
            $this->state()->lock();
            return $jobs->start(1, 'color.accent', 'color.accent-contrast', null, null);
        });
        $db->transaction(function () use ($jobs, $id): void {
            $this->state()->lock();
            self::assertTrue($jobs->transition($id, 'completed'));
            self::assertFalse($jobs->transition($id, 'failed'), 'completed is terminal');
            self::assertFalse($jobs->transition($id, 'running'));
            self::assertFalse($jobs->transition($id, 'cancelled'));
        });
        self::assertSame('completed', $jobs->find($id)?->status);
        $this->expectException(\LogicException::class);
        $jobs->transition($id, 'cancelled');
    }
}
