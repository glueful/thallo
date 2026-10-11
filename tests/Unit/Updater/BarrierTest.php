<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\Barrier;
use Thallo\Core\Updater\FileLock;
use Thallo\Core\Updater\Paths;

final class BarrierTest extends TestCase
{
    private string $base;
    private Barrier $barrier;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/thallo-barrier-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/storage/framework/updates', 0777, true);
        $this->barrier = new Barrier(new Paths($this->base));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testTheExclusiveBarrierWaitsForEveryAdmittedProcess(): void
    {
        $a = $this->barrier->admit();
        $b = $this->barrier->admit();
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNull($this->acquireNow(), 'two admitted holders block the barrier');
        FileLock::release($a);
        self::assertNull($this->acquireNow(), 'one still blocks it');
        FileLock::release($b);
        self::assertNotNull($this->acquireNow(), 'granted once no admitted process remains');
    }

    public function testNothingIsAdmittedWhileClosedOrWhileTheBarrierIsHeld(): void
    {
        $this->barrier->close();
        self::assertNull($this->barrier->admit(), 'the flag refuses');
        $this->barrier->open();
        $held = $this->acquireNow();
        self::assertNotNull($held);
        self::assertNull($this->barrier->admit(), 'a held barrier refuses');
    }

    public function testAcquireGivesUpAfterTheTimeout(): void
    {
        $holder = $this->barrier->admit();
        $t = 0;
        $got = $this->barrier->acquire(3, static function () use (&$t): int {
            return $t;
        }, static function () use (&$t): void {
            $t++;
        });
        self::assertNull($got);
        self::assertSame(3, $t, 'it polled once a second until the timeout');
        FileLock::release($holder);
    }

    /** @return resource|null */
    private function acquireNow()
    {
        return $this->barrier->acquire(0, static fn (): int => 0, static function (): void {
        });
    }
}
