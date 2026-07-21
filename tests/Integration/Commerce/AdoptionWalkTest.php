<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Starter\DefaultStarterContributorRegistry;
use App\Content\Starter\Kinds\ContentTypeKind;
use App\Tests\Support\RecordingExtensionActivation;
use App\Tests\Support\RetrofitHarnessTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CatalogReader;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Commerce\Adoption\CommerceAdoptionContributor;
use Thallo\Commerce\Console\ReconcileLinksCommand;
use Thallo\Commerce\Links\ProductLinkRepository;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Starter\ProductPageContributor;
use Thallo\Contracts\Content\EntryExistenceReader;
use Thallo\Tenancy\Adoption\AdoptionContributorRegistry;
use Thallo\Tenancy\Cache\CacheTransition;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\FinalizationProbe;
use Thallo\Tenancy\Enablement\TenancyEnablement;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Commerce-Slice-1 Task 12: the full sentinel -> widened -> enforced adoption walk (design spec
 * §10 "Adoption") — a single-store install with commerce products + a canonical link + a
 * `product_page` entry, all still on the `''` sentinel, driven through the REAL
 * `TenancyEnablement::confirm()` -> two-boot `finalize()` machine (mirrors
 * `EnableFullMachineAcceptanceTest`/`EnableToOnAcceptanceTest`'s established two-boot dance and
 * `CommerceAdoptionEnablementTest`'s manually-constructed-service convention), then proves reads
 * resolve through the real container under active enforcement (mode (c)), that
 * `commerce:tenancy:adopt`'s mixed-data refusal survives the walk, and closes the boundary-review
 * carry-forward: `LinkReconciler::withTenantContext`'s `runAsTenant` branch (only reachable once
 * enforcement is genuinely active) removes a stale link while leaving a healthy one untouched.
 *
 * Opt-in (THALLO_TENANCY_DEV_LINK=1), self-skips otherwise -- see
 * `RetrofitHarnessTestCase`/phpunit.xml's `tenancy-retrofit` comment: retrofit-harness classes
 * must run in their own invocation, exactly like every other Commerce acceptance test in this
 * directory (CommerceAdoptionEnablementTest/CommercePurgePipelineTest/
 * ProductPageStarterTenancyTest).
 */
final class AdoptionWalkTest extends RetrofitHarnessTestCase
{
    private const PRODUCT_TYPE = [
        'status' => 'active',
        'type' => 'external',
    ];

    protected static function includeTenancyExtensionOnEngineBoot(): bool
    {
        return false;
    }

    public function testSentinelToWidenedToEnforcedWalkAdoptsDataThenReadsResolveUnderEnforcement(): void
    {
        $boot1 = self::$engineApp;
        self::assertNotNull($boot1);
        $container1 = $boot1->getContainer();
        $connection1 = $this->connection();

        // ---------------------------------------------------------------------------------
        // 1. Single-store install: commerce product + a product_page entry + a link, all
        //    implicitly on the '' sentinel (pre-retrofit -- no tenant_uuid column exists yet
        //    on entries/content_types, and Commerce resolves mode (a) '' with no flags set).
        // ---------------------------------------------------------------------------------
        $typeUuid = $this->createProductPageType($boot1);
        $product = (string) $container1->get(CatalogService::class)->createProduct($boot1, [
            ...self::PRODUCT_TYPE,
            'slug' => 'walk-product',
            'name' => 'Walk Product',
            'metadata' => ['external_url' => 'https://example.test/walk-product'],
        ])['uuid'];
        $entry = $container1->get(EntryRepository::class)->createEntry($typeUuid, 'en', 1, null);
        $link = $container1->get(ProductLinkService::class)->link($boot1, $product, $entry);
        self::assertSame('', $link['tenant_uuid'], 'sanity: the link starts on the sentinel tenant');

        $adoptionRegistry = $container1->get(AdoptionContributorRegistry::class);
        self::assertNotEmpty(
            array_filter(
                $adoptionRegistry->all(),
                static fn ($c): bool => $c->id() === CommerceAdoptionContributor::ID,
            ),
            'sanity: the pack\'s own boot() must have already registered its adoption contributor',
        );

        // ---------------------------------------------------------------------------------
        // 2. confirm(): real retrofit (widens + adopts core Thallo tables, including
        //    content_types/entries -- "starter adopted into the default tenant") THEN the
        //    registered AdoptionContributor (link rows + Commerce's own TenantAdopter).
        // ---------------------------------------------------------------------------------
        $activation = new RecordingExtensionActivation();
        $service1 = $this->service($boot1, $activation, $adoptionRegistry);

        self::assertSame(EnablementStep::MIGRATING_EXTENSION, $service1->begin()->step);
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $service1->begin()->step);
        $confirmed = $service1->confirm('commerce-walk', 'Commerce Walk', 'user00000001');
        self::assertSame(
            EnablementStep::RELOADING,
            $confirmed->step,
            (string) $container1->get(EnablementStore::class)->failure(),
        );

        $flags1 = $container1->get(SystemFlags::class);
        $defaultTenant = $flags1->defaultTenantUuid();
        self::assertNotNull($defaultTenant);
        self::assertNotSame('', $defaultTenant);

        // Commerce + link rows rekeyed by the pack's own AdoptionContributor.
        self::assertSame(
            0,
            (int) $connection1->table('commerce_products')->where('tenant_uuid', '')->count(),
        );
        self::assertSame(
            1,
            (int) $connection1->table('commerce_products')->where('tenant_uuid', $defaultTenant)->count(),
        );
        self::assertSame(
            0,
            (int) $connection1->table('thallo_commerce_product_links')->where('tenant_uuid', '')->count(),
        );
        self::assertSame(
            1,
            (int) $connection1->table('thallo_commerce_product_links')
                ->where('tenant_uuid', $defaultTenant)->count(),
        );

        // The product_page content type + its entry: core Thallo tables, adopted by the
        // ordinary schema retrofit itself (design spec's "starter adopted into the default
        // tenant"), not by the Commerce-specific contributor.
        $typeRow = $connection1->table('content_types')->where('uuid', $typeUuid)->first();
        self::assertNotNull($typeRow);
        self::assertSame($defaultTenant, $typeRow['tenant_uuid']);
        $entryRow = $connection1->table('entries')->where('uuid', $entry)->first();
        self::assertNotNull($entryRow);
        self::assertSame($defaultTenant, $entryRow['tenant_uuid']);

        // ---------------------------------------------------------------------------------
        // 3. Second boot WITH the tenancy extension (Phase E's own two-boot dance) ->
        //    FinalizationProbe passes (incl. the link + commerce tables) -> finalize() -> ON.
        // ---------------------------------------------------------------------------------
        $boot2 = $this->bootWithTenancyExtension();
        $container2 = $boot2->getContainer();

        $probe = $container2->get(FinalizationProbe::class)->report($boot2);
        self::assertTrue(
            $probe['enforcement'],
            'FinalizationProbe must confirm the pack link table AND every Commerce table are '
                . 'registered before enforcement may report ON',
        );

        $service2 = $this->service($boot2, $activation, $container2->get(AdoptionContributorRegistry::class));
        self::assertSame(EnablementStep::ON, $service2->finalize()->step);
        $guard2 = $container2->get(RetrofitMaintenanceGuard::class);
        $guard2->refresh();
        self::assertFalse($guard2->active());

        // ---------------------------------------------------------------------------------
        // 4. Enforcement-active reads resolve the default tenant's data through the REAL
        //    container: CatalogReader + ProductLinkService + EntryExistenceReader all land in
        //    mode (c) (request-resolved, delegated tenant), never a latched sentinel.
        // ---------------------------------------------------------------------------------
        $runner = $container2->get(TenantContextRunner::class);
        $reads = $runner->runAsTenant(
            $defaultTenant,
            function () use ($container2, $boot2, $product, $entry, $defaultTenant): array {
                return [
                    'resolvedTenant' => $container2->get(CommerceTenantResolution::class)->tenantUuid($boot2),
                    'product' => $container2->get(CatalogReader::class)
                        ->findLiveProduct($boot2, $defaultTenant, $product),
                    'link' => $container2->get(ProductLinkService::class)->resolveByProduct($boot2, $product),
                    'entry' => $container2->get(EntryExistenceReader::class)->exists($entry, $defaultTenant),
                ];
            },
        );
        self::assertSame($defaultTenant, $reads['resolvedTenant'], 'mode (c) must resolve the request tenant');
        self::assertNotNull($reads['product'], 'CatalogReader must find the adopted product under enforcement');
        self::assertNotNull($reads['link'], 'ProductLinkService must resolve the adopted link under enforcement');
        self::assertSame($entry, $reads['link']['entry_uuid']);
        self::assertNotNull($reads['entry'], 'EntryExistenceReader must find the adopted entry under enforcement');

        // ---------------------------------------------------------------------------------
        // 5. commerce:tenancy:adopt's mixed-data refusal survives the walk: a SECOND tenant
        //    cannot adopt while tenant A's (the default tenant's) commerce rows are present.
        // ---------------------------------------------------------------------------------
        $tenantB = Utils::generateNanoID(12);
        $container2->get(TenantProvisioner::class)->provisionDefault(
            $boot2,
            $tenantB,
            'walk-tenant-b',
            'Walk Tenant B',
            'user00000001',
        );

        $refused = false;
        try {
            $container2->get(TenantAdopter::class)->adopt($boot2, $tenantB);
        } catch (\RuntimeException $exception) {
            $refused = true;
            self::assertStringContainsString('non-sentinel rows', $exception->getMessage());
        }
        self::assertTrue(
            $refused,
            'TenantAdopter must refuse adopting into tenant B while tenant A already owns non-sentinel rows',
        );

        // ---------------------------------------------------------------------------------
        // 6. NEW COVERAGE (boundary-review carry-forward): under ACTIVE enforcement, seed a
        //    healthy link and a stale one (tombstoned product, linked directly -- bypassing
        //    ProductLinkService's validation and ProductDeletedListener, mirroring
        //    LinkLifecycleTest's drift technique) inside the default tenant, then run
        //    `thallo:commerce:links:reconcile`. This is the FIRST test to exercise
        //    LinkReconciler::withTenantContext's real `runAsTenant()` branch and
        //    discoverTenants() under genuine enforcement -- every other reconcile test runs
        //    under mode (a)/(b), where that branch is structurally unreachable.
        // ---------------------------------------------------------------------------------
        [$healthyProduct, $staleProduct] = $runner->runAsTenant(
            $defaultTenant,
            function () use ($container2, $boot2, $defaultTenant): array {
                $catalog = $container2->get(CatalogService::class);
                $entries = $container2->get(EntryRepository::class);
                $links = $container2->get(ProductLinkRepository::class);

                $healthyProduct = (string) $catalog->createProduct($boot2, [
                    ...self::PRODUCT_TYPE,
                    'slug' => 'walk-reconcile-healthy',
                    'name' => 'Walk Reconcile Healthy',
                    'metadata' => ['external_url' => 'https://example.test/walk-reconcile-healthy'],
                ])['uuid'];
                $healthyEntry = $entries->createEntry('ctype0000001', 'en', 1, null);
                $links->insert($defaultTenant, $healthyProduct, $healthyEntry);

                $staleProduct = (string) $catalog->createProduct($boot2, [
                    ...self::PRODUCT_TYPE,
                    'slug' => 'walk-reconcile-stale',
                    'name' => 'Walk Reconcile Stale',
                    'metadata' => ['external_url' => 'https://example.test/walk-reconcile-stale'],
                ])['uuid'];
                $catalog->deleteProduct($boot2, $staleProduct);
                $staleEntry = $entries->createEntry('ctype0000001', 'en', 1, null);
                // Direct repository insert (bypasses ProductLinkService's validation AND the
                // ProductDeletedListener, which only fires for a link that existed at delete
                // time) -- a genuinely drifted row, exactly like LinkLifecycleTest's technique.
                $links->insert($defaultTenant, $staleProduct, $staleEntry);

                return [$healthyProduct, $staleProduct];
            },
        );

        $tester = new CommandTester(new ReconcileLinksCommand($container2, $boot2));
        $exit = $tester->execute(['--tenant' => $defaultTenant], ['interactive' => false]);
        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('removed 1 stale link(s)', $tester->getDisplay());

        $links2 = $container2->get(ProductLinkRepository::class);
        self::assertNotNull(
            $links2->findByProduct($defaultTenant, $healthyProduct),
            'the healthy link must be untouched by the mode (c) reconcile sweep',
        );
        self::assertNull(
            $links2->findByProduct($defaultTenant, $staleProduct),
            'the stale (tombstoned-product) link must be removed via the real runAsTenant() branch',
        );
    }

    // --- helpers -------------------------------------------------------------------------------

    private function bootWithTenancyExtension(): ApplicationContext
    {
        self::resetTenancyGlobals();
        self::resetSharedRepositoryConnection();
        /** @var array{enabled: list<string>} $base */
        $base = require dirname(__DIR__, 3) . '/config/serviceproviders.php';
        $providers = [...$base['enabled'], 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider'];

        return self::bootAppWithConfigOverride('serviceproviders', ['enabled' => $providers]);
    }

    private function service(
        ApplicationContext $context,
        RecordingExtensionActivation $activation,
        AdoptionContributorRegistry $registry,
    ): TenancyEnablement {
        $container = $context->getContainer();

        return new TenancyEnablement(
            $context,
            $container->get(EnablementStore::class),
            $container->get(EnablementLock::class),
            $container->get(SystemFlags::class),
            $activation,
            $container->get(FinalizationProbe::class),
            $container->get(TenantRuntimeReadiness::class),
            $container->get(RetrofitMaintenanceGuard::class),
            $container->get(CacheTransition::class),
            $container->get(Connection::class),
            null,
            null,
            null,
            $registry,
        );
    }

    /**
     * Persists a REAL `product_page` content-type row using the same throwaway-registry
     * technique as `ProductPageStarterTest::createProductPageType()` — nothing here registers
     * into the shared boot's own container.
     */
    private function createProductPageType(ApplicationContext $context): string
    {
        $container = $context->getContainer();
        /** @var ContainerInterface $container */
        $repository = $container->get(ContentTypeRepository::class);
        $registry = new DefaultStarterContributorRegistry();
        $registry->register(new ProductPageContributor());
        $kind = new ContentTypeKind($repository, $container->get(Connection::class), $registry);

        foreach ($kind->definitions() as $definition) {
            if ($definition->definitionKey === ProductPageContributor::SLUG) {
                return $repository->create($definition->payload);
            }
        }

        throw new \RuntimeException('product_page definition not found');
    }
}
