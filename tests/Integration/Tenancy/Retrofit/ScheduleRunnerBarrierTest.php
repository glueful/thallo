<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Content\Scheduling\ScheduleRunner;
use App\Tests\Support\RetrofitHarnessTestCase;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;

/**
 * The system scheduler is a long-running drain that mutates owned entry_schedules via raw PDO. When
 * the retrofit barrier is up it must refuse to run — the coarse assertWritable() gate at the top of
 * run() re-reads fresh persisted state, so even an already-running scheduler stops.
 */
final class ScheduleRunnerBarrierTest extends RetrofitHarnessTestCase
{
    private function guard(): RetrofitMaintenanceGuard
    {
        return $this->container()->get(RetrofitMaintenanceGuard::class);
    }

    protected function setUp(): void
    {
        // Lower any barrier a PRIOR test left up BEFORE parent::setUp() truncates owned tables.
        self::$engineApp?->getContainer()->get(RetrofitMaintenanceGuard::class)->end();
        parent::setUp();
        $this->guard()->end();
    }

    public function testSchedulerRefusesWhileBarrierActive(): void
    {
        $this->guard()->begin();
        $runner = $this->container()->get(ScheduleRunner::class);

        $this->expectException(RetrofitInProgressException::class);
        $runner->run();
    }

    public function testSchedulerRunsOnceBarrierLowered(): void
    {
        $this->guard()->begin();
        $this->guard()->end();

        $runner = $this->container()->get(ScheduleRunner::class);
        // No due rows seeded → a clean run drains nothing and returns 0 (proves no barrier throw).
        self::assertSame(0, $runner->run());
    }
}
