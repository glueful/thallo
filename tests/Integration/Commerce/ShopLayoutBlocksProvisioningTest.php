<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Starter\ShopLayoutBlocksContributor;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\ContributedBlockTypeReconciler;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Blocks\StarterBlockTypeSync;
use Thallo\Core\Content\Http\Controllers\BlockTypeController;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The shop pages' blocks reach a site (type layouts plan C2, S1): declared with commerce's capability,
 * in the Fields category and flagged layout-only; seeded on an existing site by the seeding
 * `thallo:provision` runs, which also gives an existing Product name its new `link` setting and the
 * Entry list its declared defaults. The Product list's cards declare the shop grid's own
 * arrangement, and the Settings API hands that declaration to the admin.
 */
final class ShopLayoutBlocksProvisioningTest extends AppTestCase
{
    public function testTheFourAreLayoutOnlyFieldsBlocksGatedByCommerce(): void
    {
        $definitions = (new ShopLayoutBlocksContributor())->blockTypeDefinitions();
        self::assertSame(ShopLayoutBlocksContributor::SLUGS, array_map(static fn ($d) => $d->slug, $definitions));
        $caps = [];
        foreach ($definitions as $definition) {
            self::assertSame('thallo.commerce', $definition->requiresCapability, $definition->slug);
            self::assertSame('Fields', $definition->category, $definition->slug);
            self::assertSame(['layout_only' => true], $definition->flags, $definition->slug);
            self::assertSame('thallo-commerce:' . $definition->slug, $definition->sourceId);
            $caps[$definition->slug] = $definition->styleCapabilities;
        }
        // Every shop page keeps its Product list: the required block cannot be hidden at any size.
        self::assertNotContains('visibility', $caps['product_loop']);
        self::assertContains('visibility', $caps['product_tile']);
    }

    public function testTheProductListsCardsDeclareTheShopGrid(): void
    {
        $loop = array_values(array_filter(
            (new ShopLayoutBlocksContributor())->blockTypeDefinitions(),
            static fn ($d): bool => $d->slug === 'product_loop',
        ))[0];
        $targets = StyleTargets::fromDeclaration($loop->styleTargets);
        self::assertSame(ShopLayoutBlocksContributor::CARD_DEFAULTS, $targets->defaults('cards'));
        self::assertSame('grid', $targets->defaults('cards')['display']);
        self::assertNull($targets->defaults('root'));
        self::assertSame('cards', $targets->targetFor('layout.columns'));
    }

    public function testProvisioningSeedsThemAndSyncsTheEvolvedDefinitions(): void
    {
        $blockTypes = $this->container()->get(BlockTypeRepository::class);
        $flags = $this->container()->get(SystemFlags::class);
        $before = $flags->get(ContributedBlockTypeReconciler::FLAG);
        // A site that switched commerce on before this release: the reconciler recorded it as seeded.
        $flags->put(ContributedBlockTypeReconciler::FLAG, (string) json_encode(['thallo.commerce']));
        try {
            $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
            // Rows as this release found them: a Product name without `link`, an Entry list whose
            // cards declared no defaults, and none of the four shop blocks.
            $name = $blockTypes->findBySlug('product_name');
            $this->connection()->table('block_types')->where('uuid', '=', $name['uuid'])->update([
                'schema' => (string) json_encode(array_values(array_filter(
                    $name['schema'],
                    static fn (array $field): bool => $field['name'] !== 'link',
                ))),
            ]);
            $entryLoop = $blockTypes->findBySlug('entry_loop');
            $old = $entryLoop['style_targets'];
            unset($old['targets']['cards']['defaults']);
            $blockTypes->updateStyle(
                (string) $entryLoop['uuid'],
                $entryLoop['style_capabilities'],
                $old,
                $entryLoop['flags'],
                null,
            );
            foreach (ShopLayoutBlocksContributor::SLUGS as $slug) {
                $row = $blockTypes->findBySlug($slug);
                if ($row !== null) {
                    $this->connection()->table('block_types')->where('uuid', '=', $row['uuid'])->delete();
                }
            }

            // What thallo:provision runs.
            $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
            $this->container()->get(StarterBlockTypeSync::class)->sync();

            foreach (ShopLayoutBlocksContributor::SLUGS as $slug) {
                $row = $blockTypes->findBySlug($slug);
                self::assertNotNull($row, "{$slug} is seeded");
                self::assertSame('Fields', $row['category'], $slug);
                self::assertTrue((bool) ($row['flags']['layout_only'] ?? false), $slug);
            }
            self::assertContains('link', array_column($blockTypes->findBySlug('product_name')['schema'], 'name'));
            self::assertSame(
                ['display' => 'flex'],
                $blockTypes->findBySlug('entry_loop')['style_targets']['targets']['cards']['defaults'] ?? null,
            );
            // Stored as jsonb, whose key order is its own: compared as maps.
            self::assertEquals(
                ShopLayoutBlocksContributor::CARD_DEFAULTS,
                $blockTypes->findBySlug('product_loop')['style_targets']['targets']['cards']['defaults'] ?? null,
            );
        } finally {
            $before === null
                ? $flags->forget(ContributedBlockTypeReconciler::FLAG)
                : $flags->put(ContributedBlockTypeReconciler::FLAG, (string) $before);
        }
    }

    /** The admin reads a loop's defaults from the block type the Settings API lists. */
    public function testTheBlockTypesApiCarriesTheDefaults(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $before = $flags->get(ContributedBlockTypeReconciler::FLAG);
        $flags->put(ContributedBlockTypeReconciler::FLAG, (string) json_encode(['thallo.commerce']));
        try {
            $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
            $listed = json_decode((string) $this->container()->get(BlockTypeController::class)
                ->index(Request::create('/block-types'))->getContent(), true)['data']['block_types'];
            $bySlug = array_column($listed, null, 'slug');
            // Stored as jsonb, whose key order is its own: compared as maps.
            self::assertEquals(
                ShopLayoutBlocksContributor::CARD_DEFAULTS,
                $bySlug['product_loop']['style_targets']['targets']['cards']['defaults'] ?? null,
            );
            self::assertSame(
                ['display' => 'flex'],
                $bySlug['entry_loop']['style_targets']['targets']['cards']['defaults'] ?? null,
            );
        } finally {
            $before === null
                ? $flags->forget(ContributedBlockTypeReconciler::FLAG)
                : $flags->put(ContributedBlockTypeReconciler::FLAG, (string) $before);
        }
    }
}
