<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\System\SystemFlags;

final class EnablementOrderingTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec(
            "DELETE FROM thallo_system_flags WHERE key IN "
            . "('tenancy.enabled','tenancy.enable_step','tenancy.retrofit_active')"
        );
        parent::tearDown();
    }

    public function testEnforcementActiveOnlyWhenOnAndBarrierDown(): void
    {
        self::assertFalse($this->flags()->enforcementActive());

        $this->flags()->put('tenancy.enabled', '1');
        $this->flags()->put('tenancy.enable_step', 'reloading');
        self::assertFalse($this->flags()->enforcementActive());

        $this->flags()->put('tenancy.enable_step', 'on');
        $this->flags()->put('tenancy.retrofit_active', '1');
        self::assertFalse($this->flags()->enforcementActive());

        $this->flags()->put('tenancy.retrofit_active', '0');
        self::assertTrue($this->flags()->enforcementActive());
    }

    public function testEnforcementActiveRefreshesRemoteLifecycleChanges(): void
    {
        $worker = new SystemFlags($this->appContext());
        $writer = new SystemFlags($this->appContext());

        self::assertFalse($worker->enforcementActive());
        $writer->put('tenancy.enabled', '1');
        $writer->put('tenancy.enable_step', 'on');
        $writer->put('tenancy.retrofit_active', '0');
        self::assertTrue($worker->enforcementActive());

        $writer->put('tenancy.enable_step', 'disabling');
        self::assertFalse($worker->enforcementActive());
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }
}
