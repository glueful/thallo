<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Tenancy\Enablement;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\TenancyEnablement;

/**
 * Enabling workspaces was refused with a data collection defined, but only at the confirm step,
 * after the tenancy extension had been installed and migrated; nothing on the screen said so
 * before. The status names each blocker up front, and the flow refuses before it installs.
 */
final class EnablementBlockersTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec("DELETE FROM collection_definitions WHERE name = 'blockprobe'");
        parent::tearDown();
    }

    private function defineCollection(): void
    {
        $this->connection()->getPDO()->exec(
            "INSERT INTO collection_definitions (uuid, tenant_uuid, name, label, table_name, fields) "
            . "VALUES ('blockprobe000000000001', 'sentinel0001', 'blockprobe', 'Probe', 'col_blockprobe', '[]')"
        );
    }

    public function testNoBlockersWhenNothingStandsInTheWay(): void
    {
        self::assertSame([], $this->container()->get(TenancyEnablement::class)->status()->blockers);
    }

    public function testADefinedCollectionIsNamedBeforeAnythingStarts(): void
    {
        $this->defineCollection();

        $blockers = $this->container()->get(TenancyEnablement::class)->status()->blockers;

        self::assertSame(['collections'], array_column($blockers, 'code'));
        self::assertStringContainsString('collection', $blockers[0]['message']);
    }

    public function testBeginRefusesBeforeInstallingWhileACollectionIsDefined(): void
    {
        $this->defineCollection();
        $this->container()->get(EnablementStore::class)->setStep(EnablementStep::OFF);

        $status = $this->container()->get(TenancyEnablement::class)->begin();

        self::assertSame(EnablementStep::FAILED, $status->step);
        self::assertStringContainsString('collection', (string) $status->failure);
        self::assertSame(EnablementStep::OFF, $this->container()->get(EnablementStore::class)->failedFrom());
    }
}
