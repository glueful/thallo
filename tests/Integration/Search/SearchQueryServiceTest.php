<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Contracts\Search\SearchAudience;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\ArraySource;
use Thallo\Core\Tests\Support\Search\LifecycleKit;
use Thallo\Search\Lifecycle\Cutover;
use Thallo\Search\Lifecycle\RebuildOutcome;
use Thallo\Search\Lifecycle\Workspace;
use Thallo\Search\Query\CursorSigner;
use Thallo\Search\Query\SearchInput;
use Thallo\Search\Query\SearchQueryService;
use Thallo\Search\Query\Surface;

/**
 * One query path (search block spec §3.4, §3.7): the engine matches and ranks; contributors decide,
 * from current records, what is shown; a withdrawn candidate is dropped and the page refilled from
 * further batches; the cursor advances past examined candidates only; an unavailable scope never
 * widens; and an empty batch is not "no results" while more remain.
 */
final class SearchQueryServiceTest extends AppTestCase
{
    private LifecycleKit $kit;
    private ArraySource $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->products = new ArraySource('products', ['thallo.commerce']);
        $this->kit = new LifecycleKit($this->appContext(), $this->connection());
        $this->kit->registry->register($this->products);
        $this->kit->source->items = ['e1' => ['en' => 'Rose garden']];
    }

    public function testAWithdrawnCandidateIsNeverShownAndTheBatchIsRefilled(): void
    {
        foreach (['P1', 'P2', 'P3'] as $i => $id) {
            $this->products->items[$id] = ['*' => 'Rose attar ' . $i];
        }
        $this->build();
        $this->products->withdrawn = ['P1' => true, 'P2' => true];

        $outcome = $this->service()->search(
            $this->input(['q' => 'attar', 'scope' => 'products']),
            SearchAudience::public(),
            2,
            true,
        );

        self::assertSame('results', $outcome->state);
        self::assertSame(['P3'], array_map(static fn ($i): string => $i->sourceId, $outcome->items));
        self::assertTrue($outcome->totalApproximate);
    }

    public function testARunOfWithdrawnCandidatesBeyondTheRefillLimit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $id = sprintf('P%02d', $i);
            $this->products->items[$id] = ['*' => 'Rose attar'];
            if ($i < 9) {
                $this->products->withdrawn[$id] = true;
            }
        }
        $this->build();
        $service = $this->service();

        $page = $service->search(
            $this->input(['q' => 'attar', 'scope' => 'products']),
            SearchAudience::public(),
            2,
            true,
        );
        self::assertSame('empty_batch', $page->state);
        self::assertSame([], $page->items);
        self::assertNotNull($page->next, 'more remain beyond the refill limit');

        $next = $service->search(
            $this->input(['q' => 'attar', 'scope' => 'products', 'cursor' => $page->next]),
            SearchAudience::public(),
            2,
            true,
        );
        self::assertSame('results', $next->state);
        self::assertSame(['P09'], array_map(static fn ($i): string => $i->sourceId, $next->items));
    }

    public function testTheCursorAdvancesOnlyPastExaminedCandidates(): void
    {
        foreach (['P1', 'P2', 'P3'] as $id) {
            $this->products->items[$id] = ['*' => 'Rose attar'];
        }
        $this->build();
        $service = $this->service();
        $seen = [];
        $cursor = null;
        do {
            $query = ['q' => 'attar', 'scope' => 'products'] + ($cursor !== null ? ['cursor' => $cursor] : []);
            $page = $service->search($this->input($query), SearchAudience::public(), 1, true);
            foreach ($page->items as $item) {
                $seen[] = $item->sourceId;
            }
            $cursor = $page->next;
        } while ($cursor !== null);
        sort($seen);
        self::assertSame(['P1', 'P2', 'P3'], $seen, 'one at a time, none skipped, none repeated');
    }

    public function testAnUnavailableScopeNeverWidens(): void
    {
        $this->products->items['P1'] = ['*' => 'Rose attar'];
        $this->build();
        $this->kit->capabilities['thallo.commerce'] = false;

        $outcome = $this->service()->search(
            $this->input(['q' => 'rose', 'scope' => 'products']),
            SearchAudience::public(),
            10,
            true,
        );
        self::assertSame('scope_unavailable', $outcome->state);
        self::assertSame([], $outcome->items);
        self::assertSame('Requires Commerce', $outcome->unavailableReason);

        $unknown = $this->service()->search(
            $this->input(['q' => 'rose', 'scope' => 'reviews']),
            SearchAudience::public(),
            10,
            true,
        );
        self::assertSame('scope_unavailable', $unknown->state);

        $all = $this->service()->search($this->input(['q' => 'rose']), SearchAudience::public(), 10, true);
        self::assertSame(
            ['entries'],
            array_values(array_unique(array_map(static fn ($i): string => $i->kind, $all->items))),
        );
    }

    public function testRemovedTextNeverAppears(): void
    {
        $this->kit->source->items = ['e1' => ['en' => 'Rose garden with a secret fountain']];
        $this->build();
        // The text changes; the index keeps the old words (its update "failed").
        $this->kit->source->items = ['e1' => ['en' => 'Rose garden']];

        $outcome = $this->service()->search($this->input(['q' => 'fountain']), SearchAudience::public(), 10, true);
        foreach ($outcome->items as $item) {
            self::assertStringNotContainsString('fountain', $item->snippetHtml);
            self::assertStringNotContainsString('fountain', $item->display->title . $item->display->text);
        }
    }

    public function testNoMatchesOnlyWhenExhausted(): void
    {
        $this->build();
        $outcome = $this->service()->search($this->input(['q' => 'zzzz']), SearchAudience::public(), 10, true);
        self::assertSame('no_matches', $outcome->state);
        self::assertNull($outcome->next);
    }

    public function testAnEmptyQueryAsksForNothing(): void
    {
        self::assertSame(
            'no_query',
            $this->service()->search($this->input([]), SearchAudience::public(), 10, true)->state,
        );
    }

    public function testABrokenEngineIsUnavailableNotNoResults(): void
    {
        $this->build();
        $broken = $this->createMock(\Thallo\Search\Store\IndexStore::class);
        $broken->method('search')->willThrowException(new \RuntimeException('engine down'));
        $service = $this->service($broken);
        self::assertSame(
            'unavailable',
            $service->search($this->input(['q' => 'rose']), SearchAudience::public(), 10, true)->state,
        );
    }

    public function testAFederatedQueryRetriesOnceAfterAMissingIndex(): void
    {
        $kit = new LifecycleKit($this->appContext(), $this->connection(), 'meili');
        $kit->registry->register($this->products);
        $kit->source->items = ['e1' => ['en' => 'Rose garden']];
        $this->products->items['P1'] = ['*' => 'Rose attar'];
        $this->buildWith($kit);
        $stale = (string) $kit->state->row('entries')['active_target'];

        // A rebuild promotes a new index and the old one is gone by the time the query reaches it.
        $kit->clock->advance(5);
        self::assertSame(RebuildOutcome::PROMOTED, $kit->rebuilder()->run('entries'));
        $kit->meili->deleteIndex($stale);
        $service = $this->service(null, $kit, fn () => $kit->locator());
        $outcome = $service->search($this->input(['q' => 'rose']), SearchAudience::public(), 10, true);
        self::assertSame('results', $outcome->state);

        // A second miss is a failure, never a silent empty page.
        $kit->meili->deleteIndex((string) $kit->state->row('entries')['active_target']);
        self::assertSame(
            'unavailable',
            $service->search($this->input(['q' => 'rose']), SearchAudience::public(), 10, true)->state,
        );
    }

    private function build(): void
    {
        $this->buildWith($this->kit);
    }

    private function buildWith(LifecycleKit $kit): void
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

    /** @param array<string, mixed> $query */
    private function input(array $query): SearchInput
    {
        return SearchInput::from($query, Surface::Site, ['en'], 'en', array_keys($this->kit->registry->all()));
    }

    private function service(
        ?\Thallo\Search\Store\IndexStore $store = null,
        ?LifecycleKit $kit = null,
        ?\Closure $locator = null,
    ): SearchQueryService {
        $kit ??= $this->kit;
        return new SearchQueryService(
            $kit->registry,
            $kit->availability(),
            $store ?? $kit->store,
            $kit->locator(),
            new CursorSigner('test-key'),
            new Workspace($this->appContext()),
        );
    }
}
