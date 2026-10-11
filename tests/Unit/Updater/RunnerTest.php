<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\FileLock;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updater\Runner;
use Thallo\Core\Updater\Status;

final class RunnerTest extends TestCase
{
    private string $base;
    private Paths $paths;
    private OperationStore $ops;
    private FakeHost $host;
    private string $token;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-runner-' . bin2hex(random_bytes(4));
        foreach (['storage/framework/updates', 'storage/logs', 'vendor/composer', 'public/admin', 'bootstrap'] as $d) {
            mkdir("{$this->base}/{$d}", 0777, true);
        }
        $skeleton = (string) file_get_contents(dirname(__DIR__, 3) . '/skeleton/composer.json');
        file_put_contents("{$this->base}/composer.json", $skeleton);
        file_put_contents("{$this->base}/composer.lock", '{"packages":[]}');
        file_put_contents("{$this->base}/vendor/marker", 'old');
        file_put_contents("{$this->base}/public/admin/index.html", 'old admin');
        file_put_contents("{$this->base}/storage/framework/updates/guard.installed", '100');
        // The owner confirmed the restart after the guard was installed.
        file_put_contents("{$this->base}/storage/framework/updates/guard.ready", '200');
        $this->paths = new Paths($this->base);
        $this->ops = new OperationStore($this->paths, fn (): int => $this->host->clock);
        $this->host = new FakeHost($this->base, '1.0.0-beta.101', '1.0.0-beta.102');
        [, $this->token] = $this->ops->start('1.0.0-beta.102', '1.0.0-beta.101', 'owner');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testTheHappyPathRunsEveryStepAndReopens(): void
    {
        $this->runner()->tick();
        $op = $this->ops->current();
        self::assertSame(Status::SUCCEEDED, $op['status'], (string) $op['error']);
        self::assertSame(Runner::STEPS, array_column($op['steps'], 'name'));
        self::assertSame('new', file_get_contents("{$this->base}/vendor/marker"));
        self::assertFileDoesNotExist($this->paths->closedFlag());
        self::assertNotNull($op['reopen_ready_at']);
        self::assertContains('queue:restart', array_merge(...$this->host->calls));
        self::assertDirectoryDoesNotExist($this->paths->opDir($op['id']) . '/stage');
    }

    /** @return iterable<string, array{?string}> */
    public static function unconfirmedRestarts(): iterable
    {
        yield 'never confirmed' => [null];
        yield 'confirmed before the guard was installed' => ['50'];
    }

    /** @dataProvider unconfirmedRestarts */
    public function testAQueuedOperationWithoutRestartConfirmationFailsBeforeStaging(?string $ready): void
    {
        $file = "{$this->base}/storage/framework/updates/guard.ready";
        $ready === null ? unlink($file) : file_put_contents($file, $ready);
        $this->runner()->tick(); // no old processes are visible (FakeHost's ps is empty)
        $op = $this->ops->current();
        self::assertSame(Status::FAILED, $op['status']);
        self::assertStringContainsString('confirm-restart', (string) $op['error']);
        self::assertSame(['preflight'], array_column($op['steps'], 'name'), 'nothing after preflight ran');
        $joined = implode("\n", array_map(static fn (array $c): string => implode(' ', $c), $this->host->calls));
        self::assertStringNotContainsString('update glueful/thallo-core', $joined, 'no staging');
        self::assertStringNotContainsString('thallo:update:backup --to=', $joined, 'no backup');
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testAStagingFailureNeverClosesTheSite(): void
    {
        $this->host->stageExit = 2;
        $this->runner()->tick();
        self::assertSame(Status::FAILED, $this->ops->current()['status']);
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
        self::assertNotContains('close', array_column($this->ops->current()['steps'], 'name'));
    }

    public function testABarrierTimeoutReopensWithNothingChanged(): void
    {
        $holder = (new Barrier($this->paths))->admit(); // a request that never finishes
        $this->runner()->tick();
        FileLock::release($holder);
        self::assertSame(Status::FAILED, $this->ops->current()['status']);
        self::assertStringContainsString('barrier', (string) $this->ops->current()['error']);
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testAFailedBackupChangesNothing(): void
    {
        $this->host->backupExit = 1;
        $this->runner()->tick();
        self::assertSame(Status::FAILED, $this->ops->current()['status']);
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testAProvisionFailureNeedsRecoveryEvenWithoutAMigration(): void
    {
        $this->host->onProvision = fn () => file_put_contents("{$this->base}/public/admin/index.html", 'new admin');
        $this->host->provisionExit = 1;
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->ops->current()['status']);
        self::assertFileExists($this->paths->closedFlag(), 'the site stays closed');
    }

    public function testADeathBeforeAnyRenameFailsAndReopens(): void
    {
        $this->killAt('backup.done');
        $this->runner()->tick(); // a fresh runner finds the dead run
        self::assertSame(Status::FAILED, $this->ops->current()['status']);
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testADeathMidSwapRollsBackOnlyWhenTheOwnerAsks(): void
    {
        $this->killAt('swap.2.renamed');
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->ops->current()['status']);
        $this->ops->requestRecovery($this->token, 'rollback');
        $this->runner()->tick();
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
        self::assertSame(Status::RECOVERY_REQUIRED, $this->opStatus(), 'code back; closed until the owner reopens');
        $this->ops->requestRecovery($this->token, 'reopen');
        $this->runner()->tick();
        self::assertSame(Status::RECOVERED_BY_OWNER, $this->ops->current()['status']);
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    /** @return iterable<string, array{string}> */
    public static function reopenKillPoints(): iterable
    {
        yield 'before removing the flag' => ['reopen.before-open'];
        yield 'after removing the flag' => ['reopen.after-open'];
        yield 'after releasing the barrier' => ['reopen.after-release'];
    }

    /** @dataProvider reopenKillPoints */
    public function testADeathAfterVerificationCompletesTheReopen(string $point): void
    {
        $this->killAt($point);
        $this->runner()->tick();
        $op = $this->ops->current();
        self::assertSame(Status::SUCCEEDED, $op['status']);
        self::assertSame('new', file_get_contents("{$this->base}/vendor/marker"), 'never rolled back once verified');
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testRollbackRecoveryRestoresProvisionOutputs(): void
    {
        $this->host->onProvision = fn () => file_put_contents("{$this->base}/public/admin/index.html", 'new admin');
        $this->host->provisionExit = 1;
        $this->runner()->tick();
        $this->ops->requestRecovery($this->token, 'rollback');
        $this->runner()->tick();
        self::assertSame('old admin', file_get_contents("{$this->base}/public/admin/index.html"));
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
    }

    public function testAFailedVerificationIsAFailureEvenWithAnOldSuccessInTheLog(): void
    {
        file_put_contents($this->paths->log($this->ops->current()['id']), '{"version":"1.0.0-beta.102"}' . "\n");
        $this->host->verifyExit = 1;
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->opStatus(), 'a stale log line is not this answer');
        self::assertFileExists($this->paths->closedFlag());
    }

    public function testAFailedCacheClearNeverReachesTheReopenMarker(): void
    {
        $this->host->clearExit = 1;
        $this->runner()->tick();
        $op = $this->ops->current();
        self::assertSame(Status::RECOVERY_REQUIRED, $op['status']);
        self::assertNull($op['reopen_ready_at']);
        self::assertFileExists($this->paths->closedFlag());
        $this->runner()->tick(); // and a later tick does not reopen it either
        self::assertFileExists($this->paths->closedFlag());
    }

    public function testAFailedWorkerSignalNeverReachesTheReopenMarker(): void
    {
        $this->host->restartExit = 1;
        $this->runner()->tick();
        self::assertSame(Status::FAILED, $this->opStatus(), 'the signal at close fails before anything changed');
        self::assertFileDoesNotExist($this->paths->closedFlag());
    }

    public function testAPausedTickResumingAfterCompletionAndANewStartTouchesOnlyWhatItNowOwns(): void
    {
        $first = $this->ops->current()['id'];
        $second = null;
        // Runner A pauses before taking the run lock; meanwhile runner B completes the operation and a new one starts.
        $paused = $this->runner(function (string $name) use (&$second): void {
            if ($name === 'tick.before-lock' && $second === null) {
                $this->runner()->tick();
                [$op] = $this->ops->start('1.0.0-beta.103', '1.0.0-beta.102', 'owner');
                $second = $op['id'];
            }
        });
        $paused->tick();
        $ops = $this->ops->current();
        self::assertSame($second, $ops['id'], 'the successor is current');
        self::assertNotSame($first, $ops['id']);
        // A claimed only the successor it re-read under the lock; nothing of the first was rewritten.
        self::assertContains($ops['status'], [Status::SUCCEEDED, Status::RECOVERY_REQUIRED, Status::FAILED]);
        self::assertSame('1.0.0-beta.103', $ops['target']);
    }

    public function testAStaleRunnerIsFencedOutOfTheSuccessor(): void
    {
        $this->runner()->tick();
        $done = $this->ops->current();
        [$next] = $this->ops->start('1.0.0-beta.103', '1.0.0-beta.102', 'owner');
        $this->expectException(\Thallo\Core\Updater\Fenced::class);
        $this->ops->finish($done['id'], (string) $done['owner'], Status::FAILED, 'late');
    }

    /** @requires extension posix */
    public function testNoRecoveryClaimWhileADescendantThatClosedItsLockKeepsWriting(): void
    {
        // A runner failed its descendant cleanup (recovery_required, its group recorded) and exited.
        // Its descendant closed every inherited descriptor and keeps writing: the run lock is free.
        $null = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $fixture = dirname(__DIR__, 2) . '/fixtures/updater/fail-cleanup-and-die.php';
        $p = proc_open([PHP_BINARY, $fixture, $this->base], $null, $pipes);
        for ($i = 0; $i < 100 && (proc_get_status($p)['running'] || !is_file($this->base . '/writer.pid')); $i++) {
            usleep(50_000);
        }
        proc_close($p);
        $writer = (int) file_get_contents($this->base . '/writer.pid');
        try {
            self::assertSame(Status::RECOVERY_REQUIRED, $this->ops->current()['status']);
            self::assertTrue($this->ops->requestRecovery($this->token, 'reopen'));
            $this->runner()->tick();
            self::assertSame(Status::RECOVERY_REQUIRED, $this->opStatus(), 'not claimed while the group has a member');
            self::assertSame('reopen', $this->ops->current()['recovery_request'], 'the request waits');
            self::assertFileExists($this->paths->closedFlag(), 'the site stays closed');
            $grew = filesize($this->base . '/writes');
            usleep(500_000);
            clearstatcache();
            self::assertGreaterThan($grew, filesize($this->base . '/writes'), 'the descendant really is still writing');
        } finally {
            posix_kill($writer, SIGKILL);
        }
        // Wait for the whole group to empty: the writer's short-lived children outlive it briefly.
        $pgid = (int) $this->ops->current()['pgid'];
        for ($i = 0; $i < 100 && \Thallo\Core\Updater\Liveness::groupAlive($pgid); $i++) {
            usleep(50_000);
        }
        $this->runner()->tick();
        self::assertSame(Status::RECOVERED_BY_OWNER, $this->opStatus(), 'claimed once the group is empty');
    }

    public function testASecondTickCannotClaimWhileTheFirstHoldsTheRunLock(): void
    {
        $held = FileLock::exclusive($this->paths->runLock());
        $this->runner()->tick();
        self::assertSame(Status::REQUESTED, $this->ops->current()['status']);
        FileLock::release($held);
    }

    private function opStatus(): string
    {
        return (string) $this->ops->current()['status'];
    }

    /** Runs a tick that dies at $point: the kill throws, the runner's handles are dropped. */
    private function killAt(string $point): void
    {
        try {
            $this->runner(static function (string $name) use ($point): void {
                if ($name === $point) {
                    throw new \RuntimeException('killed at ' . $name);
                }
            })->tick();
            self::fail("kill point {$point} did not fire");
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('killed at', $e->getMessage());
        }
        gc_collect_cycles(); // the dead runner's lock handles close
    }

    private function runner(?\Closure $kill = null): Runner
    {
        return new Runner(
            $this->paths,
            $this->ops,
            new Barrier($this->paths),
            $this->host,
            ['php' => PHP_BINARY, 'composer' => '/usr/local/bin/composer',
                'verify_url' => 'http://127.0.0.1/_update/verify',
                'verify_host' => 'site.test', 'keep_backups' => 3, 'barrier_timeout' => 5, 'web_settle' => 10],
            static fn (): array => [12345, null], // no process group: a dropped runner is dead at once
            $kill
        );
    }
}
