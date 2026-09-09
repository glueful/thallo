<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blocks;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypeSeeder;
use App\Content\Starter\Kinds\BlockTypeKind;
use App\Tests\Support\AppTestCase;

/**
 * Seeds every starter block type (the fixed library plus pack contributions) that is not in the
 * table yet, never touching existing rows — so `thallo:provision` can run it on every upgrade
 * and an install that got only migration 021's subset is healed on the next run.
 */
final class StarterBlockTypeSeederTest extends AppTestCase
{
    public function testSeedsOnlyTheMissingStarterBlockTypes(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'section',
            'label' => 'Custom section',
            'schema' => [['name' => 'x', 'type' => 'string']],
        ]);
        $repo->create([
            'slug' => 'rich_text',
            'label' => 'Mine',
            'schema' => [['name' => 'body', 'type' => 'text']],
        ]);
        $total = count($this->container()->get(BlockTypeKind::class)->definitions());

        $report = $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();

        self::assertCount($total - 2, $report['created']);
        self::assertSame(['section', 'rich_text'], $report['skipped']);
        self::assertContains('hero', $report['created']);
        self::assertSame(
            'Custom section',
            $repo->findBySlug('section')['label'] ?? null,
            'existing rows are never touched',
        );
        self::assertCount($total, $this->connection()->table('block_types')->select(['slug'])->get());
    }

    public function testASecondRunCreatesNothing(): void
    {
        $seeder = $this->container()->get(StarterBlockTypeSeeder::class);
        $seeder->seedMissing();

        self::assertSame([], $seeder->seedMissing()['created']);
    }
}
