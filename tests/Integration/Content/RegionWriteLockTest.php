<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Regions\RegionVersionConflict;
use Thallo\Core\Content\Regions\RegionWriteLock;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Every writer of the `regions` table queues on one advisory lock (regions-stage spec §4.5), so a
 * save can check versions and validate the whole candidate knowing nothing else writes meanwhile.
 * Proven against a second database connection, and against a writer in another process.
 */
final class RegionWriteLockTest extends AppTestCase
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

    private function lock(): RegionWriteLock
    {
        return new RegionWriteLock($this->connection());
    }

    /** Can another session take the region lock right now? */
    private function otherCanTake(): bool
    {
        $this->other ??= $this->connection()->newPdo();
        $this->other->beginTransaction();
        $stmt = $this->other->prepare('SELECT pg_try_advisory_xact_lock(?) AS got');
        $stmt->execute([$this->lock()->key()]);
        $got = (bool) $stmt->fetchColumn();
        $this->other->rollBack();
        return $got;
    }

    /** @return list<array<string,mixed>> */
    private function blocks(string $text): array
    {
        $id = 'blk' . substr(md5($text), 0, 9);
        return [['id' => $id, 'type' => 'rich_text', 'data' => ['body' => "<p>{$text}</p>"]]];
    }

    private function repo(): RegionRepository
    {
        return new RegionRepository($this->connection());
    }

    /** A conditional save under the region lock. */
    private function save(string $slug, string $text, ?int $expected): int
    {
        return $this->lock()->within(
            fn () => $this->repo()->saveExpected($slug, $this->blocks($text), [], $expected, null),
        );
    }

    private function errFile(): string
    {
        return sys_get_temp_dir() . '/region-writer.err';
    }

    public function testWritersAreSerialized(): void
    {
        $inside = null;
        $this->lock()->within(function () use (&$inside): void {
            $inside = $this->otherCanTake();
        });
        self::assertFalse($inside, 'another session took the region lock while it was held');
        self::assertTrue($this->otherCanTake(), 'the lock outlived its transaction');
    }

    public function testAnOuterTransactionStillTakesTheLock(): void
    {
        $during = $after = null;
        $this->connection()->transaction(function () use (&$during, &$after): void {
            $this->lock()->within(function () use (&$during): void {
                $during = $this->otherCanTake();
            });
            // within() returned, but the outer transaction has not ended: still held.
            $after = $this->otherCanTake();
        });
        self::assertFalse($during);
        self::assertFalse($after, 'the lock was released before the outer transaction ended');
        self::assertTrue($this->otherCanTake());
    }

    public function testIsHeldReflectsTheLock(): void
    {
        $lock = $this->lock();
        self::assertFalse($lock->isHeld());
        $inside = $afterInner = null;
        $this->connection()->transaction(function () use ($lock, &$inside, &$afterInner): void {
            $lock->within(function () use ($lock, &$inside): void {
                $inside = $lock->isHeld();
            });
            $afterInner = $lock->isHeld();
        });
        self::assertTrue($inside);
        self::assertTrue($afterInner);
        self::assertFalse($lock->isHeld());
    }

    public function testFindReturnsTheVersion(): void
    {
        $this->repo()->save('header', $this->blocks('a'), [], null);
        self::assertSame(0, $this->repo()->find('header')['lock_version']);
        $this->repo()->save('header', $this->blocks('b'), [], null);
        self::assertSame(1, $this->repo()->find('header')['lock_version']);
    }

    public function testSaveExpectedWritesOnlyAgainstTheExpectedVersion(): void
    {
        $this->repo()->save('header', $this->blocks('seed'), [], null);
        $version = $this->repo()->find('header')['lock_version'];

        $next = $this->save('header', 'one', $version);
        self::assertSame($version + 1, $next);

        try {
            $this->save('header', 'two', $version);
            self::fail('a save against a moved version was written');
        } catch (RegionVersionConflict $e) {
            self::assertSame(['header'], $e->moved);
        }
        self::assertStringContainsString('one', json_encode($this->repo()->find('header')['blocks']));
    }

    public function testSaveExpectedNullCreatesAndThenConflicts(): void
    {
        self::assertNull($this->repo()->find('footer'));
        $created = $this->save('footer', 'new', null);
        self::assertSame(0, $created);
        self::assertNotNull($this->repo()->find('footer'));

        $this->expectException(RegionVersionConflict::class);
        $this->save('footer', 'again', null);
    }

    public function testSaveExpectedOutsideTheLockIsRefused(): void
    {
        try {
            $this->repo()->saveExpected('header', $this->blocks('x'), [], null, null);
            self::fail('saveExpected wrote without a transaction');
        } catch (\LogicException) {
        }
        $this->expectException(\LogicException::class);
        // An ordinary transaction is not the region lock.
        $this->connection()->transaction(
            fn () => $this->repo()->saveExpected('header', $this->blocks('y'), [], null, null),
        );
    }

    public function testBackgroundPersistAndAdminSaveSerialize(): void
    {
        $source = new RegionsSource($this->connection());
        $this->repo()->save('header', $this->blocks('seed'), [], null);

        // The admin save lands first: the background write, from the older read, is refused.
        $ref = $this->refFor($source, 'header');
        $this->save('header', 'admin', (int) $ref->revision);
        self::assertFalse($source->persist($ref, ['blocks' => $this->blocks('job')]));
        self::assertStringContainsString('admin', json_encode($this->repo()->find('header')['blocks']));

        // The background write lands first: the admin save, against the older version, conflicts.
        $ref = $this->refFor($source, 'header');
        $before = (int) $ref->revision;
        self::assertTrue($source->persist($ref, ['blocks' => $this->blocks('job2')]));
        $this->expectException(RegionVersionConflict::class);
        $this->save('header', 'late', $before);
    }

    public function testRenameBumpsTheVersion(): void
    {
        $this->repo()->save('old_header', $this->blocks('seed'), [], null);
        $before = $this->repo()->find('old_header')['lock_version'];
        $kind = $this->container()->get(\Thallo\Core\Content\Starter\Kinds\RegionKind::class);
        $definition = new \Thallo\Core\Content\Starter\StarterDefinition('test', 'header', []);
        $kind->rename($definition, 'old_header');
        self::assertNull($this->repo()->find('old_header'));
        self::assertSame($before + 1, $this->repo()->find('header')['lock_version']);
    }

    /**
     * A writer in another process waits on the region lock before it reads or validates: the
     * child reports ready with its backend pid, the test sees that pid waiting on this lock in
     * pg_locks, and only then commits its own write.
     */
    public function testConcurrentWritersQueueBeforeReadingAndValidating(): void
    {
        $source = new RegionsSource($this->connection());
        $this->repo()->save('header', $this->blocks('seed'), [], null);
        // Captured before the child starts: stale by construction once the test writes.
        $ref = $this->refFor($source, 'header');

        $result = null;
        $this->lock()->within(function () use ($ref, &$result): void {
            $this->connection()->table('regions')->where('slug', '=', 'header')->update([
                'blocks' => json_encode($this->blocks('held')),
                'lock_version' => ((int) $ref->revision) + 1,
            ]);
            $child = $this->startWriter('persist', [
                'slug' => 'header',
                'revision' => $ref->revision,
                'blocks' => $this->blocks('child'),
            ]);
            $ready = $this->readLine($child['stdout']);
            self::assertTrue($ready['ready'] ?? false, 'the writer did not report ready');
            $this->awaitLockWait((int) $ready['pid']);
            $result = $child;
        });
        // The test's write has committed; the child can now take the lock.
        $line = $this->readLine($result['stdout'], 15);
        proc_close($result['proc']);
        self::assertSame(['persisted' => false], $line);
        self::assertStringContainsString('held', json_encode($this->repo()->find('header')['blocks']));
    }

    private function refFor(RegionsSource $source, string $slug): DocumentRef
    {
        $found = null;
        $source->each(function (DocumentRef $ref) use ($slug, &$found): void {
            if ($ref->sourceId === $slug) {
                $found = $ref;
            }
        });
        self::assertNotNull($found);
        return $found;
    }

    /** @param array<string,mixed> $input @return array{proc: resource, stdout: resource} */
    private function startWriter(string $path, array $input): array
    {
        $script = dirname(__DIR__, 2) . '/Support/bin/region-writer.php';
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

    private function awaitLockWait(int $pid): void
    {
        $this->other ??= $this->connection()->newPdo();
        $stmt = $this->other->prepare(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = ? AND locktype = 'advisory' AND NOT granted"
            . ' AND classid = 0 AND objid = ? AND objsubid = 1)'
        );
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $stmt->execute([$pid, $this->lock()->key()]);
            if ((bool) $stmt->fetchColumn()) {
                return;
            }
            usleep(20_000);
        }
        self::fail("backend {$pid} never waited on the region lock");
    }
}
