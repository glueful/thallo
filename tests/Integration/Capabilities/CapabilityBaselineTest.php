<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Thallo\Core\Capabilities\CapabilityStateVersion;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\CapabilityBaseline;

/**
 * The test harness's capability baseline changes switches the way the application does: a
 * restore that changes one advances the capability-state version, so a compiled route table from
 * before it is never reused; a restore that changes nothing leaves the version alone.
 */
final class CapabilityBaselineTest extends AppTestCase
{
    protected function tearDown(): void
    {
        CapabilityBaseline::restore($this->connection()->getPDO());
        parent::tearDown();
    }

    private function version(): string
    {
        return (new CapabilityStateVersion($this->connection()))->current();
    }

    public function testARestoreThatChangesASwitchAdvancesTheVersion(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key = 'capability.thallo.payments.enabled'");
        $before = $this->version();
        CapabilityBaseline::restore($pdo);
        self::assertGreaterThan((int) $before, (int) $this->version());
    }

    public function testARestoreThatChangesNothingKeepsTheVersion(): void
    {
        $pdo = $this->connection()->getPDO();
        CapabilityBaseline::restore($pdo);
        $before = $this->version();
        CapabilityBaseline::restore($pdo);
        self::assertSame($before, $this->version());
    }
}
