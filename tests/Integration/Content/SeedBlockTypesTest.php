<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Console\SeedBlockTypesCommand;
use App\Tests\Support\LemmaTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * NOTE (harness caveat): CommandTester runs the command object directly — it does
 * not prove console-manifest registration; that is covered by the provider diff
 * (consoleCommandServices + commands list) and the commands:cache note.
 */
final class SeedBlockTypesTest extends LemmaTestCase
{
    private function runSeed(): CommandTester
    {
        $command = $this->container()->get(SeedBlockTypesCommand::class);
        $tester = new CommandTester($command);
        $tester->execute([]);
        return $tester;
    }

    public function testFirstRunCreatesAllDefinitionsThroughTheRepository(): void
    {
        $tester = $this->runSeed();
        $repo = new BlockTypeRepository($this->connection());
        $expected = count(StarterBlockTypes::definitions()); // not a literal (spec §8)
        self::assertCount($expected, $repo->all());
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString("Created {$expected}, skipped 0.", $tester->getDisplay());
        self::assertStringContainsString('created hero', $tester->getDisplay());

        // Every definition passed create() → §2 rules validated the starters themselves.
        $section = $repo->findBySlug('section');
        self::assertSame('Layout', $section['category']);
        self::assertContains('blocks', array_column($section['schema'], 'type'));

        // Block-library expansion (spec §3): 30 types; html seeds DEACTIVATED;
        // hero/cta carry the Nuxt UI shapes; container declares value constraints.
        self::assertSame(30, $expected);
        self::assertSame(0, (int) $repo->findBySlug('html')['active']);
        self::assertSame('Items', $repo->findBySlug('testimonial')['category']);
        $heroFields = array_column($repo->findBySlug('hero')['schema'], 'name');
        self::assertSame(
            ['headline', 'title', 'description', 'links', 'image', 'orientation', 'reverse'],
            $heroFields,
        );
        $ctaFields = array_column($repo->findBySlug('cta')['schema'], 'name');
        self::assertSame(
            ['title', 'description', 'variant', 'orientation', 'reverse', 'links'],
            $ctaFields,
        );
        $container = array_column($repo->findBySlug('container')['schema'], null, 'name');
        self::assertSame('#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?', $container['background_color']['pattern']);
        self::assertSame(0, $container['overlay_opacity']['min']);
        self::assertSame(100, $container['overlay_opacity']['max']);
    }

    public function testRerunSkipsEverythingAndPreservesAdminEdits(): void
    {
        $this->runSeed();
        $repo = new BlockTypeRepository($this->connection());
        // Admin edits hero (ADDITIVELY — updateSchema is additive-only since the
        // block-migrations spec §1; destructive edits go through migrations)…
        $hero = $repo->findBySlug('hero');
        $repo->updateSchema(
            (string) $hero['uuid'],
            [...$hero['schema'], ['name' => 'badge_text', 'type' => 'string']],
            'My Hero',
            null,
            null,
            'Custom',
        );
        // …and deactivates quote (also an admin decision the seeder must respect).
        $repo->setActive((string) $repo->findBySlug('quote')['uuid'], false);

        $tester = $this->runSeed();
        $expected = count(StarterBlockTypes::definitions());
        self::assertStringContainsString("Created 0, skipped {$expected}.", $tester->getDisplay());
        self::assertStringContainsString('skipped hero (exists)', $tester->getDisplay());

        $after = $repo->findBySlug('hero');
        self::assertSame('My Hero', $after['label']);                      // byte-identical edit survives
        self::assertContains('badge_text', array_column($after['schema'], 'name'));
        self::assertSame(0, (int) $repo->findBySlug('quote')['active']);   // deactivation survives
    }
}
