<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Commerce\CommerceIntegrationServiceProvider;
use Thallo\Render\Contribution\RenderContributionRegistry;
use Thallo\Render\Contribution\ReservedPathContributor;
use Thallo\Render\Contribution\TemplatePathContributor;
use Thallo\Render\RenderServiceProvider;
use Thallo\Render\ReservedPaths;
use Thallo\Render\ThemeLocator;
use Thallo\Workflow\WorkflowServiceProvider;
use Twig\Loader\FilesystemLoader;

/**
 * Render contributor registries (storefront-rendering spec §5.1/§5.2): reserved-path +
 * template-path contribution, with strict freeze/ordering/duplicate semantics. Covers the
 * registry in isolation, ThemeLocator's 3-tier chain, the container wiring in
 * RenderServiceProvider, and — via the real activation list, a source scan of boot(), and the
 * real factories run against an isolated registry — that the freeze point is the first
 * consumption of ReservedPaths/ThemeLocator, not any provider's boot() position.
 */
final class RenderContributionTest extends AppTestCase
{
    private string $tmpThemes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpThemes = sys_get_temp_dir() . '/thallo-render-contrib-themes-' . bin2hex(random_bytes(4));
        mkdir($this->tmpThemes, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpThemes);
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /** @param list<string> $prefixes @param list<string> $exacts */
    private function reservedContributor(
        string $id,
        array $prefixes,
        array $exacts,
        int $priority = 0,
    ): ReservedPathContributor {
        return new class ($id, $prefixes, $exacts, $priority) implements ReservedPathContributor {
            /** @param list<string> $prefixes @param list<string> $exacts */
            public function __construct(
                private readonly string $id,
                private readonly array $prefixes,
                private readonly array $exacts,
                private readonly int $priority,
            ) {
            }

            public function contributorId(): string
            {
                return $this->id;
            }

            public function priority(): int
            {
                return $this->priority;
            }

            /** @return list<string> */
            public function reservedPrefixes(): array
            {
                return $this->prefixes;
            }

            /** @return list<string> */
            public function reservedExacts(): array
            {
                return $this->exacts;
            }
        };
    }

    /** @param list<string> $dirs */
    private function templateContributor(string $id, array $dirs, int $priority = 0): TemplatePathContributor
    {
        return new class ($id, $dirs, $priority) implements TemplatePathContributor {
            /** @param list<string> $dirs */
            public function __construct(
                private readonly string $id,
                private readonly array $dirs,
                private readonly int $priority,
            ) {
            }

            public function contributorId(): string
            {
                return $this->id;
            }

            public function priority(): int
            {
                return $this->priority;
            }

            /** @return list<string> */
            public function templatePaths(): array
            {
                return $this->dirs;
            }
        };
    }

    // --- RenderContributionRegistry (pure) ----------------------------------------------------

    public function testFreshRegistryHasEmptySnapshotsByDefault(): void
    {
        $registry = new RenderContributionRegistry();
        self::assertSame(['prefixes' => [], 'exacts' => []], $registry->frozenReserved());
        self::assertSame([], $registry->frozenTemplatePaths());
    }

    public function testReservedOrderingIsDeterministicAcrossPermutedRegistrationOrder(): void
    {
        $a = $this->reservedContributor('a-contrib', ['pa'], [], priority: 5);
        $b = $this->reservedContributor('b-contrib', ['pb'], [], priority: 1);
        $c = $this->reservedContributor('c-contrib', ['pc'], [], priority: 5);
        // Sort key is (priority, contributorId): b(1) first, then a-contrib/c-contrib(5) by id.
        $expected = ['pb', 'pa', 'pc'];

        $forward = new RenderContributionRegistry();
        $forward->registerReservedPaths($a);
        $forward->registerReservedPaths($b);
        $forward->registerReservedPaths($c);

        $reversed = new RenderContributionRegistry();
        $reversed->registerReservedPaths($c);
        $reversed->registerReservedPaths($b);
        $reversed->registerReservedPaths($a);

        self::assertSame($expected, $forward->frozenReserved()['prefixes']);
        self::assertSame($expected, $reversed->frozenReserved()['prefixes']);
    }

    public function testTemplateOrderingIsDeterministicAcrossPermutedRegistrationOrder(): void
    {
        $a = $this->templateContributor('a-contrib', ['/dir/a'], priority: 5);
        $b = $this->templateContributor('b-contrib', ['/dir/b'], priority: 1);
        $c = $this->templateContributor('c-contrib', ['/dir/c'], priority: 5);
        $expected = ['/dir/b', '/dir/a', '/dir/c'];

        $forward = new RenderContributionRegistry();
        $forward->registerTemplatePaths($a);
        $forward->registerTemplatePaths($b);
        $forward->registerTemplatePaths($c);

        $reversed = new RenderContributionRegistry();
        $reversed->registerTemplatePaths($c);
        $reversed->registerTemplatePaths($b);
        $reversed->registerTemplatePaths($a);

        self::assertSame($expected, $forward->frozenTemplatePaths());
        self::assertSame($expected, $reversed->frozenTemplatePaths());
    }

    public function testDuplicateReservedContributorIdThrows(): void
    {
        $registry = new RenderContributionRegistry();
        $registry->registerReservedPaths($this->reservedContributor('dup', ['x'], []));

        $this->expectException(\LogicException::class);
        $registry->registerReservedPaths($this->reservedContributor('dup', ['y'], []));
    }

    public function testDuplicateTemplateContributorIdThrows(): void
    {
        $registry = new RenderContributionRegistry();
        $registry->registerTemplatePaths($this->templateContributor('dup', ['/dir/x']));

        $this->expectException(\LogicException::class);
        $registry->registerTemplatePaths($this->templateContributor('dup', ['/dir/y']));
    }

    public function testDuplicateReservedPrefixAcrossContributorsThrowsNeverFirstWins(): void
    {
        $registry = new RenderContributionRegistry();
        $registry->registerReservedPaths($this->reservedContributor('one', ['shared-prefix'], []));
        $registry->registerReservedPaths($this->reservedContributor('two', ['shared-prefix'], []));

        $this->expectException(\LogicException::class);
        $registry->frozenReserved();
    }

    public function testDuplicateReservedExactAcrossContributorsThrowsNeverFirstWins(): void
    {
        $registry = new RenderContributionRegistry();
        $registry->registerReservedPaths($this->reservedContributor('one', [], ['shared.txt']));
        $registry->registerReservedPaths($this->reservedContributor('two', [], ['shared.txt']));

        $this->expectException(\LogicException::class);
        $registry->frozenReserved();
    }

    public function testDuplicateTemplateDirAcrossContributorsThrowsNeverFirstWins(): void
    {
        $registry = new RenderContributionRegistry();
        $registry->registerTemplatePaths($this->templateContributor('one', ['/shared/dir']));
        $registry->registerTemplatePaths($this->templateContributor('two', ['/shared/dir']));

        $this->expectException(\LogicException::class);
        $registry->frozenTemplatePaths();
    }

    public function testLateReservedRegistrationAfterFreezeThrowsRuntimeException(): void
    {
        $registry = new RenderContributionRegistry();
        $registry->registerReservedPaths($this->reservedContributor('first', ['a'], []));
        $registry->frozenReserved(); // freezes

        $this->expectException(\RuntimeException::class);
        $registry->registerReservedPaths($this->reservedContributor('late', ['b'], []));
    }

    public function testLateTemplateRegistrationAfterFreezeThrowsRuntimeException(): void
    {
        $registry = new RenderContributionRegistry();
        $registry->registerTemplatePaths($this->templateContributor('first', ['/dir/a']));
        $registry->frozenTemplatePaths(); // freezes

        $this->expectException(\RuntimeException::class);
        $registry->registerTemplatePaths($this->templateContributor('late', ['/dir/b']));
    }

    public function testFrozenReservedReadAlsoFreezesTheTemplateChannel(): void
    {
        $registry = new RenderContributionRegistry();
        $registry->registerReservedPaths($this->reservedContributor('r1', ['x'], []));
        $registry->frozenReserved(); // reads ONLY the reserved-path channel

        // BOTH channels froze together on that one read — the template channel is closed too.
        $this->expectException(\RuntimeException::class);
        $registry->registerTemplatePaths($this->templateContributor('t1', ['/dir/late']));
    }

    public function testFrozenTemplatePathsReadAlsoFreezesTheReservedChannel(): void
    {
        $registry = new RenderContributionRegistry();
        $registry->registerTemplatePaths($this->templateContributor('t1', ['/dir/a']));
        $registry->frozenTemplatePaths(); // reads ONLY the template-path channel

        $this->expectException(\RuntimeException::class);
        $registry->registerReservedPaths($this->reservedContributor('r1', ['late'], []));
    }

    // --- ThemeLocator 3-tier chain (pure) ------------------------------------------------------

    public function testZeroContributedDirsTemplateChainIsByteIdenticalToPreFeature(): void
    {
        $withoutParam = new ThemeLocator('default', $this->tmpThemes);
        $withEmptyContribs = new ThemeLocator('default', $this->tmpThemes, null, []);

        self::assertSame($withoutParam->activePaths(), $withEmptyContribs->activePaths());
        self::assertCount(1, $withEmptyContribs->activePaths()['templates']); // pack default only
    }

    public function testContributedDirLosesToAppThemeButWinsOverRenderDefault(): void
    {
        mkdir($this->tmpThemes . '/mytheme/templates', 0755, true);
        file_put_contents(
            $this->tmpThemes . '/mytheme/theme.json',
            (string) json_encode(['name' => 'mytheme', 'version' => '1.0.0']),
        );
        file_put_contents($this->tmpThemes . '/mytheme/templates/__contribution_probe.twig', 'APP-THEME');

        $contribDir = $this->tmpThemes . '/__contrib';
        mkdir($contribDir, 0755, true);
        // Present in BOTH the app theme and the contribution — app theme must win.
        file_put_contents($contribDir . '/__contribution_probe.twig', 'PACK-CONTRIB');
        // Present ONLY in the contribution — must still resolve (contribution beats default).
        file_put_contents($contribDir . '/__contrib_only.twig', 'CONTRIB-ONLY');

        $locator = new ThemeLocator('mytheme', $this->tmpThemes, null, [$contribDir]);
        $templates = $locator->activePaths()['templates'];

        self::assertCount(3, $templates);
        self::assertStringContainsString('/mytheme/templates', $templates[0]);
        self::assertSame($contribDir, $templates[1]);
        self::assertStringContainsString('thallo-render/themes/default/templates', $templates[2]);

        $loader = new FilesystemLoader($templates);
        self::assertSame('APP-THEME', $loader->getSourceContext('__contribution_probe.twig')->getCode());
        self::assertSame('CONTRIB-ONLY', $loader->getSourceContext('__contrib_only.twig')->getCode());
    }

    public function testContributedDirWinsOverRenderDefaultWhenNoAppThemeOverride(): void
    {
        $contribDir = $this->tmpThemes . '/__contrib';
        mkdir($contribDir, 0755, true);
        file_put_contents($contribDir . '/__contribution_probe.twig', 'PACK-CONTRIB');

        // No app theme dir at all — chain is [contribution, render default].
        $locator = new ThemeLocator('nonexistent', $this->tmpThemes, null, [$contribDir]);
        $templates = $locator->activePaths()['templates'];

        self::assertCount(2, $templates);
        self::assertSame($contribDir, $templates[0]);
        self::assertStringContainsString('thallo-render/themes/default/templates', $templates[1]);

        $loader = new FilesystemLoader($templates);
        self::assertSame('PACK-CONTRIB', $loader->getSourceContext('__contribution_probe.twig')->getCode());
        // A pack-default-only template (never contributed) still resolves through the chain.
        self::assertNotSame('', $loader->getSourceContext('layout.twig')->getCode());
    }

    // --- DI wiring on the shared app (safe: production has zero real contributors today) -------

    public function testSharedContainerReservedPathsStillHonorsConfigDefaults(): void
    {
        $reserved = $this->container()->get(ReservedPaths::class);
        self::assertTrue($reserved->isReserved('v1/whatever'));
        self::assertTrue($reserved->isReserved('sitemap.xml'));
        self::assertFalse($reserved->isReserved('totally-normal-page'));

        self::assertInstanceOf(
            RenderContributionRegistry::class,
            $this->container()->get(RenderContributionRegistry::class),
        );
    }

    // --- Real provider order: freeze happens at first consumption, not at any provider's -------
    // --- boot() position (no second Framework::boot() — see the note on the decorator below) ----

    /**
     * Wraps the REAL shared container so every service EXCEPT RenderContributionRegistry comes
     * from the process-shared app (already booted, already warm — zero new DB connections);
     * only the registry itself is swapped for a fresh, unfrozen instance. This lets
     * RenderServiceProvider's actual production factories run end-to-end against a virgin
     * registry without a second full Framework::boot() — this suite already runs many
     * bootAppWithConfigOverride() second boots close to Postgres's connection ceiling (each opens
     * its own raw PDO connection — pooling is disabled in tests), so a test-only decorator is the
     * responsible way to get an isolated registry here.
     */
    private function containerWithFreshRegistry(RenderContributionRegistry $registry): \Psr\Container\ContainerInterface
    {
        $real = $this->container();
        return new class ($real, $registry) implements \Psr\Container\ContainerInterface {
            public function __construct(
                private readonly \Psr\Container\ContainerInterface $real,
                private readonly RenderContributionRegistry $registry,
            ) {
            }

            public function get(string $id): mixed
            {
                return $id === RenderContributionRegistry::class ? $this->registry : $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return $id === RenderContributionRegistry::class || $this->real->has($id);
            }
        };
    }

    public function testProviderOrderInTheRealActivationListDoesNotConstrainContributionTiming(): void
    {
        // Ground the claim in the ACTUAL activation list (config/extensions.php): thallo-commerce
        // (the pack that will contribute in Task 7) boots BEFORE thallo-render, while
        // thallo-workflow boots AFTER it. The registry must accept contributions from EITHER
        // side — consumption (the freeze trigger) is deferred to first read, not tied to any
        // provider's boot() position. testContributionRegisteredAfterAllProvidersHaveBootedIs...
        // below proves that deferred-consumption mechanism against the real factories.
        $root = dirname(__DIR__, 3);
        $enabled = (require $root . '/config/extensions.php')['enabled'];
        $renderIndex = array_search(RenderServiceProvider::class, $enabled, true);
        $commerceIndex = array_search(CommerceIntegrationServiceProvider::class, $enabled, true);
        $workflowIndex = array_search(WorkflowServiceProvider::class, $enabled, true);
        self::assertIsInt($renderIndex, 'RenderServiceProvider must be in the real activation list');
        self::assertIsInt($commerceIndex, 'CommerceIntegrationServiceProvider must be in the real activation list');
        self::assertIsInt($workflowIndex, 'WorkflowServiceProvider must be in the real activation list');
        self::assertLessThan($renderIndex, $commerceIndex, 'thallo-commerce boots BEFORE thallo-render');
        self::assertGreaterThan($renderIndex, $workflowIndex, 'thallo-workflow boots AFTER thallo-render');
    }

    public function testBootMethodNeverEagerlyResolvesTheFreezeTriggeringServices(): void
    {
        // The structural half of the "render doesn't freeze before every provider has
        // contributed" proof: boot() (called for EVERY provider, in list order, before any
        // provider's routes are dispatched) must never itself resolve one of the services whose
        // factory reads frozen*() — doing so would freeze the registry mid-boot, before a
        // later-booting provider (e.g. thallo-workflow, per the index test above) gets a chance
        // to contribute. Scans the REAL method body, so a future regression that adds an eager
        // resolution here fails this test immediately.
        $method = new \ReflectionMethod(RenderServiceProvider::class, 'boot');
        $lines = (array) file((string) $method->getFileName());
        $length = $method->getEndLine() - $method->getStartLine() + 1;
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $length));

        $needles = [
            'ReservedPaths::class',
            'ThemeLocator::class',
            'TwigFactory::class',
            'RenderContributionRegistry::class',
        ];
        foreach ($needles as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $body,
                "RenderServiceProvider::boot() must never eagerly resolve {$needle} — doing so would "
                . 'freeze the contribution registry before every provider has had a chance to '
                . 'contribute (storefront-rendering spec §5.1/§5.2).',
            );
        }
    }

    public function testContributionRegisteredAfterAllProvidersHaveBootedIsHonoredUntilFirstConsumption(): void
    {
        $registry = new RenderContributionRegistry();

        $tmpDir = $this->tmpThemes . '/__contrib-boot-stand-in';
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir . '/__contribution_probe.twig', 'PACK-CONTRIB');

        // Standing in for "a provider registers during its own boot(), which may run before OR
        // after render's — see the index test above": nothing has consumed this fresh registry
        // yet, so registration here succeeds exactly like it would at any point during the real
        // provider-boot phase.
        $registry->registerReservedPaths($this->reservedContributor(
            'contribution-probe',
            ['contribution-probe-shop'],
            ['contribution-probe.txt'],
        ));
        $registry->registerTemplatePaths($this->templateContributor('contribution-probe-templates', [$tmpDir]));

        $container = $this->containerWithFreshRegistry($registry);

        // First consumption — the REAL production factories, called exactly as
        // RenderServiceProvider::services() wires them (see makeReservedPaths()/
        // makeThemeLocator()). This is where frozen*() is read for the first time.
        $reservedPaths = RenderServiceProvider::makeReservedPaths($container);
        self::assertTrue($reservedPaths->isReserved('contribution-probe-shop/anything'));
        self::assertTrue($reservedPaths->isReserved('contribution-probe.txt'));
        self::assertFalse($reservedPaths->isReserved('totally-unrelated-path'));
        // Config-declared defaults still apply alongside the contribution (merge, not replace).
        self::assertTrue($reservedPaths->isReserved('v1/whatever'));

        $themeLocator = RenderServiceProvider::makeThemeLocator($container);
        self::assertContains($tmpDir, $themeLocator->activePaths()['templates']);

        // NOW the registry is frozen (the factory calls above triggered it) — further
        // registration is a loud boot-ordering bug, never a silently dropped contribution.
        $threw = null;
        try {
            $registry->registerReservedPaths($this->reservedContributor('too-late', ['whatever'], []));
        } catch (\Throwable $e) {
            $threw = $e;
        }
        self::assertInstanceOf(\RuntimeException::class, $threw);
    }
}
