<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\RouteRepository;
use App\Tests\Support\RetrofittedTenantTestCase;
use PDO;
use Thallo\Workflow\WorkflowStateRepository;

/**
 * MANDATORY ACCEPTANCE A — identical business keys coexist across tenants.
 *
 * On the fully retrofitted content graph (real engine, barrier down, two tenants seeded), two tenants
 * persist IDENTICAL business keys with NO collision, across the three widened-unique shapes:
 *   - slug shape        content_types           unique (tenant_uuid, slug)
 *   - composite shape   entry_routes            unique (tenant_uuid, content_type_uuid, locale, slug)
 *   - ON-CONFLICT shape workflow_review_states  upsert target (tenant_uuid, entry_uuid, locale)
 *
 * Coexistence is proven by a RAW UNSCOPED read (via getPDO) that sees BOTH tenants' rows; isolation is
 * proven by a SCOPED read under each tenant that sees ONLY its own row. If a unique had NOT been widened
 * with tenant_uuid, the second tenant's write would collide (slug/composite) or clobber the first tenant's
 * row (ON-CONFLICT) — so two independent rows, each carrying the right tenant_uuid, is the acceptance.
 */
final class TenantKeyCoexistenceTest extends RetrofittedTenantTestCase
{
    // --- slug shape: content_types unique (tenant_uuid, slug) ---------------------------------------

    public function testIdenticalContentTypeSlugCoexistsAcrossTenants(): void
    {
        // Same slug 'articles' created under each tenant. The builder insert is stamped with the current
        // tenant, so the widened (tenant_uuid, slug) unique lets both succeed with distinct global uuids.
        $uuidA = $this->runAsTenant(
            self::$tenantAUuid,
            fn(): string => $this->contentTypes()->create(['slug' => 'articles', 'name' => 'Articles A']),
        );
        $uuidB = $this->runAsTenant(
            self::$tenantBUuid,
            fn(): string => $this->contentTypes()->create(['slug' => 'articles', 'name' => 'Articles B']),
        );
        self::assertNotSame($uuidA, $uuidB, 'each tenant got its own globally-unique content type uuid');

        // Coexistence: a raw unscoped read sees BOTH rows — one per tenant.
        $byTenant = $this->rawMap(
            "SELECT tenant_uuid, uuid FROM content_types WHERE slug = 'articles'",
            'tenant_uuid',
            'uuid',
        );
        self::assertCount(2, $byTenant, 'two content_types rows share slug=articles, one per tenant');
        self::assertSame($uuidA, $byTenant[self::$tenantAUuid] ?? null);
        self::assertSame($uuidB, $byTenant[self::$tenantBUuid] ?? null);

        // Isolation: a scoped read under each tenant sees ONLY its own row.
        $seenA = $this->runAsTenant(self::$tenantAUuid, fn(): ?array => $this->contentTypes()->findBySlug('articles'));
        $seenB = $this->runAsTenant(self::$tenantBUuid, fn(): ?array => $this->contentTypes()->findBySlug('articles'));
        self::assertIsArray($seenA);
        self::assertIsArray($seenB);
        self::assertSame($uuidA, $seenA['uuid'], 'tenant A scoped read returns only tenant A row');
        self::assertSame($uuidB, $seenB['uuid'], 'tenant B scoped read returns only tenant B row');
    }

    // --- composite shape: entry_routes unique (tenant_uuid, content_type_uuid, locale, slug) --------

    public function testIdenticalEntryRouteCompositeCoexistsAcrossTenants(): void
    {
        // Identical composite business key (content_type_uuid, locale, slug) under each tenant, differing
        // only by entry_uuid. Without the widened (tenant_uuid, ...) unique this second write would collide.
        $contentTypeUuid = 'ctroute00001';
        $locale = 'en';
        $slug = 'hello-world';

        $this->runAsTenant(self::$tenantAUuid, function () use ($contentTypeUuid, $locale, $slug): void {
            $this->routes()->assign('entryaaaa001', $contentTypeUuid, $locale, $slug);
        });
        $this->runAsTenant(self::$tenantBUuid, function () use ($contentTypeUuid, $locale, $slug): void {
            $this->routes()->assign('entrybbbb001', $contentTypeUuid, $locale, $slug);
        });

        // Coexistence: a raw unscoped read sees BOTH routes — one per tenant, same composite key.
        $byTenant = $this->rawMap(
            "SELECT tenant_uuid, entry_uuid FROM entry_routes"
            . " WHERE content_type_uuid = 'ctroute00001' AND locale = 'en' AND slug = 'hello-world'",
            'tenant_uuid',
            'entry_uuid',
        );
        self::assertCount(2, $byTenant, 'two entry_routes rows share the composite key, one per tenant');
        self::assertSame('entryaaaa001', $byTenant[self::$tenantAUuid] ?? null);
        self::assertSame('entrybbbb001', $byTenant[self::$tenantBUuid] ?? null);

        // Isolation: a scoped lookup by the SAME composite key returns each tenant's own row only.
        $seenA = $this->runAsTenant(
            self::$tenantAUuid,
            fn(): ?array => $this->routes()->findBySlug($contentTypeUuid, $locale, $slug),
        );
        $seenB = $this->runAsTenant(
            self::$tenantBUuid,
            fn(): ?array => $this->routes()->findBySlug($contentTypeUuid, $locale, $slug),
        );
        self::assertIsArray($seenA);
        self::assertIsArray($seenB);
        self::assertSame('entryaaaa001', $seenA['entry_uuid'], 'tenant A scoped read returns only tenant A route');
        self::assertSame('entrybbbb001', $seenB['entry_uuid'], 'tenant B scoped read returns only tenant B route');
    }

    // --- ON-CONFLICT shape: workflow_review_states target (tenant_uuid, entry_uuid, locale) ----------

    public function testIdenticalWorkflowStateKeyCoexistsAcrossTenants(): void
    {
        // Same (entry_uuid, locale) upserted under each tenant with DIFFERENT states. With the widened
        // ON-CONFLICT target (tenant_uuid, entry_uuid, locale) the two upserts do not clobber each other;
        // had the target stayed (entry_uuid, locale), tenant B's setState would overwrite tenant A's row.
        $entryUuid = 'entrywf00001';
        $locale = 'en';

        $this->runAsTenant(self::$tenantAUuid, function () use ($entryUuid, $locale): void {
            $this->workflow()->setState($entryUuid, $locale, 'pending');
        });
        $this->runAsTenant(self::$tenantBUuid, function () use ($entryUuid, $locale): void {
            $this->workflow()->setState($entryUuid, $locale, 'approved');
        });

        // Coexistence: two independent rows for the same (entry_uuid, locale), one per tenant.
        $byTenant = $this->rawMap(
            "SELECT tenant_uuid, state FROM workflow_review_states WHERE entry_uuid = 'entrywf00001' AND locale = 'en'",
            'tenant_uuid',
            'state',
        );
        self::assertCount(2, $byTenant, 'two workflow states share the (entry, locale) key, one per tenant');
        self::assertSame('pending', $byTenant[self::$tenantAUuid] ?? null);
        self::assertSame('approved', $byTenant[self::$tenantBUuid] ?? null);

        // Isolation + no-clobber: each tenant's scoped read still sees its own state after the other wrote.
        $stateA = $this->runAsTenant(
            self::$tenantAUuid,
            fn(): string => $this->workflow()->stateOf($entryUuid, $locale),
        );
        $stateB = $this->runAsTenant(
            self::$tenantBUuid,
            fn(): string => $this->workflow()->stateOf($entryUuid, $locale),
        );
        self::assertSame('pending', $stateA, 'tenant A state untouched by tenant B upsert (no ON-CONFLICT clobber)');
        self::assertSame('approved', $stateB, 'tenant B state is its own independent row');
    }

    // --- alias-read regression (B2 gap surfaced by Task 15) -----------------------------------------

    public function testAliasedOwnedReadStaysScopedAcrossTenants(): void
    {
        // `table('content_types as ct')` used to FAIL-CLOSE under tenancy-on: the auto-injection hook
        // matched only exact table names, so the aliased read went unscoped and TenantQueryGuard threw.
        // The alias-aware hook now injects `ct.tenant_uuid = ?` — valid SQL AND tenant-scoped.
        $this->runAsTenant(self::$tenantAUuid, fn(): string => $this->contentTypes()
            ->create(['slug' => 'aliased-a', 'name' => 'A']));
        $this->runAsTenant(self::$tenantBUuid, fn(): string => $this->contentTypes()
            ->create(['slug' => 'aliased-b', 'name' => 'B']));

        $slugsA = $this->runAsTenant(self::$tenantAUuid, fn(): array => array_column(
            $this->connection()->table('content_types as ct')->get(),
            'slug',
        ));
        $slugsB = $this->runAsTenant(self::$tenantBUuid, fn(): array => array_column(
            $this->connection()->table('content_types as ct')->get(),
            'slug',
        ));

        self::assertSame(['aliased-a'], $slugsA); // scoped by ct.tenant_uuid — only tenant A's row
        self::assertSame(['aliased-b'], $slugsB);
    }

    public function testAliasedJoinOfOwnedTablesStaysScoped(): void
    {
        // Aliased primary owned table joined to another owned table — the real delivery/publish read
        // shape. Scoping the primary + a correlated join key keeps the result tenant-isolated.
        $this->runAsTenant(self::$tenantAUuid, function (): void {
            $uuid = $this->contentTypes()->create(['slug' => 'joina', 'name' => 'JA']);
            $this->routes()->assign('enjoina0001', $uuid, 'en', 'route-a');
        });
        $this->runAsTenant(self::$tenantBUuid, function (): void {
            $uuid = $this->contentTypes()->create(['slug' => 'joinb', 'name' => 'JB']);
            $this->routes()->assign('enjoinb0001', $uuid, 'en', 'route-b');
        });

        $rows = $this->runAsTenant(self::$tenantAUuid, fn(): array => $this->connection()
            ->table('content_types as ct')
            ->join('entry_routes as r', 'r.content_type_uuid', '=', 'ct.uuid')
            ->select(['ct.slug'])
            ->get());

        self::assertSame(['joina'], array_column($rows, 'slug')); // no fail-close, no cross-tenant leak
    }

    // --- helpers ------------------------------------------------------------------------------------

    private function contentTypes(): ContentTypeRepository
    {
        $repo = $this->container()->get(ContentTypeRepository::class);
        self::assertInstanceOf(ContentTypeRepository::class, $repo);
        return $repo;
    }

    private function routes(): RouteRepository
    {
        $repo = $this->container()->get(RouteRepository::class);
        self::assertInstanceOf(RouteRepository::class, $repo);
        return $repo;
    }

    private function workflow(): WorkflowStateRepository
    {
        $repo = $this->container()->get(WorkflowStateRepository::class);
        self::assertInstanceOf(WorkflowStateRepository::class, $repo);
        return $repo;
    }

    /**
     * Raw UNSCOPED read (bypasses the tenancy scope) keyed by $key → $value, proving both tenants' rows
     * physically coexist regardless of any query-time scoping.
     *
     * @return array<string,string>
     */
    private function rawMap(string $sql, string $key, string $value): array
    {
        $rows = $this->connection()->getPDO()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row[$key]] = (string) $row[$value];
        }
        return $map;
    }
}
