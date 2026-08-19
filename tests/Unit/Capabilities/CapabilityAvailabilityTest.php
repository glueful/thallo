<?php

declare(strict_types=1);

namespace App\Tests\Unit\Capabilities;

use App\Capabilities\DefaultCapabilityRegistry;
use App\Tests\Support\ScriptedAvailabilityResolver;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Schema\ReadinessState;
use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityAvailability;
use Thallo\Contracts\Capability\CapabilityAvailabilityResolver;

/**
 * The owner-availability contract (spec B3): the resolver's truth table over the owning
 * engine's install/enable/readiness states, and the registry's proof that EFFECTIVE enabled is
 * requested AND available — a requested-but-unavailable capability vanishes from isEnabled()
 * and enabled() while remaining visible in all().
 */
final class CapabilityAvailabilityTest extends TestCase
{
    private function resolver(): ScriptedAvailabilityResolver
    {
        return new ScriptedAvailabilityResolver(ApplicationContext::forTesting(sys_get_temp_dir()));
    }

    private function owned(): Capability
    {
        return new Capability('thallo.search', owningPackage: 'glueful/meilisearch');
    }

    private function installedRow(): object
    {
        return new class {
            public string $provider = 'Vendor\\Meili\\Provider';
        };
    }

    // ── resolver truth table ──────────────────────────────────────────────────────

    public function testOwnerlessCapabilityIsAlwaysAvailable(): void
    {
        $verdict = $this->resolver()->resolve(new Capability('thallo.render'));
        self::assertTrue($verdict->available);
        self::assertNull($verdict->reason);
    }

    public function testAbsentOwnerIsUnavailableWithComposerRemedy(): void
    {
        $verdict = $this->resolver()->resolve($this->owned());
        self::assertFalse($verdict->available);
        self::assertStringContainsString('not installed', (string) $verdict->reason);
        self::assertSame('composer require glueful/meilisearch', $verdict->remedy);
    }

    public function testInstalledButDisabledOwnerIsUnavailableWithEnableRemedy(): void
    {
        $resolver = $this->resolver();
        $resolver->installed['glueful/meilisearch'] = $this->installedRow();

        $verdict = $resolver->resolve($this->owned());

        self::assertFalse($verdict->available);
        self::assertStringContainsString('not enabled', (string) $verdict->reason);
        self::assertSame('php glueful extensions:enable glueful/meilisearch', $verdict->remedy);
    }

    public function testEnabledButPendingSchemaIsUnavailableWithMigrateRemedy(): void
    {
        $resolver = $this->resolver();
        $resolver->installed['glueful/meilisearch'] = $this->installedRow();
        $resolver->enabled = ['Vendor\\Meili\\Provider'];
        $resolver->readiness['glueful/meilisearch'] = [
            'glueful/meilisearch' => ['state' => ReadinessState::Pending, 'reasons' => ['1 migration(s) pending']],
        ];

        $verdict = $resolver->resolve($this->owned());

        self::assertFalse($verdict->available);
        self::assertStringContainsString('pending', (string) $verdict->reason);
        self::assertSame('php glueful migrate:run', $verdict->remedy);
    }

    public function testDivergentOutranksPendingWithVerifyRemedy(): void
    {
        $resolver = $this->resolver();
        $resolver->installed['glueful/meilisearch'] = $this->installedRow();
        $resolver->enabled = ['Vendor\\Meili\\Provider'];
        $resolver->readiness['glueful/meilisearch'] = [
            'glueful/meilisearch' => ['state' => ReadinessState::Pending, 'reasons' => ['1 migration(s) pending']],
            'glueful/meilisearch:x' =>
                ['state' => ReadinessState::Divergent, 'reasons' => ['checksum mismatch for 001_X.php']],
        ];

        $verdict = $resolver->resolve($this->owned());

        self::assertFalse($verdict->available);
        self::assertStringContainsString('divergent', (string) $verdict->reason);
        self::assertStringContainsString('checksum mismatch', (string) $verdict->reason);
        self::assertSame('php glueful migrate:verify', $verdict->remedy);
    }

    public function testExplicitNoneIsReadyAndAvailable(): void
    {
        $resolver = $this->resolver();
        $resolver->installed['glueful/meilisearch'] = $this->installedRow();
        $resolver->enabled = ['Vendor\\Meili\\Provider'];
        // migrations: none => forPackage() answers with zero descriptors.

        self::assertTrue($resolver->resolve($this->owned())->available);
    }

    public function testReadySchemaIsAvailable(): void
    {
        $resolver = $this->resolver();
        $resolver->installed['glueful/meilisearch'] = $this->installedRow();
        $resolver->enabled = ['Vendor\\Meili\\Provider'];
        $resolver->readiness['glueful/meilisearch'] = [
            'glueful/meilisearch' => ['state' => ReadinessState::Ready, 'reasons' => []],
        ];

        self::assertTrue($resolver->resolve($this->owned())->available);
    }

    public function testMissingLedgerOrUnreachableDbFailsClosedWithoutThrowing(): void
    {
        $resolver = $this->resolver();
        $resolver->installed['glueful/meilisearch'] = $this->installedRow();
        $resolver->enabled = ['Vendor\\Meili\\Provider'];
        $resolver->bootFailure = new \PDOException('SQLSTATE[08006] connection refused');

        $verdict = $resolver->resolve($this->owned());

        self::assertFalse($verdict->available, 'an undeterminable answer fails closed');
        self::assertStringContainsString('could not be determined', (string) $verdict->reason);
        self::assertStringContainsString('connection refused', (string) $verdict->reason);
    }

    // ── registry: effective = requested && available ──────────────────────────────

    public function testRequestedButUnavailableIsExcludedFromEnabledYetVisibleInAll(): void
    {
        $resolver = new class implements CapabilityAvailabilityResolver {
            public function resolve(Capability $capability): CapabilityAvailability
            {
                return $capability->owningPackage === null
                    ? CapabilityAvailability::available()
                    : CapabilityAvailability::unavailable('engine down');
            }
        };
        $registry = new DefaultCapabilityRegistry([], $resolver);
        $registry->register(new Capability('thallo.render'));
        $registry->register($this->owned()); // requested (no override) but unavailable

        self::assertTrue($registry->isRequestedEnabled('thallo.search'), 'the switchboard says on');
        self::assertFalse($registry->availability('thallo.search')->available);
        self::assertFalse($registry->isEnabled('thallo.search'), 'effective = requested AND available');
        self::assertSame(
            ['thallo.render'],
            array_map(static fn(Capability $c) => $c->id, $registry->enabled())
        );
        self::assertCount(2, $registry->all(), 'unavailable capabilities stay visible in all()');
    }

    public function testAvailabilityIsMemoizedForTheRegistryLifetime(): void
    {
        $resolver = new class implements CapabilityAvailabilityResolver {
            public int $calls = 0;

            public function resolve(Capability $capability): CapabilityAvailability
            {
                $this->calls++;
                return CapabilityAvailability::available();
            }
        };
        $registry = new DefaultCapabilityRegistry([], $resolver);
        $registry->register($this->owned());

        $registry->isEnabled('thallo.search');
        $registry->isEnabled('thallo.search');
        $registry->enabled();

        self::assertSame(1, $resolver->calls, 'repeated gates must not repeat ledger queries');
    }

    public function testMissingResolverKeepsOwnerlessAvailableAndFailsOwnedClosed(): void
    {
        $registry = new DefaultCapabilityRegistry();
        $registry->register(new Capability('thallo.render'));
        $registry->register($this->owned());

        self::assertTrue($registry->isEnabled('thallo.render'), 'direct construction stays compatible');
        self::assertFalse($registry->isEnabled('thallo.search'));
        self::assertStringContainsString(
            'resolver unavailable',
            (string) $registry->availability('thallo.search')->reason
        );
    }

    public function testOwningPackageMustBeAComposerPackageString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Capability('thallo.search', owningPackage: 'not a package');
    }
}
