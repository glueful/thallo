<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionEventsTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionOverridesTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionPlansTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateSubscriptionsTable;
use Glueful\Extensions\Subscriptions\Database\Migrations\CreateV2PreparationState;
use Glueful\Extensions\Subscriptions\Database\Migrations\SubjectModel;
use Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Purge\SubscriptionsPurgeHandler;
use Thallo\Tenancy\Purge\PurgeHandler;
use Thallo\Tenancy\Purge\PurgeResourceRegistry;

/**
 * Task 10 (Phase B): {@see SubscriptionsPurgeHandler}'s fail-closed matrix — user ruling: a
 * direct `hasTable('subscriptions')` check is the ONLY thing that may produce the zero-pass;
 * table present + no purger MUST throw regardless of WHY the purger is unavailable (engine
 * disabled, schema not ready, or the engine provider simply absent from this process).
 *
 * The default shared boot (see AppTestCase::setUpBeforeClass) runs against a REAL Postgres
 * connection with the complete, fully-migrated 2.x schema — exactly like EngineGatewayTest's
 * "ready" scenario. The legacy/partial-schema scenarios below fabricate their own throwaway
 * SQLite `Connection` + `ApplicationContext` instead of touching that shared database (mirrors
 * `vendor/glueful/subscriptions/tests/Support/SubscriptionsTestCase.php`'s own harness, which
 * this app cannot autoload directly — it lives under that package's `autoload-dev`, not its
 * main `autoload`).
 */
final class SubscriptionsPurgeHandlerTest extends AppTestCase
{
    private static int $seq = 0;

    // ------------------------------------------------------------------
    // 1. schema-absent: zero-pass, gateway never consulted
    // ------------------------------------------------------------------

    public function testSchemaAbsentIsAZeroPassWithoutConsultingReadinessOrTheGateway(): void
    {
        $connection = $this->sqliteConnection();
        self::assertFalse(
            $connection->getSchemaBuilder()->hasTable('subscriptions'),
            'sanity: a brand-new connection really has no subscriptions table',
        );

        // A gateway wired to a container that throws on ANY access — proves the handler
        // never even asks it a question when the table is absent.
        $poisonedContext = $this->contextWithContainer(new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException("must not consult the container for '{$id}' when schema is absent");
            }

            public function has(string $id): bool
            {
                throw new \LogicException("must not consult the container for '{$id}' when schema is absent");
            }
        });
        $gateway = new EngineGateway($poisonedContext);
        $handler = new SubscriptionsPurgeHandler($poisonedContext, $connection, $gateway);

        self::assertSame(['counts' => []], $handler->prepare($poisonedContext, 'tenantAbsent1'));
        $handler->purge($poisonedContext, 'tenantAbsent1', []); // must not throw
        self::assertTrue($handler->verify($poisonedContext, 'tenantAbsent1', []));
    }

    // ------------------------------------------------------------------
    // 2. lone legacy 1.x table + no engine: throws from all three
    // ------------------------------------------------------------------

    public function testThrowsFromAllThreeMethodsWithLoneLegacyTableAndNoEngine(): void
    {
        $connection = $this->sqliteConnection();
        (new CreateSubscriptionsTable())->up($connection->getSchemaBuilder());
        self::assertTrue($connection->getSchemaBuilder()->hasTable('subscriptions'));
        self::assertFalse(
            $connection->getSchemaBuilder()->hasTable('subscription_overrides'),
            'sanity: this is a LONE legacy table, none of the sibling tables exist',
        );

        // "No engine" — the container lacks SubscriptionService::class entirely, exactly how
        // EngineGateway::engineState() defines DISABLED.
        $context = $this->contextWithContainer($this->fakeContainer($connection, []));
        $gateway = new EngineGateway($context);
        self::assertSame(EngineGateway::DISABLED, $gateway->engineState());

        $this->assertAllThreeMethodsThrowTheFailClosedMessage(
            new SubscriptionsPurgeHandler($context, $connection, $gateway),
            $context,
        );
    }

    // ------------------------------------------------------------------
    // 3. partial-006 schema + readiness false: throws from all three
    // ------------------------------------------------------------------

    public function testThrowsFromAllThreeMethodsWithPartial006SchemaAndReadinessFalse(): void
    {
        $connection = $this->sqliteConnection();
        $schema = $connection->getSchemaBuilder();
        (new CreateSubscriptionsTable())->up($schema);
        (new CreateSubscriptionOverridesTable())->up($schema);
        (new CreateSubscriptionEventsTable())->up($schema);
        (new CreateSubscriptionPlansTable())->up($schema);
        (new CreateV2PreparationState())->up($schema);
        (new SubjectModel())->up($schema); // fresh install, empty tables — the guard is exempt

        // Representative partial-006 shape: every required table now exists, but strip one of
        // the subject-model columns readiness requires (mirrors
        // SubscriptionSchemaReadinessTest::testNotReadyWhenSubscriptionsPlanUuidColumnIsMissing).
        $connection->getPDO()->exec('ALTER TABLE "subscriptions" DROP COLUMN "plan_uuid"');

        self::assertTrue($schema->hasTable('subscriptions'));
        foreach (
            ['subscription_overrides', 'subscription_events', 'subscription_plans',
                'subscription_provider_event_receipts'] as $table
        ) {
            self::assertTrue($schema->hasTable($table), "sanity: {$table} must exist in a partial-006 schema");
        }

        // Readiness needs only the Connection binding to probe the (fabricated) schema.
        $readinessContext = $this->contextWithContainer($this->fakeContainer($connection, []));
        $readiness = new SubscriptionSchemaReadiness($readinessContext);
        self::assertFalse($readiness->isReady(), 'sanity: the fabricated schema really is not ready');

        // The engine IS bound (unlike case 2) — only readiness reports false.
        $context = $this->contextWithContainer($this->fakeContainer($connection, [
            SubscriptionService::class => new \stdClass(),
            SubscriptionSchemaReadiness::class => $readiness,
        ]));
        $gateway = new EngineGateway($context);
        self::assertSame(EngineGateway::SCHEMA_NOT_READY, $gateway->engineState());

        $this->assertAllThreeMethodsThrowTheFailClosedMessage(
            new SubscriptionsPurgeHandler($context, $connection, $gateway),
            $context,
        );
    }

    // ------------------------------------------------------------------
    // 4. complete schema + engine provider absent: throws from all three,
    //    AND the alias stays registered in the shared PurgeResourceRegistry.
    // ------------------------------------------------------------------

    public function testThrowsFromAllThreeMethodsWithCompleteSchemaAndEngineProviderDisabled(): void
    {
        $disabledApp = $this->bootWithEngineProviderDisabled();

        try {
            $container = $disabledApp->getContainer();
            self::assertFalse(
                $container->has(SubscriptionService::class),
                'sanity: this second boot really lacks the engine binding',
            );

            $connection = $container->get(Connection::class);
            self::assertTrue(
                $connection->getSchemaBuilder()->hasTable('subscriptions'),
                'sanity: the real DB schema, migrated by the primary shared boot, is complete here too',
            );

            $gateway = new EngineGateway($disabledApp);
            self::assertSame(EngineGateway::DISABLED, $gateway->engineState());

            $handler = new SubscriptionsPurgeHandler($disabledApp, $connection, $gateway);
            $this->assertAllThreeMethodsThrowTheFailClosedMessage($handler, $disabledApp);

            // The alias must survive the engine provider being entirely absent from this
            // process (Task 10 requirement) — the pack's OWN provider is still active, and its
            // factory soft-resolves EngineGateway rather than the engine directly.
            $ids = array_map(
                static fn (PurgeHandler $h): string => $h->id(),
                $container->get(PurgeResourceRegistry::class)->all(),
            );
            self::assertContains('subscriptions', $ids);
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // ------------------------------------------------------------------
    // 5. alias survives a disabled thallo.subscriptions capability
    // ------------------------------------------------------------------

    public function testAliasIsRegisteredEvenWhenTheCapabilityIsDisabled(): void
    {
        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.subscriptions' => false],
        ]);

        try {
            $container = $disabledApp->getContainer();
            self::assertFalse(
                $container->get(CapabilityRegistry::class)->isEnabled('thallo.subscriptions'),
                'sanity: the capability really is disabled in this second boot',
            );

            $ids = array_map(
                static fn (PurgeHandler $h): string => $h->id(),
                $container->get(PurgeResourceRegistry::class)->all(),
            );
            self::assertContains('subscriptions', $ids);
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // ------------------------------------------------------------------
    // 6. default boot: the tenancy registry lists 'subscriptions' among handler ids
    // ------------------------------------------------------------------

    public function testPurgeResourceRegistryListsSubscriptionsAmongHandlerIds(): void
    {
        $ids = array_map(
            static fn (PurgeHandler $h): string => $h->id(),
            $this->container()->get(PurgeResourceRegistry::class)->all(),
        );

        self::assertContains('subscriptions', $ids);
    }

    // ------------------------------------------------------------------
    // 7. ready path: counts -> purges -> verifies, seeded data includes member plans
    // ------------------------------------------------------------------

    public function testReadyPathCountsPurgesAndVerifiesSeededBillingDataIncludingMemberPlans(): void
    {
        $connection = $this->connection();
        $handler = $this->container()->get(SubscriptionsPurgeHandler::class);
        $context = $this->appContext();

        $target = 'purgeSubsT' . str_pad((string) (++self::$seq), 3, '0', STR_PAD_LEFT);
        $memberUuid = 'purgeMbr' . str_pad((string) self::$seq, 4, '0', STR_PAD_LEFT);

        try {
            // Tenant's own self-subscription.
            $connection->table('subscriptions')->insert([
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $target,
                'subject_type' => 'tenant',
                'subject_uuid' => $target,
                'plan_key' => 'ready-path-tenant',
                'plan_uuid' => Utils::generateNanoID(12),
                'status' => 'active',
            ]);
            // A member's own subscription under the same workspace.
            $connection->table('subscriptions')->insert([
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $target,
                'subject_type' => 'user',
                'subject_uuid' => $memberUuid,
                'plan_key' => 'ready-path-member',
                'plan_uuid' => Utils::generateNanoID(12),
                'status' => 'active',
            ]);
            $connection->table('subscription_overrides')->insert([
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $target,
                'subject_type' => 'tenant',
                'subject_uuid' => $target,
                'entitlement' => 'seats',
                'value' => json_encode(['limit' => 10], JSON_THROW_ON_ERROR),
            ]);
            $connection->table('subscription_events')->insert([
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $target,
                'subject_type' => 'tenant',
                'subject_uuid' => $target,
                'type' => 'created',
                'source' => 'manual',
            ]);
            $connection->table('subscription_provider_event_receipts')->insert([
                'uuid' => Utils::generateNanoID(12),
                'provider_gateway' => 'stripe',
                'provider_logical_event_key' => 'purge-ready-' . self::$seq,
                'event_type' => 'invoice.paid',
                'tenant_uuid' => $target,
                'subject_type' => 'tenant',
                'subject_uuid' => $target,
                'outcome' => 'accepted',
            ]);
            // A member plan the workspace itself created (audience=user, owner_tenant_uuid=target)
            // — the hard-purge form's own docblock: this must be swept too, unlike platform plans.
            $connection->table('subscription_plans')->insert([
                'uuid' => Utils::generateNanoID(12),
                'plan_key' => 'ready-path-custom',
                'display_name' => 'Ready Path Custom',
                'entitlements' => json_encode([], JSON_THROW_ON_ERROR),
                'status' => 'active',
                'audience' => 'user',
                'owner_tenant_uuid' => $target,
            ]);

            $artifacts = $handler->prepare($context, $target);
            self::assertEqualsCanonicalizing([
                'subscriptions' => 2,
                'subscription_overrides' => 1,
                'subscription_events' => 1,
                'subscription_provider_event_receipts' => 1,
                'subscription_plans' => 1,
            ], $artifacts['counts']);

            $handler->purge($context, $target, $artifacts);

            self::assertTrue($handler->verify($context, $target, $artifacts));

            self::assertSame(0, (int) $connection->table('subscriptions')->where('tenant_uuid', $target)->count());
            self::assertSame(
                0,
                (int) $connection->table('subscription_overrides')->where('tenant_uuid', $target)->count(),
            );
            self::assertSame(
                0,
                (int) $connection->table('subscription_events')->where('tenant_uuid', $target)->count(),
            );
            self::assertSame(
                0,
                (int) $connection->table('subscription_provider_event_receipts')
                    ->where('tenant_uuid', $target)->count(),
            );
            self::assertSame(
                0,
                (int) $connection->table('subscription_plans')
                    ->where('audience', 'user')->where('owner_tenant_uuid', $target)->count(),
            );
        } finally {
            $connection->table('subscriptions')->where('tenant_uuid', $target)->forceDelete();
            $connection->table('subscription_overrides')->where('tenant_uuid', $target)->forceDelete();
            $connection->table('subscription_events')->where('tenant_uuid', $target)->forceDelete();
            $connection->table('subscription_provider_event_receipts')
                ->where('tenant_uuid', $target)->forceDelete();
            $connection->table('subscription_plans')
                ->where('audience', 'user')->where('owner_tenant_uuid', $target)->forceDelete();
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function sqliteConnection(): Connection
    {
        return new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
    }

    private function contextWithContainer(ContainerInterface $container): ApplicationContext
    {
        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);

        return $context;
    }

    /** @param array<string,mixed> $bindings */
    private function fakeContainer(Connection $connection, array $bindings): ContainerInterface
    {
        return new class ($connection, $bindings) implements ContainerInterface {
            /** @param array<string,mixed> $bindings */
            public function __construct(private Connection $connection, private array $bindings)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }
                if (array_key_exists($id, $this->bindings)) {
                    return $this->bindings[$id];
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class || array_key_exists($id, $this->bindings);
            }
        };
    }

    private function assertAllThreeMethodsThrowTheFailClosedMessage(
        SubscriptionsPurgeHandler $handler,
        ApplicationContext $context,
    ): void {
        $expected = 'subscriptions data exists but the subscriptions engine is disabled or not ready — '
            . 'enable and migrate the extension before purging this tenant';

        foreach (['prepare', 'purge', 'verify'] as $method) {
            try {
                if ($method === 'prepare') {
                    $handler->prepare($context, 'tenantFailClosed1');
                } else {
                    $handler->{$method}($context, 'tenantFailClosed1', []);
                }
                self::fail("{$method}() must throw when subscriptions data exists but no purger resolves");
            } catch (\RuntimeException $e) {
                self::assertSame($expected, $e->getMessage());
            }
        }
    }

    /**
     * A REAL second boot with glueful/subscriptions' own provider filtered out of
     * config/extensions.php's `enabled` list — mirrors
     * EngineGatewayTest::bootWithEngineProviderDisabled() exactly (that helper is private to
     * its own test class, so it is duplicated here rather than shared).
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
}
