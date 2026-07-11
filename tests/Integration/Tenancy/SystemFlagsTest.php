<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Query\TenantInsertStamper;
use Thallo\Tenancy\System\SystemFlags;

final class SystemFlagsTest extends AppTestCase
{
    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    public function testDefaultsWhenNothingSet(): void
    {
        self::assertFalse($this->flags()->tenancyEnabled());
        self::assertSame('none', $this->flags()->schemaState());
        self::assertNull($this->flags()->defaultTenantUuid());
    }

    public function testPutGetForgetRoundTrip(): void
    {
        $flags = $this->flags();
        $flags->put('tenancy.enabled', '1');
        $flags->put('tenancy.schema_state', 'widened');
        $flags->put('tenancy.default_tenant_uuid', 'ten000000001');

        // Re-read (the shared instance's cache was invalidated by put()) to prove it persisted.
        $fresh = $this->container()->get(SystemFlags::class);
        self::assertTrue($fresh->tenancyEnabled());
        self::assertSame('widened', $fresh->schemaState());
        self::assertSame('ten000000001', $fresh->defaultTenantUuid());

        $fresh->forget('tenancy.enabled');
        self::assertFalse($this->container()->get(SystemFlags::class)->tenancyEnabled());
    }

    public function testWritesAreNotStampedWhileTheTenantInsertHookIsActive(): void
    {
        if (!method_exists(Connection::class, 'addInsertHook')) {
            self::markTestSkipped('Framework lacks the A2 insert-hook seam (pinned at release).');
        }
        if (!class_exists(TenantInsertStamper::class)) {
            self::markTestSkipped('glueful/tenancy not dev-linked — stamper regression skipped.');
        }

        // Arm the REAL tenant stamper and establish a request context (deliberately with NO tenant):
        // for a tenant-OWNED table this setup would fail closed, but thallo_system_flags is not
        // owned, so the stamper returns early — proving the channel is excluded from scoping.
        CurrentContext::set($this->appContext());
        Connection::addInsertHook(TenantInsertStamper::hook());

        try {
            $this->container()->get(SystemFlags::class)->put('tenancy.enabled', '1');

            $row = db($this->appContext())->table('thallo_system_flags')
                ->where(['key' => 'tenancy.enabled'])->first();
            self::assertSame('1', $row['value'], 'system-channel write still works under active hooks');
            self::assertArrayNotHasKey('tenant_uuid', $row, 'system-channel row was never tenant-stamped');
        } finally {
            Connection::clearInsertHooks();
            CurrentContext::clear();
        }
    }
}
