<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Thallo\Contracts\Schema\ContentSchemaReader;
use Thallo\Contracts\Schema\FieldDescriptor;
use Thallo\Contracts\Search\IndexableContent;
use Thallo\Search\Engine\LiveMeilisearchIndex;
use Thallo\Search\Engine\MeilisearchBackend;
use Thallo\Search\Index\DocumentBuilder;
use Thallo\Search\Query\SearchRequest;

/**
 * Smoke test against a REAL Meilisearch server — the only place Meilisearch's actual
 * contract is exercised (document-id charset, filterable-attribute enforcement,
 * delete-by-filter). The unit suite fakes the index seam, which is exactly how three
 * ship-blocking contract bugs (invalid `:` ids, non-filterable entry_uuid purges,
 * settings-less auto-created index) survived it.
 *
 * Opt-in: set MEILISEARCH_SMOKE=1 with a reachable server (MEILISEARCH_HOST/config),
 * e.g. locally or in a docker-service CI job. Skipped otherwise.
 */
final class MeilisearchSmokeTest extends AppTestCase
{
    private const INDEX = 'app_smoke_test';

    private ?IndexManager $manager = null;
    private ?MeilisearchBackend $backend = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('MEILISEARCH_SMOKE') !== '1') {
            self::markTestSkipped('MEILISEARCH_SMOKE=1 not set (needs a running Meilisearch).');
        }
        if (!$this->container()->has(IndexManager::class)) {
            self::markTestSkipped('glueful/meilisearch extension is not enabled.');
        }

        $this->manager = $this->container()->get(IndexManager::class);
        $index = new LiveMeilisearchIndex($this->manager, self::INDEX);
        if (!$index->reachable()) {
            // getStats on a missing index throws too — probe the server itself.
            try {
                $this->manager->getClient()->health();
            } catch (\Throwable) {
                self::markTestSkipped('Meilisearch server is not reachable.');
            }
        }

        $this->backend = new MeilisearchBackend($index, 40);
    }

    protected function tearDown(): void
    {
        if ($this->manager !== null) {
            try {
                $task = $this->manager->deleteIndex(self::INDEX);
                $this->manager->waitForTask((int) $task['taskUid']);
            } catch (\Throwable) {
                // Index may not exist (skipped/failed early) — nothing to clean.
            }
        }
        parent::tearDown();
    }

    public function testIndexSearchAndDeleteAgainstRealMeilisearch(): void
    {
        $builder = new DocumentBuilder([]);
        $schema = $this->schema(['title' => 'string', 'body' => 'text']);

        $this->backend->ensureIndex();

        // Two locales of one entry + one other entry, matching real document shapes.
        $en = $this->content('e-smoke-1', 'en', 'ct-a', ['title' => 'Hello', 'body' => 'climate crisis text']);
        $fr = $this->content('e-smoke-1', 'fr', 'ct-a', ['title' => 'Bonjour', 'body' => 'texte crise climatique']);
        $other = $this->content('e-smoke-2', 'en', 'ct-b', ['title' => 'Other', 'body' => 'unrelated words']);
        $this->backend->upsert([
            $builder->build($en, $schema),
            $builder->build($fr, $schema),
            $builder->build($other, $schema),
        ]);
        $this->waitForDocuments(3);

        // Visibility-filtered search (locale + content_type_uuid IN) — proves the settings
        // ensureIndex applied are the ones search needs.
        $results = $this->backend->search(new SearchRequest('climate', 'en', null, false, ['ct-a'], 20, 0));
        self::assertSame(1, count($results->hits));
        self::assertSame('e-smoke-1', $results->hits[0]->entryUuid);

        // Per-locale delete (validates the document-id charset end-to-end).
        $this->backend->deleteEntry('e-smoke-1', 'fr');
        $this->waitForDocuments(2);

        // Whole-entry purge via delete-by-filter (validates entry_uuid is filterable).
        $this->backend->deleteEntry('e-smoke-1', null);
        $this->waitForDocuments(1);

        $rest = $this->backend->search(new SearchRequest('words', 'en', null, true, [], 20, 0));
        self::assertSame('e-smoke-2', $rest->hits[0]->entryUuid);
    }

    /** Meilisearch writes are async tasks — poll the doc count instead of sleeping blind. */
    private function waitForDocuments(int $expected, float $timeoutSeconds = 10.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $stats = $this->manager->getStats(self::INDEX);
            $count = (int) ($stats['numberOfDocuments'] ?? -1);
            if ($count === $expected && !($stats['isIndexing'] ?? false)) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail(sprintf(
            'Index never reached %d documents within %.0fs (last count: %d) — a write task '
            . 'likely failed asynchronously (invalid id? non-filterable delete filter?).',
            $expected,
            $timeoutSeconds,
            $count ?? -1,
        ));
    }

    /** @param array<string,mixed> $fields */
    private function content(string $uuid, string $locale, string $typeUuid, array $fields): IndexableContent
    {
        return new IndexableContent(
            entryUuid: $uuid,
            locale: $locale,
            contentTypeUuid: $typeUuid,
            contentTypeSlug: 'smoke',
            publicDelivery: true,
            href: "/{$locale}/smoke/x",
            entryLabel: 'x',
            fields: $fields,
        );
    }

    /** @param array<string,string> $fieldTypes */
    private function schema(array $fieldTypes): ContentSchemaReader
    {
        $fields = [];
        foreach ($fieldTypes as $name => $type) {
            $fields[$name] = new class ($name, $type) implements FieldDescriptor {
                public function __construct(private string $n, private string $t)
                {
                }
                public function name(): string
                {
                    return $this->n;
                }
                public function type(): string
                {
                    return $this->t;
                }
                public function isMultiple(): bool
                {
                    return false;
                }
                public function referenceType(): ?string
                {
                    return null;
                }
                public function referenceSlugField(): ?string
                {
                    return null;
                }
                public function format(): ?string
                {
                    return null;
                }
            };
        }
        return new class ($fields) implements ContentSchemaReader {
            /** @param array<string,FieldDescriptor> $fields */
            public function __construct(private array $fields)
            {
            }
            public function fields(): array
            {
                return array_values($this->fields);
            }
            public function field(string $name): ?FieldDescriptor
            {
                return $this->fields[$name] ?? null;
            }
        };
    }
}
