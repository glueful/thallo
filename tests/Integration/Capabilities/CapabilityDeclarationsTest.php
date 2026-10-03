<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Thallo\Contracts\Capability\ActivationCopy;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Capability\ExternalFlowDestination;
use Thallo\Contracts\Capability\ManagementMode;
use Thallo\Core\Capabilities\Declarations\DeclarationSet;
use Thallo\Core\Capabilities\Declarations\PackageCapabilityDeclarations;
use Thallo\Core\Capabilities\FeatureManagementPolicy;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Capabilities\EarlyConsumerProvider;
use Thallo\Core\Tests\Support\Capabilities\LateDeclarerProvider;

/**
 * Management is declared by the package that contributes a capability (spec §7.3–§7.4): an
 * always-loaded pack declares through DeclaresCapabilities, an installed package through its
 * composer.json metadata (read whether or not it is enabled). The set is collected before any
 * provider boots, sealed at the first capability decision, and the engine's provider comes from
 * the package manifest, never from Thallo.
 */
final class CapabilityDeclarationsTest extends AppTestCase
{
    private const FIRST_PARTY = [
        'thallo.accounts', 'thallo.analytics', 'thallo.collections', 'thallo.commerce',
        'thallo.importers', 'thallo.navigation', 'thallo.render', 'thallo.search', 'thallo.seo',
        'thallo.subscriptions', 'thallo.tenancy', 'thallo.workflow',
    ];

    private function set(): DeclarationSet
    {
        return $this->container()->get(DeclarationSet::class);
    }

    public function testFirstPartyModesComeFromTheirPacks(): void
    {
        $set = $this->set();
        self::assertSame(ManagementMode::Activation, $set->modeOf('thallo.commerce'));
        self::assertSame(ManagementMode::Activation, $set->modeOf('thallo.subscriptions'));
        self::assertSame(
            ['package' => 'glueful/commerce', 'provider' => 'Glueful\\Extensions\\Commerce\\CommerceServiceProvider'],
            $set->engineOf('thallo.commerce'),
        );
        self::assertSame(ManagementMode::ExternalFlow, $set->modeOf('thallo.tenancy'));
        self::assertSame('/settings/workspaces', $set->valid()['thallo.tenancy']->destination?->path);
        self::assertSame(ManagementMode::Simple, $set->modeOf('thallo.search'));
        self::assertNotNull($set->valid()['thallo.commerce']->copy?->turnOn);
    }

    public function testTheRealBootDeclaresEveryFirstPartyCapability(): void
    {
        $registry = $this->container()->get(CapabilityRegistry::class);
        $ids = array_map(static fn (Capability $c): string => $c->id, $registry->all());
        sort($ids);
        self::assertSame(self::FIRST_PARTY, array_values(array_intersect($ids, self::FIRST_PARTY)));
        $missing = array_values(array_diff(self::FIRST_PARTY, $ids));
        self::assertSame([], $missing, 'every first-party capability is declared');
        $foreign = array_values(array_filter($ids, static fn (string $id): bool => !str_starts_with($id, 'thallo.')));
        self::assertSame([], $foreign);
    }

    public function testAPackageMetadataDeclarationAppearsWhileItsPackageIsDisabled(): void
    {
        $installed = tempnam(sys_get_temp_dir(), 'thallo-installed-') ?: '';
        file_put_contents($installed, (string) json_encode(['packages' => [[
            'name' => 'acme/widgets',
            'type' => 'glueful-extension',
            'extra' => [
                'glueful' => ['provider' => 'Acme\\Widgets\\WidgetsServiceProvider'],
                'thallo' => ['capabilities' => [[
                    'id' => 'acme.widgets', 'label' => 'Widgets', 'description' => 'Sell widgets.',
                    'mode' => 'activation', 'copy' => ['turn_on' => 'This prepares widgets.'],
                ]]],
            ],
        ]]]));
        try {
            $declarations = (new PackageCapabilityDeclarations($this->appContext(), $installed))->all();
        } finally {
            @unlink($installed);
        }
        self::assertCount(1, $declarations);
        $capability = $declarations[0]->capability;
        self::assertSame('acme.widgets', $capability->id);
        self::assertSame('acme/widgets', $capability->owningPackage);
        self::assertSame(ManagementMode::Activation, $capability->management);
        self::assertSame('This prepares widgets.', $capability->copy?->turnOn);
        self::assertSame('package:acme/widgets', $declarations[0]->source);
    }

    public function testAProviderIsResolvedFromTheManifestNotFromThallo(): void
    {
        $path = dirname(__DIR__, 3) . '/core/src/Capabilities/FeatureManagementPolicy.php';
        $source = (string) file_get_contents($path);
        self::assertDoesNotMatchRegularExpression('/ServiceProvider[\'"]/', $source, 'no provider class strings');
        self::assertDoesNotMatchRegularExpression('/glueful\/(commerce|subscriptions|tenancy)/', $source);
        self::assertSame(
            'Glueful\\Extensions\\Commerce\\CommerceServiceProvider',
            $this->container()->get(FeatureManagementPolicy::class)->engineOf('thallo.commerce')['provider'] ?? null,
        );
    }

    public function testTheContractRejectsAnActivationWithoutAnOwnerAndAnExternalFlowWithoutADestination(): void
    {
        try {
            new Capability('x.one', management: ManagementMode::Activation);
            self::fail('an activation needs an owning package');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new Capability('x.two', management: ManagementMode::ExternalFlow);
    }

    public function testDeclarationsAreCompleteBeforeAnyProviderBoots(): void
    {
        /** @var array{enabled: list<string>} $base */
        $base = require dirname(__DIR__, 3) . '/config/serviceproviders.php';
        $orders = [
            [EarlyConsumerProvider::class, LateDeclarerProvider::class],
            [LateDeclarerProvider::class, EarlyConsumerProvider::class],
        ];
        foreach ($orders as $order) {
            EarlyConsumerProvider::$seen = [];
            self::bootAppWithConfigOverride('serviceproviders', ['enabled' => [...$base['enabled'], ...$order]]);
            self::assertContains('test.early', EarlyConsumerProvider::$seen, implode(' → ', $order));
            $why = 'a provider booting later already declared: ' . implode(' → ', $order);
            self::assertContains('test.late', EarlyConsumerProvider::$seen, $why);
            self::assertTrue(EarlyConsumerProvider::$decision, 'an untouched simple capability follows availability');
        }
    }

    public function testARegistrationAfterTheSetIsSealedIsRefused(): void
    {
        $registry = $this->container()->get(CapabilityRegistry::class);
        $registry->isEnabled('thallo.search');                               // sealed long since
        $existing = $this->set()->valid()['thallo.search'];
        $registry->register($existing);                                    // identical: a no-op
        $this->expectException(\LogicException::class);
        $registry->register(new Capability('test.too-late', label: 'Too late'));
    }

    public function testCopyAndDestinationAreCarriedOnTheContract(): void
    {
        $capability = new Capability(
            'x.three',
            owningPackage: 'acme/three',
            management: ManagementMode::Activation,
            copy: new ActivationCopy('On.', 'Off.', [['label' => 'Go', 'to' => '/go']]),
        );
        self::assertSame('Off.', $capability->copy?->turnOff);
        $destination = new ExternalFlowDestination('/x', 'X');
        $flow = new Capability('x.four', management: ManagementMode::ExternalFlow, destination: $destination);
        self::assertSame('X', $flow->destination?->label);
    }
}
