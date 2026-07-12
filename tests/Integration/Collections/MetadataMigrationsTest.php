<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class MetadataMigrationsTest extends CollectionsTestCase
{
    public function testMetadataTablesExist(): void
    {
        $schema = $this->container()->get(SchemaBuilderInterface::class);
        self::assertTrue($schema->hasTable('collection_definitions'));
        self::assertTrue($schema->hasTable('collection_schema_changes'));
    }
}
