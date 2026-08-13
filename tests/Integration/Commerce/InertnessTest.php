<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Content\Repositories\EntryRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry as TenantTableRegistryContract;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\CommerceIntegrationServiceProvider;
use Thallo\Commerce\Console\CommerceDiagnoseCommand;
use Thallo\Commerce\Links\ProductLinkRepository;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Starter\ProductStoryContributor;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Starter\StarterContributorRegistry;
use Thallo\Tenancy\Purge\PurgeHandler;
use Thallo\Tenancy\Purge\PurgeResourceRegistry;

/**
 * Commerce-Slice-1 Task 12: the inertness matrix (design spec §10 "Inertness") + the
 * marketplace-diagnostics gate (§10 "Marketplace").
 *
 * Every scenario here runs against the DEFAULT test boot (Commerce + thallo-commerce are hard
 * composer dependencies, always active regardless of `THALLO_TENANCY_DEV_LINK` — see
 * `config/testing/extensions.php`'s header) — nothing in this file needs the retrofit harness.
 */
final class InertnessTest extends AppTestCase
{
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // capability disabled: routes + starter OFF, maintenance stays ON
    // ------------------------------------------------------------------

    public function testCapabilityDisabledHidesRoutesAndStarterButKeepsMaintenanceActive(): void
    {
        // Mirrors ProductLinkApiTest::testRoutesAbsentWhenCapabilityDisabled's exact
        // choreography: reset flags this class never sets (defensive symmetry) before a second
        // full boot.
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);
        $container = $disabledApp->getContainer();

        try {
            self::assertFalse(
                $container->get(CapabilityRegistry::class)->isEnabled('thallo.commerce'),
                'sanity check: the capability really is disabled in this second boot',
            );

            // -- no pack routes -- GETs fall through to render's `GET /{path}` catch-all
            // (404 for unknown paths); non-GETs match that catch-all's PATH template with
            // the wrong method (405). Both prove the pack registered nothing.
            $hit = static fn (string $method, string $path): int => (new Application($disabledApp))->handle(
                Request::create($path, $method, [], [], [], [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ]),
            )->getStatusCode();
            self::assertSame(405, $hit('PUT', '/v1/admin/commerce/products/p-1/link'));
            self::assertSame(405, $hit('DELETE', '/v1/admin/commerce/products/p-1/link'));
            self::assertSame(404, $hit('GET', '/v1/admin/commerce/products/p-1/link'));
            self::assertSame(404, $hit('GET', '/v1/admin/commerce/entries/e-1/link'));

            // -- no starter contribution --
            $starterRegistry = $container->get(StarterContributorRegistry::class);
            foreach ($starterRegistry->all() as $contributor) {
                self::assertNotInstanceOf(ProductStoryContributor::class, $contributor);
            }

            // -- BUT migrations are still applied (installed unconditionally, boot() outside
            //    any gate) --
            self::assertTrue(
                $container->get(Connection::class)->getSchemaBuilder()
                    ->hasTable('thallo_commerce_product_links'),
                'the link table migration must have run regardless of the capability state',
            );

            // -- BUT link-table registration still runs outside the gate: the provider's own
            //    method takes no capability dependency at all (an injectable-registry seam,
            //    mirroring PackSkeletonTest's identical proof on the ENABLED boot) --
            $fakeTables = $this->capturingTableRegistry();
            $provider = new CommerceIntegrationServiceProvider($container);
            self::assertTrue($provider->registerProductLinkTable($disabledApp, $fakeTables));
            self::assertSame(
                ['thallo_commerce_product_links', 'thallo_commerce_payment_link_deliveries'],
                array_keys($fakeTables->registered),
            );

            // -- BUT the purge handler stays registered (aliased into the shared registry
            //    unconditionally by the pack's own boot(), which this disabled boot still ran) --
            $handlerIds = array_map(
                static fn (PurgeHandler $handler): string => $handler->id(),
                $container->get(PurgeResourceRegistry::class)->all(),
            );
            self::assertContains('thallo.commerce', $handlerIds);

            // -- BUT the lifecycle listeners still fire: entry.deleted removes the link even
            //    with thallo.commerce disabled (LinkLifecycleTest::
            //    testBothListenersFireWithCapabilityDisabled covers BOTH listeners exhaustively;
            //    this is a lighter, complementary proof scoped to this matrix) --
            $product = (string) $container->get(CatalogService::class)->createProduct($disabledApp, [
                'slug' => 'inert-listener-' . (++self::$seq),
                'name' => 'Inert Listener',
                'status' => 'active',
                'type' => 'external',
                'metadata' => ['external_url' => 'https://example.test/inert-listener'],
            ])['uuid'];
            $entry = $container->get(EntryRepository::class)->createEntry('ctype0000001', 'en', 1, null);
            $container->get(ProductLinkService::class)->link($disabledApp, $product, $entry);

            $container->get(EntryRepository::class)->softDelete($entry);
            self::assertNull(
                $container->get(ProductLinkRepository::class)->findByProduct('', $product),
                'EntryDeletedListener must still fire with thallo.commerce disabled',
            );
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // ------------------------------------------------------------------
    // pack fully absent: proven by construction (no App\ references)
    // ------------------------------------------------------------------

    public function testPackSourceHasNoAppNamespaceReferences(): void
    {
        $root = dirname(__DIR__, 3);
        $pkgDir = $root . '/packages/thallo-commerce';
        self::assertDirectoryExists($pkgDir, 'sanity: the pack directory must exist');

        // In-process, localized re-check (mirrors the Collections pack's precedent
        // NoAppReferencesTest): scan every .php file under src/ + routes/ with the EXACT same
        // pattern check-pack-boundaries.php uses, so a failure here names the offending
        // file:line directly instead of only "the script failed".
        $violations = [];
        foreach (['src', 'routes'] as $sub) {
            $dir = $pkgDir . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $lines = file((string) $file->getPathname()) ?: [];
                foreach ($lines as $lineNumber => $line) {
                    if (preg_match('/(^|[^\w])App\\\\/', $line) === 1) {
                        $violations[] = $sub . '/' . $file->getFilename() . ':' . ($lineNumber + 1)
                            . ' — ' . trim($line);
                    }
                }
            }
        }
        self::assertSame([], $violations, "thallo-commerce references App\\:\n - " . implode("\n - ", $violations));

        // Authoritative signal: the repo-wide boundaries script (composer run boundaries),
        // which this pack's composer.json/src/routes are already part of.
        $script = $root . '/scripts/check-pack-boundaries.php';
        self::assertFileExists($script);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, $root);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, "check-pack-boundaries.php failed:\n{$stdout}{$stderr}");
        self::assertStringContainsString(
            'Pack boundaries OK',
            $stdout,
            'sanity: the script actually ran a real check (not a silent no-op)',
        );
    }

    // ------------------------------------------------------------------
    // marketplace enabled=true: diagnostics warns, NO behavioral fork
    // ------------------------------------------------------------------

    public function testMarketplaceEnabledWarnsInDiagnoseWithNoBehavioralFork(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        // NOT AppTestCase::bootAppWithConfigOverride(): that helper deletes the override file
        // in its OWN finally, immediately after boot() returns. ApplicationContext caches
        // config lazily per namespace on first READ (see ApplicationContext::resolveConfigValue),
        // and nothing touches the 'commerce' namespace during boot() itself (unlike 'thallo',
        // which CapabilityRegistry reads eagerly) -- so the override would already be gone by
        // the time this test's own config()/report() calls trigger the first lazy read. Boot by
        // hand instead, and defer the file cleanup to THIS method's own finally, after every
        // commerce.* read this test performs.
        $marketplaceApp = $this->bootWithCommerceMarketplaceEnabled();
        $container = $marketplaceApp->getContainer();

        try {
            self::assertTrue(
                (bool) config($marketplaceApp, 'commerce.marketplace.enabled'),
                'sanity: the config override actually took effect on this boot',
            );

            $tester = new CommandTester(new CommerceDiagnoseCommand($container, $marketplaceApp));
            $exit = $tester->execute([]);
            $display = $tester->getDisplay();

            self::assertStringContainsString('WARN marketplace', $display);
            self::assertStringContainsString('unsupported configuration in Thallo v1', $display);
            self::assertSame(
                0,
                $exit,
                'a marketplace warning alone must not fail the diagnose command (no behavioral fork)',
            );

            // No behavioral fork: a completely ordinary link operation still works exactly as
            // it would with marketplace disabled.
            $product = (string) $container->get(CatalogService::class)->createProduct($marketplaceApp, [
                'slug' => 'marketplace-noop-' . (++self::$seq),
                'name' => 'Marketplace Noop',
                'status' => 'active',
                'type' => 'external',
                'metadata' => ['external_url' => 'https://example.test/marketplace-noop'],
            ])['uuid'];
            $entry = $container->get(EntryRepository::class)->createEntry('ctype0000001', 'en', 1, null);
            $row = $container->get(ProductLinkService::class)->link($marketplaceApp, $product, $entry);

            self::assertSame($product, $row['product_uuid']);
            self::assertSame($entry, $row['entry_uuid']);
        } finally {
            $this->cleanupCommerceMarketplaceOverride();
            self::resetSharedRepositoryConnection();
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function flags(): \Thallo\Tenancy\System\SystemFlags
    {
        return $this->container()->get(\Thallo\Tenancy\System\SystemFlags::class);
    }

    private function bootWithCommerceMarketplaceEnabled(): \Glueful\Bootstrap\ApplicationContext
    {
        $root = dirname(__DIR__, 3);
        $overrideDir = $root . '/config/testing';
        if (!is_dir($overrideDir)) {
            mkdir($overrideDir, 0755, true);
        }
        file_put_contents(
            $overrideDir . '/commerce.php',
            "<?php\nreturn ['marketplace' => ['enabled' => true]];\n",
        );

        \Glueful\Routing\RouteManifest::reset();
        foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $f) {
            @unlink($f);
        }

        return \Glueful\Framework::create($root)
            ->withConfigDir($root . '/config')
            ->withEnvironment('testing')
            ->boot()
            ->getContext();
    }

    private function cleanupCommerceMarketplaceOverride(): void
    {
        $root = dirname(__DIR__, 3);
        @unlink($root . '/config/testing/commerce.php');
        \Glueful\Routing\RouteManifest::reset();
    }

    /** A capturing fake bound to the contract, mirroring PackSkeletonTest's identical helper. */
    private function capturingTableRegistry(): TenantTableRegistryContract
    {
        return new class implements TenantTableRegistryContract {
            /** @var array<string,true> */
            public array $registered = [];

            public function register(array $tables): void
            {
                foreach ($tables as $table) {
                    $this->registered[$table] = true;
                }
            }
        };
    }
}
