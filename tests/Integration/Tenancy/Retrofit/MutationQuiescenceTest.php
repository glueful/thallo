<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofittedTenantTestCase;
use Glueful\Database\Connection;
use PDOException;
use Thallo\Tenancy\Retrofit\MutationBoundaryLock;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;

final class MutationQuiescenceTest extends RetrofittedTenantTestCase
{
    public function testOwnedMutationIsRejectedWhileRetrofitHoldsExclusiveLock(): void
    {
        $lock = $this->container()->get(MutationBoundaryLock::class);
        $lock->acquireExclusive();
        $threw = false;
        try {
            $this->runAsTenant(self::$tenantAUuid, function (): void {
                $this->connection()->table('content_types')->insert([
                    'uuid' => 'mutlock00001',
                    'slug' => 'blocked',
                    'name' => 'Blocked',
                    'status' => 'active',
                    'schema' => '[]',
                    'schema_version' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            });
        } catch (RetrofitInProgressException) {
            $threw = true;
        } finally {
            $lock->releaseExclusive();
        }

        self::assertTrue($threw);
    }

    public function testFailedMutationTransactionStillReleasesSharedBoundary(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->beginTransaction();
        $threw = false;
        try {
            $this->runAsTenant(self::$tenantAUuid, function (): void {
                $this->connection()->table('content_types')->insert([
                    'uuid' => 'mutfail00001',
                    'nonexistent_column' => 'x',
                ]);
            });
        } catch (PDOException) {
            $threw = true;
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        self::assertTrue($threw);

        $lock = $this->container()->get(MutationBoundaryLock::class);
        $lock->acquireExclusive();
        $lock->releaseExclusive();
        self::addToAssertionCount(1);
    }

    public function testExclusiveAcquireWaitsForSharedHolderToFinish(): void
    {
        $connection = $this->container()->get(Connection::class);
        $participant = $connection->newPdo();
        $contender = $connection->newPdo();

        self::assertTrue((bool) $participant
            ->query('SELECT pg_try_advisory_lock_shared(4823711)')->fetchColumn());
        self::assertFalse((bool) $contender
            ->query('SELECT pg_try_advisory_lock(4823711)')->fetchColumn());

        $participant->exec('SELECT pg_advisory_unlock_shared(4823711)');
        self::assertTrue((bool) $contender
            ->query('SELECT pg_try_advisory_lock(4823711)')->fetchColumn());
        $contender->exec('SELECT pg_advisory_unlock(4823711)');
    }
}
