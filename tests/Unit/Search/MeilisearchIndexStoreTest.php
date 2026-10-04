<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\SearchDocument;
use Thallo\Core\Tests\Support\Search\FakeMeilisearch;
use Thallo\Search\Engine\IndexNotFound;
use Thallo\Search\Lifecycle\Fence;
use Thallo\Search\Store\ConfirmResult;
use Thallo\Search\Store\IndexNameFor;
use Thallo\Search\Store\MeilisearchIndexStore;
use Thallo\Search\Store\StoreQuery;
use Thallo\Search\Store\Target;

/**
 * The Meilisearch index store (search block spec §3.3, §3.5.5): an index per workspace, kind and
 * build attempt; writes that return their tasks and never wait; confirmation that reports the real
 * outcome (a timeout is pending, never failed); federated search across kinds; and a readiness check
 * against the running server's version.
 */
final class MeilisearchIndexStoreTest extends TestCase
{
    private FakeMeilisearch $meili;
    private MeilisearchIndexStore $store;
    private Fence $fence;

    protected function setUp(): void
    {
        $this->meili = new FakeMeilisearch();
        $this->store = new MeilisearchIndexStore($this->meili, 100, 5);
        $this->fence = Fence::drainer('entries', 'token');
    }

    public function testWritesReturnTasksAndConfirmReportsTheRealOutcome(): void
    {
        $target = $this->target('entries', 1);
        $this->store->createTarget($target);
        $this->meili->autoComplete = false;

        [$receipt] = $this->store->write($target, [$this->doc('a', 'en', 'Rose')], $this->fence);
        self::assertSame($target->key(), $receipt->targetKey);
        self::assertCount(1, $receipt->taskUids);

        $pending = $this->store->confirm($receipt);
        self::assertSame(ConfirmResult::PENDING, $pending->result, 'a timeout is pending, never failed');
        self::assertSame($receipt->taskUids, $pending->outstandingTaskUids);

        $this->meili->complete($receipt->taskUids[0]);
        self::assertSame(ConfirmResult::SUCCEEDED, $this->store->confirm($receipt)->result);

        [$failing] = $this->store->write($target, [$this->doc('b', 'en', 'Lily')], $this->fence);
        $this->meili->fail($failing->taskUids[0]);
        self::assertSame(ConfirmResult::FAILED, $this->store->confirm($failing)->result);
    }

    public function testAWriteThatSucceedsWithAFailedDeletionIsNotConfirmed(): void
    {
        $target = $this->target('entries', 1);
        $this->store->createTarget($target);
        $this->meili->autoComplete = false;
        [$receipt] = $this->store->replaceSource(
            [$target],
            'entries',
            'a',
            [$this->doc('a', 'en', 'Rose')],
            $this->fence,
        );
        self::assertCount(2, $receipt->taskUids, 'the upsert and the deletion');
        [$add, $delete] = $receipt->taskUids;
        $this->meili->complete($add);
        $this->meili->fail($delete);
        self::assertSame(ConfirmResult::FAILED, $this->store->confirm($receipt)->result);
    }

    public function testAPendingTaskKeepsTheReceiptPendingEvenIfItsSiblingFailed(): void
    {
        $target = $this->target('entries', 1);
        $this->store->createTarget($target);
        $this->meili->autoComplete = false;
        [$receipt] = $this->store->replaceSource(
            [$target],
            'entries',
            'a',
            [$this->doc('a', 'en', 'Rose')],
            $this->fence,
        );
        [$add, $delete] = $receipt->taskUids;
        $this->meili->fail($add);
        $outcome = $this->store->confirm($receipt);
        self::assertSame(ConfirmResult::PENDING, $outcome->result);
        self::assertSame([$delete], $outcome->outstandingTaskUids);
    }

    public function testReplacingRemovesOneLocaleOrTheWholeSource(): void
    {
        $target = $this->target('entries', 1);
        $this->store->createTarget($target);
        $this->store->replaceSource(
            [$target],
            'entries',
            'a',
            [$this->doc('a', 'en', 'En'), $this->doc('a', 'fr', 'Fr')],
            $this->fence,
        );
        $this->store->replaceSource([$target], 'entries', 'b', [$this->doc('b', 'en', 'B')], $this->fence);

        $this->store->replaceSource([$target], 'entries', 'a', [$this->doc('a', 'en', 'En edited')], $this->fence);
        self::assertSame(['entries_a_Len', 'entries_b_Len'], $this->meili->ids($target->name));
        self::assertSame('En edited', $this->meili->document($target->name, 'entries_a_Len')['title']);

        $this->store->replaceSource([$target], 'entries', 'a', [], $this->fence);
        self::assertSame(['entries_b_Len'], $this->meili->ids($target->name));
    }

    public function testIndexNamesAreUniquePerAttemptAndValid(): void
    {
        $name = IndexNameFor::target('content', 'ws1abcdefghi', 'products', 7);
        self::assertSame('content_v2_ws1abcdefghi_products_g7', $name);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $name);
        self::assertSame('content_v2_products_g7', IndexNameFor::target('content', null, 'products', 7));
        self::assertNotSame($name, IndexNameFor::target('content', 'ws1abcdefghi', 'products', 8));
    }

    public function testFederatedSearchSpansTargetsWithFilters(): void
    {
        $entries = $this->target('entries', 1);
        $products = $this->target('products', 1);
        $this->store->createTarget($entries);
        $this->store->createTarget($products);
        $this->store->write($entries, [
            $this->doc('e1', 'en', 'Rose oil', 't1'),
            $this->doc('e2', 'en', 'Rose water', 't2'),
            $this->doc('e3', 'fr', 'Rose rouge', 't1'),
        ], $this->fence);
        $this->store->write(
            $products,
            [new SearchDocument('products', 'P1', '*', null, '/p', 'Rose attar', 'rose')],
            $this->fence,
        );

        $result = $this->store->search(['entries' => $entries, 'products' => $products], new StoreQuery('rose', 'en', [
            'entries' => KindFilter::subtypes(['t1']),
            'products' => KindFilter::all(),
        ], 10, 0, false));

        $found = array_map(static fn ($h): string => $h->kind . ':' . $h->sourceId, $result->hits);
        sort($found);
        self::assertSame(['entries:e1', 'products:P1'], $found);
        $filters = array_column($this->meili->lastQueries, 'filter', 'indexUid');
        self::assertSame(
            'kind = "entries" AND subtype IN ["t1"] AND (locale = "en" OR locale = "*")',
            $filters[$entries->name],
        );
        self::assertSame('kind = "products" AND (locale = "en" OR locale = "*")', $filters[$products->name]);
    }

    public function testAnOldServerIsNotReady(): void
    {
        $this->meili->version = '1.9.2';
        $readiness = $this->store->readiness();
        self::assertFalse($readiness->available);
        self::assertSame(
            'Meilisearch 1.10 or newer is required for search across kinds (the server reports 1.9.2). '
            . 'Upgrade the server, or set `SEARCH_ENGINE=postgres`.',
            $readiness->message,
        );
        $this->meili->version = '1.10.0';
        self::assertTrue($this->store->readiness()->available);
    }

    public function testAMissingIndexSurfacesAsIndexNotFound(): void
    {
        $target = $this->target('entries', 1);
        $this->expectException(IndexNotFound::class);
        $this->store->search(
            ['entries' => $target],
            new StoreQuery('rose', 'en', ['entries' => KindFilter::all()], 10, 0, false),
        );
    }

    public function testLegacyQueriesReadTheSharedIndexAsEntries(): void
    {
        $this->meili->ensureIndex('content', []);
        $this->meili->addDocuments('content', [[
            'id' => 'legacy1_en', 'entry_uuid' => 'legacy1', 'content_type_uuid' => 't1', 'locale' => 'en',
            'title' => 'Rose legacy', 'body' => 'old',
        ]]);
        $result = $this->store->search(
            [],
            new StoreQuery('rose', 'en', ['entries' => KindFilter::subtypes(['t1'])], 10, 0, true),
        );
        self::assertSame(
            ['entries', 'legacy1', 't1'],
            [$result->hits[0]->kind, $result->hits[0]->sourceId, $result->hits[0]->subtype],
        );
    }

    public function testTargetsAreListedByPrefixAndDropped(): void
    {
        $this->store->createTarget($this->target('entries', 1));
        $this->store->createTarget($this->target('entries', 2));
        self::assertSame(
            ['content_v2_entries_g1', 'content_v2_entries_g2'],
            $this->store->listTargets('content_v2_entries_'),
        );
        $this->store->dropTarget($this->target('entries', 1));
        self::assertSame(['content_v2_entries_g2'], $this->store->listTargets('content_v2_entries_'));
    }

    private function target(string $kind, int $generation): Target
    {
        return new Target('meilisearch', IndexNameFor::target('content', null, $kind, $generation), $generation);
    }

    private function doc(string $id, string $locale, string $title, ?string $subtype = 't1'): SearchDocument
    {
        return new SearchDocument('entries', $id, $locale, $subtype, '/' . $id, $title, $title . ' body');
    }
}
