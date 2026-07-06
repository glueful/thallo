<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use Thallo\Search\Engine\MeilisearchBackend;
use Thallo\Search\Engine\MeilisearchIndex;
use Thallo\Search\Index\DocumentBuilder;
use Thallo\Search\Query\SearchRequest;
use PHPUnit\Framework\TestCase;

final class MeilisearchBackendTest extends TestCase
{
    private function fakeIndex(): MeilisearchIndex
    {
        return new class implements MeilisearchIndex {
            /** @var array<string,mixed> */
            public array $settings = [];
            /** @var list<array<string,mixed>> */
            public array $added = [];
            /** @var list<string> */
            public array $deletedIds = [];
            /** @var list<string> */
            public array $deletedFilters = [];
            public ?string $lastQuery = null;
            /** @var array<string,mixed> */
            public array $lastParams = [];
            /** @var array<string,mixed> */
            public array $searchResult = ['hits' => [], 'estimatedTotalHits' => 0];
            public bool $up = true;

            public function ensureIndex(array $settings): void
            {
                $this->settings = $settings;
            }
            public function addDocuments(array $documents): void
            {
                foreach ($documents as $d) {
                    $this->added[] = $d;
                }
            }
            public function deleteDocument(string $id): void
            {
                $this->deletedIds[] = $id;
            }
            public function deleteByFilter(string $filter): void
            {
                $this->deletedFilters[] = $filter;
            }
            public function rawSearch(string $query, array $params): array
            {
                $this->lastQuery = $query;
                $this->lastParams = $params;
                return $this->searchResult;
            }
            public function reachable(): bool
            {
                return $this->up;
            }
        };
    }

    public function testEnsureIndexAppliesSearchableAndFilterableSettings(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->ensureIndex();

        self::assertSame(['title', 'body'], $index->settings['searchableAttributes']);
        self::assertContains('content_type_uuid', $index->settings['filterableAttributes']);
        self::assertContains('locale', $index->settings['filterableAttributes']);
        self::assertContains('content_type_slug', $index->settings['filterableAttributes']);
        // entry_uuid must be filterable or whole-entry purges (deleteByFilter) are rejected.
        self::assertContains('entry_uuid', $index->settings['filterableAttributes']);
        // Visibility is resolved live at query time — never denormalized into the index.
        self::assertNotContains('public_delivery', $index->settings['filterableAttributes']);
    }

    public function testUpsertForwardsDocuments(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->upsert([['id' => 'e-1_en', 'title' => 'x']]);
        self::assertSame('e-1_en', $index->added[0]['id']);
    }

    public function testDeleteEntryWithLocaleDeletesSingleDocumentId(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->deleteEntry('e-1', 'en');
        // Must compose the same Meilisearch-safe id DocumentBuilder indexes under
        // (colons are invalid in Meilisearch document ids).
        self::assertSame([DocumentBuilder::documentId('e-1', 'en')], $index->deletedIds);
        self::assertSame(['e-1_en'], $index->deletedIds);
        self::assertSame([], $index->deletedFilters);
    }

    public function testDeleteEntryWithNullLocaleDeletesAllEntryDocumentsByFilter(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->deleteEntry('e-1', null);
        self::assertSame([], $index->deletedIds);
        self::assertSame(['entry_uuid = "e-1"'], $index->deletedFilters);
    }

    public function testSearchBuildsVisibilityFilterAndMapsHits(): void
    {
        $index = $this->fakeIndex();
        $index->searchResult = [
            'estimatedTotalHits' => 1,
            'hits' => [[
                'entry_uuid' => 'e-9', 'content_type_slug' => 'blog', 'locale' => 'en',
                'href' => '/en/blog/x', 'title' => 'Clean Title',
                '_rankingScore' => 0.87,
                '_formatted' => ['body' => "the \x02climate\x03 crisis <script>"],
            ]],
        ];
        $backend = new MeilisearchBackend($index, 40);

        $req = new SearchRequest('climate', 'en', null, false, ['ct-a', 'ct-b'], 20, 0);
        $results = $backend->search($req);

        // Filter: locale AND the live-resolved visible type allowlist.
        $filter = $index->lastParams['filter'];
        self::assertStringContainsString('locale = "en"', $filter);
        self::assertStringContainsString('content_type_uuid IN ["ct-a", "ct-b"]', $filter);
        self::assertStringNotContainsString('public_delivery', $filter);

        self::assertSame(1, $results->total);
        $hit = $results->hits[0];
        self::assertSame('e-9', $hit->entryUuid);
        self::assertSame('Clean Title', $hit->title); // plain text, no highlight tags
        self::assertSame(0.87, $hit->score);
        // Snippet: highlight sentinels → <mark>, surrounding markup escaped.
        self::assertStringContainsString('<mark>climate</mark>', $hit->snippet);
        self::assertStringContainsString('&lt;script&gt;', $hit->snippet);
        self::assertStringNotContainsString('<script>', $hit->snippet);
    }

    public function testSearchAllAccessOmitsTypeUuidClauseAndTypeSlugWhenProvided(): void
    {
        $index = $this->fakeIndex();
        $backend = new MeilisearchBackend($index, 40);
        $backend->search(new SearchRequest('x', 'en', 'blog', true, [], 20, 0));

        $filter = $index->lastParams['filter'];
        self::assertStringContainsString('content_type_slug = "blog"', $filter);
        // all-access ⇒ no visibility narrowing to the visible-type allowlist.
        self::assertStringNotContainsString('content_type_uuid IN', $filter);
    }

    public function testSearchShortCircuitsWhenNothingIsVisible(): void
    {
        // No all-access and an empty visible set: nothing can match — Meilisearch is
        // never queried (avoids an empty IN [] filter).
        $index = $this->fakeIndex();
        $results = (new MeilisearchBackend($index, 40))
            ->search(new SearchRequest('x', 'en', null, false, [], 20, 0));

        self::assertSame([], $results->hits);
        self::assertSame(0, $results->total);
        self::assertNull($index->lastQuery);
    }

    public function testHealthReflectsIndexReachability(): void
    {
        $index = $this->fakeIndex();
        $backend = new MeilisearchBackend($index, 40);
        self::assertTrue($backend->health());
        $index->up = false;
        self::assertFalse($backend->health());
    }
}
