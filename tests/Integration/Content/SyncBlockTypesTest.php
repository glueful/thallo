<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Contracts\Style\StyleTargets;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Content\Blocks\StarterBlockTypeSync;
use Thallo\Core\Content\Console\SeedBlockTypesCommand;
use Thallo\Core\Content\Console\SyncBlockTypesCommand;
use Thallo\Core\Tests\Support\AppTestCase;
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
        $reduced = array_values(array_filter($style['schema'], fn ($f) => $f['name'] !== 'neutral'));
        $repo->applyMigratedSchema((string) $style['uuid'], $reduced);
        self::assertNotContains('neutral', array_column($repo->findBySlug('style')['schema'], 'name'));

        $tester = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('synced style', $tester->getDisplay());
        self::assertContains('neutral', array_column($repo->findBySlug('style')['schema'], 'name'));
    }

    /**
     * Presentation-metadata attach (slider-config follow-up): a same-name field
     * the starter now labels (enum_labels) gets the labels ADDED when the row has
     * none — additive-only, so an operator's own labels are never overwritten.
     */
    public function testSyncAttachesMissingEnumLabelsToExistingFields(): void
    {
        $this->seed();
        $repo = new BlockTypeRepository($this->connection());
        $carousel = $repo->findBySlug('carousel');
        // Simulate the pre-labels install: same fields, transition WITHOUT labels.
        $stripped = array_values(array_map(static function (array $f): array {
            unset($f['enum_labels']);
            return $f;
        }, $carousel['schema']));
        $repo->applyMigratedSchema((string) $carousel['uuid'], $stripped);

        $tester = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester->execute([]);
        self::assertStringContainsString('synced carousel', $tester->getDisplay());

        $transition = null;
        foreach ($repo->findBySlug('carousel')['schema'] as $f) {
            if (($f['name'] ?? '') === 'transition') {
                $transition = $f;
            }
        }
        self::assertSame('Zoom (Ken Burns)', $transition['enum_labels']['zoom'] ?? null);

        // Idempotent once labelled.
        $tester2 = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester2->execute([]);
        self::assertStringContainsString('Synced 0', $tester2->getDisplay());

        // An operator's customized labels survive a later sync untouched.
        $custom = array_values(array_map(static function (array $f): array {
            if (($f['name'] ?? '') === 'transition') {
                $f['enum_labels'] = ['zoom' => 'My zoom'];
            }
            return $f;
        }, $repo->findBySlug('carousel')['schema']));
        $repo->applyMigratedSchema((string) $carousel['uuid'], $custom);
        (new CommandTester($this->container()->get(SyncBlockTypesCommand::class)))->execute([]);
        foreach ($repo->findBySlug('carousel')['schema'] as $f) {
            if (($f['name'] ?? '') === 'transition') {
                self::assertSame(['zoom' => 'My zoom'], $f['enum_labels']);
            }
        }
    }

    /**
     * The same attach for a field's `format`, the hint that picks its editor: a links block's
     * items, JSON underneath, are edited as a list of links once the row carries the format.
     */
    public function testSyncAttachesAMissingFormatToAnExistingField(): void
    {
        $this->seed();
        $repo = new BlockTypeRepository($this->connection());
        $links = $repo->findBySlug('links');
        $stripped = array_values(array_map(static function (array $f): array {
            unset($f['format']);
            return $f;
        }, $links['schema']));
        $repo->applyMigratedSchema((string) $links['uuid'], $stripped);

        $tester = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester->execute([]);
        self::assertStringContainsString('synced links', $tester->getDisplay());

        $items = array_values(array_filter(
            $repo->findBySlug('links')['schema'],
            static fn (array $f): bool => ($f['name'] ?? '') === 'items',
        ))[0];
        self::assertSame('json', $items['type']);
        self::assertSame('link-list', $items['format'] ?? null);

        $again = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $again->execute([]);
        self::assertStringContainsString('Synced 0', $again->getDisplay());
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
        $reduced = array_values(array_filter($style['schema'], fn ($f) => $f['name'] !== 'neutral'));
        $reduced[] = ['name' => 'op_custom', 'type' => 'string'];
        $repo->applyMigratedSchema((string) $style['uuid'], $reduced);

        (new CommandTester($this->container()->get(SyncBlockTypesCommand::class)))->execute([]);

        $names = array_column($repo->findBySlug('style')['schema'], 'name');
        self::assertContains('op_custom', $names);                       // operator field preserved
        self::assertContains('neutral', $names);                         // starter field restored
        // Existing order kept; the restored starter field is appended AFTER op_custom.
        self::assertLessThan(
            array_search('neutral', $names, true),
            array_search('op_custom', $names, true),
        );
    }

    public function testSyncBackfillsCarouselStyleAndHeroHeadingLevelOnPreChangeInstalls(): void
    {
        $this->seed();
        $repo = new BlockTypeRepository($this->connection());

        // Simulate an install seeded from the PRE-change definitions (modern-blocks
        // spec §2): existing rows created before `style`/`heading_level` were added
        // to the carousel/hero starters, via the guard-exempt migrated-schema path.
        $carousel = $repo->findBySlug('carousel');
        $preCarouselSchema = array_values(array_filter(
            $carousel['schema'],
            fn (array $f): bool => $f['name'] !== 'style',
        ));
        $repo->applyMigratedSchema((string) $carousel['uuid'], $preCarouselSchema);
        self::assertNotContains('style', array_column($repo->findBySlug('carousel')['schema'], 'name'));

        $hero = $repo->findBySlug('hero');
        $preHeroSchema = array_values(array_filter(
            $hero['schema'],
            fn (array $f): bool => $f['name'] !== 'heading_level',
        ));
        $repo->applyMigratedSchema((string) $hero['uuid'], $preHeroSchema);
        self::assertNotContains('heading_level', array_column($repo->findBySlug('hero')['schema'], 'name'));

        $tester = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('synced carousel', $tester->getDisplay());
        self::assertStringContainsString('synced hero', $tester->getDisplay());
        self::assertContains('style', array_column($repo->findBySlug('carousel')['schema'], 'name'));
        self::assertContains('heading_level', array_column($repo->findBySlug('hero')['schema'], 'name'));

        // Idempotent (brief requirement): a second run makes no further changes.
        $tester2 = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester2->execute([]);
        self::assertSame(0, $tester2->getStatusCode());
        self::assertStringContainsString('Synced 0', $tester2->getDisplay());
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $this->seed();
        $repo = new BlockTypeRepository($this->connection());
        $style = $repo->findBySlug('style');
        $reduced = array_values(array_filter($style['schema'], fn ($f) => $f['name'] !== 'neutral'));
        $repo->applyMigratedSchema((string) $style['uuid'], $reduced);

        $tester = new CommandTester($this->container()->get(SyncBlockTypesCommand::class));
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('synced style', $tester->getDisplay());   // same line, no write
        self::assertStringContainsString('No changes written', $tester->getDisplay());
        // DB schema is untouched — the field is still absent.
        self::assertNotContains('neutral', array_column($repo->findBySlug('style')['schema'], 'name'));
    }

    /**
     * Visual builder spec §1.7: the starter is the authority for a starter's style declaration.
     * A row seeded before the declarations existed (beta.28) carries none, and a row synced
     * before a block's conversion carries a narrower one; both take the starter's on sync, so an
     * upgrade renders converted settings without a further command.
     */
    public function testSyncRefreshesAStarterStyleDeclarationThatDiffersFromTheDefinition(): void
    {
        $this->seed();
        $repo = new BlockTypeRepository($this->connection());
        $container = $repo->findBySlug('container');
        $repo->updateStyle((string) $container['uuid'], null, null, null, null);
        $style = $repo->findBySlug('style');
        $repo->updateStyle((string) $style['uuid'], ['spacing'], StyleTargets::root('box', ['spacing']), [], null);

        $result = $this->container()->get(StarterBlockTypeSync::class)->sync();
        self::assertContains('container', array_column($result['synced'], 'slug'));
        self::assertContains('style', array_column($result['synced'], 'slug'));

        $starters = array_column(StarterBlockTypes::definitions(), null, 'slug');
        foreach (['container', 'style'] as $slug) {
            $row = $repo->findBySlug($slug);
            // JSONB stores object keys in its own order: compare by content.
            self::assertSame($starters[$slug]['style_capabilities'], $row['style_capabilities'], $slug);
            self::assertEqualsCanonicalizing($starters[$slug]['style_targets'], $row['style_targets'], $slug);
            self::assertEqualsCanonicalizing($starters[$slug]['flags'], $row['flags'], $slug);
        }

        // Idempotent once the declarations match.
        self::assertSame([], $this->container()->get(StarterBlockTypeSync::class)->sync()['synced']);
    }
}
