<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Tests\Support\AppTestCase;

/**
 * Verifies that the new multi-value schema attributes (`multiple`, `max_items`,
 * `reference_slug_field`) round-trip correctly through persistence (create + reload)
 * and that a single-valued reference field is unaffected.
 */
final class MultiValueSchemaPersistenceTest extends AppTestCase
{
    private function repo(): ContentTypeRepository
    {
        return new ContentTypeRepository($this->connection());
    }

    public function testMultipleReferenceFieldRoundTrips(): void
    {
        $schema = [
            [
                'name' => 'title',
                'type' => 'string',
                'required' => true,
            ],
            [
                'name' => 'related_posts',
                'type' => 'reference',
                'filterable' => true,
                'multiple' => true,
                'max_items' => 5,
                'reference_slug_field' => 'slug',
            ],
        ];

        $uuid = $this->repo()->create([
            'slug' => 'article',
            'name' => 'Article',
            'schema' => $schema,
            'created_by' => 'user00000001',
        ]);

        $row = $this->repo()->findByUuid($uuid);
        self::assertNotNull($row);

        // Reload through ContentTypeSchema to exercise fromArray() + toArray() normalization.
        $reloaded = ContentTypeSchema::fromArray($row['schema']);
        $fields = [];
        foreach ($reloaded->fields() as $f) {
            $fields[$f->name] = $f;
        }

        // The multi-valued reference field must carry all three attributes.
        self::assertArrayHasKey('related_posts', $fields);
        $ref = $fields['related_posts'];
        self::assertTrue($ref->multiple, 'multiple should be true');
        self::assertSame(5, $ref->maxItems, 'max_items should be 5');
        self::assertSame('slug', $ref->referenceSlugField, 'reference_slug_field should be slug');

        // The plain string field must be unaffected.
        self::assertArrayHasKey('title', $fields);
        $title = $fields['title'];
        self::assertFalse($title->multiple, 'title.multiple should be false');
        self::assertNull($title->maxItems, 'title.max_items should be null');
        self::assertNull($title->referenceSlugField, 'title.reference_slug_field should be null');
    }

    public function testSingleValuedReferenceFieldIsUnaffected(): void
    {
        $uuid = $this->repo()->create([
            'slug' => 'product',
            'name' => 'Product',
            'schema' => [
                [
                    'name' => 'category',
                    'type' => 'reference',
                    'reference_type' => 'category',
                ],
            ],
        ]);

        $row = $this->repo()->findByUuid($uuid);
        self::assertNotNull($row);

        $reloaded = ContentTypeSchema::fromArray($row['schema']);
        $category = $reloaded->field('category');
        self::assertNotNull($category);

        // Single-valued reference: multiple is false, maxItems is null.
        self::assertFalse($category->multiple);
        self::assertNull($category->maxItems);
        // referenceSlugField defaults to 'slug' for reference fields.
        self::assertSame('slug', $category->referenceSlugField);
    }

    public function testSchemaUpdatePreservesMultipleAttributes(): void
    {
        $uuid = $this->repo()->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [
                ['name' => 'title', 'type' => 'string'],
            ],
        ]);

        // Add a multi-valued asset field via updateSchema.
        $this->repo()->updateSchema($uuid, [
            ['name' => 'title', 'type' => 'string'],
            [
                'name' => 'gallery',
                'type' => 'asset',
                'multiple' => true,
                'max_items' => 10,
            ],
        ]);

        $row = $this->repo()->findByUuid($uuid);
        self::assertNotNull($row);

        $reloaded = ContentTypeSchema::fromArray($row['schema']);
        $gallery = $reloaded->field('gallery');
        self::assertNotNull($gallery);
        self::assertTrue($gallery->multiple);
        self::assertSame(10, $gallery->maxItems);
        // referenceSlugField is null for asset fields.
        self::assertNull($gallery->referenceSlugField);
    }
}
