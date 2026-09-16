<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Blocks;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Migration 030 appends the tabs block's styling fields to an older row and, when the row still
 * carries the starter's previous style declaration, adopts the starter's new one (the panels
 * target); an admin's own declaration is left alone.
 */
final class TabsStyleFieldsMigrationTest extends AppTestCase
{
    private const FIELDS = [
        'variant', 'align', 'list_background', 'tab_color', 'active_background', 'active_color', 'panel_padding',
    ];

    public function testAppendsTheFieldsAndAdoptsTheStarterStyleDeclarationOnAnUntouchedRow(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'tabs',
            'label' => 'Our tabs',
            'schema' => [['name' => 'items', 'type' => 'blocks', 'block_types' => ['tab']]],
            'style_capabilities' => ['spacing', 'visibility'],
            'style_targets' => ['targets' => ['root' => ['kind' => 'box']], 'map' => [
                'advanced.anchor' => 'root', 'advanced.css_classes' => 'root', 'advanced.attributes' => 'root',
                'spacing' => 'root', 'visibility' => 'root',
            ]],
        ]);

        $this->migration()->up($this->connection()->getSchemaBuilder());

        $row = $repo->findBySlug('tabs');
        self::assertSame('Our tabs', $row['label']);
        self::assertSame(['items', ...self::FIELDS], array_map(fn(array $f): string => $f['name'], $row['schema']));
        $starter = $this->starter();
        self::assertSame($starter['style_capabilities'], $row['style_capabilities']);
        self::assertEquals($starter['style_targets'], $row['style_targets']); // the row normalises key order
        self::assertSame('panels', $row['style_targets']['map']['colors']);

        $this->migration()->up($this->connection()->getSchemaBuilder());
        self::assertCount(1 + count(self::FIELDS), $repo->findBySlug('tabs')['schema']);
    }

    public function testLeavesAnAdminsOwnStyleDeclarationAlone(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'tabs',
            'label' => 'Tabs',
            'schema' => [['name' => 'items', 'type' => 'blocks', 'block_types' => ['tab']]],
            'style_capabilities' => ['spacing', 'visibility', 'shadow'],
            'style_targets' => StyleTargets::root('box', ['spacing', 'visibility', 'shadow']),
        ]);

        $this->migration()->up($this->connection()->getSchemaBuilder());

        $row = $repo->findBySlug('tabs');
        self::assertSame(['spacing', 'visibility', 'shadow'], $row['style_capabilities']);
        self::assertSame('root', $row['style_targets']['map']['shadow']);
        self::assertArrayNotHasKey('panels', $row['style_targets']['targets']);
        self::assertCount(1 + count(self::FIELDS), $row['schema'], 'the fields still land');
    }

    /** @return array<string,mixed> */
    private function starter(): array
    {
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($definition['slug'] === 'tabs') {
                return $definition;
            }
        }
        self::fail('no tabs starter');
    }

    private function migration(): \Glueful\Database\Migrations\MigrationInterface
    {
        $path = dirname(__DIR__, 3) . '/core/database/migrations/030_StyleFieldsOnTabsBlockType.php';
        self::assertFileExists($path);
        if (!class_exists(\StyleFieldsOnTabsBlockType::class, false)) {
            require $path;
        }
        return new \StyleFieldsOnTabsBlockType();
    }
}
