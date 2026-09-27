<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\Starter\ProductFieldBlocksContributor;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\ContributedBlockTypeReconciler;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\LayoutSessionData;
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
        // Every product page keeps its Product buy box: the required block cannot be hidden at any size.
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
            self::assertSame('Product buy box', $blockTypes->findBySlug('product_buy')['label']);
        } finally {
            $before === null
                ? $flags->forget(ContributedBlockTypeReconciler::FLAG)
                : $flags->put(ContributedBlockTypeReconciler::FLAG, (string) $before);
        }
    }

    /**
     * An existing site upgraded without provisioning: the product layout's row says why it cannot be
     * opened and how to fix it, the editor's session is refused with the same reason, and once the
     * seeding runs the row opens.
     */
    public function testTheProductLayoutWaitsForItsBlocksOnAnUpgradedSite(): void
    {
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $this->connection()->table('block_types')->whereIn('slug', ProductFieldBlocksContributor::SLUGS)->delete();
        $row = function (): array {
            $body = json_decode((string) $this->container()->get(LayoutAdminController::class)
                ->index(Request::create('/v1/admin/layouts'))->getContent(), true);
            foreach ($body['data']['layouts'] as $row) {
                if ($row['surface'] === 'product') {
                    return $row;
                }
            }
            self::fail('the product layout is listed');
        };
        $session = fn () => $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => 'product', 'target' => '@site'],
            ),
        );

        $waiting = $row();
        self::assertFalse($waiting['enabled']);
        self::assertStringContainsString('php glueful thallo:provision', (string) $waiting['reason']);
        self::assertStringContainsString(
            'php glueful thallo:tenant:sync --all --kind=block_type',
            (string) $waiting['reason'],
        );
        $refused = $session();
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(
            $waiting['reason'],
            json_decode((string) $refused->getContent(), true)['error']['details']['target'] ?? null,
        );

        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $ready = $row();
        self::assertTrue($ready['enabled']);
        self::assertNull($ready['reason']);
        self::assertSame(200, $session()->getStatusCode());
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
