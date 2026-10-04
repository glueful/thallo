<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\SearchDocument;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Search\Engine\IndexNotFound;
use Thallo\Search\Engine\LiveMeilisearchIndex;
use Thallo\Search\Lifecycle\Fence;
use Thallo\Search\Store\MeilisearchIndexStore;
use Thallo\Search\Store\ConfirmResult;
use Thallo\Search\Store\StoreQuery;
use Thallo\Search\Store\Target;
use Thallo\Search\Store\TargetReceipt;

/**
 * Smoke test against a REAL Meilisearch server — the only place Meilisearch's actual contract is
 * exercised: document ids, filterable attributes, a federated search across kinds, a missing index
 * inside one, and delete-by-filter. The suite fakes the index seam, which is how four ship-blocking
 * contract bugs survived it (invalid `:` ids, non-filterable purges, a settings-less auto-created
 * index, and plain arrays handed to the client's multiSearch).
 *
 * Opt-in: set MEILISEARCH_SMOKE=1 with a reachable server (MEILISEARCH_HOST/config). Run it before
 * shipping any index-shape change: `MEILISEARCH_SMOKE=1 vendor/bin/phpunit --filter MeilisearchSmokeTest`.
 */
final class MeilisearchSmokeTest extends AppTestCase
{
    private const ENTRIES = 'smoke_v2_entries_g1';
    private const PRODUCTS = 'smoke_v2_products_g1';

    private ?MeilisearchIndexStore $store = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('MEILISEARCH_SMOKE') !== '1') {
            self::markTestSkipped('MEILISEARCH_SMOKE=1 not set (needs a running Meilisearch).');
        }
        if (!$this->container()->has(IndexManager::class)) {
            self::markTestSkipped('glueful/meilisearch extension is not enabled.');
        }
        try {
            $this->container()->get(IndexManager::class)->getClient()->health();
        } catch (\Throwable) {
            self::markTestSkipped('Meilisearch server is not reachable.');
        }
        $this->store = new MeilisearchIndexStore(LiveMeilisearchIndex::fromContainer($this->container()));
        self::assertTrue($this->store->readiness()->available, (string) $this->store->readiness()->message);
    }

    protected function tearDown(): void
    {
        foreach ([self::ENTRIES, self::PRODUCTS] as $name) {
            try {
                $this->store?->dropTarget($this->target($name));
            } catch (\Throwable) {
                // Never created (skipped or failed early).
            }
        }
        parent::tearDown();
    }

    public function testAFederatedSearchAcrossKindsWithFiltersAndDeleteByFilter(): void
    {
        $entries = $this->target(self::ENTRIES);
        $products = $this->target(self::PRODUCTS);
        $this->store->createTarget($entries);
        $this->store->createTarget($products);
        $fence = Fence::builder('entries', 'smoketoken', 1);

        $this->settle($this->store->write($entries, [
            new SearchDocument('entries', 'e1', 'en', 't1', '/rose', 'Rose garden', 'Roses in the morning'),
            new SearchDocument('entries', 'e2', 'en', 't2', '/hidden', 'Rose hidden', 'A hidden rose'),
            new SearchDocument('entries', 'e1', 'fr', 't1', '/fr/rose', 'Jardin de roses', 'Roses le matin'),
        ], $fence));
        $this->settle($this->store->write($products, [
            new SearchDocument('products', 'P1', '*', null, '/shop/p1', 'Rose attar', 'A rose perfume'),
        ], $fence));

        $result = $this->store->search(['entries' => $entries, 'products' => $products], new StoreQuery(
            'rose',
            'en',
            ['entries' => KindFilter::subtypes(['t1']), 'products' => KindFilter::all()],
            10,
            0,
        ));
        $found = array_map(static fn ($h): string => $h->kind . ':' . $h->sourceId . ':' . $h->locale, $result->hits);
        sort($found);
        self::assertSame(['entries:e1:en', 'products:P1:*'], $found);

        // Replacing e1 with only its English document deletes the French one by filter.
        $this->settle($this->store->replaceSource(['e' => $entries], 'entries', 'e1', [
            new SearchDocument('entries', 'e1', 'en', 't1', '/rose', 'Rose garden', 'Roses in the morning'),
        ], $fence));
        $french = $this->store->search(['entries' => $entries], new StoreQuery(
            'jardin',
            'fr',
            ['entries' => KindFilter::all()],
            10,
            0,
        ));
        self::assertSame(0, $french->total);
    }

    public function testAMissingIndexInsideAFederatedQueryIsIndexNotFound(): void
    {
        $this->expectException(IndexNotFound::class);
        $this->store->search(
            ['entries' => $this->target('smoke_v2_entries_g404')],
            new StoreQuery('rose', 'en', ['entries' => KindFilter::all()], 10, 0),
        );
    }

    private function target(string $name): Target
    {
        return new Target('meilisearch', $name, 1);
    }

    /** @param list<TargetReceipt> $receipts */
    private function settle(array $receipts): void
    {
        foreach ($receipts as $receipt) {
            for ($i = 0; $i < 100; $i++) {
                $outcome = $this->store->confirm($receipt);
                if ($outcome->result !== ConfirmResult::PENDING) {
                    self::assertSame(ConfirmResult::SUCCEEDED, $outcome->result);
                    continue 2;
                }
            }
            self::fail('Meilisearch did not finish a task.');
        }
    }
}
