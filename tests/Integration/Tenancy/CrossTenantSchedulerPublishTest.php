<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Content\Enums\ScheduleAction;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ScheduleRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Scheduling\ScheduleRunner;
use App\Tests\Support\RetrofittedTenantTestCase;
use PDO;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Workflow\WorkflowStateRepository;

/**
 * MANDATORY ACCEPTANCE B — cross-tenant scheduler publish.
 *
 * A SYSTEM scheduler drains due publish rows for TWO tenants in ONE pass and PUBLISHES EACH INSIDE
 * ITS OWN TENANT CONTEXT, on the fully retrofitted content graph (real engine, barrier down, two
 * tenants seeded).
 *
 * Setup per tenant (under runAsTenant): create a content type, create a draft entry, and schedule a
 * DUE publish (run_at in the past). Every one of those builder writes is stamped with the acting
 * tenant, so the entry_schedules row carries its tenant_uuid.
 *
 * Drive: runAsSystem(fn () => ScheduleRunner::run()). The runner claims the due rows cross-tenant via
 * the raw system path (RETURNING * surfaces each row's tenant_uuid), then re-enters that tenant's
 * context (TenantContextRunner::runAsTenant) to publish — so PublishService's builder writes
 * (entry_versions / entry_publications) are stamped and scoped to the right tenant.
 *
 * Proof:
 *   - a raw UNSCOPED read sees BOTH publications, each carrying the correct tenant_uuid (published in
 *     the right context — an unscoped publish would have written the '' partition);
 *   - a SCOPED read under each tenant sees ONLY its own publication (isolation);
 *   - both schedule rows reach the terminal 'done' outcome.
 */
final class CrossTenantSchedulerPublishTest extends RetrofittedTenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // scheduler_enabled is a SYSTEM key (routed to the unscoped channel). parent::setUp() truncates
        // the system-flags table, so (re)set it ON here, after truncation, so the runner is allowed to run.
        $channel = $this->container()->get(SystemChannel::class);
        self::assertInstanceOf(SystemChannel::class, $channel);
        $channel->put('scheduler_enabled', 'true');
    }

    public function testSystemSchedulerPublishesEachTenantInItsOwnContext(): void
    {
        // A due run_at in the normalized (UTC, explicit-Z) shape the schedule repository persists.
        $dueAt = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);

        // Tenant A: content type + draft entry + due publish, all stamped for tenant A by the builder.
        $entryA = $this->runAsTenant(self::$tenantAUuid, function () use ($dueAt): string {
            $typeUuid = $this->contentTypes()->create(['slug' => 'articles', 'name' => 'Articles A']);
            $entryUuid = $this->entries()->createEntry($typeUuid, 'en', 1, 'user00000001');
            // Approve the review so the workflow publish gate admits the scheduled publish (production
            // schedules a publish on approved content). The approval is stamped for tenant A.
            $this->workflow()->setState($entryUuid, 'en', 'approved');
            $this->schedules()->schedule($entryUuid, 'en', ScheduleAction::Publish, $dueAt, 'user00000001');
            return $entryUuid;
        });

        // Tenant B: identical shape, stamped for tenant B.
        $entryB = $this->runAsTenant(self::$tenantBUuid, function () use ($dueAt): string {
            $typeUuid = $this->contentTypes()->create(['slug' => 'articles', 'name' => 'Articles B']);
            $entryUuid = $this->entries()->createEntry($typeUuid, 'en', 1, 'user00000001');
            $this->workflow()->setState($entryUuid, 'en', 'approved');
            $this->schedules()->schedule($entryUuid, 'en', ScheduleAction::Publish, $dueAt, 'user00000001');
            return $entryUuid;
        });

        self::assertNotSame($entryA, $entryB, 'each tenant minted its own globally-unique entry uuid');

        // Pre-state: neither entry is published yet (a raw unscoped read sees zero publications).
        self::assertCount(0, $this->publicationsByEntry(), 'no publications before the scheduler runs');

        // Drive the SYSTEM path: one drain publishes BOTH tenants' due rows, each in its own context.
        $fired = $this->runAsSystem(fn (): int => $this->runner()->run());
        self::assertSame(2, $fired, 'the single system-path drain fired both tenants\' due publishes');

        // Coexistence + correct-context: a raw UNSCOPED read sees BOTH publications, each carrying its
        // own tenant_uuid. A publish that ran unscoped would have stamped the '' partition here.
        $byEntry = $this->publicationsByEntry();
        self::assertCount(2, $byEntry, 'both entries are now published, one row per tenant');
        self::assertSame(self::$tenantAUuid, $byEntry[$entryA] ?? null, 'entry A published in tenant A context');
        self::assertSame(self::$tenantBUuid, $byEntry[$entryB] ?? null, 'entry B published in tenant B context');

        // Isolation: a SCOPED read under each tenant sees ONLY its own publication.
        $find = fn (string $tenant, string $entry): ?array => $this->runAsTenant(
            $tenant,
            fn (): ?array => $this->versions()->findPublication($entry, 'en'),
        );
        $seenAA = $find(self::$tenantAUuid, $entryA);
        $seenAB = $find(self::$tenantAUuid, $entryB);
        $seenBB = $find(self::$tenantBUuid, $entryB);
        $seenBA = $find(self::$tenantBUuid, $entryA);

        self::assertIsArray($seenAA, 'tenant A sees its own publication');
        self::assertNull($seenAB, 'tenant A does NOT see tenant B\'s publication');
        self::assertIsArray($seenBB, 'tenant B sees its own publication');
        self::assertNull($seenBA, 'tenant B does NOT see tenant A\'s publication');

        // The drained schedule rows reached the terminal 'done' outcome.
        $statuses = $this->connection()->getPDO()
            ->query("SELECT DISTINCT status FROM entry_schedules")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['done'], $statuses, 'both schedule rows finished in the terminal done state');
    }

    /**
     * Raw UNSCOPED map entry_uuid => tenant_uuid over entry_publications, proving physical coexistence
     * and the tenant context each publish actually ran in (bypasses any query-time scoping).
     *
     * @return array<string,string>
     */
    private function publicationsByEntry(): array
    {
        $rows = $this->connection()->getPDO()
            ->query('SELECT entry_uuid, tenant_uuid FROM entry_publications')
            ->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['entry_uuid']] = (string) $row['tenant_uuid'];
        }
        return $map;
    }

    private function contentTypes(): ContentTypeRepository
    {
        $repo = $this->container()->get(ContentTypeRepository::class);
        self::assertInstanceOf(ContentTypeRepository::class, $repo);
        return $repo;
    }

    private function entries(): EntryRepository
    {
        $repo = $this->container()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $repo);
        return $repo;
    }

    private function schedules(): ScheduleRepository
    {
        $repo = $this->container()->get(ScheduleRepository::class);
        self::assertInstanceOf(ScheduleRepository::class, $repo);
        return $repo;
    }

    private function versions(): VersionRepository
    {
        $repo = $this->container()->get(VersionRepository::class);
        self::assertInstanceOf(VersionRepository::class, $repo);
        return $repo;
    }

    private function runner(): ScheduleRunner
    {
        $runner = $this->container()->get(ScheduleRunner::class);
        self::assertInstanceOf(ScheduleRunner::class, $runner);
        return $runner;
    }

    private function workflow(): WorkflowStateRepository
    {
        $repo = $this->container()->get(WorkflowStateRepository::class);
        self::assertInstanceOf(WorkflowStateRepository::class, $repo);
        return $repo;
    }
}
