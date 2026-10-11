<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Liveness;
use Thallo\Core\Updater\Paths;

/** @requires extension posix */
final class LivenessTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-live-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->base . '/grandchild.pid')) {
            @posix_kill((int) file_get_contents($this->base . '/grandchild.pid'), SIGKILL);
        }
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testAGrandchildKeepsTheOperationAlive(): void
    {
        $op = $this->runStandIn('sleep 30');
        $paths = new Paths($this->base);
        self::assertNull(Liveness::claimIfDead($op, $paths), 'the grandchild holds the lock and the group');
        posix_kill((int) file_get_contents($this->base . '/grandchild.pid'), SIGKILL);
        $this->waitUntil(fn () => !Liveness::groupAlive($op['pgid']));
        self::assertNotNull(Liveness::claimIfDead($op, $paths), 'dead only once every descendant is gone');
    }

    public function testAGrandchildThatClosedTheLockStillKeepsItAliveThroughTheGroup(): void
    {
        $op = $this->runStandIn('sh -c "exec 3>&-; sleep 30"');
        self::assertNull(Liveness::claimIfDead($op, new Paths($this->base)), 'the process group still has a member');
    }

    public function testAKilledRecoveryRunnerWithASurvivingGrandchildIsStillAlive(): void
    {
        // A first run died; its group is long gone. A recovery runner claims with ITS OWN group,
        // starts a grandchild and is killed. Liveness must follow the recovery runner's group.
        $paths = new Paths($this->base);
        $ops = new \Thallo\Core\Updater\OperationStore($paths, static fn (): int => 1);
        [$op, $token] = $ops->start('2', '1', 'u');
        $dead = $ops->claim($op['id'], 1, 999_999); // a group that does not exist
        $ops->finish($op['id'], $dead['owner'], \Thallo\Core\Updater\Status::RECOVERY_REQUIRED);
        $ops->requestRecovery($token, 'rollback');
        $p = proc_open([PHP_BINARY, $this->fixture('recover-and-die.php'), $this->base], [], $pipes);
        $this->waitUntil(fn () => !proc_get_status($p)['running'] && is_file($this->base . '/grandchild.pid'));
        proc_close($p);
        $now = $ops->current();
        self::assertNotSame(999_999, $now['pgid'], 'recovery recorded fresh ownership');
        self::assertNull(Liveness::claimIfDead($now, $paths), 'the grandchild keeps the recovery run alive');
        posix_kill((int) file_get_contents($this->base . '/grandchild.pid'), SIGKILL);
        $this->waitUntil(fn () => !Liveness::groupAlive($now['pgid']));
        self::assertNotNull(Liveness::claimIfDead($now, $paths));
    }

    public function testAChildIgnoringSigtermAndItsGrandchildAreStoppedWithinBounds(): void
    {
        $p = proc_open([PHP_BINARY, $this->fixture('timeout-with-grandchild.php'), $this->base], [], $pipes);
        // 2 s timeout + up to 10 s of SIGTERM grace + SIGKILL: allow 30 s, the bound the test asserts.
        $this->waitUntil(fn () => !proc_get_status($p)['running'], 600);
        $code = proc_close($p);
        self::assertSame(0, $code, (string) @file_get_contents($this->base . '/log'));
        [$code, $took] = explode(' ', trim((string) file_get_contents($this->base . '/result')));
        self::assertSame('124', $code);
        self::assertLessThan(30, (int) $took, 'termination is bounded even when SIGTERM is ignored');
        $grandchild = (int) file_get_contents($this->base . '/grandchild.pid');
        self::assertFalse(posix_kill($grandchild, 0), 'the grandchild is gone');
    }

    public function testANotRunningOperationIsNeverDead(): void
    {
        self::assertNull(Liveness::claimIfDead(['status' => 'recovery_required', 'pgid' => 1], new Paths($this->base)));
    }

    /** @return array{status:string,pgid:int} */
    private function runStandIn(string $grandchild): array
    {
        $cmd = [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/updater/hold-and-die.php', $this->base, $grandchild];
        $p = proc_open($cmd, [], $pipes);
        $this->waitUntil(fn () => !proc_get_status($p)['running'] && is_file($this->base . '/grandchild.pid'));
        proc_close($p);
        return ['status' => 'running', 'pgid' => (int) file_get_contents($this->base . '/pgid')];
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__, 2) . '/fixtures/updater/' . $name;
    }

    private function waitUntil(callable $ok, int $tries = 100): void
    {
        for ($i = 0; $i < $tries && !$ok(); $i++) {
            usleep(50_000);
        }
        self::assertTrue($ok(), 'timed out');
    }
}
