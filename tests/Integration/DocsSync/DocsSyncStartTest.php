<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Glueful\Queue\QueueManager;
use Thallo\Core\Content\DocsSync\DocsSyncConflict;
use Thallo\Core\Content\DocsSync\DocsSyncStore;
use Thallo\Core\Content\Jobs\DocsSyncJob;
use Thallo\Core\Tests\Support\AppTestCase;

/** Starting a run takes the site's single slot and queues its job (docs sync design §6.1). */
final class DocsSyncStartTest extends AppTestCase
{
    use DocsSyncTestHelpers;

    protected function tearDown(): void
    {
        $this->cleanDocsSync();
        $this->connection()->table('queue_jobs')->where('queue', '=', 'import-export')->delete();
        parent::tearDown();
    }

    public function testOneStartTakesTheSlotAndASecondIsRefused(): void
    {
        $this->saveSource();
        $queue = $this->recordingQueue();
        $service = $this->service($queue);

        $run = $service->start('commit', 'user00000001');

        self::assertSame('dispatched', $this->runRow($run['id'])['status']);
        self::assertSame($run['id'], $this->stateRow()['active_run']);
        self::assertCount(1, $queue->pushed);
        self::assertSame(DocsSyncJob::class, $queue->pushed[0]['job']);
        self::assertSame('import-export', $queue->pushed[0]['queue']);

        try {
            $service->start('commit', 'user00000001');
            self::fail('A second start must be refused while the first holds the slot.');
        } catch (DocsSyncConflict $e) {
            self::assertSame('running', $e->reason);
        }
        self::assertCount(1, $queue->pushed, 'the refused start queued nothing');
        self::assertCount(1, $this->connection()->table('docs_sync_runs')->whereNotNull('id')->get());
    }

    public function testAStartAfterTheSourceChangedIsRefused(): void
    {
        $this->saveSource();
        $queue = $this->recordingQueue();
        $service = $this->service($queue);
        $service->beforeNextReserve(function (): void {
            $this->container()->get(DocsSyncStore::class)->saveSource(
                \Thallo\Core\Content\DocsSync\DocsSyncSource::fromArray([
                    'repo' => 'glueful/thallo', 'folder' => 'guides', 'follow' => 'release',
                    'content_type' => 'docs', 'edit_branch' => 'main',
                ]),
            );
        });

        try {
            $service->start('commit', null);
            self::fail('A start whose source changed under it must be refused.');
        } catch (DocsSyncConflict $e) {
            self::assertSame('source_changed', $e->reason);
        }
        self::assertSame([], $queue->pushed);
        self::assertNull($this->stateRow()['active_run']);
    }

    public function testDatabaseQueueReservesAndQueuesInOneTransaction(): void
    {
        if ($this->connection()->getDriverName() === 'sqlite') {
            self::markTestSkipped('On SQLite the queue has its own handle: the push follows the commit.');
        }
        $this->saveSource();
        $service = $this->service($this->container()->get(QueueManager::class));
        $db = $this->connection();

        try {
            $db->transaction(function () use ($service): void {
                $service->start('commit', null);
                throw new \RuntimeException('the request dies before it commits');
            });
        } catch (\RuntimeException) {
        }

        self::assertCount(0, $db->table('docs_sync_runs')->whereNotNull('id')->get());
        self::assertNull($this->stateRow()['active_run']);
        self::assertCount(0, $db->table('queue_jobs')->where('queue', '=', 'import-export')->get());

        $service->start('commit', null);
        self::assertCount(1, $db->table('queue_jobs')->where('queue', '=', 'import-export')->get());
    }

    public function testWorkspaceIsCapturedOnTheRunAndThePayload(): void
    {
        $this->saveSource();
        $queue = $this->recordingQueue();

        $run = $this->service($queue)->start('dry_run', null);

        $row = $this->runRow($run['id']);
        self::assertSame('dry_run', $row['mode']);
        self::assertArrayHasKey('workspace', $queue->pushed[0]['data']);
        self::assertSame($row['workspace'], $queue->pushed[0]['data']['workspace']);
        self::assertSame($run['id'], $queue->pushed[0]['data']['run']);
    }

    public function testOnlyCommitAndDryRunAreAccepted(): void
    {
        $this->saveSource();
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->start('publish', null);
    }

    public function testNoSourceIsRefused(): void
    {
        $this->expectException(DocsSyncConflict::class);
        $this->service()->start('commit', null);
    }
}
