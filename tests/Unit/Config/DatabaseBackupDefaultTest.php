<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * Before framework 1.86 the scheduled backup could not dump a stock install, yet it was on by default
 * in production. It works now, but stays off by default: it needs pg_dump on the scheduler host and
 * writes to the same machine as the database, so a site turns it on deliberately.
 */
final class DatabaseBackupDefaultTest extends TestCase
{
    public function testTheScheduledBackupIsOffUnlessTurnedOnEvenInProduction(): void
    {
        $saved = [
            getenv('DB_BACKUP_ENABLED'),
            getenv('APP_ENV'),
            $_ENV['DB_BACKUP_ENABLED'] ?? null,
            $_ENV['APP_ENV'] ?? null,
        ];
        putenv('DB_BACKUP_ENABLED');
        unset($_ENV['DB_BACKUP_ENABLED'], $_SERVER['DB_BACKUP_ENABLED']);
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        try {
            foreach (['config', 'skeleton/config'] as $dir) {
                $config = require dirname(__DIR__, 3) . "/{$dir}/schedule.php";
                $jobs = array_column($config['jobs'], null, 'name');
                self::assertFalse((bool) $jobs['database_backup']['enabled'], $dir);
            }
        } finally {
            putenv($saved[0] === false ? 'DB_BACKUP_ENABLED' : 'DB_BACKUP_ENABLED=' . $saved[0]);
            putenv($saved[1] === false ? 'APP_ENV' : 'APP_ENV=' . $saved[1]);
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $saved[3] ?? ($saved[1] === false ? 'testing' : $saved[1]);
            if ($saved[2] !== null) {
                $_ENV['DB_BACKUP_ENABLED'] = $saved[2];
            }
        }
    }
}
