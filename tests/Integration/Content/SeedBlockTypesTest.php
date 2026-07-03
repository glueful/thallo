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
            [...$hero['schema'], ['name' => 'headline', 'type' => 'string']],
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
        self::assertContains('headline', array_column($after['schema'], 'name'));
        self::assertSame(0, (int) $repo->findBySlug('quote')['active']);   // deactivation survives
    }
}
