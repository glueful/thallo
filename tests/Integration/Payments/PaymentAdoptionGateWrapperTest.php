<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Thallo\Core\Payments\Tenancy\PaymentAdoptionGateWrapper;
use Thallo\Core\Payments\Tenancy\PaymentTables;
use Thallo\Core\Payments\Tenancy\PaymentTenancyAdoption;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Payvia's tenantless paths — subscription and dispute webhooks — read a row's owner from the row
 * itself, not from the tenant resolver, so the resolver's hold cannot cover them. Before the schema
 * is widened, ANY statement on a payments table therefore holds the adoption gate for the rest of
 * the unit of work: an owner read before the flip can never be used for a write after it.
 */
final class PaymentAdoptionGateWrapperTest extends AppTestCase
{
    private ?AdoptionGate $flip = null;

    protected function tearDown(): void
    {
        $this->flip?->releaseExclusive();
        $this->gate()->release();
        parent::tearDown();
    }

    private function gate(): AdoptionGate
    {
        return $this->container()->get(AdoptionGate::class);
    }

    public function testAStatementOnAPaymentsTableHoldsTheGateOnTheSingleStore(): void
    {
        $this->connection()->table('entries')->limit(1)->get();
        self::assertFalse($this->gate()->isHeld(), 'other tables leave the gate alone');

        $this->connection()->table('gateway_subscriptions')->limit(1)->get();
        self::assertTrue($this->gate()->isHeld());
    }

    public function testAStatementOnAPaymentsTableDuringTheFlipFailsClosed(): void
    {
        $this->flip = new AdoptionGate($this->connection());
        $this->flip->acquireExclusive(200);

        $this->expectException(RetrofitInProgressException::class);
        $this->connection()->table('payments')->limit(1)->get();
    }

    /** A site enabled before payments were adopted keeps the gate until its repair guards them. */
    public function testAWidenedSchemaStillHoldsUntilPaymentsAreGuarded(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', 'tenDefault01');

        $this->connection()->table('gateway_subscriptions')->limit(1)->get();
        self::assertTrue($this->gate()->isHeld(), 'an ownership read before the repair holds the gate');
    }

    public function testGuardedPaymentsNeedNoHold(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', 'tenDefault01');
        $flags->put(PaymentTenancyAdoption::GUARDED_FLAG, '1');

        $this->connection()->table('payments')->limit(1)->get();
        self::assertFalse($this->gate()->isHeld());
    }

    /**
     * The cheap check may only skip what the table matcher would skip: every statement shape the
     * matcher catches, for every table in the inventory, passes the cheap check too.
     */
    public function testTheCheapCheckNeverSkipsWhatTheMatcherCatches(): void
    {
        $shapes = [
            'SELECT * FROM %s WHERE id = ?',
            'select * from "%s" limit 1',
            'INSERT INTO "public"."%s" ("uuid") VALUES (?)',
            'UPDATE %s SET status = ? WHERE uuid = ?',
            'DELETE FROM %s',
            'SELECT a.* FROM orders o JOIN %s a ON a.x = o.y',
            'SELECT count(*) FROM (%s)',
        ];
        foreach (PaymentTables::workspaceOwned() as $table) {
            foreach ([$table, strtoupper($table)] as $name) {
                foreach ($shapes as $shape) {
                    $sql = sprintf($shape, $name);
                    if (PaymentAdoptionGateWrapper::matchesTable($sql)) {
                        self::assertTrue(PaymentAdoptionGateWrapper::mayMatchTable($sql), $sql);
                    }
                }
                self::assertTrue(PaymentAdoptionGateWrapper::matchesTable(sprintf($shapes[0], $name)), $name);
            }
        }
        self::assertFalse(PaymentAdoptionGateWrapper::mayMatchTable('SELECT * FROM entries WHERE id = ?'));
        self::assertFalse(PaymentAdoptionGateWrapper::mayMatchTable('UPDATE thallo_system_flags SET value = ?'));
    }
}
