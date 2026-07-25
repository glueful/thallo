<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Starter;

use App\Tests\Support\RetrofittedTenantTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Contracts\Starter\StarterContentTypeContributor;
use Thallo\Contracts\Starter\StarterContentTypeDefinition;
use Thallo\Contracts\Starter\StarterContributorRegistry;
use Thallo\Tenancy\Console\TenantSyncCommand;
use Thallo\Tenancy\Contracts\TenantSeedRepair;

/**
 * Task 5 — the tenant-provisioning/sync half of the starter content-type contributor seam
 * ({@see \App\Tests\Integration\Content\Starter\StarterContributorTest} covers the pure
 * conversion/validation logic and DI wiring). Extends the same opt-in Postgres retrofit harness
 * as its siblings {@see \App\Tests\Integration\Tenancy\StarterSeedIntegrationTest} and
 * {@see \App\Tests\Integration\Tenancy\StarterSyncIntegrationTest} — TenantSeeder/StarterSync are
 * only meaningfully exercisable against a real widened multi-tenant schema (THALLO_TENANCY_DEV_LINK=1);
 * the class self-skips otherwise, matching those two.
 *
 * A single stub contributor is registered ONCE for the whole class (setUpBeforeClass, into this
 * class's own throwaway boot's container) so both tests below see the same contributed content
 * type, mirroring how a pack registers its contributor once at boot in production.
 */
final class StarterContributorTenancyTest extends RetrofittedTenantTestCase
{
    private const CONTRIBUTED_SLUG = 'event';
    private const CONTRIBUTED_SOURCE_ID = 'content_type:event';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$onApp === null) {
            return; // harness skipped (THALLO_TENANCY_DEV_LINK not set) — nothing to wire.
        }
        self::$onApp->getContainer()->get(StarterContributorRegistry::class)->register(
            self::stubContributor(),
        );
    }

    public function testFreshTenantProvisioningCreatesTheContributedContentType(): void
    {
        $this->container()->get(TenantSeedRepair::class)->repair(self::$tenantAUuid);

        $slugs = $this->runAsTenant(
            self::$tenantAUuid,
            fn() => array_column(
                $this->connection()->table('content_types')->orderBy('slug', 'ASC')->get(),
                'slug',
            ),
        );

        self::assertSame(['category', self::CONTRIBUTED_SLUG, 'pages', 'post'], $slugs);
    }

    public function testTenantSyncWithKindContentTypeAdoptsTheContributedTypeIdempotently(): void
    {
        $first = $this->syncContentTypeKind(self::$tenantBUuid);
        self::assertSame(
            'added',
            $first[self::CONTRIBUTED_SOURCE_ID] ?? null,
            'first sync of a pre-existing (unseeded) tenant must add the contributed type',
        );

        $second = $this->syncContentTypeKind(self::$tenantBUuid);
        self::assertSame(
            'unchanged',
            $second[self::CONTRIBUTED_SOURCE_ID] ?? null,
            'a second sync run must be a no-op for the already-adopted contributed type',
        );
    }

    /** @return array<string,string> source_id => action */
    private function syncContentTypeKind(string $tenantUuid): array
    {
        // TenantSyncCommand is auto-discovered (Thallo\Tenancy\TenancyServiceProvider::boot() ->
        // discoverCommands()), not bound as a resolvable container service — construct it directly
        // with the container + context, same as ProvisionCommandTest does for ProvisionCommand.
        $command = new TenantSyncCommand($this->container(), $this->appContext());
        $tester = new CommandTester($command);
        $exit = $tester->execute(['uuid' => $tenantUuid, '--kind' => 'content_type']);
        self::assertSame(0, $exit, $tester->getDisplay());

        /** @var list<array{kind:string,source_id:string,action:string}> $report */
        $report = json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR);

        return array_column($report, 'action', 'source_id');
    }

    private static function stubContributor(): StarterContentTypeContributor
    {
        return new class implements StarterContentTypeContributor {
            /** @return list<StarterContentTypeDefinition> */
            public function contentTypeDefinitions(): array
            {
                return [new StarterContentTypeDefinition(
                    sourceId: 'content_type:event',
                    slug: 'event',
                    name: 'Events',
                    description: 'Contributed by a pack for tenancy-seam coverage.',
                    cacheTtl: null,
                    publicDelivery: true,
                    mountAtRoot: false,
                    schema: [['name' => 'title', 'type' => 'string', 'required' => true]],
                )];
            }
        };
    }
}
