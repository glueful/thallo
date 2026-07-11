<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofittedTenantTestCase;
use PDO;

/**
 * Proves that after the two-boot transition — real retrofit under boot1, enablement flipped,
 * process-global hooks reset, a FRESH boot2 arming the read-hook/stamper/guard, and the barrier
 * lowered through boot2 — a plain builder insert that OMITS tenant_uuid is auto-stamped with the
 * current tenant by the freshly-armed insert-hook.
 *
 * The insert deliberately supplies NO tenant_uuid, so success alone proves stamping happened (a
 * repository that supplied tenant_uuid explicitly, e.g. SeoMetaRepository::upsert, would NOT). The
 * row is read back via raw PDO (unscoped) so the read itself proves the value the hook wrote — not a
 * scoped read that could only ever return the current tenant's rows. Hook COUNT is not observable, so
 * no claim is made about it; the point is that a clean second boot stamps at all.
 */
final class PostTransitionStamperTest extends RetrofittedTenantTestCase
{
    public function testLoweredBarrierStampsOmittedTenantUuidAfterTransition(): void
    {
        // Builder insert with NO tenant_uuid in the payload → the fresh insert-hook must inject it.
        $this->runAsTenant(self::$tenantAUuid, function (): void {
            $this->connection()->table('content_types')->insert([
                'uuid' => 'ctstamp00001', 'slug' => 'stamped', 'name' => 'Stamped',
                'status' => 'active', 'schema' => '[]', 'schema_version' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                // deliberately NO 'tenant_uuid'
            ]);
        });

        // Inspect via raw PDO (unscoped) so the read itself proves the value the hook wrote.
        $row = $this->connection()->getPDO()
            ->query("SELECT tenant_uuid FROM content_types WHERE uuid = 'ctstamp00001'")
            ->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame(self::$tenantAUuid, $row['tenant_uuid']); // stamped by the fresh boot2 stamper
    }
}
