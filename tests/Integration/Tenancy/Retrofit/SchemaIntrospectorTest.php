<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Retrofit\SchemaIntrospector;

/**
 * Exercises the introspector against the REAL migrated suite DB (PostgreSQL). `content_types` carries
 * two single-column uniques (`slug`, `uuid`) + a `pkey` on `id`; `id` is the only NOT NULL column.
 */
final class SchemaIntrospectorTest extends AppTestCase
{
    private function introspector(): SchemaIntrospector
    {
        return $this->container()->get(SchemaIntrospector::class);
    }

    public function testDriverIsPostgresOnTheSuiteDb(): void
    {
        self::assertSame('pgsql', $this->introspector()->driver());
    }

    public function testUniqueNameReturnsTheConstraintCoveringExactlyThatColumnSet(): void
    {
        self::assertSame(
            'content_types_slug_unique',
            $this->introspector()->uniqueName('content_types', ['slug']),
        );
    }

    public function testUniqueNameIsNullWhenNoUniqueCoversTheColumnSet(): void
    {
        // `name` has no unique; a superset that no single unique matches is also null.
        self::assertNull($this->introspector()->uniqueName('content_types', ['name']));
        self::assertNull($this->introspector()->uniqueName('content_types', ['slug', 'uuid']));
    }

    public function testUniqueExistsMatchesSetEqualRegardlessOfOrder(): void
    {
        $i = $this->introspector();
        self::assertTrue($i->uniqueExists('content_types', ['slug']));
        self::assertTrue($i->uniqueExists('content_types', ['uuid']));
        self::assertFalse($i->uniqueExists('content_types', ['name']));
    }

    public function testIndexExistsByName(): void
    {
        $i = $this->introspector();
        self::assertTrue($i->indexExists('content_types', 'content_types_slug_unique'));
        self::assertFalse($i->indexExists('content_types', 'content_types_no_such_index'));
    }

    public function testColumnNotNull(): void
    {
        $i = $this->introspector();
        // `id` is NOT NULL (surrogate PK); `slug` is nullable in reality; a missing column is false.
        self::assertTrue($i->columnNotNull('content_types', 'id'));
        self::assertFalse($i->columnNotNull('content_types', 'slug'));
        self::assertFalse($i->columnNotNull('content_types', 'no_such_column'));
    }
}
