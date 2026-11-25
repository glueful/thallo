<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Glueful\Database\Connection;
use Glueful\Storage\StorageManager;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Content\DocsSync\DocsSyncRunRepository;
use Thallo\Core\Content\Jobs\DocsSyncJob;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Leases and fencing (docs sync design §6.0–§6.3): two executions never write pages for one run at
 * once, and an attempt that lost its lease changes nothing more. Page content is asserted, not only
 * the run's records.
 */
final class DocsSyncFencingTest extends AppTestCase
{
    use DocsSyncTestHelpers;
    use DocsSyncRunnerHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(DocsSetup::class)->run('docs', ['guides']);
        $this->saveSource();
        $this->repoFiles = ['p1.md' => "# P1\n", 'p2.md' => "# P2\n", 'p3.md' => "# P3\n"];
    }

    protected function tearDown(): void
    {
        $this->cleanDocsSync();
        $this->container()->get(SettingsStore::class)->forget('listing_types');
        parent::tearDown();
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

    public function testTwoDeliveriesWriteEachPageOnce(): void
    {
        $run = $this->startRun();
        $queue = new RecordingQueue();
        $first = $this->runner();
        $first->pauseAt(function (string $at, ?string $path) use ($run, $queue): void {
            if ($at === 'page' && $path === 'p1.md') {
                // A second delivery arrives while the first holds a live lease.
                $this->runner(null, $queue)->run($run);
            }
        });

        $first->run($run);

        self::assertCount(1, $queue->pushed, 'the duplicate re-queued itself for later');
        self::assertSame(60, $queue->pushed[0]['delay']);
        self::assertSame(DocsSyncJob::class, $queue->pushed[0]['job']);
        self::assertSame(['p1.md' => 'created', 'p2.md' => 'created', 'p3.md' => 'created'], $this->pageStatuses($run));

        // The re-queued delivery, later: the run is finished, it does nothing.
        $this->runner(null, $queue)->run($run);
        self::assertCount(3, $this->pageStatuses($run));
        self::assertSame(3, (int) $this->runRow($run)['created']);
    }

    public function testATakeoverWaitsForThePageInFlightAndFencesTheOldAttempt(): void
    {
        if ($this->connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('The lock-wait proof uses a second PostgreSQL backend.');
        }
        $run = $this->startRun();
        $other = $this->secondConnection();
        $blocked = null;
        $attempt = $this->runner();
        $attempt->pauseAt(function (string $at, ?string $path) use ($other, &$blocked): void {
            if ($at !== 'page') {
                return;
            }
            if ($path === 'p1.md') {
                // Another backend's takeover must wait for this page to commit.
                $pdo = $other->getPDO();
                $pdo->exec("SET lock_timeout = '300ms'");
                try {
                    $pdo->exec("UPDATE docs_sync_state SET lease_token = 'attempt-b' WHERE site = 'site'");
                    $blocked = false;
                } catch (\PDOException) {
                    $blocked = true;
                }
            }
            if ($path === 'p2.md') {
                // The takeover happens while p2 is in flight: p2 commits, p3 is fenced out.
                $this->connection()->table('docs_sync_state')->where('site', '=', 'site')
                    ->update(['lease_token' => 'attempt-b']);
            }
        });

        $attempt->run($run);

        self::assertTrue($blocked, 'the takeover waited for the page in flight');
        self::assertSame(['p1.md' => 'created', 'p2.md' => 'created'], $this->pageStatuses($run));
        self::assertFalse($this->slugExists('p3'), 'the old attempt wrote nothing after the takeover');
        self::assertSame('running', $this->runRow($run)['status'], 'and could not finish the run');
        self::assertSame('attempt-b', $this->stateRow()['lease_token']);
    }

    public function testAStaleAttemptCannotRecordOrFinish(): void
    {
        $run = $this->startRun();
        $attempt = $this->runner();
        $attempt->pauseAt(function (string $at): void {
            if ($at === 'download') {
                $this->connection()->table('docs_sync_state')->where('site', '=', 'site')
                    ->update(['lease_token' => 'successor']);
            }
        });

        $attempt->run($run);

        $row = $this->runRow($run);
        self::assertNull($row['archive_path'], 'a fenced-out attempt does not record its archive');
        self::assertSame('running', $row['status']);
        self::assertSame([], $this->pageStatuses($run));
        self::assertSame([], $this->container()->get(StorageManager::class)->disk('uploads')
            ->listContents('docs-sync/' . $run)->toArray(), 'and deleted its own file');
    }

    public function testAnAttemptKilledMidPageRollsThatPageBack(): void
    {
        $run = $this->startRun();
        $attempt = $this->runner();
        $attempt->pauseAt(function (string $at, ?string $path): void {
            if ($at === 'page' && $path === 'p2.md') {
                throw new \RuntimeException('the worker died here');
            }
        });

        try {
            $attempt->run($run);
            self::fail('The attempt dies.');
        } catch (\RuntimeException) {
        }
        self::assertSame(['p1.md' => 'created'], $this->pageStatuses($run));
        self::assertFalse($this->slugExists('p2'));

        $this->setState(['lease_at' => self::ago(200)]);
        $this->runner()->run($run);

        $row = $this->runRow($run);
        self::assertSame('success', $row['outcome']);
        self::assertSame(3, (int) $row['created'], 'the counters equal the page rows');
        self::assertCount(3, $this->pageStatuses($run));
    }

    public function testCancelDuringADownloadDoesNotWaitAndStopsAtTheNextBoundary(): void
    {
        $run = $this->startRun();
        $attempt = $this->runner();
        $attempt->pauseAt(function (string $at) use ($run): void {
            if ($at === 'download') {
                $this->setRun($run, ['cancel_requested' => true]); // no transaction is open here
            }
        });

        $attempt->run($run);

        $row = $this->runRow($run);
        self::assertSame('cancelled', $row['outcome']);
        self::assertNull($row['archive_path']);
        self::assertSame([], $this->pageStatuses($run));
        self::assertNull($this->stateRow()['active_run']);
    }

    public function testOverlappingDownloadsKeepToTheirOwnFiles(): void
    {
        $run = $this->startRun();
        $disk = $this->container()->get(StorageManager::class)->disk('uploads');
        $stale = $this->runner();
        $successorFile = null;
        $stale->pauseAt(function (string $at) use ($run, &$successorFile): void {
            if ($at !== 'download') {
                return;
            }
            // The stalled attempt's lease expires; a successor takes over and finishes.
            $this->setState(['lease_at' => self::ago(200)]);
            $successor = $this->runner();
            $successor->pauseAt(function (string $at) use ($run, &$successorFile): void {
                if ($at === 'before-finish') {
                    $successorFile = (string) $this->runRow($run)['archive_path'];
                }
            });
            $successor->run($run);
        });

        $stale->run($run);

        self::assertSame('success', $this->runRow($run)['outcome']);
        self::assertNotNull($successorFile);
        self::assertSame($successorFile, $this->runRow($run)['archive_path'], 'only the owner\'s file was recorded');
        self::assertSame([], $disk->listContents('docs-sync/' . $run)->toArray(), 'each deleted only its own');
        self::assertCount(3, $this->pageStatuses($run));
    }

    public function testAFastWorkerIsNotDowngradedByALateDispatched(): void
    {
        $run = $this->startRun();
        $this->runner()->run($run);

        $this->container()->get(DocsSyncRunRepository::class)->markDispatched($run);

        self::assertSame('finished', $this->runRow($run)['status']);
    }

    public function testEveryPushTellsTheQueueToDeliverOnce(): void
    {
        $queue = new RecordingQueue();
        $run = (string) $this->service($queue)->start('commit', null)['id'];

        self::assertSame(1, $queue->pushed[0]['data']['maxAttempts']);
        self::assertSame($run, $queue->pushed[0]['data']['run']);
    }
}
