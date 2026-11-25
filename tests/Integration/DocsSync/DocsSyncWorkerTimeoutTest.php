<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Glueful\Database\Connection;
use Glueful\Queue\QueueManager;
use Glueful\Storage\StorageManager;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Content\DocsSync\DocsSyncService;
use Thallo\Core\Content\Jobs\DocsSyncJob;
use Thallo\Core\Content\Jobs\DocsSyncRecoverJob;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Real `queue:work` processes against the test database (docs sync design §6.4): the queue delivers
 * a sync job once, and a delivery the worker's timeout kills is recovered by the sync itself.
 */
final class DocsSyncWorkerTimeoutTest extends AppTestCase
{
    use DocsSyncTestHelpers;
    use DocsSyncRunnerHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('The worker proofs use PostgreSQL.');
        }
        $this->clearQueue();
    }

    protected function tearDown(): void
    {
        $this->clearQueue();
        $this->cleanDocsSync();
        $this->container()->get(SettingsStore::class)->forget('listing_types');
        parent::tearDown();
    }

    private function clearQueue(): void
    {
        $this->connection()->table('queue_jobs')->where('queue', '=', 'import-export')->delete();
        $this->connection()->table('queue_failed_jobs')->where('queue', '=', 'import-export')->delete();
    }

    /** Run a real worker until the queue is empty; returns its exit code. */
    private function work(int $timeout, int $wait = 30): int
    {
        $process = proc_open(
            [PHP_BINARY, 'glueful', 'queue:work', '--queue=import-export', '--stop-when-empty',
                '--timeout=' . $timeout, '--sleep=0'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            getenv(),
        );
        self::assertIsResource($process);
        $deadline = time() + $wait;
        while (proc_get_status($process)['running'] && time() < $deadline) {
            usleep(100_000);
        }
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $status['running'] ? -1 : (int) $status['exitcode'];
    }

    private function secondConnection(): Connection
    {
        return new Connection([
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'app_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ]);
    }

    public function testTheQueueStopsDeliveringAFailingSyncJob(): void
    {
        // Every push says maxAttempts 1. The database queue counts a delivery's attempt only after
        // the worker has read the row (DatabaseQueue reserves with attempts + 1 but hands the job
        // the row it read), so a job allowed one attempt is delivered twice before it fails: a
        // framework off-by-one, reported separately. The sync does not depend on it: a duplicate
        // delivery waits behind the live lease or finds the run finished. What must hold: the
        // queue gives up, and never delivers it a third time.
        $this->container()->get(QueueManager::class)
            ->push(DocsSyncJob::class, DocsSyncJob::payload('', null), 'import-export');
        $queued = fn () => $this->connection()->table('queue_jobs')->where('queue', '=', 'import-export')->get();
        $failed = fn () => $this->connection()->table('queue_failed_jobs')->where('queue', '=', 'import-export')->get();

        for ($delivery = 1; $delivery <= 3 && $queued() !== []; $delivery++) {
            $this->connection()->table('queue_jobs')->where('queue', '=', 'import-export')
                ->update(['available_at' => gmdate('Y-m-d H:i:s', time() - 5)]);
            $this->work(10);
        }

        self::assertSame([], $queued());
        self::assertCount(1, $failed());
        self::assertStringContainsString('DocsSyncJob', (string) $failed()[0]['payload']);
        self::assertLessThanOrEqual(3, $delivery, 'delivered at most twice');
    }

    public function testADeliveryKilledByTheWorkerTimeoutIsRecoveredAndFinishes(): void
    {
        if (!function_exists('pcntl_alarm')) {
            self::markTestSkipped('The worker enforces its timeout with pcntl.');
        }
        $this->container()->get(DocsSetup::class)->run('docs', ['guides']);
        $this->saveSource();
        $this->repoFiles = [];
        for ($i = 1; $i <= 5; $i++) {
            $this->repoFiles["p{$i}.md"] = "# Page {$i}\n";
        }
        // The run's commit and archive are already recorded, so the worker needs no network.
        $run = $this->service($this->container()->get(QueueManager::class))->start('commit', null)['id'];
        $path = "docs-sync/{$run}/prepared.zip";
        $this->container()->get(StorageManager::class)->disk('uploads')->write($path, $this->archiveBytes());
        $this->setRun($run, ['ref' => 'v1.0.0', 'sha' => self::SHA, 'archive_path' => $path]);

        // Another backend holds the slot row: the worker's claim waits on it until its timeout kills it.
        $other = $this->secondConnection()->getPDO();
        $other->beginTransaction();
        $other->exec("UPDATE docs_sync_state SET touch = 'held' WHERE site = 'site'");
        $this->work(2, 15);
        $other->rollBack();

        self::assertSame([], $this->pageStatuses($run), 'the killed attempt wrote nothing');
        self::assertNotSame('finished', $this->runRow($run)['status']);

        // No card is opened: the recurring task recovers the run once its dispatch is old enough.
        $this->setRun($run, ['last_dispatch_at' => self::ago(150)]);
        $recover = new DocsSyncRecoverJob([], $this->appContext());
        $recover->useService($this->container()->get(DocsSyncService::class));
        $recover->handle();

        // Whatever came back with the recovery's delivery, one finishes the run and pages land once.
        $this->work(10);

        $row = $this->runRow($run);
        self::assertSame('finished', $row['status']);
        self::assertSame('success', $row['outcome']);
        self::assertCount(5, $this->pageStatuses($run));
        self::assertSame(5, (int) $row['created']);
        for ($i = 1; $i <= 5; $i++) {
            self::assertTrue($this->slugExists('page-' . $i) || $this->slugExists('p' . $i));
        }
    }
}
