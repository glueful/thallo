<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Schema\FieldTypeRegistry;

/**
 * Verifies that CollectionsServiceProvider seeds all collections.* field types
 * into the shared FieldTypeRegistry and that none of them collide with content.* keys.
 */
final class CollectionFieldTypesRegistrationTest extends AppTestCase
{
    /** @var list<string> */
    private const EXPECTED_TYPES = [
        'collections.string',
        'collections.text',
        'collections.integer',
        'collections.decimal',
        'collections.boolean',
        'collections.date',
        'collections.datetime',
        'collections.email',
        'collections.url',
        'collections.enum',
        'collections.json',
        'collections.relation',
        'collections.asset',
    ];

    private FieldTypeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = $this->container()->get(FieldTypeRegistry::class);
    }

    public function testAllCollectionsTypesAreRegistered(): void
    {
        foreach (self::EXPECTED_TYPES as $key) {
            self::assertTrue(
                $this->registry->has($key),
                "Expected '{$key}' to be registered in the FieldTypeRegistry.",
            );
        }
    }

    public function testCollectionsDecimalKeyResolves(): void
    {
        $type = $this->registry->get('collections.decimal');
        self::assertSame('collections.decimal', $type->key());
        self::assertSame('scalar', $type->valueShape());
    }

    public function testNoCollectionsKeyCollidesWithContentKey(): void
    {
        $allKeys = array_keys($this->registry->all());
        $contentKeys     = array_filter($allKeys, static fn (string $k): bool => str_starts_with($k, 'content.'));
        $collectionsKeys = array_filter($allKeys, static fn (string $k): bool => str_starts_with($k, 'collections.'));

        // The two domains must be disjoint at the FULL (namespaced) key level. The prefix is exactly
        // what lets `content.text` and `collections.string` coexist as distinct field types, so bare-name
        // overlap across domains is expected and fine — what must NOT happen is the same full key
        // registered under both domains.
        $collisions = array_intersect($contentKeys, $collectionsKeys);
        self::assertEmpty(
            $collisions,
            'A key is registered under both content.* and collections.*: ' . implode(', ', $collisions),
        );
    }

    public function testScalarTypesAreFilterableSortableAndIndexable(): void
    {
        // Filterable/sortable scalars. The `text` type is deliberately excluded — see the dedicated
        // test below: a TEXT column can't be plainly indexed or sorted.
        $scalarTypes = ['collections.string', 'collections.integer', 'collections.decimal',
                        'collections.boolean', 'collections.date', 'collections.datetime',
                        'collections.email', 'collections.url', 'collections.enum'];

        foreach ($scalarTypes as $key) {
            $caps = $this->registry->get($key)->capabilities();
            self::assertTrue($caps['filterable'] ?? false, "'{$key}' should be filterable.");
            self::assertTrue($caps['sortable'] ?? false, "'{$key}' should be sortable.");
            self::assertTrue($caps['indexable'] ?? false, "'{$key}' should be indexable.");
        }
    }

    public function testTextIsIndexableButNotFilterableOrSortable(): void
    {
        // Spec carve-out: the `text` type maps to a TEXT column, which cannot be plainly indexed or
        // sorted — so it is a scalar that is intentionally NOT filterable/sortable.
        $caps = $this->registry->get('collections.text')->capabilities();
        self::assertFalse($caps['filterable'] ?? true, 'text must not be filterable.');
        self::assertFalse($caps['sortable'] ?? true, 'text must not be sortable.');
        self::assertTrue($caps['indexable'] ?? false, 'text should still be indexable.');
    }

    public function testJsonRelationAssetAreNotFilterableOrSortable(): void
    {
        foreach (['collections.json', 'collections.relation', 'collections.asset'] as $key) {
            $caps = $this->registry->get($key)->capabilities();
            self::assertFalse(
                $caps['filterable'] ?? true,
                "'{$key}' should not be filterable.",
            );
            self::assertFalse(
                $caps['sortable'] ?? true,
                "'{$key}' should not be sortable.",
            );
        }
    }

    public function testRelationAndAssetAreFlaggedMulti(): void
    {
        foreach (['collections.relation', 'collections.asset'] as $key) {
            $caps = $this->registry->get($key)->capabilities();
            self::assertTrue(
                $caps['multi'] ?? false,
                "'{$key}' should have multi=true.",
            );
        }
    }
}
