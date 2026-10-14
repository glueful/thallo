<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\FileLock;

/**
 * The lock files are shared by two users in one group — PHP-FPM and the update runner (docs/upgrading.md,
 * Updating from the admin). Whichever creates a lock file first must leave it group read-write, or
 * the other cannot open it.
 */
final class FileLockTest extends TestCase
{
    public function testANewLockFileIsGroupReadWrite(): void
    {
        $old = umask(0022);
        $dir = sys_get_temp_dir() . '/thallo-lock-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $path = "{$dir}/exclusive.lock";
            $h = FileLock::exclusive($path);
            self::assertNotNull($h);
            clearstatcache();
            self::assertSame('0660', substr(sprintf('%o', fileperms($path)), -4));
            FileLock::release($h);
        } finally {
            umask($old);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testASharedLockOpensTheFileReadOnlyAndNeverCreatesIt(): void
    {
        $dir = sys_get_temp_dir() . '/thallo-lock-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            self::assertFalse(FileLock::shared("{$dir}/missing.lock"), 'missing: cannot open, distinct from held');
            self::assertFileDoesNotExist("{$dir}/missing.lock");
            touch("{$dir}/admission.lock");
            chmod("{$dir}/admission.lock", 0640); // the web group may only read it (spec §16.1)
            $h = FileLock::shared("{$dir}/admission.lock");
            self::assertIsResource($h, 'a shared lock on a read-only descriptor');
            $held = FileLock::exclusive("{$dir}/admission.lock", false);
            self::assertNull($held, 'the shared holder blocks the exclusive lock');
            FileLock::release($h);
        } finally {
            exec('chmod -R u+w ' . escapeshellarg($dir) . ' && rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * A request's admission lock stays with the request. A process it starts in the background — the
     * update runner, in simple mode — must not keep it, or the runner's barrier waits on itself.
     */
    public function testASharedLockIsNotKeptByAProcessStartedInTheBackground(): void
    {
        $dir = sys_get_temp_dir() . '/thallo-lock-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = "{$dir}/admission.lock";
        touch($path);
        try {
            $h = FileLock::shared($path);
            self::assertIsResource($h);
            $null = ['file', '/dev/null', 'w'];
            $quiet = [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null];
            proc_close(proc_open(['/bin/sh', '-c', 'nohup sleep 3 >/dev/null 2>&1 &'], $quiet, $pipes));
            fclose($h); // as the end of a request lets go of it: closed, never explicitly unlocked
            $x = FileLock::exclusive($path, false);
            self::assertNotNull($x, 'the background process holds no share of the lock');
            FileLock::release($x);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
