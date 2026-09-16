<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Blocks;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterFieldsAppender;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Migration 029 appends the feature block's marker, number, marker colours, variant and orientation
 * to an install that
 * seeded the row before they existed. The append itself is StarterFieldsAppender: additive,
 * label-preserving, idempotent, reading the fields from StarterBlockTypes.
 */
final class FeatureCardFieldsMigrationTest extends AppTestCase
{
    public function testAppendsTheCardFieldsToAnOlderFeatureRowKeepingItsLabelAndOnlyOnce(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'feature',
            'label' => 'Our feature',
            'schema' => [
                ['name' => 'icon', 'type' => 'string'],
                ['name' => 'title', 'type' => 'string', 'required' => true],
            ],
        ]);

        $this->migration()->up($this->connection()->getSchemaBuilder());

        $row = $repo->findBySlug('feature');
        self::assertSame('Our feature', $row['label']);
        $names = array_map(fn(array $f): string => $f['name'], $row['schema']);
        self::assertSame(
            ['icon', 'title', 'marker', 'number', 'marker_background', 'marker_color', 'variant', 'orientation'],
            $names,
        );
        $byName = array_column($row['schema'], null, 'name');
        self::assertSame(['plain', 'outline', 'soft', 'subtle'], $byName['variant']['enum']);
        self::assertSame(['icon', 'number', 'none'], $byName['marker']['enum']);

        $this->migration()->up($this->connection()->getSchemaBuilder());
        self::assertCount(8, $repo->findBySlug('feature')['schema']);
    }

    public function testTheAppenderDoesNothingWithoutTheRowOrWhenTheFieldsExist(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $appender = new StarterFieldsAppender($this->connection());
        self::assertSame(0, $appender->append('feature', ['number']));
        $repo->create(['slug' => 'feature', 'label' => 'F', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'number', 'type' => 'string'],
        ]]);
        self::assertSame(0, $appender->append('feature', ['number']));
        self::assertSame(2, $appender->append('feature', ['variant', 'orientation']));
    }

    private function migration(): \Glueful\Database\Migrations\MigrationInterface
    {
        $path = dirname(__DIR__, 3) . '/core/database/migrations/029_CardFieldsOnFeatureBlockType.php';
        self::assertFileExists($path);
        if (!class_exists(\CardFieldsOnFeatureBlockType::class, false)) {
            require $path;
        }
        return new \CardFieldsOnFeatureBlockType();
    }
}
