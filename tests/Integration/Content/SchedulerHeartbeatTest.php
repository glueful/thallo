<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Core\Content\Scheduling\ScheduleRunner;
use Thallo\Core\Content\Scheduling\SchedulerHeartbeat;
use Thallo\Core\Http\Controllers\HealthAdminController;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Every scheduled job depends on a cron entry ticking the scheduler, and a missing entry is
 * invisible until someone notices publishing never happened. The scheduled-publishing runner
 * (every minute, via the scheduler) leaves a heartbeat in the system flags, and the Health page
 * reports it: ok while recent, a warning with the exact cron line when stale or absent.
 */
final class SchedulerHeartbeatTest extends AppTestCase
{
    private function heartbeat(): SchedulerHeartbeat
    {
        return new SchedulerHeartbeat($this->container()->get(SystemChannel::class));
    }

    public function testNoTickYetIsAWarningThatNamesTheCronLine(): void
    {
        $check = $this->heartbeat()->check();

        self::assertSame('warning', $check['status']);
        self::assertStringContainsString('never', $check['message']);
        self::assertStringContainsString('queue:scheduler run', implode(' ', $check['recommendations']));
    }

    public function testARecentTickIsOk(): void
    {
        $this->heartbeat()->beat();

        $check = $this->heartbeat()->check();

        self::assertSame('ok', $check['status']);
        self::assertArrayNotHasKey('recommendations', $check);
    }

    public function testAStaleTickIsAWarning(): void
    {
        $this->container()->get(SystemChannel::class)
            ->put(SchedulerHeartbeat::KEY, date(DATE_ATOM, time() - 3600));

        $check = $this->heartbeat()->check();

        self::assertSame('warning', $check['status']);
        self::assertStringContainsString('queue:scheduler run', implode(' ', $check['recommendations']));
    }

    public function testTheScheduledPublishingRunnerLeavesTheHeartbeat(): void
    {
        $this->container()->get(ScheduleRunner::class)->run();

        self::assertNotNull($this->container()->get(SystemChannel::class)->get(SchedulerHeartbeat::KEY));
    }

    public function testTheHealthReportCarriesTheSchedulerCheck(): void
    {
        $body = json_decode((string) $this->container()->get(HealthAdminController::class)->show()->getContent(), true);
        $names = array_column($body['data']['health']['checks'], 'name');

        self::assertContains('scheduler', $names);
    }
}
