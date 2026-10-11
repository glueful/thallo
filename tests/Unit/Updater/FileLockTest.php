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
}
