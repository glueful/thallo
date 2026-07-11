<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Retrofit\RetrofitProgress;
use Thallo\Tenancy\System\SystemFlags;

final class RetrofitProgressTest extends AppTestCase
{
    private function progress(): RetrofitProgress
    {
        return new RetrofitProgress($this->flags());
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    public function testPhaseOfIsNullWhenNothingRecorded(): void
    {
        self::assertNull($this->progress()->phaseOf('content_types'));
    }

    public function testMarkThenPhaseOfRoundTrip(): void
    {
        $p = $this->progress();
        $p->mark('content_types', RetrofitProgress::BACKFILLED);
        self::assertSame(RetrofitProgress::BACKFILLED, $p->phaseOf('content_types'));
    }

    public function testReachedAtAboveAndBelowRank(): void
    {
        $p = $this->progress();
        $p->mark('content_types', RetrofitProgress::NOT_NULL);

        // at the recorded phase
        self::assertTrue($p->reached('content_types', RetrofitProgress::NOT_NULL));
        // below the recorded phase (earlier in the ladder)
        self::assertTrue($p->reached('content_types', RetrofitProgress::COLUMN_ADDED));
        self::assertTrue($p->reached('content_types', RetrofitProgress::BACKFILLED));
        // above the recorded phase (later in the ladder)
        self::assertFalse($p->reached('content_types', RetrofitProgress::WIDENED_UNIQUE_ADDED));
        self::assertFalse($p->reached('content_types', RetrofitProgress::REBUILT));
    }

    public function testReachedIsFalseForUnrecordedTable(): void
    {
        self::assertFalse($this->progress()->reached('content_types', RetrofitProgress::COLUMN_ADDED));
    }

    public function testMarkIsMonotonicAndNeverDowngrades(): void
    {
        $p = $this->progress();
        $p->mark('content_types', RetrofitProgress::WIDENED_UNIQUE_ADDED);
        // a later mark at a LOWER rank must not move the table backward
        $p->mark('content_types', RetrofitProgress::COLUMN_ADDED);
        self::assertSame(RetrofitProgress::WIDENED_UNIQUE_ADDED, $p->phaseOf('content_types'));

        // a mark at a HIGHER rank still advances
        $p->mark('content_types', RetrofitProgress::REBUILD_CREATED);
        self::assertSame(RetrofitProgress::REBUILD_CREATED, $p->phaseOf('content_types'));
    }

    public function testReMarkingSamePhaseIsIdempotent(): void
    {
        $p = $this->progress();
        $p->mark('regions', RetrofitProgress::REBUILT);
        $p->mark('regions', RetrofitProgress::REBUILT);
        self::assertSame(RetrofitProgress::REBUILT, $p->phaseOf('regions'));
    }

    public function testResetClearsATableEntry(): void
    {
        $p = $this->progress();
        $p->mark('content_types', RetrofitProgress::BACKFILLED);
        $p->mark('regions', RetrofitProgress::REBUILT);

        $p->reset('content_types');

        self::assertNull($p->phaseOf('content_types'));
        // sibling entries are untouched
        self::assertSame(RetrofitProgress::REBUILT, $p->phaseOf('regions'));
    }

    public function testResetOnUnknownTableIsNoOp(): void
    {
        $p = $this->progress();
        $p->reset('content_types');
        self::assertNull($p->phaseOf('content_types'));
    }

    public function testSnapshotWithMultipleTables(): void
    {
        $p = $this->progress();
        $p->mark('content_types', RetrofitProgress::WIDENED_UNIQUE_ADDED);
        $p->mark('regions', RetrofitProgress::REBUILD_SWAPPED);
        $p->mark('entry_redirects', RetrofitProgress::REBUILT);

        self::assertSame([
            'content_types' => RetrofitProgress::WIDENED_UNIQUE_ADDED,
            'regions' => RetrofitProgress::REBUILD_SWAPPED,
            'entry_redirects' => RetrofitProgress::REBUILT,
        ], $p->snapshot());
    }

    public function testSnapshotIsEmptyByDefault(): void
    {
        self::assertSame([], $this->progress()->snapshot());
    }

    public function testProgressPersistsAcrossFreshInstances(): void
    {
        $this->progress()->mark('content_types', RetrofitProgress::NARROW_UNIQUE_DROPPED);

        // A fresh RetrofitProgress reading the same SystemFlags sees the prior mark.
        $fresh = new RetrofitProgress($this->flags());
        self::assertSame(RetrofitProgress::NARROW_UNIQUE_DROPPED, $fresh->phaseOf('content_types'));
        self::assertTrue($fresh->reached('content_types', RetrofitProgress::COLUMN_ADDED));
    }

    public function testResolvesFromContainer(): void
    {
        $p = $this->container()->get(RetrofitProgress::class);
        self::assertInstanceOf(RetrofitProgress::class, $p);
    }
}
