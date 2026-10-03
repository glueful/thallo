<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ProtectedProviders;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Capability\ManagementMode;
use Thallo\Core\Capabilities\Activation\ActivationRunner;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\Console\CapabilitiesCommand;
use Thallo\Core\Capabilities\Console\CapabilitiesEnableCommand;
use Thallo\Core\Capabilities\Declarations\CapabilityDeclaration;
use Thallo\Core\Capabilities\Declarations\DeclarationSet;
use Thallo\Core\Capabilities\Declarations\PackageCapabilityDeclarations;
use Thallo\Core\Capabilities\FeatureManagementPolicy;
use Thallo\Core\Http\Controllers\CapabilityActivationController;
use Thallo\Core\Http\DTOs\ActivationGenerationData;
use Thallo\Core\Http\DTOs\UpdateCapabilityStateData;
use Thallo\Core\Setup\Console\DoctorCommand;
use Thallo\Core\Setup\InstallRoleGrants;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Capabilities\ContestedConsumerProvider;
use Thallo\Core\Tests\Support\Capabilities\ContestingProvider;
use Thallo\Core\Tests\Support\TestableCapabilityAdminController;
use Thallo\Core\Tests\Support\TestableExtensionAdminController;
use Thallo\Core\Tests\Support\SpySchemaExecutor;

/**
 * A misconfigured declaration is rejected and blocks every path (spec §7.3): the verdict doesn't
 * depend on order, the capability is ineffective even when stored on, every management path refuses
 * it, and the packages involved are protected as misconfigured — never treated as independent. An
 * engine that is already enabled keeps running.
 */
final class MisconfiguredCapabilityTest extends AppTestCase
{
    private const MEDIA = 'Glueful\\Extensions\\Media\\MediaServiceProvider';
    private const IMPORT_EXPORT = 'Glueful\\Extensions\\ImportExport\\ImportExportServiceProvider';

    private static ?ContainerInterface $contested = null;

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec(
            "DELETE FROM thallo_system_flags WHERE key = 'capability.test.contested.enabled'"
        );
        parent::tearDown();
    }

    /** @param list<class-string> $order */
    private function bootWith(array $order): ContainerInterface
    {
        $this->container()->get(CapabilityStateStore::class)->put('test.contested', true);
        /** @var array{enabled: list<string>} $base */
        $base = require dirname(__DIR__, 3) . '/config/serviceproviders.php';
        ContestedConsumerProvider::$decision = null;
        return self::bootAppWithConfigOverride('serviceproviders', ['enabled' => [...$base['enabled'], ...$order]])
            ->getContainer();
    }

    private function contested(): ContainerInterface
    {
        return self::$contested ??= $this->bootWith([ContestedConsumerProvider::class, ContestingProvider::class]);
    }

    /** @param list<CapabilityDeclaration> $declarations */
    private static function set(array $declarations, array $providers = [], array $required = []): DeclarationSet
    {
        return new DeclarationSet($declarations, $providers, [], $required);
    }

    private static function decl(Capability $capability, string $source): CapabilityDeclaration
    {
        return new CapabilityDeclaration($capability, $source);
    }

    public function testEachAmbiguityIsRejected(): void
    {
        $providers = ['acme/one' => 'Acme\\One', 'acme/two' => 'Acme\\Two', 'glueful/aegis' => 'Aegis'];
        $activation = static fn (string $id, string $pkg): Capability
            => new Capability($id, owningPackage: $pkg, management: ManagementMode::Activation);

        // one package owned by two non-simple capabilities
        $shared = self::set([
            self::decl($activation('a.one', 'acme/one'), 'p1'),
            self::decl($activation('a.two', 'acme/one'), 'p2'),
        ], $providers);
        self::assertSame(['a.one', 'a.two'], array_keys($shared->misconfigured()));
        self::assertSame(['acme/one'], $shared->misconfigured()['a.one']['packages']);

        // one id declared with different owners
        $differs = self::set([
            self::decl($activation('a.x', 'acme/one'), 'p1'),
            self::decl($activation('a.x', 'acme/two'), 'p2'),
        ], $providers);
        self::assertArrayHasKey('a.x', $differs->misconfigured());
        self::assertSame(['acme/one', 'acme/two'], $differs->misconfigured()['a.x']['packages']);

        // an activation engine that is required by Thallo
        $required = self::set([self::decl($activation('a.req', 'glueful/aegis'), 'p1')], $providers, ['glueful/aegis']);
        self::assertStringContainsString('required', $required->misconfigured()['a.req']['reason']);

        // an activation engine that isn't installed
        $missing = self::set([self::decl($activation('a.gone', 'acme/absent'), 'p1')], $providers);
        self::assertStringContainsString('not installed', $missing->misconfigured()['a.gone']['reason']);

        // an identical re-declaration is one capability
        $same = self::set([
            self::decl($activation('a.ok', 'acme/one'), 'p1'),
            self::decl($activation('a.ok', 'acme/one'), 'p2'),
        ], $providers);
        self::assertSame([], $same->misconfigured());
    }

    public function testAnInvalidPackageDeclarationProtectsItsPackageAndKeepsEveryEntry(): void
    {
        $installed = tempnam(sys_get_temp_dir(), 'thallo-installed-') ?: '';
        file_put_contents($installed, (string) json_encode(['packages' => [[
            'name' => 'acme/x',
            'type' => 'glueful-extension',
            'extra' => ['thallo' => ['capabilities' => [
                ['mode' => 'bogus'],                         // no id
                ['label' => 'Also no id'],                   // no id
                ['id' => 'acme.y', 'mode' => 'bogus'],
            ]]],
        ]]]));
        try {
            $packages = new PackageCapabilityDeclarations($this->appContext(), $installed);
            $set = new DeclarationSet($packages->all(), ['acme/x' => 'Acme\\X'], $packages->errorsById());
        } finally {
            @unlink($installed);
        }
        self::assertCount(3, $set->misconfigured(), 'every invalid entry is kept');
        foreach ($set->misconfigured() as $entry) {
            self::assertSame(['acme/x'], $entry['packages'], 'the declaring package is protected');
        }
        self::assertSame('misconfigured', $set->packageManagement()['acme/x']['class']);
        self::assertStringStartsWith('an invalid declaration in acme/x: ', $set->misconfigured()['acme.y']['reason']);
    }

    public function testAnInvalidEntryUnderAValidIdKeepsTheEngineProtectedInAnyOrder(): void
    {
        $valid = self::decl(
            new Capability('acme.bookings', owningPackage: 'acme/bookings', management: ManagementMode::Activation),
            'package:acme/bookings',
        );
        $providers = ['acme/bookings' => 'Acme\\Bookings', 'other/one' => 'O\\One', 'other/two' => 'O\\Two'];
        $errors = [
            ['acme.bookings' => [
                ['reason' => 'an invalid declaration in other/one: unknown mode', 'package' => 'other/one'],
                ['reason' => 'an invalid declaration in other/two: unknown mode', 'package' => 'other/two'],
            ]],
            ['acme.bookings' => [
                ['reason' => 'an invalid declaration in other/two: unknown mode', 'package' => 'other/two'],
                ['reason' => 'an invalid declaration in other/one: unknown mode', 'package' => 'other/one'],
            ]],
        ];
        foreach ($errors as $orderedErrors) {
            $set = new DeclarationSet([$valid], $providers, $orderedErrors);
            self::assertArrayHasKey('acme.bookings', $set->misconfigured());
            self::assertSame(
                ['acme/bookings', 'other/one', 'other/two'],
                $set->misconfigured()['acme.bookings']['packages'],
                'the engine and both invalid declarers are protected, whatever the order',
            );
            self::assertSame('misconfigured', $set->packageManagement()['acme/bookings']['class'], 'never independent');
        }
    }

    public function testTwoInvalidEntriesUnderOneIdAreBothKept(): void
    {
        $installed = tempnam(sys_get_temp_dir(), 'thallo-installed-') ?: '';
        $broken = static fn (string $name): array => [
            'name' => $name,
            'type' => 'glueful-extension',
            'extra' => ['thallo' => ['capabilities' => [['id' => 'acme.shared', 'mode' => 'bogus']]]],
        ];
        $manifest = ['packages' => [$broken('other/one'), $broken('other/two')]];
        file_put_contents($installed, (string) json_encode($manifest));
        try {
            $packages = new PackageCapabilityDeclarations($this->appContext(), $installed);
            $packages->all();
            $errors = $packages->errorsById();
        } finally {
            @unlink($installed);
        }
        self::assertSame(['other/one', 'other/two'], array_column($errors['acme.shared'], 'package'));
    }

    /**
     * @param array<string, mixed> $thallo acme/x's extra.thallo
     * @return array{0: list<CapabilityDeclaration>, 1: array<string, list<array{reason: string, package: string}>>}
     */
    private function declaredBy(array $thallo): array
    {
        $installed = tempnam(sys_get_temp_dir(), 'thallo-installed-') ?: '';
        file_put_contents($installed, (string) json_encode(['packages' => [[
            'name' => 'acme/x',
            'type' => 'glueful-extension',
            'extra' => ['thallo' => $thallo],
        ]]]));
        $packages = new PackageCapabilityDeclarations($this->appContext(), $installed);
        try {
            return [$packages->all(), $packages->errorsById()];
        } finally {
            @unlink($installed);
        }
    }

    public function testABrokenEntryIsNamedAsTheReasonItsValidSiblingIsBlocked(): void
    {
        [$declarations, $errors] = $this->declaredBy(['capabilities' => [
            ['id' => 'acme.x', 'mode' => 'activation'],
            ['mode' => 'bogus'],
        ]]);
        $set = new DeclarationSet($declarations, ['acme/x' => 'Acme\\X'], $errors);
        self::assertStringContainsString(
            'acme/x, which has an invalid capability entry',
            $set->misconfigured()['acme.x']['reason'],
        );
    }

    public function testACapabilitiesKeyThatIsNotAListIsReported(): void
    {
        [, $errors] = $this->declaredBy(['capabilities' => 'acme.x']);
        self::assertArrayHasKey('acme/x (capabilities)', $errors);
        self::assertSame('acme/x', $errors['acme/x (capabilities)'][0]['package']);
        self::assertStringContainsString('must be a list', $errors['acme/x (capabilities)'][0]['reason']);
    }

    public function testADeclarationThatConflictsOverAnotherActivationsEngineBlocksBoth(): void
    {
        $providers = ['acme/one' => 'Acme\\One', 'acme/two' => 'Acme\\Two'];
        $activation = static fn (string $id, string $pkg): Capability
            => new Capability($id, owningPackage: $pkg, management: ManagementMode::Activation);
        $set = self::set([
            self::decl($activation('a.x', 'acme/one'), 'p1'),
            self::decl($activation('a.y', 'acme/one'), 'p2'),
            self::decl($activation('a.y', 'acme/two'), 'p3'),
        ], $providers);
        self::assertStringStartsWith('declared differently', $set->misconfigured()['a.y']['reason']);
        self::assertArrayHasKey('a.x', $set->misconfigured(), 'a.y also claims acme/one');
        self::assertStringContainsString('claim acme/one', $set->misconfigured()['a.x']['reason']);
        self::assertSame('misconfigured', $set->packageManagement()['acme/one']['class']);
    }

    public function testAConflictMakesACapabilityStoredOnIneffectiveInEitherRegistrationOrder(): void
    {
        $providers = ['glueful/media' => self::MEDIA, 'glueful/import-export' => self::IMPORT_EXPORT];
        $over = static fn (string $package): Capability
            => new Capability('test.contested', owningPackage: $package, management: ManagementMode::Activation);
        $a = self::decl($over('glueful/media'), 'p1');
        $b = self::decl($over('glueful/import-export'), 'p2');
        foreach ([[$a, $b], [$b, $a]] as $order) {
            $set = self::set($order, $providers);
            self::assertArrayHasKey('test.contested', $set->misconfigured());
            self::assertArrayNotHasKey('test.contested', $set->valid());
        }
    }

    public function testAConflictRefusesTheCapabilityAndItsRoutesInEitherBootOrder(): void
    {
        $orders = [
            [ContestedConsumerProvider::class, ContestingProvider::class],
            [ContestingProvider::class, ContestedConsumerProvider::class],
        ];
        foreach ($orders as $order) {
            $container = $this->bootWith($order);
            $registry = $container->get(CapabilityRegistry::class);
            self::assertFalse($registry->isEnabled('test.contested'), implode(' → ', $order));
            self::assertFalse(ContestedConsumerProvider::$decision, 'the consumer saw it off at boot');
            self::assertStringStartsWith('Misconfigured', (string) $registry->availability('test.contested')->reason);
            $routes = $container->get(\Glueful\Routing\Router::class)->getAllRoutes();
            $paths = array_map(static fn (array $r): string => (string) $r['path'], $routes);
            self::assertNotContains('/test-contested', $paths, 'the gated route was never registered');
        }
    }

    public function testEveryManagementPathRefusesAMisconfiguredCapability(): void
    {
        $c = $this->contested();
        $context = $c->get(ApplicationContext::class);
        $request = $this->jsonRequest('POST', '/v1/admin/capabilities/test.contested/activation');

        $controller = new CapabilityActivationController(
            $context,
            $c->get(ActivationStore::class),
            $c->get(ActivationRunner::class),
        );
        foreach (
            [
            $controller->start($request, 'test.contested'),
            $controller->continue(new ActivationGenerationData(1), $request, 'test.contested'),
            $controller->cancel(new ActivationGenerationData(1), $request, 'test.contested'),
            ] as $response
        ) {
            self::assertSame(409, $response->getStatusCode());
            self::assertStringContainsString('misconfigured', (string) $response->getContent());
        }

        $admin = new TestableCapabilityAdminController(
            $c->get(CapabilityRegistry::class),
            $c->get(CapabilityStateStore::class),
            $context,
        );
        foreach ([true, false] as $enabled) {
            $response = $admin->update('test.contested', new UpdateCapabilityStateData($enabled));
            self::assertSame(409, $response->getStatusCode());
        }

        foreach (['--enable', '--disable'] as $flag) {
            $tester = new CommandTester(new CapabilitiesCommand($c, $context));
            self::assertSame(1, $tester->execute([$flag => 'test.contested']), $flag);
            self::assertStringContainsString('Misconfigured', $tester->getDisplay());
        }
        $tester = new CommandTester(new CapabilitiesEnableCommand($c, $context));
        self::assertSame(1, $tester->execute(['capability' => 'test.contested']));
        self::assertStringContainsString('Misconfigured', $tester->getDisplay());
    }

    public function testTheConflictingPackagesAreRefusedByTheGenericSwitches(): void
    {
        $c = $this->contested();
        $context = $c->get(ApplicationContext::class);
        foreach ([self::MEDIA, self::IMPORT_EXPORT] as $provider) {
            $refusal = (string) ProtectedProviders::refusalFor($context, $provider);
            self::assertStringContainsString('Misconfigured', $refusal, $provider);
        }
        $policy = $c->get(FeatureManagementPolicy::class);
        self::assertSame('misconfigured', $policy->managementOf('glueful/media')['class'], 'never independent');

        $toggle = new TestableExtensionAdminController($context);
        $toggle->executor = new SpySchemaExecutor();
        $request = $this->jsonRequest('POST', '/v1/admin/extensions/disable', ['name' => 'glueful/media']);
        $response = $toggle->disable($request);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame([], $toggle->executor->calls);
    }

    public function testTheInstalledRowOffersNoCommandThatWouldBeRefused(): void
    {
        $controller = new TestableExtensionAdminController($this->contested()->get(ApplicationContext::class));
        $rows = json_decode((string) $controller->index()->getContent(), true)['data']['extensions'];
        $row = array_column($rows, null, 'name')['glueful/media'];
        self::assertSame('misconfigured', $row['management']['class']);
        self::assertNull($row['cli_command'], 'extensions:enable/disable would be refused');
    }

    public function testProvisionWithholdsTheClaimedPackagesPermissions(): void
    {
        // A misconfigured capability can't be activated, so the packages it claims get no grants
        // from provision either until the declarations are fixed.
        $slug = (string) $this->connection()->getPDO()
            ->query("SELECT slug FROM permissions WHERE managed_by = 'glueful/import-export' LIMIT 1")->fetchColumn();
        self::assertNotSame('', $slug);
        $withheld = $this->contested()->get(InstallRoleGrants::class)->withheldPermissions(null);
        self::assertContains($slug, $withheld);
        self::assertNotContains($slug, $this->container()->get(InstallRoleGrants::class)->withheldPermissions(null));
    }

    public function testTheCapabilityListSaysWhyItIsMisconfigured(): void
    {
        $c = $this->contested();
        $admin = new TestableCapabilityAdminController(
            $c->get(CapabilityRegistry::class),
            $c->get(CapabilityStateStore::class),
            $c->get(ApplicationContext::class),
        );
        $rows = json_decode((string) $admin->manage()->getContent(), true)['data']['capabilities'];
        $row = array_column($rows, null, 'id')['test.contested'];
        self::assertStringStartsWith('Misconfigured', (string) $row['misconfigured']);
        self::assertFalse($row['effective']);
        self::assertNull(array_column($rows, null, 'id')['thallo.search']['misconfigured']);
    }

    public function testAnAlreadyEnabledEngineStaysLoaded(): void
    {
        self::assertTrue($this->contested()->get(ExtensionManager::class)->hasProvider(self::MEDIA));
    }

    public function testDoctorReportsIt(): void
    {
        $c = $this->contested();
        $tester = new CommandTester(new DoctorCommand($c, $c->get(ApplicationContext::class)));
        $tester->execute([]);
        self::assertMatchesRegularExpression('/capability-declarations.*test\.contested/s', $tester->getDisplay());
    }
}
