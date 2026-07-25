<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Starter;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Schema\SchemaParseException;
use App\Content\Starter\DefaultStarterContributorRegistry;
use App\Content\Starter\Kinds\ContentTypeKind;
use App\Content\Starter\StarterDefinition;
use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Thallo\Contracts\Starter\StarterContentTypeContributor;
use Thallo\Contracts\Starter\StarterContentTypeDefinition;
use Thallo\Contracts\Starter\StarterContributorRegistry;

/**
 * Task 5 — starter content-type contributor seam. Covers the pure conversion/validation logic in
 * {@see ContentTypeKind::definitions()} and the DI wiring (contracts interface -> app-owned
 * registry) without touching the database — every ContentTypeKind here is constructed directly
 * with a throwaway {@see DefaultStarterContributorRegistry} so nothing here registers into the
 * shared process boot's container (which other suites rely on staying empty for byte-parity).
 *
 * Tenant-provisioning (TenantSeeder) and `thallo:tenant:sync` coverage for this seam lives in
 * {@see \App\Tests\Integration\Content\Starter\StarterContributorTenancyTest} — that harness
 * requires the opt-in Postgres retrofit machinery (THALLO_TENANCY_DEV_LINK=1), same as its
 * siblings StarterSeedIntegrationTest/StarterSyncIntegrationTest.
 */
final class StarterContributorTest extends AppTestCase
{
    public function testZeroContributorsIsByteIdenticalToTheFixedDefinitions(): void
    {
        $withoutRegistry = $this->kind(null)->definitions();
        $withEmptyRegistry = $this->kind(new DefaultStarterContributorRegistry())->definitions();

        self::assertEquals($withoutRegistry, $withEmptyRegistry);
        self::assertSame(
            ['pages', 'category', 'post'],
            array_map(static fn(StarterDefinition $d): string => $d->definitionKey, $withoutRegistry),
        );
        self::assertSame(
            ['content_type:pages', 'content_type:category', 'content_type:post'],
            array_map(static fn(StarterDefinition $d): string => $d->sourceId, $withoutRegistry),
        );
    }

    public function testContributedDefinitionAppearsAfterTheFixedSet(): void
    {
        $registry = new DefaultStarterContributorRegistry();
        $registry->register($this->stubContributor([$this->definition('event')]));

        $definitions = $this->kind($registry)->definitions();

        self::assertCount(4, $definitions);
        $contributed = $definitions[3];
        self::assertSame('content_type:event', $contributed->sourceId);
        self::assertSame('event', $contributed->definitionKey);
        self::assertSame('Events', $contributed->payload['name']);
        self::assertSame('A pack-contributed content type.', $contributed->payload['description']);
        self::assertNull($contributed->payload['cache_ttl']);
        self::assertTrue($contributed->payload['public_delivery']);
        self::assertFalse($contributed->payload['mount_at_root']);
        self::assertSame(
            [['name' => 'title', 'type' => 'string', 'required' => true]],
            $contributed->payload['schema'],
        );
    }

    public function testRegistryAppendsContributorsInRegistrationOrder(): void
    {
        $registry = new DefaultStarterContributorRegistry();
        $a = $this->stubContributor([$this->definition('a')]);
        $b = $this->stubContributor([$this->definition('b')]);

        $registry->register($a);
        $registry->register($b);

        self::assertSame([$a, $b], $registry->all());
    }

    public function testDuplicateSourceIdAcrossContributorsThrowsBeforeAnyDefinitionIsReturned(): void
    {
        $registry = new DefaultStarterContributorRegistry();
        $registry->register($this->stubContributor([$this->definition('event')]));
        $registry->register($this->stubContributor([
            $this->definition('event-two', sourceId: 'content_type:event'),
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicate.*sourceId.*content_type:event/i');

        $this->kind($registry)->definitions();
    }

    public function testDuplicateSlugAgainstAFixedDefinitionThrows(): void
    {
        $registry = new DefaultStarterContributorRegistry();
        // Distinct sourceId, but the slug collides with the FIXED 'pages' content type.
        $registry->register($this->stubContributor([
            $this->definition('pages', sourceId: 'content_type:pages_v2'),
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/duplicate.*slug.*'pages'/i");

        $this->kind($registry)->definitions();
    }

    public function testMalformedContributedSchemaThrowsWithTheContributorsSourceId(): void
    {
        $registry = new DefaultStarterContributorRegistry();
        $registry->register($this->stubContributor([
            // Field names must match [a-z][a-z0-9_]* — 'Title' is invalid.
            $this->definition('event', schema: [['name' => 'Title', 'type' => 'string']]),
        ]));

        $this->expectException(SchemaParseException::class);
        $this->expectExceptionMessageMatches('/content_type:event/');

        $this->kind($registry)->definitions();
    }

    public function testBlankSlugOnAContributedDefinitionThrows(): void
    {
        $registry = new DefaultStarterContributorRegistry();
        $registry->register($this->stubContributor([$this->definition('   ')]));

        $this->expectException(\InvalidArgumentException::class);

        $this->kind($registry)->definitions();
    }

    public function testContractResolvesToTheAppOwnedRegistry(): void
    {
        $registry = $this->container()->get(StarterContributorRegistry::class);

        self::assertInstanceOf(DefaultStarterContributorRegistry::class, $registry);
    }

    /**
     * Proves the container wires {@see ContentTypeKind} to the SAME shared registry instance the
     * contracts interface resolves to — i.e. a pack registering through
     * `StarterContributorRegistry` (the contracts interface it is allowed to depend on) is visible
     * to the app-owned ContentTypeKind without either side referencing the other's concrete class.
     * Read-only (reflection), so it never mutates the shared process-boot registry other suites
     * depend on staying empty.
     */
    public function testContentTypeKindIsWiredToTheSameSharedRegistryInstanceAsTheContract(): void
    {
        $registry = $this->container()->get(StarterContributorRegistry::class);
        $kind = $this->container()->get(ContentTypeKind::class);

        $property = new \ReflectionProperty(ContentTypeKind::class, 'contributors');
        $property->setAccessible(true);

        self::assertSame($registry, $property->getValue($kind));
    }

    private function kind(?StarterContributorRegistry $registry): ContentTypeKind
    {
        return new ContentTypeKind(
            $this->container()->get(ContentTypeRepository::class),
            $this->container()->get(Connection::class),
            $registry,
        );
    }

    /** @param list<StarterContentTypeDefinition> $definitions */
    private function stubContributor(array $definitions): StarterContentTypeContributor
    {
        return new class ($definitions) implements StarterContentTypeContributor {
            /** @param list<StarterContentTypeDefinition> $definitions */
            public function __construct(private readonly array $definitions)
            {
            }

            /** @return list<StarterContentTypeDefinition> */
            public function contentTypeDefinitions(): array
            {
                return $this->definitions;
            }
        };
    }

    /** @param list<array<string,mixed>> $schema */
    private function definition(
        string $slug,
        ?string $sourceId = null,
        array $schema = [['name' => 'title', 'type' => 'string', 'required' => true]],
    ): StarterContentTypeDefinition {
        return new StarterContentTypeDefinition(
            sourceId: $sourceId ?? 'content_type:' . $slug,
            slug: $slug,
            name: 'Events',
            description: 'A pack-contributed content type.',
            cacheTtl: null,
            publicDelivery: true,
            mountAtRoot: false,
            schema: $schema,
        );
    }
}
