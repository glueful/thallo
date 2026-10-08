<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Blocks\StarterBlockTypeSync;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * An install from before the Product grid release upgrades by `thallo:provision` alone: the grid's
 * definition owns its fields, so the sync replaces the stored ones — the old Source choices and the
 * category_slug / tag_slug fields go — where any other block's sync only adds.
 */
final class ProductGridUpgradeTest extends AppTestCase
{
    /** The grid's fields as installs before this release stored them. */
    private const OLD_SCHEMA = [
        ['name' => 'source', 'type' => 'enum', 'enum' => ['category', 'tag', 'manual', 'newest']],
        ['name' => 'category_slug', 'type' => 'string'],
        ['name' => 'tag_slug', 'type' => 'string'],
        ['name' => 'products', 'type' => 'text'],
        ['name' => 'limit', 'type' => 'number', 'min' => 1, 'max' => 48],
        ['name' => 'columns', 'type' => 'enum', 'enum' => ['auto', '2', '3', '4', '5', '6']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
    }

    private function repo(): BlockTypeRepository
    {
        return new BlockTypeRepository($this->connection());
    }

    /** @return list<array<string,mixed>> */
    private function definitionSchema(): array
    {
        foreach ((new ShopBlockTypesContributor())->blockTypeDefinitions() as $definition) {
            if ($definition->slug === 'product-grid') {
                return $definition->schema;
            }
        }
        self::fail('no product grid definition');
    }

    public function testAnOldGridRowTakesTheDefinitionsFieldsWhole(): void
    {
        $row = $this->repo()->findBySlug('product-grid');
        self::assertNotNull($row, 'precondition: the grid is seeded');
        $this->repo()->applyMigratedSchema((string) $row['uuid'], self::OLD_SCHEMA);

        $report = $this->container()->get(StarterBlockTypeSync::class)->sync();
        self::assertContains('product-grid', array_column($report['synced'], 'slug'));

        $schema = $this->repo()->findBySlug('product-grid')['schema'];
        self::assertSame(array_column($this->definitionSchema(), 'name'), array_column($schema, 'name'));
        self::assertSame(['all', 'on_sale', 'manual'], $schema[0]['enum']);

        $again = $this->container()->get(StarterBlockTypeSync::class)->sync();
        self::assertNotContains('product-grid', array_column($again['synced'], 'slug'), 'then unchanged');
    }

    public function testABlockThatDoesNotOwnItsFieldsKeepsAnAddedOne(): void
    {
        $row = $this->repo()->findBySlug('mini-cart');
        self::assertNotNull($row);
        $this->repo()->applyMigratedSchema((string) $row['uuid'], [['name' => 'note', 'type' => 'string']]);
        $this->container()->get(StarterBlockTypeSync::class)->sync();
        self::assertSame(['note'], array_column($this->repo()->findBySlug('mini-cart')['schema'], 'name'));
        $this->repo()->applyMigratedSchema((string) $row['uuid'], []);
    }
}
