<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Blocks;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Tests\Support\AppTestCase;

/** Migration 032 appends links_align to an older call-to-action row, keeping its label, once. */
final class CtaLinksAlignMigrationTest extends AppTestCase
{
    public function testAppendsLinksAlignOnce(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'cta',
            'label' => 'Our CTA',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'links', 'type' => 'blocks', 'block_types' => ['button']],
            ],
        ]);

        $this->migration()->up($this->connection()->getSchemaBuilder());

        $row = $repo->findBySlug('cta');
        self::assertSame('Our CTA', $row['label']);
        $names = array_map(fn(array $f): string => $f['name'], $row['schema']);
        self::assertSame(['title', 'links', 'links_align'], $names);
        self::assertSame(['start', 'center', 'end'], array_column($row['schema'], null, 'name')['links_align']['enum']);

        $this->migration()->up($this->connection()->getSchemaBuilder());
        self::assertCount(3, $repo->findBySlug('cta')['schema']);
    }

    private function migration(): \Glueful\Database\Migrations\MigrationInterface
    {
        $path = dirname(__DIR__, 3) . '/core/database/migrations/032_LinksAlignOnCtaBlockType.php';
        self::assertFileExists($path);
        if (!class_exists(\LinksAlignOnCtaBlockType::class, false)) {
            require $path;
        }
        return new \LinksAlignOnCtaBlockType();
    }
}
