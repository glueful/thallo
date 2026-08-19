<?php

declare(strict_types=1);

namespace App\Tests\Unit\Schema;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Schema\MigrationManagerFactory;
use Glueful\Installer\DatabaseConfig;
use PHPUnit\Framework\TestCase;

/**
 * The container factory — not provider boot — registers the pack descriptors (schema policy
 * spec B1): over THIS repo's real installed metadata, every schema-owning pack's source is
 * present with discoverable pending files on a fresh database.
 */
final class PackDescriptorRegistrationTest extends TestCase
{
    public function testFactoryRegistersEveryPackDescriptorFromTheRealManifest(): void
    {
        $dbPath = sys_get_temp_dir() . '/pack-reg-' . uniqid('', true) . '.sqlite';
        $config = new DatabaseConfig('sqlite', database: $dbPath);
        $connection = new Connection($config->toConnectionConfig());
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));

        try {
            $manager = MigrationManagerFactory::create($context, $connection);

            $sources = [
                'glueful/thallo-analytics', 'glueful/thallo-collections', 'glueful/thallo-commerce',
                'glueful/thallo-navigation', 'glueful/thallo-render', 'glueful/thallo-seo',
                'glueful/thallo-tenancy', 'glueful/thallo-workflow',
            ];
            foreach ($sources as $source) {
                self::assertTrue($manager->hasSource($source), "{$source} must register via the factory");
            }
            $pending = $manager->pendingForSources($sources);
            self::assertNotEmpty($pending, 'pack migrations must be discoverable on a fresh database');
            $pendingSources = array_unique(array_column($pending, 'source'));
            sort($pendingSources);
            sort($sources);
            self::assertSame($sources, $pendingSources, 'every pack contributes pending files');
        } finally {
            @unlink($dbPath);
        }
    }
}
