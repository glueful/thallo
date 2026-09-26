<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutVersionConflict;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Layout storage (type layouts spec §5.1): one row per (tenant, surface, target), written only
 * under the layout's write lock and only at the version the writer read. Removal keeps the row as
 * a tombstone, so a version never goes backwards and an editor holding an old one cannot write
 * over a layout that was removed and made again.
 */
final class LayoutRepositoryTest extends AppTestCase
{
    private ?\PDO $other = null;

    protected function tearDown(): void
    {
        if ($this->other !== null && $this->other->inTransaction()) {
            $this->other->rollBack();
        }
        $this->other = null;
        parent::tearDown();
    }

    private function lock(): LayoutWriteLock
    {
        return new LayoutWriteLock($this->connection());
    }

    private function repo(): LayoutRepository
    {
        return new LayoutRepository($this->connection(), $this->lock());
    }

    /** @return list<array<string,mixed>> */
    private static function blocks(string $text): array
    {
        return [['type' => 'heading', 'data' => ['text' => $text], 'settings' => []]];
    }

    private function save(int $expected, string $text = 'one'): int
    {
        return $this->lock()->within(
            'entry',
            'post',
            fn (): int => $this->repo()->saveExpected('entry', 'post', self::blocks($text), [], $expected, null),
        );
    }

    private function otherPdo(): \PDO
    {
        return $this->other ??= $this->connection()->newPdo();
    }

    public function testAFirstSaveCreatesAtVersionOneAndASecondFirstSaveConflicts(): void
    {
        self::assertSame(0, $this->repo()->version('entry', 'post'));
        self::assertSame(1, $this->save(0));
        self::assertSame('one', $this->repo()->find('entry', 'post')['blocks'][0]['data']['text']);
        try {
            $this->save(0);
            self::fail('a second first save must conflict');
        } catch (LayoutVersionConflict $e) {
            self::assertSame(1, $e->current);
        }
    }

    /**
     * The Regions arrangement exactly: the test holds the lock and saves; a writer in another
     * process attempts the same first save, reports its backend pid, and is seen waiting on the
     * lock; only then does the test commit — and the writer finds the row made.
     */
    public function testConcurrentFirstSavesResolveToOneRow(): void
    {
        $child = null;
        $this->lock()->within('entry', 'post', function () use (&$child): void {
            $this->repo()->saveExpected('entry', 'post', self::blocks('parent'), [], 0, null);
            $child = $this->startWriter('save', ['surface' => 'entry', 'target' => 'post', 'expected' => 0]);
            $ready = $this->readLine($child['stdout']);
            self::assertTrue($ready['ready'] ?? false, 'the writer did not report ready');
            $this->awaitLockWait((int) $ready['pid'], $this->lock()->key('entry', 'post'));
        });
        $result = $this->readLine($child['stdout'], 15);
        proc_close($child['proc']);
        self::assertSame(['conflict' => true, 'current' => 1], $result);
        self::assertSame(1, $this->connection()->table('layouts')->where('target', '=', 'post')->count());
        self::assertSame('parent', $this->repo()->find('entry', 'post')['blocks'][0]['data']['text']);
    }

    public function testSaveNeedsTheExpectedVersion(): void
    {
        $this->save(0);
        $this->save(1, 'two');
        try {
            $this->save(1, 'stale');
            self::fail('a stale save must conflict');
        } catch (LayoutVersionConflict $e) {
            self::assertSame(2, $e->current);
        }
        self::assertSame('two', $this->repo()->find('entry', 'post')['blocks'][0]['data']['text']);
    }

    public function testRemovalTombstonesAndTheVersionContinues(): void
    {
        $this->save(0);
        $version = $this->lock()->within(
            'entry',
            'post',
            fn (): int => $this->repo()->tombstone('entry', 'post', 1, null),
        );
        self::assertSame(2, $version);
        self::assertNull($this->repo()->find('entry', 'post')['blocks']);
        self::assertSame(2, $this->repo()->version('entry', 'post'));
        self::assertSame([], $this->repo()->live());

        self::assertSame(3, $this->save(2, 'again'));
        self::assertSame('again', $this->repo()->find('entry', 'post')['blocks'][0]['data']['text']);
        try {
            $this->save(0);
            self::fail('a first save over a kept row must conflict');
        } catch (LayoutVersionConflict $e) {
            self::assertSame(3, $e->current);
        }
    }

    public function testOneRowPerSubjectEvenWithoutATenant(): void
    {
        $this->save(0);
        $this->expectException(\Throwable::class);
        $this->connection()->getPDO()->exec(
            "INSERT INTO layouts (id, tenant_uuid, surface, target, blocks, settings, lock_version)"
            . " VALUES ('dupdupdup001', NULL, 'entry', 'post', '[]', '{}', 0)"
        );
    }

    public function testWritesRefuseOutsideTheLock(): void
    {
        $this->expectException(\LogicException::class);
        $this->repo()->saveExpected('entry', 'post', self::blocks('x'), [], 0, null);
    }

    public function testWithinCommitsOnReturnAndRollsBackOnThrow(): void
    {
        $visible = fn (): int => (int) $this->otherPdo()
            ->query("SELECT COUNT(*) FROM layouts WHERE surface = 'entry' AND target = 'post'")
            ->fetchColumn();
        try {
            $this->lock()->within('entry', 'post', function (): void {
                $this->repo()->saveExpected('entry', 'post', self::blocks('lost'), [], 0, null);
                throw new \RuntimeException('after the write');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $visible(), 'a thrown callback rolls the write back');
        $this->save(0);
        self::assertSame(1, $visible(), 'a returned callback has committed');
    }

    public function testTheTypeLockIsHeldOnlyInsideWithinType(): void
    {
        self::assertFalse($this->lock()->isTypeHeld('post'));
        $held = $this->lock()->withinType('post', fn (): bool => $this->lock()->isTypeHeld('post'));
        self::assertTrue($held);
        self::assertTrue($this->lock()->within('entry', 'post', fn (): bool => $this->lock()->isHeld('entry', 'post')));
    }

    /** @param array<string,mixed> $input @return array{proc: resource, stdout: resource} */
    private function startWriter(string $path, array $input): array
    {
        $script = dirname(__DIR__, 3) . '/Support/bin/layout-writer.php';
        $proc = proc_open(
            [PHP_BINARY, $script, $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->errFile(), 'a']],
            $pipes,
        );
        self::assertIsResource($proc);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        return ['proc' => $proc, 'stdout' => $pipes[1]];
    }

    private function errFile(): string
    {
        return sys_get_temp_dir() . '/thallo-layout-writer.err';
    }

    /** @param resource $stream @return array<string,mixed> */
    private function readLine($stream, int $seconds = 10): array
    {
        $deadline = microtime(true) + $seconds;
        $buffer = '';
        while (microtime(true) < $deadline) {
            $chunk = fgets($stream);
            if ($chunk !== false) {
                $buffer .= $chunk;
                if (str_ends_with($buffer, "\n")) {
                    return json_decode(trim($buffer), true, 512, JSON_THROW_ON_ERROR);
                }
            }
            usleep(20_000);
        }
        self::fail('the writer printed no line within ' . $seconds . 's; see ' . $this->errFile());
    }

    private function awaitLockWait(int $pid, int $key): void
    {
        $stmt = $this->otherPdo()->prepare(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = ? AND locktype = 'advisory' AND NOT granted"
            . ' AND classid = 0 AND objid = ? AND objsubid = 1)'
        );
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $stmt->execute([$pid, $key]);
            if ((bool) $stmt->fetchColumn()) {
                return;
            }
            usleep(20_000);
        }
        self::fail("backend {$pid} never waited on the layout lock");
    }
}
