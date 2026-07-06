<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use Thallo\Contracts\Schema\ContentSchemaReader;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Schema\FieldDescriptor;
use Thallo\Contracts\Search\IndexableContent;
use Thallo\Contracts\Search\IndexableContentReader;
use Thallo\Contracts\Search\IndexablePage;
use Thallo\Search\Console\ReindexCommand;
use Thallo\Search\Engine\SearchBackend;
use Thallo\Search\Index\DocumentBuilder;
use Thallo\Search\Query\SearchRequest;
use Thallo\Search\Query\SearchResults;
use PHPUnit\Framework\TestCase;

final class ReindexCommandTest extends TestCase
{
    public function testBackfillEnsuresIndexPagesAndUpsertsAllRecords(): void
    {
        $records = [
            new IndexableContent('e-1', 'en', 'ct-1', 'blog', true, '/en/blog/a', 'a', ['title' => 'A']),
            new IndexableContent('e-2', 'en', 'ct-1', 'blog', true, '/en/blog/b', 'b', ['title' => 'B']),
            new IndexableContent('e-3', 'en', 'ct-1', 'blog', true, '/en/blog/c', 'c', ['title' => 'C']),
        ];

        // Reader pages the records (limit/offset); total 3.
        $reader = new class ($records) implements IndexableContentReader {
            /** @param list<IndexableContent> $records */
            public function __construct(private array $records)
            {
            }
            public function getIndexablePublished(string $e, string $l): ?IndexableContent
            {
                return null;
            }
            public function enumerateIndexablePublished(
                int $limit,
                int $offset = 0,
                ?string $t = null,
                ?string $l = null
            ): IndexablePage {
                $slice = array_slice($this->records, $offset, $limit);
                return new IndexablePage(array_values($slice), $limit, $offset);
            }
        };

        $backend = new class implements SearchBackend {
            public bool $ensured = false;
            /** @var list<array<string,mixed>> */
            public array $upserted = [];
            public function ensureIndex(): void
            {
                $this->ensured = true;
            }
            public function upsert(iterable $documents): void
            {
                foreach ($documents as $d) {
                    $this->upserted[] = $d;
                }
            }
            public function deleteEntry(string $e, ?string $l = null): void
            {
            }
            public function search(SearchRequest $r): SearchResults
            {
                return new SearchResults([], 0, 20, 0);
            }
            public function health(): bool
            {
                return true;
            }
        };

        $types = new class implements ContentTypeReader {
            public int $schemaLookups = 0;
            public function findUuidBySlug(string $slug): ?string
            {
                return 'ct-1';
            }
            public function isPublicDelivery(string $uuid): bool
            {
                return true;
            }
            public function deliveryTypes(): array
            {
                return ['ct-1' => ['slug' => 'blog', 'public_delivery' => true]];
            }
            public function schemaFor(string $uuid): ?ContentSchemaReader
            {
                $this->schemaLookups++;
                return new class implements ContentSchemaReader {
                    public function fields(): array
                    {
                        return [];
                    }
                    public function field(string $name): ?FieldDescriptor
                    {
                        return null;
                    }
                };
            }
        };

        $cmd = new ReindexCommand($reader, new DocumentBuilder([]), $backend, $types);
        $count = $cmd->backfill(type: null, locale: null, pageSize: 2);

        self::assertTrue($backend->ensured);
        self::assertSame(3, $count);
        self::assertCount(3, $backend->upserted);
        // schemaFor runs an uncached DB query per call — backfill must memoize per type
        // (3 records, 1 type → 1 lookup), not look up per record.
        self::assertSame(1, $types->schemaLookups);
    }
}
