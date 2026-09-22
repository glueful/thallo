<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * The framework's DatabaseBackupTask reads flat `driver`/`database`/`username` keys the stock
 * config/database.php does not have, takes the MySQL path on a PostgreSQL site, and produces no
 * dump. It was enabled by default in production, so every production site ran a nightly job that
 * backed up nothing. It is off until the task works; a site that turns it on does so knowingly.
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
