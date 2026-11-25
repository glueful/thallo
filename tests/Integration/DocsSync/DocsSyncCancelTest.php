<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Glueful\Database\Connection;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Cancel and Release (docs sync design §6.5), ordered by the state row's lock: whichever commits
 * first decides, and the page in flight always finishes first.
 */
final class DocsSyncCancelTest extends AppTestCase
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

    public function testCancelDuringAPageLetsItCommitThenStops(): void
    {
        $run = $this->startRun();
        $attempt = $this->runner();
        $answer = null;
        $attempt->pauseAt(function (string $at, ?string $path) use ($run, &$answer): void {
            if ($at === 'page' && $path === 'p1.md') {
                $answer = $this->service()->cancel($run);
            }
        });

        $attempt->run($run);

        self::assertSame(['finished' => false, 'outcome' => null], $answer, 'a live attempt finishes it');
        $row = $this->runRow($run);
        self::assertSame('cancelled', $row['outcome']);
        self::assertSame(1, (int) $row['created']);
        self::assertSame(['p1.md' => 'created'], $this->pageStatuses($run), 'the page in flight committed');
        self::assertFalse($this->slugExists('p2'));
        self::assertNull($this->stateRow()['active_run']);
    }

    public function testCancelBeforeTheLastPageFinishCommitsIsCancelled(): void
    {
        $run = $this->startRun();
        $attempt = $this->runner();
        $attempt->pauseAt(function (string $at) use ($run): void {
            if ($at === 'before-finish') {
                $this->service()->cancel($run); // committed before the finish takes the row
            }
        });

        $attempt->run($run);

        self::assertSame('cancelled', $this->runRow($run)['outcome']);
        self::assertNull($this->stateRow()['synced_run'], 'a cancelled sync never becomes the last sync');
    }

    public function testFinishBeforeCancelKeepsTheSuccess(): void
    {
        if ($this->connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('The lock-wait proof uses a second PostgreSQL backend.');
        }
        $run = $this->startRun();
        $blocked = null;
        $attempt = $this->runner();
        $attempt->pauseAt(function (string $at) use (&$blocked): void {
            if ($at === 'finish') {
                // The finish holds the row: a Cancel from another backend waits for it.
                $pdo = $this->secondPdo();
                $pdo->exec("SET lock_timeout = '300ms'");
                try {
                    $pdo->exec(
                        "UPDATE docs_sync_runs SET cancel_requested = true WHERE id IN "
                        . "(SELECT active_run FROM docs_sync_state WHERE site = 'site' FOR UPDATE)",
                    );
                    $blocked = false;
                } catch (\PDOException) {
                    $blocked = true;
                }
            }
        });

        $attempt->run($run);
        $answer = $this->service()->cancel($run);

        self::assertTrue($blocked);
        self::assertSame(['finished' => true, 'outcome' => 'success'], $answer, 'Cancel says it had finished');
        self::assertSame($run, $this->stateRow()['synced_run']);
    }

    public function testCancelOfAnUnclaimedRunFinishesItAtOnce(): void
    {
        $run = $this->startRun();

        $answer = $this->service()->cancel($run);

        self::assertSame(['finished' => true, 'outcome' => 'cancelled'], $answer);
        self::assertSame('finished', $this->runRow($run)['status']);
        self::assertNull($this->stateRow()['active_run']);
    }

    public function testAJustQueuedRunCannotBeReleased(): void
    {
        // A sync waiting for its worker is not stuck: Release is for one that waited over two
        // minutes, or whose worker stopped reporting in.
        $run = $this->startRun();

        self::assertSame(['released' => false], $this->service()->release($run));
        self::assertSame($run, $this->stateRow()['active_run']);
        self::assertNotSame('finished', $this->runRow($run)['status']);
    }

    public function testARunWaitingOverTwoMinutesForAWorkerCanBeReleased(): void
    {
        $run = $this->startRun();
        $this->setRun($run, ['last_dispatch_at' => self::ago(150), 'created_at' => self::ago(150)]);

        self::assertSame(['released' => true], $this->service()->release($run));
        self::assertSame('interrupted', $this->runRow($run)['outcome']);
    }

    public function testReleaseRefusesALiveLease(): void
    {
        $run = $this->startRun();
        $this->setState(['lease_token' => 'live', 'lease_at' => self::ago(5)]);

        self::assertSame(['released' => false], $this->service()->release($run));
        self::assertSame($run, $this->stateRow()['active_run']);
    }

    public function testReleaseOfAStaleRunInterruptsItAndFencesTheOldAttempt(): void
    {
        $run = $this->startRun();
        $attempt = $this->runner();
        $answer = null;
        $attempt->pauseAt(function (string $at, ?string $path) use ($run, &$answer): void {
            if ($at === 'page' && $path === 'p2.md') {
                $this->setState(['lease_at' => self::ago(200)]); // the attempt looks stalled
                $answer = $this->service()->release($run);
            }
        });

        $attempt->run($run);

        self::assertSame(['released' => true], $answer);
        self::assertSame('interrupted', $this->runRow($run)['outcome']);
        self::assertFalse($this->slugExists('p3'), 'the old attempt was fenced out at its next page');
        self::assertNull($this->stateRow()['active_run']);
    }

    private function secondPdo(): \PDO
    {
        return (new Connection([
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
        ]))->getPDO();
    }
}
