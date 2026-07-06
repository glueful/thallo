<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Delivery;

use App\Content\Delivery\InvalidFilterException;
use App\Content\Delivery\ReferenceFilterResolver;
use App\Content\Schema\FieldDefinition;
use App\Tests\Support\AppTestCase;

final class ReferenceFilterResolverTest extends AppTestCase
{
    private function seedType(): void
    {
        $this->connection()->table('content_types')->insert([
            'uuid' => 'typecatref01', 'slug' => 'category', 'name' => 'Category',
            'description' => null, 'cache_ttl' => null, 'public_delivery' => false, 'status' => 'active',
            'schema' => json_encode([['name' => 'slug', 'type' => 'string']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_by' => null,
            'created_at' => '2026-06-27 00:00:00', 'updated_at' => '2026-06-27 00:00:00',
        ]);
    }

    private function seedTerm(string $entryUuid, string $versionUuid, string $slug): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => 'typecatref01', 'status' => 'active',
            'created_at' => '2026-06-27 00:00:00', 'updated_at' => '2026-06-27 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => 'en',
            'fields' => json_encode(['slug' => $slug], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'version' => 1, 'created_at' => '2026-06-27 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => 'en',
            'version_uuid' => $versionUuid, 'published_at' => '2026-06-27 01:00:00',
        ]);
    }

    private function field(): FieldDefinition
    {
        return FieldDefinition::fromArray([
            'name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
            'multiple' => true, 'filterable' => true, 'reference_slug_field' => 'slug',
        ]);
    }

    private function resolver(): ReferenceFilterResolver
    {
        return $this->container()->get(ReferenceFilterResolver::class);
    }

    public function testResolvesByUuidAndSlugWithUuidPrecedenceAndDedupe(): void
    {
        $this->seedType();
        $this->seedTerm('catnews00001', 'vcatnews0001', 'news');
        $this->seedTerm('catsport0001', 'vcatsport001', 'sports');

        $out = $this->resolver()->resolve($this->field(), 'en', ['catnews00001', 'sports', 'news']);
        sort($out);
        self::assertSame(['catnews00001', 'catsport0001'], $out);
    }

    public function testUnknownSlugContributesNoMatch(): void
    {
        $this->seedType();
        $this->seedTerm('catnews00001', 'vcatnews0001', 'news');
        self::assertSame([], $this->resolver()->resolve($this->field(), 'en', ['nope']));
    }

    public function testAmbiguousSlugThrows(): void
    {
        $this->seedType();
        $this->seedTerm('catnews00001', 'vcatnews0001', 'news');
        $this->seedTerm('catnews00002', 'vcatnews0002', 'news'); // duplicate slug, both published
        $this->expectException(InvalidFilterException::class);
        $this->resolver()->resolve($this->field(), 'en', ['news']);
    }
}
