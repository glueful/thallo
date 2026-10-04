<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\SearchDocument;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\FixedClock;
use Thallo\Search\Lifecycle\Fence;
use Thallo\Search\Lifecycle\StaleFence;
use Thallo\Search\Lifecycle\StateRepository;
use Thallo\Search\Store\ConfirmResult;
use Thallo\Search\Store\PostgresIndexStore;
use Thallo\Search\Store\StoreQuery;
use Thallo\Search\Store\Target;

/**
 * The Postgres index store (search block spec §3.3, §3.5.5): one table, fenced writes, queries
 * across kinds that the engine filters by kind, subtype and locale. Postgres has one physical row
 * per document for every generation, so a write never lowers a row's generation and one replacement
 * satisfies every target generation at once.
 */
final class PostgresIndexStoreTest extends AppTestCase
{
    private FixedClock $clock;
    private StateRepository $state;
    private PostgresIndexStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('The Postgres store needs Postgres.');
        }
        $this->clock = new FixedClock();
        $this->state = new StateRepository($this->connection(), $this->clock);
        $this->store = new PostgresIndexStore($this->connection(), $this->state);
    }

    public function testWritesAreFencedAndAStaleBuilderRollsBack(): void
    {
        $a = $this->state->claimBuild('entries', 60, 0, 1);
        $this->clock->advance(61);
        $b = $this->state->claimBuild('entries', 60, 0, 1);

        try {
            $this->store->write($this->pg($a->generation), [$this->doc('a1', 'en', 'Rose garden')], $this->builder($a));
            self::fail('a stale builder must not write');
        } catch (StaleFence) {
        }
        self::assertSame(0, $this->connection()->table('search_documents')->where('source_id', '=', 'a1')->count());

        $receipts = $this->store->write(
            $this->pg($b->generation),
            [$this->doc('a1', 'en', 'Rose garden')],
            $this->builder($b),
        );
        self::assertSame('pg:g' . $b->generation, $receipts[0]->targetKey);
        self::assertSame(ConfirmResult::SUCCEEDED, $this->store->confirm($receipts[0])->result);
        self::assertSame(1, $this->connection()->table('search_documents')->where('source_id', '=', 'a1')->count());
    }

    public function testSearchFiltersKindsSubtypesAndLocale(): void
    {
        $claim = $this->state->claimBuild('entries', 60, 0, 1);
        $fence = $this->builder($claim);
        $this->store->write($this->pg($claim->generation), [
            $this->doc('e1', 'en', 'Rose oil', 't1'),
            $this->doc('e2', 'en', 'Rose water', 't2'),
            $this->doc('e3', 'fr', 'Rose rouge', 't1'),
            new SearchDocument('products', 'P1', '*', null, '/shop/products/p1', 'Rose attar', 'A rose perfume'),
        ], $fence);

        $result = $this->store->search([], new StoreQuery('rose', 'en', [
            'entries' => KindFilter::subtypes(['t1']),
            'products' => KindFilter::all(),
        ], 10, 0));
        $found = array_map(static fn ($h): string => $h->kind . ':' . $h->sourceId, $result->hits);
        sort($found);
        self::assertSame(['entries:e1', 'products:P1'], $found);
        self::assertSame(2, $result->total);

        $none = $this->store->search([], new StoreQuery('rose', 'en', [
            'entries' => KindFilter::subtypes(['t1']),
            'products' => KindFilter::none(),
        ], 10, 0));
        self::assertSame(
            ['entries:e1'],
            array_map(static fn ($h): string => $h->kind . ':' . $h->sourceId, $none->hits),
        );
        self::assertSame('t1', $none->hits[0]->subtype);

        self::assertSame(0, $this->store->search([], new StoreQuery('rose', 'en', [
            'entries' => KindFilter::none(),
        ], 10, 0))->total);
    }

    public function testSweepRemovesOnlyOlderGenerationsOfOneKind(): void
    {
        $g1 = $this->state->claimBuild('entries', 60, 0, 1);
        $this->store->write($this->pg($g1->generation), [$this->doc('old', 'en', 'Old')], $this->builder($g1));
        $this->clock->advance(61);
        $g2 = $this->state->claimBuild('entries', 60, 0, 1);
        $this->store->write($this->pg($g2->generation), [$this->doc('new', 'en', 'New')], $this->builder($g2));
        $p1 = $this->state->claimBuild('products', 60, 0, 1);
        $this->store->write($this->pg($p1->generation, 'products'), [
            new SearchDocument('products', 'P1', '*', null, '/p', 'P', 'p'),
        ], $this->builder($p1, 'products'));

        $this->store->sweep('entries', $g2->generation, $this->builder($g2));

        self::assertSame(['new'], $this->sourceIds('entries'));
        self::assertSame(['P1'], $this->sourceIds('products'));
    }

    public function testALiveWriteNeverLowersAGeneration(): void
    {
        $this->state->ensure('entries');
        $g2 = $this->state->claimBuild('entries', 60, 0, 1);
        $this->store->write($this->pg($g2->generation), [$this->doc('a', 'en', 'Built')], $this->builder($g2));

        // A drainer that read only the older active generation before the build began.
        $drainer = Fence::drainer('entries', (string) $this->state->claimDrainer('entries', 60, 30));
        $this->store->replaceSource(
            [$this->pg($g2->generation - 1)],
            'entries',
            'a',
            [$this->doc('a', 'en', 'Edited')],
            $drainer,
        );

        $row = $this->connection()->table('search_documents')->where('source_id', '=', 'a')->first();
        self::assertSame($g2->generation, (int) $row['generation']);
        self::assertSame('Edited', $row['title']);
        $this->store->sweep('entries', $g2->generation, $this->builder($g2));
        self::assertSame(['a'], $this->sourceIds('entries'));
    }

    public function testOneReplacementSatisfiesBothGenerations(): void
    {
        $drainer = Fence::drainer('entries', (string) $this->state->claimDrainer('entries', 60, 30));
        $receipts = $this->store->replaceSource(
            [$this->pg(1), $this->pg(2)],
            'entries',
            'a',
            [$this->doc('a', 'en', 'A')],
            $drainer,
        );
        self::assertSame(['pg:g1', 'pg:g2'], array_map(static fn ($r): string => $r->targetKey, $receipts));
        foreach ($receipts as $receipt) {
            self::assertSame([], $receipt->taskUids);
            self::assertSame(ConfirmResult::SUCCEEDED, $this->store->confirm($receipt)->result);
        }
    }

    public function testReplacingKeepsRetainedLocalesAndRemovesAbsentOnes(): void
    {
        $drainer = Fence::drainer('entries', (string) $this->state->claimDrainer('entries', 60, 30));
        $this->store->replaceSource([$this->pg(1)], 'entries', 'a', [
            $this->doc('a', 'en', 'English'), $this->doc('a', 'fr', 'Français'),
        ], $drainer);
        $this->store->replaceSource(
            [$this->pg(1)],
            'entries',
            'a',
            [$this->doc('a', 'en', 'English, edited')],
            $drainer,
        );

        $rows = $this->connection()->table('search_documents')->where('source_id', '=', 'a')->get();
        self::assertCount(1, $rows);
        self::assertSame(['en', 'English, edited'], [$rows[0]['locale'], $rows[0]['title']]);
    }

    public function testReplacingWithNoDocumentsRemovesTheSource(): void
    {
        $drainer = Fence::drainer('entries', (string) $this->state->claimDrainer('entries', 60, 30));
        $this->store->replaceSource(
            [$this->pg(1)],
            'entries',
            'a',
            [$this->doc('a', 'en', 'A'), $this->doc('a', 'fr', 'A')],
            $drainer,
        );
        $this->store->replaceSource([$this->pg(1)], 'entries', 'b', [$this->doc('b', 'en', 'B')], $drainer);

        $this->store->replaceSource([$this->pg(1)], 'entries', 'a', [], $drainer);

        self::assertSame(['b'], $this->sourceIds('entries'));
    }

    private function pg(int $generation, string $kind = 'entries'): Target
    {
        return new Target('pg', Target::POSTGRES, $generation);
    }

    private function builder(\Thallo\Search\Lifecycle\Claim $claim, string $kind = 'entries'): Fence
    {
        return Fence::builder($kind, $claim->token, $claim->generation);
    }

    private function doc(string $id, string $locale, string $title, ?string $subtype = 't1'): SearchDocument
    {
        return new SearchDocument('entries', $id, $locale, $subtype, '/' . $id, $title, $title . ' body');
    }

    /** @return list<string> */
    private function sourceIds(string $kind): array
    {
        $rows = $this->connection()->table('search_documents')->where('kind', '=', $kind)->orderBy('source_id')->get();
        return array_values(array_unique(array_map(static fn (array $r): string => (string) $r['source_id'], $rows)));
    }
}
