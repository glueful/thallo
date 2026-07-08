<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Console\SeedBlockTypesCommand;
use App\Content\Console\SyncBlockTypesCommand;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Shadow-system plan Task 4: additive sync of evolved starter schemas. */
final class SyncBlockTypesTest extends AppTestCase
{
    private function seed(): void
    {
        (new CommandTester($this->container()->get(SeedBlockTypesCommand::class)))->execute([]);
    }

    public function testSyncAdditivelyRestoresMissingStarterFields(): void
    {
        $this->seed();
        $repo = new BlockTypeRepository($this->connection());
        $style = $repo->findBySlug('style');
        // Simulate a pre-evolution row missing the newest field, via the guard-exempt
        // migrated-schema path (updateSchema itself refuses field removal).
        $reduced = array_values(array_filter($style['schema'], fn ($f) => $f['name'] !== 'shadow'));
        $repo->applyMigratedSchema((string) $style['uuid'], $reduced);
        self::assertNotContains('shadow', array_column($repo->findBySlug('style')['schema'], 'name'));

        $tester = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('synced style', $tester->getDisplay());
        self::assertContains('shadow', array_column($repo->findBySlug('style')['schema'], 'name'));
    }

    public function testSyncIsIdempotentWhenUpToDate(): void
    {
        $this->seed();
        $tester = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Synced 0', $tester->getDisplay());
    }

    public function testSyncPreservesFieldOrderAndOperatorAddedFields(): void
    {
        $this->seed();
        $repo = new BlockTypeRepository($this->connection());
        $style = $repo->findBySlug('style');
        // A pre-evolution row: missing the newest starter field, plus an operator's
        // own custom field appended at the end.
        $reduced = array_values(array_filter($style['schema'], fn ($f) => $f['name'] !== 'shadow'));
        $reduced[] = ['name' => 'op_custom', 'type' => 'string'];
        $repo->applyMigratedSchema((string) $style['uuid'], $reduced);

        (new CommandTester($this->container()->get(SyncBlockTypesCommand::class)))->execute([]);

        $names = array_column($repo->findBySlug('style')['schema'], 'name');
        self::assertContains('op_custom', $names);                       // operator field preserved
        self::assertContains('shadow', $names);                          // starter field restored
        // Existing order kept; the restored starter field is appended AFTER op_custom.
        self::assertLessThan(
            array_search('shadow', $names, true),
            array_search('op_custom', $names, true),
        );
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $this->seed();
        $repo = new BlockTypeRepository($this->connection());
        $style = $repo->findBySlug('style');
        $reduced = array_values(array_filter($style['schema'], fn ($f) => $f['name'] !== 'shadow'));
        $repo->applyMigratedSchema((string) $style['uuid'], $reduced);

        $tester = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('synced style', $tester->getDisplay());   // same line, no write
        self::assertStringContainsString('No changes written', $tester->getDisplay());
        // DB schema is untouched — the field is still absent.
        self::assertNotContains('shadow', array_column($repo->findBySlug('style')['schema'], 'name'));
    }
}
