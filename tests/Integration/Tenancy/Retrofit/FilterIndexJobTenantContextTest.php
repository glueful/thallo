<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Content\Indexing\EnsureFilterIndexesJob;
use App\Tests\Support\RetrofittedTenantTestCase;

/**
 * The two-tenant verification deferred from Task 8: on the fully retrofitted content graph, tenant A
 * and tenant B each own a content type carrying an identically-named filterable `field`. Each
 * {@see EnsureFilterIndexesJob} is run with its own `tenant_uuid` payload through the fail-closed
 * handle(), which reconciles inside runAsTenant(tenant) — so the tenant-owned `content_types` read
 * (schemaFor) is scoped to the owning tenant.
 *
 * Proves isolation: each run loads ONLY its owning content type's schema and writes/reads ONLY its own
 * `filter_indexes` rows. `filter_indexes` is deliberately GLOBAL (not tenant-owned) infrastructure —
 * the isolation is carried by the tenant-scoped `content_types` read plus per-`content_type_uuid`
 * registry rows (the content_type_uuid is a globally-unique nano-id owned by exactly one tenant, so its
 * index name can never collide across tenants). Tenant A's row is left untouched by tenant B's run.
 */
final class FilterIndexJobTenantContextTest extends RetrofittedTenantTestCase
{
    private const TYPE_A = 'ctflta000001';
    private const TYPE_B = 'ctfltb000001';
    private const SCHEMA = '[{"name":"category","type":"string","filterable":true,"filter_type":"string"}]';

    public function testEachJobTouchesOnlyItsOwningTenantsTypeAndRows(): void
    {
        // Each tenant owns a content type with the SAME filterable field name ('category').
        $this->seedType(self::$tenantAUuid, self::TYPE_A, 'articles-a');
        $this->seedType(self::$tenantBUuid, self::TYPE_B, 'articles-b');

        // Tenant A's job: handle() reconciles inside runAsTenant(A). The schemaFor read of content_types
        // is scoped to A, so only TYPE_A's schema is loaded; the registry write keys by TYPE_A's uuid.
        $this->runJob(self::TYPE_A, self::$tenantAUuid);

        $rowsA = $this->filterRows(self::TYPE_A);
        self::assertCount(1, $rowsA, 'tenant A job wrote exactly its own registry row');
        self::assertSame('category', $rowsA[0]['field']);
        self::assertSame('ready', $rowsA[0]['status'], 'the expression index built valid');
        self::assertCount(0, $this->filterRows(self::TYPE_B), 'tenant A job never touched tenant B rows');

        $snapshotA = $rowsA[0]; // capture to prove tenant B's run leaves it untouched

        // Tenant B's job: reconciles inside runAsTenant(B) over its own type only.
        $this->runJob(self::TYPE_B, self::$tenantBUuid);

        $rowsBAfter = $this->filterRows(self::TYPE_B);
        self::assertCount(1, $rowsBAfter, 'tenant B job wrote exactly its own registry row');
        self::assertSame('category', $rowsBAfter[0]['field']);
        self::assertSame('ready', $rowsBAfter[0]['status']);

        // Tenant A's row is unchanged by tenant B's run — the reads/writes never crossed tenants.
        $rowsAAfter = $this->filterRows(self::TYPE_A);
        self::assertCount(1, $rowsAAfter);
        self::assertSame($snapshotA['uuid'], $rowsAAfter[0]['uuid']);
        self::assertSame($snapshotA['index_name'], $rowsAAfter[0]['index_name']);
        self::assertSame($snapshotA['status'], $rowsAAfter[0]['status']);

        // Distinct physical indexes (name derives from the globally-unique content_type_uuid): no
        // cross-tenant collision is possible even with identical field names.
        self::assertNotSame($rowsAAfter[0]['index_name'], $rowsBAfter[0]['index_name']);
    }

    private function seedType(string $tenantUuid, string $typeUuid, string $slug): void
    {
        // Builder insert inside the tenant context → the insert-hook stamps tenant_uuid.
        $this->runAsTenant($tenantUuid, function () use ($typeUuid, $slug): void {
            $this->connection()->table('content_types')->insert([
                'uuid' => $typeUuid, 'slug' => $slug, 'name' => 'Articles',
                'status' => 'active', 'schema' => self::SCHEMA, 'schema_version' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    private function runJob(string $typeUuid, string $tenantUuid): void
    {
        (new EnsureFilterIndexesJob(
            ['content_type_uuid' => $typeUuid, 'tenant_uuid' => $tenantUuid],
            $this->appContext(),
        ))->handle();
    }

    /** @return list<array<string,mixed>> */
    private function filterRows(string $typeUuid): array
    {
        return array_values($this->connection()->table('filter_indexes')
            ->where('content_type_uuid', '=', $typeUuid)
            ->orderBy('field', 'ASC')
            ->get());
    }
}
