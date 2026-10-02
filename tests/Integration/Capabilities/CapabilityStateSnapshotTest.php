<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Core\Capabilities\CapabilityStateSnapshot;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\CapabilityStateVersion;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Every capability state write advances `capability.state_version` in the same transaction, and
 * each application context decides capabilities from one snapshot of the switches and that
 * version, read in one statement. Only a missing table (or an unreachable database on a console
 * boot) falls back, and then the snapshot says so instead of claiming version 0.
 */
final class CapabilityStateSnapshotTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec(
            "DELETE FROM thallo_system_flags WHERE key IN ('capability.state_version', 'capability.test.flip.enabled')"
        );
        parent::tearDown();
    }

    private function store(): CapabilityStateStore
    {
        return $this->container()->get(CapabilityStateStore::class);
    }

    private function version(): CapabilityStateVersion
    {
        return $this->container()->get(CapabilityStateVersion::class);
    }

    public function testAStateWriteAdvancesTheVersionInTheSameTransaction(): void
    {
        $before = (int) $this->version()->current();
        $this->store()->put('test.flip', true);
        self::assertSame((string) ($before + 1), $this->version()->current());
    }

    public function testACrashBeforeCommitMovesNeitherTheStateNorTheVersion(): void
    {
        $before = $this->version()->current();
        try {
            $this->connection()->transaction(function (): void {
                $this->store()->put('test.flip', true);
                throw new \RuntimeException('killed');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame($before, $this->version()->current());
        self::assertNull($this->store()->fresh('test.flip'));
    }

    public function testTheSnapshotHoldsTheStateAndTheVersionTogether(): void
    {
        $this->store()->put('test.flip', true);
        $snapshot = CapabilityStateSnapshot::take($this->connection(), console: false);
        self::assertTrue($snapshot->available);
        self::assertSame('true', $snapshot->rows['capability.test.flip.enabled']);
        self::assertSame($this->version()->current(), $snapshot->version);
        self::assertTrue($this->store()->explicitFrom($snapshot->rows, 'test.flip'));
    }

    public function testAMissingTableFallsBackAndIsMarkedUnavailable(): void
    {
        $snapshot = null;
        try {
            $this->connection()->transaction(function () use (&$snapshot): void {
                $pdo = $this->connection()->getPDO();
                $pdo->exec('CREATE SCHEMA tmp_snapshot_missing');
                $pdo->exec('SET LOCAL search_path TO tmp_snapshot_missing');
                $snapshot = CapabilityStateSnapshot::take($this->connection(), console: true);
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }
        self::assertInstanceOf(CapabilityStateSnapshot::class, $snapshot);
        self::assertFalse($snapshot->available);
        self::assertSame(CapabilityStateSnapshot::UNAVAILABLE, $snapshot->version);
        self::assertSame([], $snapshot->rows);
    }

    /** A connection that can't be opened, as PDO reports it (SQLSTATE 08006, driver code 7). */
    private static function unreachable(): \Closure
    {
        return static function (): never {
            new \PDO('pgsql:host=127.0.0.1;port=1;dbname=nowhere;connect_timeout=2', 'x', 'y');
            throw new \LogicException('port 1 accepted a connection');
        };
    }

    public function testAnUnreachableDatabaseOnAConsoleBootFallsBack(): void
    {
        $snapshot = CapabilityStateSnapshot::resolve(self::unreachable(), console: true);
        self::assertFalse($snapshot->available);
        self::assertSame(CapabilityStateSnapshot::UNAVAILABLE, $snapshot->version);
    }

    public function testAnUnreachableDatabaseOnAnHttpBootStillThrows(): void
    {
        $this->expectException(\PDOException::class);
        CapabilityStateSnapshot::resolve(self::unreachable(), console: false);
    }

    public function testAnyOtherDatabaseErrorIsNotMasked(): void
    {
        // Another session holds the table exclusively and this one gives up quickly: a lock timeout
        // (55P03), not a missing table, so it must be rethrown.
        $holder = new \PDO(
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
        $holder->beginTransaction();
        $holder->exec('LOCK TABLE thallo_system_flags IN ACCESS EXCLUSIVE MODE');
        $pdo = $this->connection()->getPDO();
        $pdo->exec("SET lock_timeout = '100ms'");
        $code = null;
        try {
            CapabilityStateSnapshot::take($this->connection(), console: true);
        } catch (\PDOException $e) {
            $code = (string) $e->getCode();
        } finally {
            $pdo->exec('RESET lock_timeout');
            $holder->rollBack();
        }
        self::assertSame('55P03', $code, 'a lock timeout is rethrown, not taken for a missing table');
    }

    public function testTheRegistryDecidesFromTheContextsSnapshotNotALaterWrite(): void
    {
        // A fresh context takes its snapshot while booting; a switch written afterwards does not
        // change that context's decision.
        $context = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.search' => false]]);
        $registry = $context->getContainer()->get(CapabilityRegistry::class);
        $this->store()->put('test.flip', false);
        $registry->register(new Capability('test.flip'));
        self::assertTrue(
            $registry->isEnabled('test.flip'),
            'decided from the snapshot: no switch → follows availability',
        );
    }
}
