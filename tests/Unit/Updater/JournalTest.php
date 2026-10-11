<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Journal;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\Paths;

final class JournalTest extends TestCase
{
    private const SWAP = [
        ['vendor', 'op/vendor.old', 'swap.1'],
        ['stage/vendor', 'vendor', 'swap.2'],
        ['composer.lock', 'op/composer.lock.old', 'swap.3'],
        ['stage/composer.lock', 'composer.lock', 'swap.4'],
    ];
    private string $base;
    private OperationStore $ops;
    private string $id;
    private string $owner;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-journal-' . bin2hex(random_bytes(4));
        foreach (['storage/framework/updates', 'vendor', 'stage/vendor', 'op'] as $d) {
            mkdir("{$this->base}/{$d}", 0777, true);
        }
        file_put_contents("{$this->base}/vendor/marker", 'old');
        file_put_contents("{$this->base}/stage/vendor/marker", 'new');
        file_put_contents("{$this->base}/composer.lock", 'old-lock');
        file_put_contents("{$this->base}/stage/composer.lock", 'new-lock');
        $this->ops = new OperationStore(new Paths($this->base), static fn (): int => 1);
        [$op] = $this->ops->start('2', '1', 'u');
        $run = $this->ops->claim($op['id'], 1, 1);
        $this->id = $run['id'];
        $this->owner = $run['owner'];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testACompleteSwapThenRollbackRestoresTheOldTree(): void
    {
        $this->swap(new Journal($this->ops, $this->id, $this->owner));
        self::assertSame('new', file_get_contents("{$this->base}/vendor/marker"));
        (new Journal($this->ops, $this->id, $this->owner))->rollback();
        $this->assertOldTree();
    }

    /** @return iterable<string, array{string}> */
    public static function forwardKillPoints(): iterable
    {
        foreach (range(1, 4) as $n) {
            yield "swap.{$n}.intended" => ["swap.{$n}.intended"];
            yield "swap.{$n}.renamed" => ["swap.{$n}.renamed"];
        }
    }

    /** @dataProvider forwardKillPoints */
    public function testAKillAnywhereInTheSwapRollsBackCleanly(string $point): void
    {
        try {
            $this->swap($this->killedAt($point));
            self::fail('the kill point did not fire');
        } catch (\RuntimeException) {
        }
        $after = new Journal($this->ops, $this->id, $this->owner); // a fresh runner reads only the persisted journal
        $after->resume();
        $after->rollback();
        $this->assertOldTree();
    }

    /** @return iterable<string, array{string}> */
    public static function rollbackKillPoints(): iterable
    {
        foreach (range(1, 4) as $n) {
            yield "reverse of swap.{$n}" => ["undo.swap.{$n}.renamed"];
        }
    }

    /** @dataProvider rollbackKillPoints */
    public function testAnInterruptedRollbackResumes(string $point): void
    {
        $this->swap(new Journal($this->ops, $this->id, $this->owner));
        try {
            $this->killedAt($point)->rollback();
        } catch (\RuntimeException) {
        }
        $again = new Journal($this->ops, $this->id, $this->owner);
        $again->resume();
        $again->rollback();
        $this->assertOldTree();
        self::assertFalse($again->hasForwardChanges());
    }

    /** A journal whose runner dies (throws) at the named kill point. */
    private function killedAt(string $point): Journal
    {
        return new Journal($this->ops, $this->id, $this->owner, static function (string $label) use ($point): void {
            if ($label === $point) {
                throw new \RuntimeException('killed');
            }
        });
    }

    private function swap(Journal $j): void
    {
        foreach (self::SWAP as [$from, $to, $label]) {
            $j->rename("{$this->base}/{$from}", "{$this->base}/{$to}", $label);
        }
    }

    private function assertOldTree(): void
    {
        self::assertSame('old', file_get_contents("{$this->base}/vendor/marker"));
        self::assertSame('old-lock', file_get_contents("{$this->base}/composer.lock"));
        $staged = file_get_contents("{$this->base}/stage/vendor/marker");
        self::assertSame('new', $staged, 'the new tree is back in the stage');
    }
}
