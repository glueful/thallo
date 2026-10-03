<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Thallo\Contracts\Extensions\ExtensionStateCoordinator;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Thallo's writers of the enabled-provider list hold the framework's extension-state lock (the
 * session advisory lock on hashtext('glueful:extension-state')) for their whole sequence, and
 * release it even when the sequence throws.
 */
final class ExtensionStateLockTest extends AppTestCase
{
    private function otherSession(): \PDO
    {
        return new \PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                env('DB_PGSQL_HOST', '127.0.0.1'),
                env('DB_PGSQL_PORT', '5432'),
                env('DB_PGSQL_DATABASE', 'app_test'),
            ),
            (string) env('DB_PGSQL_USERNAME', 'postgres'),
            (string) env('DB_PGSQL_PASSWORD', ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    private static function tryLock(\PDO $pdo): bool
    {
        $got = (bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('glueful:extension-state'))")->fetchColumn();
        if ($got) {
            $pdo->query("SELECT pg_advisory_unlock(hashtext('glueful:extension-state'))");
        }
        return $got;
    }

    public function testTheSequenceRunsHoldingTheFrameworksLockAndReleasesItAfter(): void
    {
        $other = $this->otherSession();
        $lock = $this->container()->get(ExtensionStateCoordinator::class);
        $heldInside = $lock->within(fn (): bool => !self::tryLock($other));
        self::assertTrue($heldInside, 'another session cannot take the lock inside');
        self::assertTrue(self::tryLock($other), 'released after');
    }

    public function testTheLockIsReleasedWhenTheSequenceThrows(): void
    {
        $other = $this->otherSession();
        try {
            $this->container()->get(ExtensionStateCoordinator::class)->within(static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        self::assertTrue(self::tryLock($other));
    }
}
