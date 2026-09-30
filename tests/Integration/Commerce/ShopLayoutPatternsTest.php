<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\CommerceIntegrationServiceProvider;
use Thallo\Commerce\Layouts\ProductSurface;
use Thallo\Commerce\Layouts\ShopCategorySurface;
use Thallo\Commerce\Layouts\ShopIndexSurface;
use Thallo\Commerce\Patterns\ShopLayoutPatternsContributor;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Contracts\Patterns\LayoutTarget;
use Thallo\Contracts\Patterns\LayoutTemplate;
use Thallo\Contracts\Patterns\PatternContributorRegistry;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Patterns\PatternLibrary;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The product page's and the shop's layout patterns (sections and templates design §6): every
 * template a layout its surface accepts, every section a part that fits, the Product hero without a
 * second buy box, the templates close to today's the surfaces' own starters; offered with Commerce
 * on and gone with it off; registered once.
 */
final class ShopLayoutPatternsTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    private function contributor(): ShopLayoutPatternsContributor
    {
        return new ShopLayoutPatternsContributor(
            $this->container()->get(ProductSurface::class),
            $this->container()->get(ShopIndexSurface::class),
            $this->container()->get(ShopCategorySurface::class),
        );
    }

    private function template(string $slug): LayoutTemplate
    {
        foreach ($this->contributor()->layoutTemplates() as $template) {
            if ($template->slug === $slug) {
                return $template;
            }
        }
        self::fail("no template {$slug}");
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private static function withIds(array $blocks, int &$n = 0): array
    {
        return PatternLibrary::withIds($blocks, $n);
    }

    public function testEveryShopLayoutTemplatePassesItsSurface(): void
    {
        $validator = $this->container()->get(LayoutValidator::class);
        foreach ($this->contributor()->layoutTemplates() as $template) {
            $tree = ($template->build)(new LayoutTarget($template->surface, '@site', []));
            self::assertNotNull($tree, $template->slug);
            try {
                $validator->validate($template->surface, '@site', self::withIds($tree), $template->settings, [], false);
            } catch (ValidationException $e) {
                self::fail("{$template->slug}: " . json_encode($e->errors()));
            }
        }
    }

    public function testEveryShopLayoutSectionFits(): void
    {
        $validator = $this->container()->get(LayoutValidator::class);
        foreach ($this->contributor()->layoutSections() as $section) {
            $block = ($section->build)(new LayoutTarget($section->surface, '@site', []));
            self::assertNotNull($block, $section->slug);
            self::assertSame([], $validator->fragment($section->surface, '@site', [$block])['errors'], $section->slug);
        }
    }

    public function testTheProductHeroHoldsNoSecondBuyBox(): void
    {
        foreach ($this->contributor()->layoutSections() as $section) {
            if ($section->slug === 'product-hero') {
                $tree = ($section->build)(new LayoutTarget('product', '@site', []));
                self::assertStringNotContainsString('product_buy', (string) json_encode($tree));
                self::assertStringContainsString('product_gallery', (string) json_encode($tree));
                return;
            }
        }
        self::fail('no Product hero');
    }

    public function testTheCloseToTodayTemplatesAreTheStarters(): void
    {
        $surfaces = $this->container()->get(LayoutSurfaceRegistry::class);
        $close = [
            'product-gallery-left' => 'product',
            'shop-index-adaptive' => 'shop_index',
            'shop-category-adaptive' => 'shop_category',
        ];
        foreach ($close as $slug => $surface) {
            self::assertSame(
                $surfaces->get($surface)?->starter('@site'),
                ($this->template($slug)->build)(new LayoutTarget($surface, '@site', [])),
                $slug,
            );
        }
    }

    public function testOfferedWithCommerceOnAndGoneWithItOff(): void
    {
        $slugs = array_column($this->container()->get(PatternLibrary::class)->forLayout('product', '@site'), 'slug');
        self::assertSame(
            [
                'product-hero', 'product-details-band', 'product-story-band',
                'product-gallery-left', 'product-gallery-top', 'product-story-led',
            ],
            $slugs,
        );
        self::assertCount(5, $this->container()->get(PatternLibrary::class)->forLayout('shop_index', '@site'));
        self::assertCount(4, $this->container()->get(PatternLibrary::class)->forLayout('shop_category', '@site'));

        $disabled = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]]);
        try {
            $library = $disabled->getContainer()->get(PatternLibrary::class);
            $shop = array_filter(
                $library->layoutSlugs(),
                static fn (string $s): bool => str_starts_with($s, 'product-') || str_starts_with($s, 'shop-'),
            );
            self::assertSame([], array_values($shop));
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
    }

    public function testRegistrationIsIdempotent(): void
    {
        $provider = new CommerceIntegrationServiceProvider($this->container());
        self::assertTrue($provider->registerPatternContributor($this->appContext()));
        self::assertTrue($provider->registerPatternContributor($this->appContext()));
        $ids = array_map(
            static fn ($contributor): string => $contributor->id(),
            $this->container()->get(PatternContributorRegistry::class)->layoutContributors(),
        );
        self::assertSame(1, count(array_keys($ids, 'thallo.commerce', true)));
    }
}
