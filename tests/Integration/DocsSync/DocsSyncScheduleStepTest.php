<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Thallo\Core\Content\DocsSync\DocsSyncScheduleStep;
use Thallo\Core\Content\Jobs\DocsSyncPruneJob;
use Thallo\Core\Content\Jobs\DocsSyncRecoverJob;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Provision registers the recurring recovery and pruning in the scheduler's table, which reaches
 * existing installs: a config/schedule.php entry would reach new ones only.
 */
final class DocsSyncScheduleStepTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->connection()->table('scheduled_jobs')
            ->whereIn('name', ['docs_sync_recover', 'docs_sync_prune'])
            ->delete();
        parent::tearDown();
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $name): array
    {
        return $this->connection()->table('scheduled_jobs')->where('name', '=', $name)->get();
    }

    public function testProvisionRegistersBothTasksOnce(): void
    {
        $step = $this->container()->get(DocsSyncScheduleStep::class);

        $first = $step->run();
        self::assertSame(['docs_sync_recover', 'docs_sync_prune'], $first['registered']);
        self::assertNull($first['skipped']);
        $recover = $this->rows('docs_sync_recover');
        self::assertCount(1, $recover);
        self::assertSame('*/5 * * * *', $recover[0]['schedule']);
        self::assertSame(DocsSyncRecoverJob::class, $recover[0]['handler_class']);
        self::assertSame(DocsSyncPruneJob::class, $this->rows('docs_sync_prune')[0]['handler_class']);

        // An operator turned recovery off: provision again keeps it off and makes no second row.
        $this->connection()->table('scheduled_jobs')->where('name', '=', 'docs_sync_recover')
            ->update(['is_enabled' => 0]);
        $step->run();
        $again = $this->rows('docs_sync_recover');
        self::assertCount(1, $again);
        self::assertSame($recover[0]['uuid'], $again[0]['uuid']);
        self::assertFalse((bool) $again[0]['is_enabled']);
    }

    public function testWithoutTheSchedulerStoreItWarnsAndRegistersNothing(): void
    {
        $step = new DocsSyncScheduleStep($this->appContext(), schedulerStore: false);

        $result = $step->run();

        self::assertSame([], $result['registered']);
        self::assertStringContainsString('SCHEDULE_DATABASE_STORE', (string) $result['skipped']);
        self::assertSame([], $this->rows('docs_sync_recover'));
    }
}
