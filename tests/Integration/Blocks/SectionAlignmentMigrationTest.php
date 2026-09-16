<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Blocks;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Migration 028 adds the section block's per-part alignment fields to an install that seeded
 * the row before they existed — additively, keeping the admin's own label, and only once.
 */
final class SectionAlignmentMigrationTest extends AppTestCase
{
    private const FIELDS = ['headline_align', 'title_align', 'description_align'];

    public function testAppendsTheAlignmentFieldsToAnOlderSectionRowAndKeepsItsLabel(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'section',
            'label' => 'Our section',
            'category' => 'Layout',
            'schema' => [
                ['name' => 'headline', 'type' => 'string'],
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'content', 'type' => 'blocks'],
            ],
        ]);

        $this->migration()->up($this->connection()->getSchemaBuilder());

        $row = $repo->findBySlug('section');
        self::assertNotNull($row);
        self::assertSame('Our section', $row['label'], 'the admin\'s edits survive');
        $names = array_map(fn(array $f): string => $f['name'], $row['schema']);
        self::assertSame(['headline', 'title', 'content', ...self::FIELDS], $names);
        $byName = array_column($row['schema'], null, 'name');
        self::assertSame(['start', 'center', 'end'], $byName['title_align']['enum']);
        self::assertSame('Alignment', $byName['title_align']['group']);

        // A second run changes nothing.
        $this->migration()->up($this->connection()->getSchemaBuilder());
        self::assertCount(6, $repo->findBySlug('section')['schema']);
    }

    public function testLeavesASectionThatAlreadyDeclaresTheFieldsAndAnInstallWithoutOneAlone(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $this->migration()->up($this->connection()->getSchemaBuilder());
        self::assertNull($repo->findBySlug('section'), 'nothing is created: the seeder owns that');

        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($definition['slug'] === 'section') {
                $repo->create($definition);
            }
        }
        $before = $repo->findBySlug('section')['schema'];
        $this->migration()->up($this->connection()->getSchemaBuilder());
        self::assertSame($before, $repo->findBySlug('section')['schema']);
    }

    private function migration(): \Glueful\Database\Migrations\MigrationInterface
    {
        $path = dirname(__DIR__, 3) . '/core/database/migrations/028_AlignmentFieldsOnSectionBlockType.php';
        self::assertFileExists($path);
        if (!class_exists(\AlignmentFieldsOnSectionBlockType::class, false)) {
            require $path;
        }
        return new \AlignmentFieldsOnSectionBlockType();
    }
}
