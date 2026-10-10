<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;
use Thallo\Core\Tests\Support\Palette\PaletteReplaceFixtures;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The palette lock order against a real database (custom palette spec §4.3, §4.6), two sessions at a
 * time: a holder takes the palette row (or an uncommitted key) and stops; the contender is OBSERVED
 * waiting on exactly that holder — pg_blocking_pids names it — before the holder is released. Timing
 * is never the evidence; every timeout is only a failure bound.
 */
final class PaletteTwoProcessTest extends AppTestCase
{
    use PaletteFixtures;
    use PaletteReplaceFixtures;
    use SyncsBlockStyleDeclarations;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->replaceWorld();
        $this->dir = sys_get_temp_dir() . '/thallo-palette-race-' . getmypid();
        @mkdir($this->dir);
        array_map('unlink', glob("{$this->dir}/*") ?: []);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param array<string,string> $env
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launch(string $scenario, string $role, array $env): array
    {
        $root = dirname(__DIR__, 4);
        $command = [
            PHP_BINARY, "{$root}/vendor/bin/phpunit", '--no-coverage', '--no-configuration',
            '--bootstrap', "{$root}/vendor/autoload.php",
            "{$root}/tests/Integration/Content/Palette/PaletteConcurrentActorTest.php",
        ];
        $vars = getenv() + ['THALLO_PALETTE_ACTOR' => "{$scenario}:{$role}", 'THALLO_PALETTE_BARRIER' => $this->dir];
        foreach ($env as $k => $v) {
            $vars['THALLO_PALETTE_' . $k] = $v;
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $vars);
        self::assertIsResource($process, "{$role} process");
        return [$process, $pipes];
    }

    private function waitFor(string $file, float $seconds, string $what): string
    {
        $deadline = microtime(true) + $seconds;
        while (!is_file("{$this->dir}/{$file}")) {
            if (microtime(true) > $deadline) {
                self::fail($what);
            }
            usleep(10000);
        }
        return (string) file_get_contents("{$this->dir}/{$file}");
    }

    /** @param array{0: resource, 1: array<int,resource>} $p */
    private static function finish(array $p): array
    {
        [$process, $pipes] = $p;
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($process), $out];
    }

    /**
     * @param array<string,string> $env
     * @return array{result: string, detail: mixed}
     */
    private function race(string $scenario, array $env): array
    {
        $holder = $this->launch($scenario, 'holder', $env);
        $holderPid = (int) $this->waitFor("held-{$scenario}", 30, 'the holder never took its lock');
        $contender = $this->launch($scenario, 'contender', $env);
        $contenderPid = (int) $this->waitFor("pid-{$scenario}", 30, 'the contender never started');
        // the observed wait: the contender's backend lock-waiting with the holder among its blockers
        $deadline = microtime(true) + 20;
        $pdo = $this->connection()->getPDO();
        $seen = null;
        while (true) {
            $row = $pdo->query(
                'SELECT a.wait_event_type, pg_blocking_pids(a.pid)::text AS blockers '
                . 'FROM pg_stat_activity a WHERE a.pid = ' . $contenderPid,
            )->fetch(\PDO::FETCH_ASSOC) ?: null;
            $list = $row === null ? '' : trim((string) $row['blockers'], '{}');
            $blockers = array_map('intval', array_filter(explode(',', $list)));
            if (($row['wait_event_type'] ?? null) === 'Lock' && in_array($holderPid, $blockers, true)) {
                $seen = $row;
                break;
            }
            if (microtime(true) > $deadline) {
                touch("{$this->dir}/release");
                [, $hOut] = self::finish($holder);
                [, $cOut] = self::finish($contender);
                self::fail("the contender was never seen waiting on the holder\nholder:\n{$hOut}\ncontender:\n{$cOut}");
            }
            usleep(10000);
        }
        self::assertNotNull($seen);
        $early = "{$this->dir}/outcome-{$scenario}";
        self::assertFileDoesNotExist($early, 'the contender finished while the holder held');
        touch("{$this->dir}/release");
        [$hExit, $hOut] = self::finish($holder);
        [$cExit, $cOut] = self::finish($contender);
        self::assertSame(0, $hExit, "holder failed:\n{$hOut}");
        self::assertSame(0, $cExit, "contender failed:\n{$cOut}");
        return json_decode($this->waitFor("outcome-{$scenario}", 5, 'no outcome'), true);
    }

    public function testASaveHoldingThePaletteRowMakesAClearWaitThenRefuse(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$uuid, $lock] = $this->entry();
        self::assertSame('in_use', $this->race('save-clear', ['ENTRY' => $uuid, 'LOCK' => (string) $lock])['result']);
        self::assertNotNull($this->palette()->brand(1));
    }

    public function testAClearHoldingThePaletteRowMakesASaveWaitThenRefuseTheFreshReference(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$uuid, $lock] = $this->entry();
        self::assertSame('refused', $this->race('clear-save', ['ENTRY' => $uuid, 'LOCK' => (string) $lock])['result']);
        self::assertSame(0, $this->countDraftsNaming('color.brand-1'));
        self::assertNull($this->palette()->brand(1));
    }

    public function testACancelHoldingTheRowStopsAWorkerBeforeItsFirstWrite(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->draftsNaming('color.brand-1', 3);
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $outcome = $this->race('cancel-worker', ['JOB' => $job]);
        self::assertSame('cancelled', $outcome['detail']['status']);
        self::assertSame(3, $this->countDraftsNaming('color.brand-1'), 'nothing written after the cancel');
        self::assertNotNull($this->palette()->brand(1));
    }

    public function testConflictingStartsInTwoSessions(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        self::assertSame('conflict', $this->race('start-start', [])['result']);
        self::assertCount(1, $this->jobs()->active());
    }

    public function testTheFirstRowRaceTakesTheDuplicateInsertAndKeepsTheLosersTransactionUsable(): void
    {
        $this->connection()->table('palette_state')->where('site', '=', 'site')->delete();
        $outcome = $this->race('first-row', []);
        // the observed wait is the contender's INSERT blocked on the holder's uncommitted key —
        // ensureRow's existence check cannot block — so the duplicate-insert path was taken
        self::assertSame(['result' => 'ok', 'detail' => 1], $outcome, 'the savepoint absorbed the duplicate');
        self::assertSame(1, $this->connection()->table('palette_state')->count());
    }
}
