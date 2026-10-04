<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Glueful\Extensions\Meilisearch\Client\MeilisearchClient;
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Meilisearch\Contracts\MultiSearchFederation;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Search\Engine\LiveMeilisearchIndex;

/**
 * The live Meilisearch seam speaks the installed client's contract: a federated search hands the
 * client `SearchQuery` objects (it calls `toArray()` on each), with prefixed index names, the
 * kind's filter and ranking scores, and the federation's window.
 */
final class LiveMeilisearchIndexTest extends AppTestCase
{
    public function testAFederatedSearchSendsSearchQueryObjects(): void
    {
        $client = new class ('http://meili.invalid', null, 'site_') extends MeilisearchClient {
            /** @var list<array<string, mixed>> */
            public array $bodies = [];
            /** @var array<string, mixed> */
            public array $federation = [];

            public function multiSearch(array $queries = [], ?MultiSearchFederation $federation = null)
            {
                foreach ($queries as $query) {
                    $this->bodies[] = $query->toArray(); // what the real client does with each
                }
                $this->federation = $federation?->toArray() ?? [];
                return ['hits' => [[
                    'kind' => 'entries', 'source_id' => 'e1', 'locale' => 'en',
                    '_federation' => ['indexUid' => 'site_content_v2_entries_g1', 'weightedRankingScore' => 0.9],
                ]], 'estimatedTotalHits' => 1];
            }
        };
        $index = new LiveMeilisearchIndex(new IndexManager($client, $this->appContext()));

        $raw = $index->federatedSearch(
            [['indexUid' => 'content_v2_entries_g1', 'q' => 'rose', 'filter' => 'kind = "entries"']],
            10,
            20,
        );

        self::assertSame([[
            'indexUid' => 'site_content_v2_entries_g1',
            'q' => 'rose',
            'filter' => ['kind = "entries"'],
            'showRankingScore' => true,
        ]], array_map(static fn (array $b): array => array_intersect_key(
            $b,
            array_flip(['indexUid', 'q', 'filter', 'showRankingScore']),
        ), $client->bodies));
        $window = array_intersect_key($client->federation, ['limit' => 1, 'offset' => 1]);
        self::assertSame(['limit' => 10, 'offset' => 20], $window);
        self::assertSame('content_v2_entries_g1', $raw['hits'][0]['_index']);
        self::assertSame(0.9, $raw['hits'][0]['_score']);
    }

    public function testListingIndexesReadsEveryPage(): void
    {
        $client = new class ('http://meili.invalid', null, 'site_') extends MeilisearchClient {
            public function getIndexes(
                ?\Meilisearch\Contracts\IndexesQuery $options = null,
            ): \Meilisearch\Contracts\IndexesResults {
                $query = $options?->toArray() ?? [];
                $offset = (int) ($query['offset'] ?? 0);
                $limit = (int) ($query['limit'] ?? 20);
                $all = 2500;
                $results = [];
                for ($i = $offset; $i < min($all, $offset + $limit); $i++) {
                    $uid = 'site_content_v2_entries_g' . $i;
                    $results[] = new class ($uid) {
                        public function __construct(private string $uid)
                        {
                        }

                        public function getUid(): string
                        {
                            return $this->uid;
                        }
                    };
                }
                return new \Meilisearch\Contracts\IndexesResults(
                    ['results' => $results, 'offset' => $offset, 'limit' => $limit, 'total' => $all],
                );
            }
        };
        $index = new LiveMeilisearchIndex(new IndexManager($client, $this->appContext()));

        $names = $index->listIndexes('content_v2_entries_');
        self::assertCount(2500, $names);
        self::assertSame('content_v2_entries_g2499', $names[2499]);
    }
}
