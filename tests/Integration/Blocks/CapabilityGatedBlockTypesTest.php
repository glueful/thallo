<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blocks;

use App\Capabilities\DefaultCapabilityRegistry;
use App\Content\Blocks\BlockTypeRepository;
use App\Content\Starter\DefaultStarterBlockTypeRegistry;
use App\Content\Starter\Kinds\BlockTypeKind;
use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;

/**
 * A pack declares its starter block types unconditionally and tags them with the capability
 * they belong to. The kind applies the switch: a gated definition is seeded only while its
 * capability is on, and is reported as hidden (never deleted) while it is off.
 */
final class CapabilityGatedBlockTypesTest extends AppTestCase
{
    public function testAGatedDefinitionIsExcludedAndHiddenWhileItsCapabilityIsOff(): void
    {
        $kind = $this->kind(enabled: false);

        $slugs = array_map(static fn ($d) => $d->definitionKey, $kind->definitions());

        self::assertNotContains('pack-widget', $slugs);
        self::assertContains('pack-free', $slugs, 'an untagged contribution is never gated');
        self::assertContains('hero', $slugs, 'the fixed library is never gated');
        self::assertSame(['pack-widget'], $kind->hiddenSlugs());
    }

    public function testAGatedDefinitionIsIncludedAndVisibleWhileItsCapabilityIsOn(): void
    {
        $kind = $this->kind(enabled: true);

        $slugs = array_map(static fn ($d) => $d->definitionKey, $kind->definitions());

        self::assertContains('pack-widget', $slugs);
        self::assertSame([], $kind->hiddenSlugs());
    }

    public function testContributionsForACapabilityAreListedRegardlessOfItsState(): void
    {
        $kind = $this->kind(enabled: false);

        $sourceIds = array_map(static fn ($d) => $d->sourceId, $kind->contributionsFor('test.pack'));

        self::assertSame(['test:pack-widget'], $sourceIds);
        self::assertSame(['test.pack'], $kind->gatedCapabilities());
    }

    public function testAnUnknownCapabilityIdCountsAsOff(): void
    {
        $kind = new BlockTypeKind(
            $this->container()->get(BlockTypeRepository::class),
            $this->container()->get(Connection::class),
            $this->contributors(),
            new DefaultCapabilityRegistry([]),
        );

        self::assertSame(['pack-widget'], $kind->hiddenSlugs());
    }

    private function kind(bool $enabled): BlockTypeKind
    {
        $registry = new DefaultCapabilityRegistry(['test.pack' => $enabled]);
        $registry->register(new Capability('test.pack'));

        return new BlockTypeKind(
            $this->container()->get(BlockTypeRepository::class),
            $this->container()->get(Connection::class),
            $this->contributors(),
            $registry,
        );
    }

    private function contributors(): DefaultStarterBlockTypeRegistry
    {
        $contributors = new DefaultStarterBlockTypeRegistry();
        $contributors->register(new class implements StarterBlockTypeContributor {
            /** @return list<StarterBlockTypeDefinition> */
            public function blockTypeDefinitions(): array
            {
                return [
                    new StarterBlockTypeDefinition(
                        sourceId: 'test:pack-widget',
                        slug: 'pack-widget',
                        label: 'Pack widget',
                        icon: 'i-lucide-box',
                        category: 'Pack',
                        description: null,
                        schema: [['name' => 'title', 'type' => 'string']],
                        requiresCapability: 'test.pack',
                    ),
                    new StarterBlockTypeDefinition(
                        sourceId: 'test:pack-free',
                        slug: 'pack-free',
                        label: 'Pack free',
                        icon: 'i-lucide-box',
                        category: 'Pack',
                        description: null,
                        schema: [['name' => 'title', 'type' => 'string']],
                    ),
                ];
            }
        });

        return $contributors;
    }
}
