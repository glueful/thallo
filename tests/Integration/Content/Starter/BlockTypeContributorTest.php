<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Starter;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Schema\SchemaParseException;
use App\Content\Starter\DefaultStarterBlockTypeRegistry;
use App\Content\Starter\Kinds\BlockTypeKind;
use App\Tests\Support\RetrofittedTenantTestCase;
use Glueful\Database\Connection;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;
use Thallo\Contracts\Starter\StarterBlockTypeRegistry;
use Thallo\Tenancy\Console\TenantSyncCommand;
use Thallo\Tenancy\Contracts\TenantSeedRepair;

/**
 * Task 6 — starter block-type contributor seam, mirroring task 5's ContentTypeKind precedent
 * ({@see \App\Tests\Integration\Content\Starter\StarterContributorTest} /
 * {@see \App\Tests\Integration\Content\Starter\StarterContributorTenancyTest}) but consolidated
 * into a single file per the task-6 brief. Extends the opt-in Postgres retrofit harness
 * (THALLO_TENANCY_DEV_LINK=1) throughout — the pure conversion/validation cases below don't
 * themselves need tenancy machinery, but are grouped here with the provisioning/sync cases for
 * one coherent seam test. The whole class self-skips without THALLO_TENANCY_DEV_LINK=1.
 */
final class BlockTypeContributorTest extends RetrofittedTenantTestCase
{
    private const CONTRIBUTED_SLUG = 'event_card';
    private const CONTRIBUTED_SOURCE_ID = 'block_type:event_card';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$onApp === null) {
            return; // harness skipped (THALLO_TENANCY_DEV_LINK not set) — nothing to wire.
        }
        self::$onApp->getContainer()->get(StarterBlockTypeRegistry::class)->register(
            self::stubContributor([self::definitionFor(self::CONTRIBUTED_SLUG)]),
        );
    }

    // --- Pure conversion/validation + DI wiring --------------------------------------------

    public function testKindReturnsTheStableSyncName(): void
    {
        self::assertSame('block_type', $this->kind(null)->kind());
    }

    public function testZeroContributorsIsByteIdenticalToTheFixedDefinitions(): void
    {
        $withoutRegistry = $this->kind(null)->definitions();
        $withEmptyRegistry = $this->kind(new DefaultStarterBlockTypeRegistry())->definitions();

        self::assertEquals($withoutRegistry, $withEmptyRegistry);
        self::assertCount(count(StarterBlockTypes::definitions()), $withoutRegistry);
    }

    public function testContributedDefinitionAppearsAfterTheFixedSet(): void
    {
        $registry = new DefaultStarterBlockTypeRegistry();
        $registry->register($this->stubContributor([self::definitionFor(self::CONTRIBUTED_SLUG)]));

        $fixedCount = count(StarterBlockTypes::definitions());
        $definitions = $this->kind($registry)->definitions();

        self::assertCount($fixedCount + 1, $definitions);
        $contributed = $definitions[$fixedCount];
        self::assertSame(self::CONTRIBUTED_SOURCE_ID, $contributed->sourceId);
        self::assertSame(self::CONTRIBUTED_SLUG, $contributed->definitionKey);
        self::assertSame('Event card', $contributed->payload['label']);
        self::assertSame('i-lucide-calendar', $contributed->payload['icon']);
        self::assertSame('Items', $contributed->payload['category']);
        self::assertSame('A pack-contributed block type.', $contributed->payload['description']);
        self::assertTrue($contributed->payload['active']);
        self::assertSame(
            [['name' => 'title', 'type' => 'string', 'required' => true]],
            $contributed->payload['schema'],
        );
    }

    public function testRegistryAppendsContributorsInRegistrationOrder(): void
    {
        $registry = new DefaultStarterBlockTypeRegistry();
        $a = $this->stubContributor([self::definitionFor('a_block')]);
        $b = $this->stubContributor([self::definitionFor('b_block')]);

        $registry->register($a);
        $registry->register($b);

        self::assertSame([$a, $b], $registry->all());
    }

    public function testDuplicateSourceIdAcrossContributorsThrowsBeforeAnyDefinitionIsReturned(): void
    {
        $registry = new DefaultStarterBlockTypeRegistry();
        $registry->register($this->stubContributor([self::definitionFor(self::CONTRIBUTED_SLUG)]));
        $registry->register($this->stubContributor([
            self::definitionFor('event-card-two', sourceId: self::CONTRIBUTED_SOURCE_ID),
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/duplicate.*sourceId.*' . preg_quote(self::CONTRIBUTED_SOURCE_ID, '/') . '/i'
        );

        $this->kind($registry)->definitions();
    }

    public function testDuplicateSlugAgainstAFixedBlockThrows(): void
    {
        $registry = new DefaultStarterBlockTypeRegistry();
        // Distinct sourceId, but the slug collides with the FIXED 'button' block type.
        $registry->register($this->stubContributor([
            self::definitionFor('button', sourceId: 'block_type:button_v2'),
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/duplicate.*slug.*'button'/i");

        $this->kind($registry)->definitions();
    }

    public function testMalformedContributedSchemaThrowsWithTheContributorsSourceId(): void
    {
        $registry = new DefaultStarterBlockTypeRegistry();
        $registry->register($this->stubContributor([
            // Field names must match [a-z][a-z0-9_]* — 'Title' is invalid.
            self::definitionFor(self::CONTRIBUTED_SLUG, schema: [['name' => 'Title', 'type' => 'string']]),
        ]));

        $this->expectException(SchemaParseException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(self::CONTRIBUTED_SOURCE_ID, '/') . '/');

        $this->kind($registry)->definitions();
    }

    public function testMalformedContributedSchemaRejectsBlockOnlyProhibitions(): void
    {
        $registry = new DefaultStarterBlockTypeRegistry();
        $registry->register($this->stubContributor([
            // 'localized' is a content-type-only concern; blocks reject it outright.
            self::definitionFor(
                self::CONTRIBUTED_SLUG,
                schema: [['name' => 'title', 'type' => 'string', 'localized' => true]],
            ),
        ]));

        $this->expectException(SchemaParseException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(self::CONTRIBUTED_SOURCE_ID, '/') . '/');

        $this->kind($registry)->definitions();
    }

    public function testBlankSlugOnAContributedDefinitionThrows(): void
    {
        $registry = new DefaultStarterBlockTypeRegistry();
        $registry->register($this->stubContributor([self::definitionFor('   ')]));

        $this->expectException(\InvalidArgumentException::class);

        $this->kind($registry)->definitions();
    }

    public function testContractResolvesToTheAppOwnedRegistry(): void
    {
        $registry = $this->container()->get(StarterBlockTypeRegistry::class);

        self::assertInstanceOf(DefaultStarterBlockTypeRegistry::class, $registry);
    }

    /**
     * Proves the container wires {@see BlockTypeKind} to the SAME shared registry instance the
     * contracts interface resolves to — a pack registering through `StarterBlockTypeRegistry`
     * (the contracts interface it is allowed to depend on) is visible to the app-owned
     * BlockTypeKind without either side referencing the other's concrete class. Read-only
     * (reflection); the container-bound registry already carries the class-level stub contributor
     * registered in {@see setUpBeforeClass()}.
     */
    public function testBlockTypeKindIsWiredToTheSameSharedRegistryInstanceAsTheContract(): void
    {
        $registry = $this->container()->get(StarterBlockTypeRegistry::class);
        $kind = $this->container()->get(BlockTypeKind::class);

        $property = new \ReflectionProperty(BlockTypeKind::class, 'contributors');
        $property->setAccessible(true);

        self::assertSame($registry, $property->getValue($kind));
    }

    // --- Tenancy: fresh-tenant provisioning + thallo:tenant:sync ---------------------------

    public function testFreshTenantProvisioningCreatesTheContributedBlockType(): void
    {
        $this->container()->get(TenantSeedRepair::class)->repair(self::$tenantAUuid);

        $slugs = $this->runAsTenant(
            self::$tenantAUuid,
            fn() => array_column(
                $this->connection()->table('block_types')
                    ->where('slug', '=', self::CONTRIBUTED_SLUG)
                    ->get(),
                'slug',
            ),
        );

        self::assertSame([self::CONTRIBUTED_SLUG], $slugs);
    }

    public function testTenantSyncWithKindBlockTypeAdoptsTheContributedTypeIdempotently(): void
    {
        $kindName = $this->container()->get(BlockTypeKind::class)->kind();
        self::assertSame('block_type', $kindName);

        $first = $this->syncBlockTypeKind(self::$tenantBUuid, $kindName);
        self::assertSame(
            'added',
            $first[self::CONTRIBUTED_SOURCE_ID] ?? null,
            'first sync of a pre-existing (unseeded) tenant must add the contributed type',
        );

        $second = $this->syncBlockTypeKind(self::$tenantBUuid, $kindName);
        self::assertSame(
            'unchanged',
            $second[self::CONTRIBUTED_SOURCE_ID] ?? null,
            'a second sync run must be a no-op for the already-adopted contributed type',
        );
    }

    /** @return array<string,string> source_id => action */
    private function syncBlockTypeKind(string $tenantUuid, string $kindName): array
    {
        // TenantSyncCommand is auto-discovered (Thallo\Tenancy\TenancyServiceProvider::boot() ->
        // discoverCommands()), not bound as a resolvable container service — construct it directly
        // with the container + context, same as StarterContributorTenancyTest does for content types.
        $command = new TenantSyncCommand($this->container(), $this->appContext());
        $tester = new CommandTester($command);
        $exit = $tester->execute(['uuid' => $tenantUuid, '--kind' => $kindName]);
        self::assertSame(0, $exit, $tester->getDisplay());

        /** @var list<array{kind:string,source_id:string,action:string}> $report */
        $report = json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR);

        return array_column($report, 'action', 'source_id');
    }

    // --- helpers -----------------------------------------------------------------------------

    private function kind(?StarterBlockTypeRegistry $registry): BlockTypeKind
    {
        return new BlockTypeKind(
            $this->container()->get(BlockTypeRepository::class),
            $this->container()->get(Connection::class),
            $registry,
        );
    }

    /** @param list<StarterBlockTypeDefinition> $definitions */
    private static function stubContributor(array $definitions): StarterBlockTypeContributor
    {
        return new class ($definitions) implements StarterBlockTypeContributor {
            /** @param list<StarterBlockTypeDefinition> $definitions */
            public function __construct(private readonly array $definitions)
            {
            }

            /** @return list<StarterBlockTypeDefinition> */
            public function blockTypeDefinitions(): array
            {
                return $this->definitions;
            }
        };
    }

    /** @param list<array<string,mixed>> $schema */
    private static function definitionFor(
        string $slug,
        ?string $sourceId = null,
        array $schema = [['name' => 'title', 'type' => 'string', 'required' => true]],
    ): StarterBlockTypeDefinition {
        return new StarterBlockTypeDefinition(
            sourceId: $sourceId ?? 'block_type:' . $slug,
            slug: $slug,
            label: 'Event card',
            icon: 'i-lucide-calendar',
            category: 'Items',
            description: 'A pack-contributed block type.',
            schema: $schema,
        );
    }
}
