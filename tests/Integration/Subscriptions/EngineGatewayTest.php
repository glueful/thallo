<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Lifecycle\SubscriptionSubjectDataPurger;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Psr\Container\ContainerInterface;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Engine\EngineUnavailableException;

/**
 * Task 7 (Phase B): the three-state matrix `EngineGateway` sits on top of --
 * every later API task resolves engine services through this gateway, so it
 * is the single seam that must degrade honestly instead of 500ing.
 *
 * (a) DISABLED: a REAL second boot with glueful/subscriptions' own provider
 *     removed from config/extensions.php's enabled list (the actual, only
 *     way the container ends up lacking `SubscriptionService::class` --
 *     see EngineGateway::engineState()'s docblock). Not a hand-rolled
 *     container: the point is to prove the real disabled-engine boot
 *     produces this state, not merely that the gateway can be told to.
 * (b) SCHEMA_NOT_READY: the REAL fully-enabled shared boot's container,
 *     wrapped so ONLY `SubscriptionSchemaReadiness::class` resolves to a
 *     stub reporting `isReady(): false` -- mirrors the vendor engine's own
 *     established pattern for constructing precise container states
 *     (SubscriptionSchemaReadinessTest::testIsReadyReturnsFalseWhenThe
 *     SchemaProbeThrows hand-rolls a ContainerInterface the same way).
 *     Every OTHER id still resolves through the real container.
 * (c) READY: the ordinary shared test boot (engine enabled, schema
 *     migrated) -- accessors must return real, working engine services.
 */
final class EngineGatewayTest extends AppTestCase
{
    // ------------------------------------------------------------------
    // (a) DISABLED
    // ------------------------------------------------------------------

    public function testDisabledWhenContainerLacksSubscriptionService(): void
    {
        $disabledApp = $this->bootWithEngineProviderDisabled();

        try {
            $container = $disabledApp->getContainer();
            self::assertFalse(
                $container->has(SubscriptionService::class),
                'sanity check: this boot really lacks the engine binding',
            );

            $gateway = new EngineGateway($disabledApp);

            self::assertSame(EngineGateway::DISABLED, $gateway->engineState());
            self::assertNull($gateway->purger(), 'purger() must degrade to null, never throw, when unavailable');

            foreach (['subscriptions', 'plans', 'overrides'] as $method) {
                try {
                    $gateway->{$method}();
                    self::fail("{$method}() must throw when the engine is disabled");
                } catch (EngineUnavailableException $e) {
                    self::assertSame(EngineGateway::DISABLED, $e->state);
                }
            }
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // ------------------------------------------------------------------
    // (b) SCHEMA_NOT_READY
    // ------------------------------------------------------------------

    public function testSchemaNotReadyWhenReadinessReportsFalse(): void
    {
        $gateway = new EngineGateway($this->contextWithStubbedReadiness(ready: false));

        self::assertSame(EngineGateway::SCHEMA_NOT_READY, $gateway->engineState());
        self::assertNull($gateway->purger());

        foreach (['subscriptions', 'plans', 'overrides'] as $method) {
            try {
                $gateway->{$method}();
                self::fail("{$method}() must throw when the schema isn't ready");
            } catch (EngineUnavailableException $e) {
                self::assertSame(EngineGateway::SCHEMA_NOT_READY, $e->state);
            }
        }
    }

    // ------------------------------------------------------------------
    // (c) READY
    // ------------------------------------------------------------------

    public function testReadyReturnsRealServices(): void
    {
        $gateway = new EngineGateway($this->appContext());

        self::assertSame(EngineGateway::READY, $gateway->engineState());
        self::assertInstanceOf(SubscriptionService::class, $gateway->subscriptions());
        self::assertInstanceOf(PlanManagementService::class, $gateway->plans());
        self::assertInstanceOf(OverrideRepository::class, $gateway->overrides());
        self::assertInstanceOf(SubscriptionSubjectDataPurger::class, $gateway->purger());
    }

    // ------------------------------------------------------------------
    // container binding
    // ------------------------------------------------------------------

    public function testGatewayIsRegisteredSharedInTheContainer(): void
    {
        $first = $this->container()->get(EngineGateway::class);
        $second = $this->container()->get(EngineGateway::class);

        self::assertInstanceOf(EngineGateway::class, $first);
        self::assertSame($first, $second, 'registered shared: the pack provider must bind it as such');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * A REAL second boot with glueful/subscriptions' own provider filtered out of
     * config/extensions.php's `enabled` list -- the only honest way to produce a
     * container that truly lacks `SubscriptionService::class` (see class docblock).
     *
     * `array_replace_recursive` (the framework's env-config merge -- see
     * ConfigurationLoader::mergeConfigs) merges list arrays BY INDEX, not by value:
     * a shorter override list only overwrites the indices it defines and leaves the
     * base's TRAILING entries (anything past the override's length) untouched, which
     * would silently resurrect the very provider we just filtered out. Padding the
     * filtered list back to the base's original length (with a harmless duplicate of
     * an already-selected provider) avoids that index-merge trap; the duplicate is
     * inert because ExtensionResolver::resolve() selects providers into a map keyed by
     * FQCN, so a repeated entry just overwrites its own key.
     */
    private function bootWithEngineProviderDisabled(): ApplicationContext
    {
        $root = dirname(__DIR__, 3);
        $base = (array) require $root . '/config/extensions.php';
        $engineProvider = \Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class;

        /** @var list<string> $baseEnabled */
        $baseEnabled = (array) $base['enabled'];
        $withoutEngine = array_values(array_filter(
            $baseEnabled,
            static fn (string $provider): bool => $provider !== $engineProvider,
        ));
        while (count($withoutEngine) < count($baseEnabled)) {
            $withoutEngine[] = $withoutEngine[0];
        }

        return self::bootAppWithConfigOverride('extensions', ['enabled' => $withoutEngine]);
    }

    /**
     * Wraps the REAL shared container so every id resolves normally EXCEPT
     * `SubscriptionSchemaReadiness::class`, which resolves to a stub reporting
     * `isReady()` per `$ready` -- lets the SCHEMA_NOT_READY branch be exercised
     * against the genuine "engine bound, schema not ready" combination without a
     * second full framework boot.
     */
    private function contextWithStubbedReadiness(bool $ready): ApplicationContext
    {
        $real = $this->appContext();
        $stub = new class ($ready) {
            public function __construct(private readonly bool $ready)
            {
            }

            public function isReady(): bool
            {
                return $this->ready;
            }
        };

        $context = new ApplicationContext($real->getBasePath(), $real->getEnvironment());
        $context->setContainer(new class ($real->getContainer(), $stub) implements ContainerInterface {
            public function __construct(
                private readonly ContainerInterface $real,
                private readonly object $readinessStub,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === SubscriptionSchemaReadiness::class) {
                    return $this->readinessStub;
                }

                return $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return $id === SubscriptionSchemaReadiness::class || $this->real->has($id);
            }
        });

        return $context;
    }
}
