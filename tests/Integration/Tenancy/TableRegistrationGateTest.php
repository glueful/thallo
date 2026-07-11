<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry as TenantTableRegistryContract;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\TenancyServiceProvider;
use Thallo\Tenancy\ThalloTenantTables;

final class TableRegistrationGateTest extends AppTestCase
{
    protected function tearDown(): void
    {
        // Order-independence: the enabled flag persists in the DB. AppTestCase::setUp() already
        // truncates thallo_system_flags before every test, but reset here too so nothing this
        // test enabled bleeds into an unrelated one if setUp's guard ever changes.
        $this->container()->get(SystemFlags::class)->forget('tenancy.enabled');
        parent::tearDown();
    }

    /** A capturing fake bound to the contract so we can assert what got registered. */
    private function capturingRegistry(): TenantTableRegistryContract
    {
        return new class implements TenantTableRegistryContract {
            /** @var list<string> */
            public array $registered = [];

            public function register(array $tables): void
            {
                $this->registered = array_merge($this->registered, $tables);
            }
        };
    }

    private function provider(): TenancyServiceProvider
    {
        return new TenancyServiceProvider($this->container());
    }

    public function testNoRegistryBindingIsANoOp(): void
    {
        // No contract bound (tenancy extension not active) and none injected => must not register.
        self::assertFalse($this->provider()->registerTenantTables($this->appContext()));
    }

    public function testDisabledFlagIsANoOp(): void
    {
        $fake = $this->capturingRegistry();
        // tenancy.enabled unset => off
        self::assertFalse($this->provider()->registerTenantTables($this->appContext(), $fake));
        self::assertSame([], $fake->registered);
    }

    public function testEnabledWithBindingRegistersAllOwnedTables(): void
    {
        $fake = $this->capturingRegistry();
        $this->container()->get(SystemFlags::class)->put('tenancy.enabled', '1');

        self::assertTrue($this->provider()->registerTenantTables($this->appContext(), $fake));
        self::assertSame(ThalloTenantTables::tableNames(), $fake->registered);
    }
}
