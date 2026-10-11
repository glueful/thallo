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
    use UpdaterSite;

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
        $this->layOutUpdaterSite($this->base);
        $this->paths = new Paths($this->base);
        $this->ops = new OperationStore($this->paths, fn (): int => $this->host->clock);
        $this->host = new FakeHost($this->base, '1.0.0-beta.101', '1.0.0-beta.102');
        $this->token = bin2hex(random_bytes(32));
        $this->ops->start('1.0.0-beta.102', '1.0.0-beta.101', 'owner', hash('sha256', $this->token));
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
        $allDone = array_fill(0, count(Runner::STEPS), 'done');
        self::assertSame($allDone, array_column($op['steps'], 'status'), 'reopen too');
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
        $file = "{$this->base}/.thallo-update/guard.ready";
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
        $this->fileRecoverRequest($this->base, 'rollback', $this->token);
        $this->runner()->tick();
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
        self::assertSame(Status::RECOVERY_REQUIRED, $this->opStatus(), 'code back; closed until the owner reopens');
        $this->fileRecoverRequest($this->base, 'reopen', $this->token);
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
        $this->fileRecoverRequest($this->base, 'rollback', $this->token);
        $this->runner()->tick();
        self::assertSame('old admin', file_get_contents("{$this->base}/public/admin/index.html"));
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
    }

    public function testAFailedVerificationIsAFailureEvenWithAnOldSuccessInTheLog(): void
    {
        $log = $this->paths->log($this->ops->current()['id']);
        @mkdir(dirname($log), 0700, true);
        file_put_contents($log, '{"version":"1.0.0-beta.102"}' . "\n");
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
                $op = $this->ops->start('1.0.0-beta.103', '1.0.0-beta.102', 'owner', hash('sha256', 'token'));
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
        $next = $this->ops->start('1.0.0-beta.103', '1.0.0-beta.102', 'owner', hash('sha256', 'token'));
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
            $this->fileRecoverRequest($this->base, 'reopen', $this->token);
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

    public function testAStartRequestFromTheInboxBecomesTheOperation(): void
    {
        $this->clearOperation();
        $token = bin2hex(random_bytes(32));
        $this->fileStartRequest($this->base, '1.0.0-beta.102', $token);
        $this->runner()->tick();
        $op = $this->ops->current();
        self::assertSame(Status::SUCCEEDED, $op['status'], (string) $op['error']);
        self::assertSame('1.0.0-beta.102', $op['target']);
        self::assertSame('1.0.0-beta.101', $op['from'], 'from the live composer.lock, not the request');
        self::assertSame(hash('sha256', $token), $op['token_hash']);
        self::assertFileDoesNotExist($this->paths->startRequest(), 'claimed');
    }

    /** @return iterable<string, array{\Closure(string, string): void, string}> */
    public static function refusedStartRequests(): iterable
    {
        yield 'a symbolic link' => [static function (string $base, string $file): void {
            file_put_contents($base . '/elsewhere.json', '{}');
            unlink($file);
            symlink($base . '/elsewhere.json', $file);
        }, 'not a valid request'];
        yield 'over 4 KB' => [static function (string $base, string $file): void {
            file_put_contents($file, json_encode(['target' => '1.0.0-beta.102', 'pad' => str_repeat('x', 5000)]));
        }, 'not a valid request'];
        yield 'an extra key' => [static function (string $base, string $file): void {
            $r = json_decode((string) file_get_contents($file), true);
            file_put_contents($file, json_encode($r + ['composer_options' => '--no-plugins']));
        }, 'not a valid request'];
        yield 'a target that is not a release' => [static function (string $base, string $file): void {
            $r = json_decode((string) file_get_contents($file), true);
            file_put_contents($file, json_encode(['target' => 'dev-main as 1.0.0'] + $r));
        }, 'not a release version'];
        yield 'a target not newer than the installed one' => [static function (string $base, string $file): void {
            $r = json_decode((string) file_get_contents($file), true);
            file_put_contents($file, json_encode(['target' => '1.0.0-beta.101'] + $r));
        }, 'not newer'];
    }

    /**
     * @dataProvider refusedStartRequests
     * @param \Closure(string, string): void $tamper
     */
    public function testARefusedStartRequestIsRecordedAsFailedAndChangesNothing(\Closure $tamper, string $reason): void
    {
        $this->clearOperation();
        $this->fileStartRequest($this->base, '1.0.0-beta.102', 'token');
        $file = $this->paths->startRequest();
        $content = (string) file_get_contents($file);
        unlink($file);
        file_put_contents($file, $content);
        $tamper($this->base, $file);
        $this->runner()->tick();
        $op = $this->ops->current();
        self::assertSame(Status::FAILED, $op['status']);
        self::assertStringContainsString($reason, (string) $op['error']);
        self::assertFileDoesNotExist($file);
        $joined = implode("\n", array_map(static fn (array $c): string => implode(' ', $c), $this->host->calls));
        self::assertStringNotContainsString('update glueful/thallo-core', $joined, 'nothing was staged');
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
        if (is_file($this->base . '/elsewhere.json')) {
            self::assertSame('{}', file_get_contents($this->base . '/elsewhere.json'), 'the link target untouched');
        }
    }

    public function testARecoveryWithAWrongTokenOrAnOptionNotOnOfferIsIgnored(): void
    {
        $this->host->provisionExit = 1;
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->opStatus());
        $this->fileRecoverRequest($this->base, 'reopen', str_repeat('a', 64));
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->opStatus(), 'wrong token');
        self::assertNull($this->ops->current()['recovery_request']);
        self::assertFileDoesNotExist($this->paths->recoverRequest(), 'consumed either way');
        $this->fileRecoverRequest($this->base, 'format_disk', $this->token);
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->opStatus(), 'not an option');
        self::assertNull($this->ops->current()['recovery_request']);
    }

    public function testRetryProvisionIsRefusedAfterAHalfDoneSwap(): void
    {
        $this->killAt('swap.2.renamed');
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->opStatus());
        $published = json_decode((string) file_get_contents($this->paths->status()), true);
        self::assertSame(['rollback', 'reopen'], $published['recovery_options']);
        $this->fileRecoverRequest($this->base, 'retry_provision', $this->token);
        $calls = count($this->host->calls);
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->opStatus());
        self::assertNull($this->ops->current()['recovery_request']);
        $later = array_slice($this->host->calls, $calls);
        $joined = implode("\n", array_map(static fn (array $c): string => implode(' ', $c), $later));
        self::assertStringNotContainsString('thallo:provision', $joined, 'never on a half-swapped vendor');
    }

    /** @return iterable<string, array{string}> */
    public static function opDirOutcomes(): iterable
    {
        yield 'failed' => ['failed'];
        yield 'rolled back' => ['rolled_back'];
        yield 'a reopen completed after death' => ['death_reopen'];
    }

    /** @dataProvider opDirOutcomes */
    public function testTheOperationFolderIsRemovedOnceNothingInItIsLive(string $outcome): void
    {
        $id = $this->ops->current()['id'];
        if ($outcome === 'failed') {
            $this->host->backupExit = 1;
            $this->runner()->tick();
            self::assertSame(Status::FAILED, $this->opStatus());
        } elseif ($outcome === 'rolled_back') {
            // The last swap rename fails in-process (the stage cannot be left): the runner settles it
            // as never happened and reverses the first three itself.
            $stage = $this->paths->opDir($id) . '/stage';
            $this->runner(static function (string $name) use ($stage): void {
                if ($name === 'swap.3.renamed') {
                    chmod($stage, 0500);
                }
                if ($name === 'undo.swap.3.intended') {
                    chmod($stage, 0700);
                }
            })->tick();
            self::assertSame(Status::ROLLED_BACK, $this->opStatus(), (string) $this->ops->current()['error']);
            self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
        } else {
            $this->killAt('reopen.after-open');
            $this->runner()->tick();
            self::assertSame(Status::SUCCEEDED, $this->opStatus());
        }
        self::assertDirectoryDoesNotExist($this->paths->opDir($id));
    }

    public function testNoApplicationChildRunsWhileTheSiteIsClosed(): void
    {
        $this->host->provisionExit = 1;
        $this->runner()->tick();
        self::assertSame(Status::RECOVERY_REQUIRED, $this->opStatus());
        self::assertFileExists($this->paths->closedFlag());
        $calls = count($this->host->calls);
        $this->runner()->tick(); // nothing requested: an idle tick on a closed site
        $later = array_slice($this->host->calls, $calls);
        $joined = implode("\n", array_map(static fn (array $c): string => implode(' ', $c), $later));
        self::assertStringNotContainsString('glueful', $joined, 'the application never boots while closed');
    }

    public function testAnInsecureLayoutStopsTheRunnerBeforeItReadsAnything(): void
    {
        chmod($this->paths->privateDir(), 0755);
        $this->runner()->tick();
        self::assertSame(Status::REQUESTED, $this->opStatus(), 'not claimed');
        $report = json_decode((string) file_get_contents($this->paths->runnerReport()), true);
        self::assertContains('.thallo-update must be mode 0700, not 0755.', $report['layout_problems']);
    }

    /** No operation: a fresh site. */
    private function clearOperation(): void
    {
        unlink($this->paths->operation());
        @unlink($this->paths->status());
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
