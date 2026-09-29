<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

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

    public function testAWidenedSchemaNeedsNoHold(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', 'tenDefault01');

        $this->connection()->table('payments')->limit(1)->get();
        self::assertFalse($this->gate()->isHeld());
    }
}
