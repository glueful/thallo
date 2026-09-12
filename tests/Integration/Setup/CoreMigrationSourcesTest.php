<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Setup;

use Glueful\Database\Migrations\MigrationManager;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The product's migrations moved under core/ but their ledger SOURCE names did not: a beta.20
 * database must show nothing pending after the move (the pre-beta.3 ledger break must not repeat).
 */
final class CoreMigrationSourcesTest extends AppTestCase
{
    public function testCoreMigrationsAreRegisteredUnderTheHistoricalSources(): void
    {
        $manager = $this->container()->get(MigrationManager::class);
        self::assertTrue($manager->hasSource('app:dependent'));

        $root = dirname(__DIR__, 3);
        self::assertCount(0, glob("$root/database/migrations/*.php"), 'database/migrations is the operator\'s');
        self::assertGreaterThanOrEqual(21, count(glob("$root/core/database/migrations/*.php")));
        self::assertGreaterThanOrEqual(11, count(glob("$root/core/database/dependent-migrations/*.php")));
    }

    public function testNothingIsPendingOnTheMigratedTestDatabase(): void
    {
        $manager = $this->container()->get(MigrationManager::class);
        self::assertSame([], $manager->getPendingMigrations(), 'a moved file must not look like a new migration');
    }
}
