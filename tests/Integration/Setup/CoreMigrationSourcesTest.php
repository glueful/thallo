<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Glueful\Database\Migrations\MigrationManager;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Thallo's migrations are declared by the thallo-core manifest under its own sources, with the
 * names every earlier database recorded them under (`app`, `app:dependent`) as previous
 * sources — so a beta.20 database shows nothing pending after the split (the pre-beta.3 ledger
 * break must not repeat). The root database/migrations stays the operator's `app` lane.
 */
final class CoreMigrationSourcesTest extends AppTestCase
{
    public function testCoreLanesAreDescriptorSourcesAndTheRootStaysTheOperators(): void
    {
        $manager = $this->container()->get(MigrationManager::class);
        self::assertTrue($manager->hasSource('glueful/thallo-core'));
        self::assertTrue($manager->hasSource('glueful/thallo-core:dependent'));
        self::assertTrue($manager->hasSource('app'), 'the operator\'s root database/migrations');

        $root = dirname(__DIR__, 3);
        self::assertCount(0, glob("$root/database/migrations/*.php"), 'database/migrations is the operator\'s');
        self::assertGreaterThanOrEqual(21, count(glob("$root/core/database/migrations/*.php")));
        self::assertGreaterThanOrEqual(11, count(glob("$root/core/database/dependent-migrations/*.php")));
    }

    public function testADatabaseRecordedUnderThePreviousSourcesShowsNothingPending(): void
    {
        // The test database was migrated by this tree, so its rows carry the new names; plant the
        // pre-split shape on two rows (one per lane) and prove the previous-source declaration.
        $pdo = $this->connection()->getPDO();
        $set = static fn (string $source, string $file): string =>
            "UPDATE migrations SET source = '{$source}' WHERE migration = '{$file}'";
        $pdo->exec($set('app', '017_CreateBlockTypesTable.php'));
        $pdo->exec($set('app:dependent', '012_CreateStarterProvenanceTable.php'));
        try {
            $manager = $this->container()->get(MigrationManager::class);
            self::assertSame([], $manager->getPendingMigrations(), 'previously recorded rows count as applied');
        } finally {
            $pdo->exec($set('glueful/thallo-core', '017_CreateBlockTypesTable.php'));
            $pdo->exec($set('glueful/thallo-core:dependent', '012_CreateStarterProvenanceTable.php'));
        }
    }
}
