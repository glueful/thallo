<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Migrations;

use Thallo\Core\Tests\Support\AppTestCase;

/**
 * A workspace retrofitted for multi-store tenancy before the rebuild kept `regions.schema_stamp`
 * lost the column while migration 023 stays recorded as run: migration 046 puts it back, and is a
 * no-op where it is present.
 */
final class RegionsSchemaStampRepairTest extends AppTestCase
{
    private function migration(): object
    {
        require_once dirname(__DIR__, 3) . '/core/database/migrations/046_RepairSchemaStampOnRegions.php';
        return new \RepairSchemaStampOnRegions();
    }

    public function testItRestoresAMissingColumnAndLeavesAPresentOneAlone(): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        $this->connection()->getPDO()->exec('ALTER TABLE regions DROP COLUMN schema_stamp');
        try {
            self::assertFalse($schema->hasColumn('regions', 'schema_stamp'));
            $this->migration()->up($schema);
            self::assertTrue($schema->hasColumn('regions', 'schema_stamp'));
            $this->migration()->up($schema); // already there: nothing to do
            self::assertTrue($schema->hasColumn('regions', 'schema_stamp'));
        } finally {
            if (!$schema->hasColumn('regions', 'schema_stamp')) {
                $this->connection()->getPDO()->exec('ALTER TABLE regions ADD COLUMN schema_stamp jsonb');
            }
        }
    }
}
