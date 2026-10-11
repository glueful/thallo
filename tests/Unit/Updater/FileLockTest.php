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
            foreach (['exclusive', 'shared'] as $kind) {
                $path = "{$dir}/{$kind}.lock";
                $h = $kind === 'exclusive' ? FileLock::exclusive($path) : FileLock::shared($path);
                self::assertNotNull($h);
                clearstatcache();
                self::assertSame('0660', substr(sprintf('%o', fileperms($path)), -4), $kind);
                FileLock::release($h);
            }
        } finally {
            umask($old);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
