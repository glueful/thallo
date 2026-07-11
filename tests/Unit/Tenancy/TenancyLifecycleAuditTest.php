<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use App\Support\TenancyLifecycleAudit;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use PHPUnit\Framework\TestCase;

final class TenancyLifecycleAuditTest extends TestCase
{
    public function testRecordsTenantTargetAndContext(): void
    {
        $entries = [];
        $recorder = new class ($entries) implements AuditRecorderInterface {
            /** @param list<AuditEntry> $entries */
            public function __construct(private array &$entries)
            {
            }

            public function record(AuditEntry $entry): void
            {
                $this->entries[] = $entry;
            }
        };
        $audit = new TenancyLifecycleAudit($recorder);
        $audit->record('tenant.deleted', 'actor0000001', 'tenant000001', ['source' => 'http']);

        self::assertCount(1, $entries);
        self::assertSame('tenant.deleted', $entries[0]->action);
        self::assertSame('tenant', $entries[0]->targetType);
        self::assertSame('tenant000001', $entries[0]->targetUuid);
        self::assertSame(['source' => 'http'], $entries[0]->context);
    }

    public function testRecorderFailureNeverChangesLifecycleOutcome(): void
    {
        $recorder = new class implements AuditRecorderInterface {
            public function record(AuditEntry $entry): void
            {
                throw new \RuntimeException('audit unavailable');
            }
        };

        (new TenancyLifecycleAudit($recorder))->record('tenant.restored', null, 'tenant000001');
        self::assertTrue(true);
    }
}
