<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Blocks;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Theme\ThemeColors;

/** Migration 033 appends the hero's gradient colour and strength to an older row, once. */
final class HeroGradientMigrationTest extends AppTestCase
{
    public function testAppendsTheGradientFieldsOnce(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'hero',
            'label' => 'Our hero',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'background', 'type' => 'enum', 'enum' => ['gradient', 'none', 'muted', 'inverted']],
            ],
        ]);

        $this->migration()->up($this->connection()->getSchemaBuilder());

        $row = $repo->findBySlug('hero');
        self::assertSame('Our hero', $row['label']);
        $byName = array_column($row['schema'], null, 'name');
        self::assertSame(
            ['title', 'background', 'gradient_color', 'gradient_strength'],
            array_keys($byName),
        );
        // The site accent's families, and the accent itself first: the look a hero always had.
        self::assertSame(array_merge(['accent'], ThemeColors::ACCENTS), $byName['gradient_color']['enum']);
        self::assertSame(['subtle', 'medium', 'strong'], $byName['gradient_strength']['enum']);

        $this->migration()->up($this->connection()->getSchemaBuilder());
        self::assertCount(4, $repo->findBySlug('hero')['schema']);
    }

    private function migration(): \Glueful\Database\Migrations\MigrationInterface
    {
        $path = dirname(__DIR__, 3) . '/core/database/migrations/033_GradientFieldsOnHeroBlockType.php';
        self::assertFileExists($path);
        if (!class_exists(\GradientFieldsOnHeroBlockType::class, false)) {
            require $path;
        }
        return new \GradientFieldsOnHeroBlockType();
    }
}
