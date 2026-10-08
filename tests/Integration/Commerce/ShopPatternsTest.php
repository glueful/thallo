<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Commerce\CommerceIntegrationServiceProvider;
use Thallo\Contracts\Patterns\PatternContributorRegistry;
use Thallo\Core\Content\Patterns\PatternLibrary;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The shop's sections and page templates (sections and templates design §6, release S1): offered
 * with Commerce on and gone with it off; every template saves as a page; no pattern ships a value
 * of one site's shop; they need no products to be offered; registration happens once.
 */
final class ShopPatternsTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const SECTIONS = [
        'shop-new-arrivals', 'shop-collection-grid', 'shop-featured-spotlight', 'shop-add-to-cart-cta',
        'shop-sale-banner', 'shop-reasons', 'shop-product-faq', 'shop-cta-band',
    ];

    private const TEMPLATES = [
        'shop-landing' => 5,
        'shop-product-launch' => 4,
        'shop-sale' => 4,
        'shop-new-arrivals-page' => 4,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @return array<string,array<string,mixed>> */
    private function patterns(?PatternLibrary $library = null): array
    {
        $library ??= $this->container()->get(PatternLibrary::class);
        return array_column($library->all(), null, 'slug');
    }

    /** @return list<array<string,mixed>> every block of every shop pattern, all the way down */
    private static function blocks(array $tree): array
    {
        $out = [];
        foreach ($tree as $block) {
            $out[] = $block;
            foreach ($block['data'] as $value) {
                if (is_array($value) && is_array($value[0] ?? null) && isset($value[0]['type'])) {
                    array_push($out, ...self::blocks($value));
                }
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $blocks */
    private static function withIds(array $blocks, int &$n): array
    {
        return array_map(static function (array $block) use (&$n): array {
            $block['id'] = 'sp' . str_pad((string) ++$n, 10, '0', STR_PAD_LEFT);
            foreach ($block['data'] as $key => $value) {
                if (is_array($value) && $value !== [] && is_array($value[0] ?? null) && isset($value[0]['type'])) {
                    $block['data'][$key] = self::withIds($value, $n);
                }
            }
            return $block;
        }, $blocks);
    }

    public function testTheShopPatternsAreOfferedWithCommerceOn(): void
    {
        $patterns = $this->patterns();
        foreach (self::SECTIONS as $slug) {
            self::assertArrayHasKey($slug, $patterns, $slug);
            self::assertSame(['section', 'page'], [$patterns[$slug]['kind'], $patterns[$slug]['scope']], $slug);
        }
        foreach (self::TEMPLATES as $slug => $count) {
            self::assertArrayHasKey($slug, $patterns, $slug);
            self::assertSame('page', $patterns[$slug]['kind']);
            self::assertCount($count, $patterns[$slug]['blocks'], "{$slug}: every section, whole");
        }
        self::assertSame('product', $patterns['shop-featured-spotlight']['requires']);
        self::assertSame('product', $patterns['shop-add-to-cart-cta']['requires']);
        self::assertNull($patterns['shop-new-arrivals']['requires']);
        foreach (['shop-landing', 'shop-product-launch', 'shop-new-arrivals-page'] as $slug) {
            self::assertSame('product', $patterns[$slug]['requires'], $slug);
        }
        self::assertNull($patterns['shop-sale']['requires']);
    }

    public function testEveryShopPatternSavesAsAPage(): void
    {
        $validator = $this->container()->get(FieldValidator::class);
        $schema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        $checked = 0;
        foreach ($this->patterns() as $slug => $pattern) {
            if (!str_starts_with((string) $slug, 'shop-')) {
                continue;
            }
            $n = 0;
            try {
                $validator->validate($schema, ['body' => self::withIds($pattern['blocks'], $n)], true);
            } catch (ValidationException $e) {
                self::fail("{$slug} must save as a page: " . json_encode($e->errors()));
            }
            $checked++;
        }
        self::assertSame(12, $checked);
    }

    public function testNoShopPatternShipsASiteSpecificValue(): void
    {
        foreach ($this->patterns() as $slug => $pattern) {
            if (!str_starts_with((string) $slug, 'shop-')) {
                continue;
            }
            foreach (self::blocks($pattern['blocks']) as $block) {
                $data = $block['data'];
                if (array_key_exists('product_slug', $data)) {
                    self::assertSame('', $data['product_slug'], "{$slug}: {$block['type']}");
                }
                foreach (['categories', 'tags'] as $key) {
                    self::assertSame([], $data[$key] ?? [], "{$slug}: {$block['type']}.{$key}");
                }
                if ($block['type'] === 'product-grid') {
                    // Portable: every product, newest first — no category, tag or product named.
                    self::assertSame('all', $data['source'], "{$slug}: a portable grid");
                    self::assertSame('newest', $data['order_by'], "{$slug}: a portable grid");
                }
                if (array_key_exists('url', $data)) {
                    self::assertSame('#', $data['url'], "{$slug}: {$block['type']}");
                }
            }
        }
    }

    public function testTheShopPatternsResolveOnAnEmptyShop(): void
    {
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        $patterns = $this->patterns();
        foreach ([...self::SECTIONS, ...array_keys(self::TEMPLATES)] as $slug) {
            self::assertArrayHasKey($slug, $patterns, "{$slug}: patterns never depend on data");
        }
    }

    public function testWithCommerceOffNoShopPatternExists(): void
    {
        $disabled = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.commerce' => false]]);
        try {
            $container = $disabled->getContainer();
            $slugs = array_column($container->get(PatternLibrary::class)->all(), 'slug');
            $shop = array_filter($slugs, static fn (string $s): bool => str_starts_with($s, 'shop-'));
            self::assertSame([], array_values($shop));
            $ids = array_map(
                static fn ($contributor): string => $contributor->id(),
                $container->get(PatternContributorRegistry::class)->all(),
            );
            self::assertNotContains('thallo.commerce', $ids);
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
    }

    public function testRegistrationIsIdempotent(): void
    {
        $registry = $this->container()->get(PatternContributorRegistry::class);
        $provider = new CommerceIntegrationServiceProvider($this->container());
        self::assertTrue($provider->registerPatternContributor($this->appContext()));
        self::assertTrue($provider->registerPatternContributor($this->appContext()));
        $ids = array_map(static fn ($contributor): string => $contributor->id(), $registry->all());
        self::assertSame(1, count(array_keys($ids, 'thallo.commerce', true)));
    }
}
