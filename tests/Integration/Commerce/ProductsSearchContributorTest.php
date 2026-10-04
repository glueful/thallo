<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Thallo\Commerce\Search\ProductsSearchContributor;
use Thallo\Commerce\Search\PushCatalogChangesToSearch;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Contracts\Search\SearchIndex;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\LifecycleKit;
use Thallo\Search\Lifecycle\Cutover;
use Thallo\Search\Lifecycle\Workspace;
use Thallo\Search\Query\CursorSigner;
use Thallo\Search\Query\SearchInput;
use Thallo\Search\Query\SearchQueryService;
use Thallo\Search\Query\Surface;

/**
 * Products as a search source (search block spec §3.2): only what the storefront lists, enumerated
 * by uuid; and what is shown — name, price, picture — read from current records, so a renamed or
 * withdrawn product is never shown stale, whatever the index still holds.
 */
final class ProductsSearchContributorTest extends AppTestCase
{
    private const TENANT = 'searchtenant';
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testOnlyListedProductsAreDocuments(): void
    {
        $active = $this->product('rose-attar', 'Rose attar', 'A <strong>deep</strong> rose.');
        $draft = $this->product('draft-one', 'Draft one', '', 'draft');
        $archived = $this->product('old-one', 'Old one', '', 'archived');
        $deleted = $this->product('gone-one', 'Gone one');
        $this->connection()->table('commerce_products')->where(
            'uuid',
            '=',
            $deleted,
        )->update(['deleted_at' => gmdate('Y-m-d H:i:s')]);
        $this->categorise($active, 'Oud', 'Bestseller');

        foreach ([$draft, $archived, $deleted] as $uuid) {
            self::assertSame([], $this->contributor()->documents($uuid));
        }
        [$doc] = $this->contributor()->documents($active);
        self::assertSame(['products', $active, '*', null], [$doc->kind, $doc->sourceId, $doc->locale, $doc->subtype]);
        self::assertSame('/shop/products/rose-attar', $doc->href);
        self::assertStringContainsString('deep rose', $doc->body);
        self::assertStringNotContainsString('<strong>', $doc->body);
        self::assertStringContainsString('Oud', $doc->body);
        self::assertStringContainsString('Bestseller', $doc->body);
    }

    public function testEnumerationIsKeysetOrderedByUuid(): void
    {
        foreach (['a', 'b', 'c'] as $slug) {
            $this->product('p-' . $slug, 'Product ' . $slug);
        }
        $seen = [];
        $after = null;
        do {
            $page = $this->contributor()->enumerate($after, 2);
            foreach ($page->documents as $doc) {
                $seen[] = $doc->sourceId;
            }
            $after = $page->nextAfter;
        } while ($after !== null);
        self::assertCount(3, array_unique($seen));
    }

    public function testPresentIsCurrentAndDropsTheWithdrawn(): void
    {
        $a = $this->product('rose-attar', 'Rose attar');
        $b = $this->product('lily', 'Lily');
        $this->connection()->table('commerce_products')->where(
            'uuid',
            '=',
            $a,
        )->update(['name' => 'Rose attar intense']);
        $this->connection()->table('commerce_products')->where('uuid', '=', $b)->update(['status' => 'archived']);

        $shown = $this->contributor()->present(SearchAudience::public(), 'en', [$a, $b]);
        self::assertSame('Rose attar intense', $shown[$a]?->title);
        self::assertNull($shown[$b]);
    }

    public function testPresentReturnsPriceAndCover(): void
    {
        $a = $this->product('rose-attar', 'Rose attar', '', 'active', 8900);
        $display = $this->contributor()->present(SearchAudience::public(), 'en', [$a])[$a];
        self::assertNotNull($display?->price);
        self::assertStringContainsString('89', (string) $display->price);
    }

    public function testARenamedProductShowsItsCurrentNameWhileTheIndexIsStale(): void
    {
        $a = $this->product('rose-attar', 'Rose attar');
        $kit = $this->kit();
        $this->build($kit);
        // Renamed with no catalog event: the index still holds "Rose attar".
        $this->connection()->table('commerce_products')->where('uuid', '=', $a)->update(['name' => 'Velvet oud']);

        $outcome = $this->service($kit)->search(
            $this->input($kit, ['q' => 'attar', 'scope' => 'products']),
            SearchAudience::public(),
            10,
            true,
        );
        self::assertSame(['Velvet oud'], array_map(static fn ($i): string => $i->display->title, $outcome->items));
    }

    public function testCatalogChangesReachTheJournal(): void
    {
        $index = new class implements SearchIndex {
            /** @var list<string> */
            public array $calls = [];
            public function changed(string $kind, string $sourceId): void
            {
                $this->calls[] = "changed:{$kind}:{$sourceId}";
            }
            public function kindChanged(string $kind, string $reason): void
            {
                $this->calls[] = "kind:{$kind}:{$reason}";
            }
        };
        $listener = new PushCatalogChangesToSearch(static fn (): SearchIndex => $index);
        $listener->onCatalogChanged(new StorefrontCatalogChanged(
            self::TENANT,
            StorefrontCatalogChanged::REASON_PRODUCT_UPDATED,
            'Ab12Cd34Ef56',
        ));
        $listener->onCatalogChanged(new StorefrontCatalogChanged(
            self::TENANT,
            StorefrontCatalogChanged::REASON_CATEGORY_CHANGED,
            null,
        ));
        self::assertSame(['changed:products:Ab12Cd34Ef56', 'kind:products:taxonomy'], $index->calls);
    }

    public function testTheKindIsDiscoverableWhileCommerceIsOff(): void
    {
        $off = self::bootAppWithConfigOverride(
            'thallo',
            ['capabilities' => ['thallo.commerce' => false, 'thallo.search' => true]],
        );
        $c = $off->getContainer();
        $registry = $c->get(\Thallo\Contracts\Search\SearchSourceRegistry::class);
        self::assertArrayHasKey('products', $registry->all());
        self::assertSame(
            'Requires Commerce',
            $c->get(\Thallo\Search\Query\KindAvailability::class)->reasonFor('products'),
        );
    }

    public function testCommerceOffExcludesProductsWithoutDeletingThemAndBackOnReconciles(): void
    {
        $a = $this->product('rose-attar', 'Rose attar');
        $b = $this->product('lily', 'Lily attar');
        $kit = $this->kit();
        $this->build($kit);
        self::assertSame(2, $this->connection()->table('search_documents')->where('kind', '=', 'products')->count());

        $kit->capabilities['thallo.commerce'] = false;
        $outcome = $this->service($kit)->search(
            $this->input($kit, ['q' => 'attar']),
            SearchAudience::public(),
            10,
            true,
        );
        self::assertSame([], array_filter($outcome->items, static fn ($i): bool => $i->kind === 'products'));
        self::assertSame(
            2,
            $this->connection()->table('search_documents')->where('kind', '=', 'products')->count(),
            'kept, not deleted',
        );

        // While off: one product is deleted and one renamed, with nothing listening.
        $this->connection()->table('commerce_products')->where(
            'uuid',
            '=',
            $a,
        )->update(['deleted_at' => gmdate('Y-m-d H:i:s')]);
        $this->connection()->table('commerce_products')->where('uuid', '=', $b)->update(['name' => 'Lily nocturne']);
        $kit->capabilities['thallo.commerce'] = true;
        $kit->clock->advance(5);
        $kit->state->addDemand('products', 'capability');
        $kit->reconciler()->runWorkspace(false);

        $titles = array_map(
            static fn (array $r): string => (string) $r['title'],
            $this->connection()->table('search_documents')->where('kind', '=', 'products')->get(),
        );
        self::assertSame(['Lily nocturne'], $titles);
    }

    private function contributor(): ProductsSearchContributor
    {
        return new ProductsSearchContributor($this->container(), $this->appContext());
    }

    private function kit(): LifecycleKit
    {
        $kit = new LifecycleKit($this->appContext(), $this->connection());
        $kit->registry->register($this->contributor());
        return $kit;
    }

    private function build(LifecycleKit $kit): void
    {
        $kit->reconciler()->runWorkspace(false);
        (new Cutover(
            $kit->state,
            $kit->store,
            $kit->locator(),
            new Workspace($this->appContext()),
            $this->container()->get(\Thallo\Contracts\Settings\SystemChannel::class),
            $this->connection(),
            'content',
        ))->flipIfReady();
        $kit->reconciler()->runWorkspace(false);
    }

    private function service(LifecycleKit $kit): SearchQueryService
    {
        return new SearchQueryService(
            $kit->registry,
            $kit->availability(),
            $kit->store,
            $kit->locator(),
            new CursorSigner('k'),
            new Workspace($this->appContext()),
        );
    }

    /** @param array<string, mixed> $query */
    private function input(LifecycleKit $kit, array $query): SearchInput
    {
        return SearchInput::from($query, Surface::Site, ['en'], 'en', array_keys($kit->registry->all()));
    }

    private function product(
        string $slug,
        string $name,
        string $description = '',
        string $status = 'active',
        int $price = 4900,
    ): string {
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug, 'name' => $name, 'description' => $description, 'status' => $status, 'type' => 'physical',
            'variants' => [[
                'sku' => 'sku-' . $slug . '-' . (++self::$seq),
                'price' => $price,
                'currency' => 'USD',
                'option_values' => [],
            ]],
        ]);
        return (string) $product['uuid'];
    }

    private function categorise(string $product, string $category, string $tag): void
    {
        $db = $this->connection();
        $db->table('commerce_categories')->insert([
            'uuid' => 'cat000000001',
            'tenant_uuid' => '',
            'slug' => 'oud',
            'name' => $category,
            'position' => 0,
            'revision' => 0,
        ]);
        $db->table('commerce_product_categories')->insert([
            'product_uuid' => $product,
            'category_uuid' => 'cat000000001',
        ]);
        $db->table('commerce_tags')->insert([
            'uuid' => 'tag000000001',
            'tenant_uuid' => '',
            'slug' => 'best',
            'name' => $tag,
            'revision' => 0,
        ]);
        $db->table('commerce_product_tags')->insert(['product_uuid' => $product, 'tag_uuid' => 'tag000000001']);
    }

    private function clean(): void
    {
        $pdo = $this->connection()->getPDO();
        foreach (
            [
            'commerce_product_addons',
            'commerce_product_categories',
            'commerce_product_tags',
            'commerce_product_media',
            'commerce_variants',
            'commerce_categories',
            'commerce_tags',
            'commerce_products',
            ] as $table
        ) {
            $pdo->exec("DELETE FROM {$table}");
        }
    }
}
