<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Palette\Http\PaletteController;
use Thallo\Core\Content\Palette\PaletteHistoryExpired;
use Thallo\Core\Content\Palette\PaletteHistoryPruner;
use Thallo\Core\Content\Palette\PaletteReplacements;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;

/**
 * Replacement records (custom palette spec §5.3; plan Task 12): only completed jobs, in completion
 * order, a reused slot's two replacements kept apart, a batch complete over its range or expired —
 * never truncated by a prune.
 */
final class PaletteReplacementsTest extends AppTestCase
{
    use PaletteFixtures;

    private function replacements(): PaletteReplacements
    {
        return $this->container()->get(PaletteReplacements::class);
    }

    private function now(): int
    {
        return $this->state()->snapshot()->generation;
    }

    /** Every finished job finished this many days ago. */
    private function ageJobs(int $days): void
    {
        $this->connection()->table('palette_jobs')->whereIn('status', ['completed', 'cancelled'])
            ->update(['finished_at' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
    }

    private static function range(int $after, int $through): Request
    {
        return Request::create('/?after=' . $after . '&through=' . $through);
    }

    public function testRecordsAreCompletedJobsInCompletionOrderWithSlotReuseKeptApart(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $g0 = $this->now();
        $this->replaceAndClear(1, 'color.brand-2');               // record A: brand-1 → brand-2
        $this->replaceAndClear(2, 'color.accent');                // record B: brand-2 → accent
        $this->configure(1, 'Ink', '#111111');                    // slot 1 reused
        $this->replaceAndClear(1, 'color.surface', 'color.text'); // record C: brand-1 → surface
        $this->configure(1, 'Moss', '#3a5a3a');
        $running = $this->startJob(1, 'color.accent', 'color.accent-contrast');
        $records = $this->replacements()->batch($g0, $this->now())['records'];
        self::assertCount(3, $records, 'a running job is not a record');
        self::assertSame([
            ['color.brand-1' => 'color.brand-2', 'color.brand-1-contrast' => 'color.brand-2-contrast'],
            ['color.brand-2' => 'color.accent', 'color.brand-2-contrast' => 'color.accent-contrast'],
            ['color.brand-1' => 'color.surface', 'color.brand-1-contrast' => 'color.text'],
        ], array_column($records, 'map'));
        self::assertTrue($records[0]['completed_generation'] < $records[1]['completed_generation']
            && $records[1]['completed_generation'] < $records[2]['completed_generation']);
        $afterB = $this->replacements()->batch($records[1]['completed_generation'], $this->now());
        self::assertSame([$records[2]], $afterB['records']);
        $throughA = $this->replacements()->batch($g0, $records[0]['completed_generation']);
        self::assertSame([$records[0]], $throughA['records'], 'bounded above');
        $this->cancelJob($running);
        $all = $this->replacements()->batch($g0, $this->now());
        self::assertCount(3, $all['records'], 'a cancelled job never becomes a record');
    }

    public function testPruningRaisesTheHorizonAndARangeBelowItIsExpired(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $g0 = $this->now();
        $this->replaceAndClear(1, 'color.accent');
        $this->ageJobs(days: 91);
        self::assertSame(1, $this->container()->get(PaletteHistoryPruner::class)->prune());
        self::assertGreaterThan($g0, $this->replacements()->horizon());
        $this->expectException(PaletteHistoryExpired::class);
        $this->replacements()->batch($g0, $this->now());
    }

    public function testPruningKeepsRecentJobsAndNeverLowersTheHorizon(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->replaceAndClear(1, 'color.accent');
        $this->connection()->table('palette_state')->where('site', '=', 'site')->update(['history_horizon' => 999]);
        self::assertSame(0, $this->container()->get(PaletteHistoryPruner::class)->prune(), 'finished today: kept');
        $this->ageJobs(days: 91);
        $this->container()->get(PaletteHistoryPruner::class)->prune();
        self::assertSame(999, $this->replacements()->horizon());
    }

    /**
     * Pause point 1: a prune commits between the records read and the horizon read. In-process stand-in
     * until Task 14's actor harness runs the prune in its own process and connection.
     */
    #[Group('palette-two-process')]
    public function testABatchReadRacingAPruneIsCompleteOrExpiredNeverTruncated(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $g0 = $this->now();
        $this->replaceAndClear(1, 'color.accent');
        $this->ageJobs(days: 91);
        $replacements = $this->replacements();
        $replacements->afterRecordsRead(fn () => $this->container()->get(PaletteHistoryPruner::class)->prune());
        try {
            $batch = $replacements->batch($g0, $this->now());
            self::assertCount(1, $batch['records'], 'complete if not expired');
        } catch (PaletteHistoryExpired) {
            $left = $this->connection()->table('palette_jobs')->count();
            self::assertSame(0, $left, 'expired because the prune committed');
        }
    }

    /** Pause point 2: the prune commits before the records read. */
    #[Group('palette-two-process')]
    public function testABatchReadStartingAfterAPruneCommittedIsExpired(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $g0 = $this->now();
        $this->replaceAndClear(1, 'color.accent');
        $this->ageJobs(days: 91);
        $this->container()->get(PaletteHistoryPruner::class)->prune();
        $this->expectException(PaletteHistoryExpired::class);
        $this->replacements()->batch($g0, $this->now());
    }

    public function testTheSchemaBatchStartsAtTheHorizonOrTheLastOldCompletion(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $this->replaceAndClear(1, 'color.accent');
        $this->ageJobs(days: 91);                                 // older than the schema's window, not pruned
        $old = $this->replacements()->batch(0, $this->now())['records'][0]['completed_generation'];
        $this->replaceAndClear(2, 'color.muted', null);
        $batch = $this->replacements()->forSchema($this->now());
        self::assertSame([$old, $this->now()], [$batch['after'], $batch['through']]);
        self::assertSame(['color.brand-2' => 'color.muted'], $batch['records'][0]['map']);
        self::assertCount(1, $batch['records']);
    }

    public function testTheReplacementsEndpointReturnsABatch(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $g0 = $this->now();
        $this->replaceAndClear(1, 'color.accent');
        $res = $this->container()->get(PaletteController::class)->replacements(self::range($g0, $this->now()));
        self::assertSame(200, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true)['data']['replacements'];
        self::assertSame([$g0, $this->now()], [$data['after'], $data['through']]);
        self::assertCount(1, $data['records']);
    }

    public function testTheReplacementsEndpointRefusesAMalformedRange(): void
    {
        $controller = $this->container()->get(PaletteController::class);
        self::assertSame(422, $controller->replacements(Request::create('/?after=x&through=3'))->getStatusCode());
        self::assertSame(422, $controller->replacements(self::range(5, 3))->getStatusCode());
    }

    public function testTheReplacementsEndpointReturns410ForAnExpiredRange(): void
    {
        $this->state()->snapshot(); // the row exists
        $this->connection()->table('palette_state')->where('site', '=', 'site')->update(['history_horizon' => 50]);
        $res = $this->container()->get(PaletteController::class)->replacements(self::range(10, 60));
        self::assertSame(410, $res->getStatusCode());
        self::assertStringContainsString('PALETTE_HISTORY_EXPIRED', (string) $res->getContent());
    }

    public function testAMappinglessContrastIsOmittedFromTheRecord(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->replaceAndClear(1, 'color.surface', null);
        $records = $this->replacements()->batch(0, $this->now())['records'];
        self::assertSame(['color.brand-1' => 'color.surface'], $records[0]['map']);
    }
}
