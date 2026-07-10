<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Support\TenantOracleTestCase;
use Thallo\Workflow\WorkflowStateRepository;

final class WorkflowStateTenantScopeTest extends TenantOracleTestCase
{
    private function repo(): WorkflowStateRepository
    {
        return $this->container()->get(WorkflowStateRepository::class);
    }

    public function testQueueAndStateAreScopedToTenant(): void
    {
        // DISTINCT entries per tenant (the narrow unique(entry_uuid,locale) stays — harness pin).
        $this->runAsTenant(self::$tenantAUuid, fn () => $this->repo()->setState('entry-a', 'en', 'pending'));
        $this->runAsTenant(self::$tenantBUuid, fn () => $this->repo()->setState('entry-b', 'en', 'pending'));

        // queuePage() is raw SQL: unscoped it would return BOTH tenants' pending rows.
        $aQueue = $this->runAsTenant(self::$tenantAUuid, fn () => $this->repo()->queuePage('pending', 1, 50));
        $bQueue = $this->runAsTenant(self::$tenantBUuid, fn () => $this->repo()->queuePage('pending', 1, 50));

        self::assertSame(1, $aQueue['total']);
        self::assertSame('entry-a', $aQueue['items'][0]['entry_uuid']);
        self::assertSame(1, $bQueue['total']);
        self::assertSame('entry-b', $bQueue['items'][0]['entry_uuid']);

        // stateOf() is a builder read (auto-scoped): tenant A cannot see tenant B's row.
        $stateOfA = fn (string $entry): string => $this->runAsTenant(
            self::$tenantAUuid,
            fn () => $this->repo()->stateOf($entry, 'en'),
        );
        self::assertSame('pending', $stateOfA('entry-a'));
        self::assertSame('draft', $stateOfA('entry-b'));
    }
}
