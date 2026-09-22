<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * The framework's LogCleanupJob reads `options.retention_days` from its parameters and falls back
 * to 30. The shipped schedule passed a top-level `retentionDays`, so LOG_RETENTION_DAYS changed
 * nothing: every site kept thirty days of logs whatever it set.
 */
final class LogRetentionScheduleTest extends TestCase
{
    public function testLogRetentionDaysReachesTheKeyTheCleanupJobReads(): void
    {
        $saved = getenv('LOG_RETENTION_DAYS');
        $savedEnv = $_ENV['LOG_RETENTION_DAYS'] ?? null;
        putenv('LOG_RETENTION_DAYS=9');
        $_ENV['LOG_RETENTION_DAYS'] = $_SERVER['LOG_RETENTION_DAYS'] = '9';
        try {
            foreach (['config', 'skeleton/config'] as $dir) {
                $config = require dirname(__DIR__, 3) . "/{$dir}/schedule.php";
                $jobs = array_column($config['jobs'], null, 'name');
                $parameters = $jobs['log_cleanup']['parameters'];

                self::assertSame(9, (int) ($parameters['options']['retention_days'] ?? 0), $dir);
                self::assertArrayNotHasKey('retentionDays', $parameters, $dir);
            }
        } finally {
            putenv($saved === false ? 'LOG_RETENTION_DAYS' : 'LOG_RETENTION_DAYS=' . $saved);
            if ($savedEnv === null) {
                unset($_ENV['LOG_RETENTION_DAYS'], $_SERVER['LOG_RETENTION_DAYS']);
            } else {
                $_ENV['LOG_RETENTION_DAYS'] = $_SERVER['LOG_RETENTION_DAYS'] = $savedEnv;
            }
        }
    }
}
