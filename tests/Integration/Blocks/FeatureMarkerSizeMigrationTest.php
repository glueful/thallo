<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Blocks;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Tests\Support\AppTestCase;

/** Migration 031 appends the feature marker's size to an older row, keeping its label, once. */
final class FeatureMarkerSizeMigrationTest extends AppTestCase
{
    public function testAppendsMarkerSizeOnce(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'feature',
            'label' => 'Our feature',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'marker', 'type' => 'enum', 'enum' => ['icon', 'number', 'none']],
            ],
        ]);

        $this->migration()->up($this->connection()->getSchemaBuilder());

        $row = $repo->findBySlug('feature');
        self::assertSame('Our feature', $row['label']);
        $names = array_map(fn(array $f): string => $f['name'], $row['schema']);
        self::assertSame(['title', 'marker', 'marker_size'], $names);
        self::assertSame(['sm', 'md', 'lg', 'xl'], array_column($row['schema'], null, 'name')['marker_size']['enum']);

        $this->migration()->up($this->connection()->getSchemaBuilder());
        self::assertCount(3, $repo->findBySlug('feature')['schema']);
    }

    private function migration(): \Glueful\Database\Migrations\MigrationInterface
    {
        $path = dirname(__DIR__, 3) . '/core/database/migrations/031_MarkerSizeOnFeatureBlockType.php';
        self::assertFileExists($path);
        if (!class_exists(\MarkerSizeOnFeatureBlockType::class, false)) {
            require $path;
        }
        return new \MarkerSizeOnFeatureBlockType();
    }
}
