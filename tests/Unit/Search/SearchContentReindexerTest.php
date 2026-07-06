<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use Thallo\Contracts\Schema\ContentSchemaReader;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Schema\FieldDescriptor;
use Thallo\Contracts\Search\IndexableContent;
use Thallo\Contracts\Search\IndexableContentReader;
use Thallo\Contracts\Search\IndexablePage;
use Thallo\Search\Engine\SearchBackend;
use Thallo\Search\Index\DocumentBuilder;
use Thallo\Search\Index\ResilientContentReindexer;
use Thallo\Search\Index\SearchContentReindexer;
use Thallo\Search\Query\SearchRequest;
use Thallo\Search\Query\SearchResults;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class SearchContentReindexerTest extends TestCase
{
    /** A SearchBackend spy recording upserts/deletes. */
    private function backend(): SearchBackend
    {
        return new class implements SearchBackend {
            /** @var list<array<string,mixed>> */
            public array $upserted = [];
            /** @var list<array{0:string,1:?string}> */
            public array $deletes = [];
            public bool $throwOnUpsert = false;
            public int $ensured = 0;
            public function ensureIndex(): void
            {
                $this->ensured++;
            }
            public function upsert(iterable $documents): void
            {
                if ($this->throwOnUpsert) {
                    throw new RuntimeException('meili down');
                }
                foreach ($documents as $d) {
                    $this->upserted[] = $d;
                }
            }
            public function deleteEntry(string $entryUuid, ?string $locale = null): void
            {
                $this->deletes[] = [$entryUuid, $locale];
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
    }

    private function reader(?IndexableContent $record): IndexableContentReader
    {
        return new class ($record) implements IndexableContentReader {
            public function __construct(private ?IndexableContent $record)
            {
            }
            public function getIndexablePublished(string $e, string $l): ?IndexableContent
            {
                return $this->record;
            }
            public function enumerateIndexablePublished(
                int $limit,
                int $offset = 0,
                ?string $t = null,
                ?string $l = null
            ): IndexablePage {
                return new IndexablePage([], $limit, $offset);
            }
        };
    }

    private function types(): ContentTypeReader
    {
        return new class implements ContentTypeReader {
            public function findUuidBySlug(string $slug): ?string
            {
                return null;
            }
            public function isPublicDelivery(string $uuid): bool
            {
                return true;
            }
            public function schemaFor(string $uuid): ?ContentSchemaReader
            {
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
            public function deliveryTypes(): array
            {
                return [];
            }
        };
    }

    private function record(): IndexableContent
    {
        return new IndexableContent('e-1', 'en', 'ct-1', 'blog', true, '/en/blog/x', 'x', ['title' => 'T']);
    }

    public function testNullLocaleDeletesAllEntryDocuments(): void
    {
        $backend = $this->backend();
        $r = new SearchContentReindexer($this->reader(null), new DocumentBuilder([]), $backend, $this->types());
        $r->reindexEntry('e-1', null);
        self::assertSame([['e-1', null]], $backend->deletes);
        self::assertSame([], $backend->upserted);
    }

    public function testMissingRecordForLocaleDeletesThatLocaleDocument(): void
    {
        $backend = $this->backend();
        $r = new SearchContentReindexer($this->reader(null), new DocumentBuilder([]), $backend, $this->types());
        $r->reindexEntry('e-1', 'en');
        self::assertSame([['e-1', 'en']], $backend->deletes);
        self::assertSame([], $backend->upserted);
    }

    public function testPresentRecordUpsertsBuiltDocument(): void
    {
        $backend = $this->backend();
        $r = new SearchContentReindexer(
            $this->reader($this->record()),
            new DocumentBuilder([]),
            $backend,
            $this->types(),
        );
        $r->reindexEntry('e-1', 'en');
        self::assertSame([], $backend->deletes);
        self::assertSame('e-1_en', $backend->upserted[0]['id']);
        // The event path must ensure the index (with settings) before its first upsert —
        // addDocuments would otherwise auto-create a settings-less index that rejects
        // every visibility-filtered search. Ensured once, not per upsert.
        self::assertSame(1, $backend->ensured);
        $r->reindexEntry('e-1', 'en');
        self::assertSame(1, $backend->ensured);
    }

    public function testResilientDecoratorSwallowsBackendFailures(): void
    {
        $backend = $this->backend();
        $backend->throwOnUpsert = true;
        $inner = new SearchContentReindexer(
            $this->reader($this->record()),
            new DocumentBuilder([]),
            $backend,
            $this->types(),
        );
        $resilient = new ResilientContentReindexer($inner, new NullLogger());

        // Must NOT throw — publishing must never break on a search failure.
        $resilient->reindexEntry('e-1', 'en');
        self::assertTrue(true);
    }
}
