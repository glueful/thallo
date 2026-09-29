<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Tenancy;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;

/**
 * The adoption gate's unit-of-work hold lives on the unit's own database session: holding it opens
 * no connection, any container in the process sees the same hold, and a session that reconnected
 * mid-unit — its lock gone with the old session — fails closed on the next hold instead of carrying
 * on unprotected.
 */
final class AdoptionGateTest extends AppTestCase
{
    private function connections(): int
    {
        return (int) $this->connection()->getPDO()
            ->query('SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()')
            ->fetchColumn();
    }

    public function testHoldingOpensNoConnection(): void
    {
        $gate = new AdoptionGate($this->connection());
        $before = $this->connections();

        self::assertTrue($gate->holdShared());
        self::assertSame($before, $this->connections());
        $gate->release();
    }

    public function testEveryGateOnTheSessionSeesAndReleasesTheSameHold(): void
    {
        $unit = new AdoptionGate($this->connection());
        $other = new AdoptionGate($this->connection());
        $unit->holdShared();

        self::assertTrue($other->isHeld());
        $other->release();
        self::assertFalse($unit->isHeld());
    }

    public function testAReconnectedSessionFailsClosedOnce(): void
    {
        $gate = new AdoptionGate($this->connection());
        $gate->holdShared();

        $this->connection()->reconnect();
        self::assertFalse($gate->isHeld(), 'the lock went with the old session');
        try {
            $gate->holdShared();
            self::fail('what the unit read before the reconnect may be stale');
        } catch (RetrofitInProgressException) {
        }

        self::assertTrue($gate->holdShared(), 'the next unit holds afresh');
        $gate->release();
    }

    /** A worker between jobs: ending the unit releases its holds, so a flip can close the gate. */
    public function testEndingTheUnitOfWorkReleasesItsHolds(): void
    {
        $unit = new AdoptionGate($this->connection());
        $unit->holdShared();

        AdoptionGate::endUnitOfWork();

        self::assertFalse($unit->isHeld());
        $flip = new AdoptionGate($this->connection());
        $flip->acquireExclusive(200);
        self::assertTrue($flip->holdsExclusive());
        self::assertTrue($flip->holdShared(), 'the process moving the rows passes its own statements');
        $flip->releaseExclusive();
        self::assertTrue($unit->holdShared(), 'the next unit holds afresh, not as a lost hold');
        $unit->release();
    }
}
