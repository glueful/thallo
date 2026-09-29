<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Commerce's orders and products move into the default workspace in the same flip as payments, so
 * they are kept apart from it the same way: a single-store unit of work that resolves commerce's
 * tenant, or touches a commerce table, holds the adoption gate, and one that tries during the flip
 * is refused — never a read of an emptied single-store partition.
 */
final class CommerceAdoptionGateTest extends AppTestCase
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

    public function testResolvingTheSingleStoreHoldsTheGate(): void
    {
        self::assertSame('', $this->container()->get(CommerceTenantResolution::class)->tenantUuid($this->appContext()));
        self::assertTrue($this->gate()->isHeld());
    }

    public function testResolvingTheSingleStoreDuringTheFlipFailsClosed(): void
    {
        $this->flip = new AdoptionGate($this->connection());
        $this->flip->acquireExclusive(200);

        $this->expectException(RetrofitInProgressException::class);
        $this->container()->get(CommerceTenantResolution::class)->tenantUuid($this->appContext());
    }

    public function testACommerceStatementHoldsTheGateUntilTheSchemaIsWidened(): void
    {
        $this->connection()->table('commerce_orders')->limit(1)->get();
        self::assertTrue($this->gate()->isHeld());
        $this->gate()->release();

        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', 'tenDefault01');
        $this->connection()->table('commerce_orders')->limit(1)->get();
        self::assertFalse($this->gate()->isHeld(), 'commerce has no repair: widened is final for it');
    }
}
