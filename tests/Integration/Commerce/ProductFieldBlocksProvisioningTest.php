<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Starter\ProductFieldBlocksContributor;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\ContributedBlockTypeReconciler;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The product page's field blocks reach a site (type layouts plan C1, P2): declared with commerce's
 * capability, in the Fields category and flagged layout-only; seeded on an existing site by the
 * seeding `thallo:provision` runs — even where commerce was switched on before this release, so the
 * reconciler has nothing left to do — and hidden, never deleted, while commerce is off.
 */
final class ProductFieldBlocksProvisioningTest extends AppTestCase
{
    public function testTheNineAreLayoutOnlyFieldsBlocksGatedByCommerce(): void
    {
        $definitions = (new ProductFieldBlocksContributor())->blockTypeDefinitions();
        self::assertSame(ProductFieldBlocksContributor::SLUGS, array_map(static fn ($d) => $d->slug, $definitions));
        foreach ($definitions as $definition) {
            self::assertSame('thallo.commerce', $definition->requiresCapability, $definition->slug);
            self::assertSame('Fields', $definition->category, $definition->slug);
            self::assertSame(['layout_only' => true], $definition->flags, $definition->slug);
            self::assertSame('thallo-commerce:' . $definition->slug, $definition->sourceId);
        }
        // A block whose root is a flex row aligns nothing by text-align: it offers no text alignment.
        $caps = array_column(array_map(
            static fn ($d): array => ['slug' => $d->slug, 'caps' => $d->styleCapabilities],
            $definitions,
        ), 'caps', 'slug');
        foreach (['product_breadcrumb', 'product_rating', 'product_price'] as $flexRoot) {
            self::assertNotContains('alignment.text', $caps[$flexRoot], $flexRoot);
        }
        self::assertContains('alignment.text', $caps['product_name']);
        // Every product page keeps its Add to cart: the required block cannot be hidden at any size.
        self::assertNotContains('visibility', $caps['product_buy']);
        self::assertContains('visibility', $caps['product_story']);
    }

    public function testProvisioningSeedsThemOnAnExistingSite(): void
    {
        $blockTypes = $this->container()->get(BlockTypeRepository::class);
        $flags = $this->container()->get(SystemFlags::class);
        $before = $flags->get(ContributedBlockTypeReconciler::FLAG);
        // A site that switched commerce on before this release: the reconciler recorded it as seeded.
        $flags->put(ContributedBlockTypeReconciler::FLAG, (string) json_encode(['thallo.commerce']));
        try {
            foreach (ProductFieldBlocksContributor::SLUGS as $slug) {
                self::assertNull($blockTypes->findBySlug($slug), "{$slug} is not there before");
            }
            $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
            foreach (ProductFieldBlocksContributor::SLUGS as $slug) {
                $row = $blockTypes->findBySlug($slug);
                self::assertNotNull($row, "{$slug} is seeded");
                self::assertSame('Fields', $row['category'], $slug);
                self::assertTrue((bool) ($row['flags']['layout_only'] ?? false), $slug);
            }
            self::assertSame('Add to cart', $blockTypes->findBySlug('product_buy')['label']);
        } finally {
            $before === null
                ? $flags->forget(ContributedBlockTypeReconciler::FLAG)
                : $flags->put(ContributedBlockTypeReconciler::FLAG, (string) $before);
        }
    }

    public function testTheyAreHiddenWhileCommerceIsOff(): void
    {
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);
        try {
            $hidden = $disabledApp->getContainer()->get(BlockTypeKind::class)->hiddenSlugs();
            foreach (ProductFieldBlocksContributor::SLUGS as $slug) {
                self::assertContains($slug, $hidden);
            }
            self::assertNotNull(
                $this->container()->get(BlockTypeRepository::class)->findBySlug('product_buy'),
                'hidden, never deleted',
            );
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }
}
