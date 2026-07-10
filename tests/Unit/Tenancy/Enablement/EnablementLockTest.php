<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Enablement;

use App\Tests\Support\AppTestCase;
use PDO;
use RuntimeException;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Enablement\EnablementLockedException;

final class EnablementLockTest extends AppTestCase
{
    private const LOCK_KEY = 4823710;

    public function testReturnsOperationResultAndExcludesAnotherSession(): void
    {
        $other = $this->newPdo();
        $result = (new EnablementLock($this->connection()))->withLock(function () use ($other): string {
            self::assertFalse($this->tryLock($other));

            return 'complete';
        });

        self::assertSame('complete', $result);
        self::assertTrue($this->tryLock($other));
        $this->unlock($other);
    }

    public function testThrowsWhenAnotherSessionHoldsLock(): void
    {
        $other = $this->newPdo();
        self::assertTrue($this->tryLock($other));

        try {
            $this->expectException(EnablementLockedException::class);
            (new EnablementLock($this->connection()))->withLock(static fn (): null => null);
        } finally {
            $this->unlock($other);
        }
    }

    public function testReleasesLockWhenOperationThrows(): void
    {
        $lock = new EnablementLock($this->connection());

        try {
            $lock->withLock(static function (): never {
                throw new RuntimeException('failed');
            });
            self::fail('Expected the operation to throw.');
        } catch (RuntimeException $exception) {
            self::assertSame('failed', $exception->getMessage());
        }

        $other = $this->newPdo();
        self::assertTrue($this->tryLock($other));
        $this->unlock($other);
    }

    private function tryLock(PDO $pdo): bool
    {
        $stmt = $pdo->prepare('SELECT pg_try_advisory_lock(:key)');
        $stmt->execute(['key' => self::LOCK_KEY]);

        return $stmt->fetchColumn() === true;
    }

    private function unlock(PDO $pdo): void
    {
        $stmt = $pdo->prepare('SELECT pg_advisory_unlock(:key)');
        $stmt->execute(['key' => self::LOCK_KEY]);
    }

    private function newPdo(): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $_ENV['DB_PGSQL_HOST'] ?? getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
            $_ENV['DB_PGSQL_PORT'] ?? getenv('DB_PGSQL_PORT') ?: '5432',
            $_ENV['DB_PGSQL_DATABASE'] ?? getenv('DB_PGSQL_DATABASE') ?: 'app_test',
        );

        return new PDO(
            $dsn,
            (string) ($_ENV['DB_PGSQL_USERNAME'] ?? getenv('DB_PGSQL_USERNAME') ?: 'postgres'),
            (string) ($_ENV['DB_PGSQL_PASSWORD'] ?? getenv('DB_PGSQL_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
