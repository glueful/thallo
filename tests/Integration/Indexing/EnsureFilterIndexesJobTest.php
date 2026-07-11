<?php

declare(strict_types=1);

namespace App\Tests\Integration\Indexing;

use App\Content\Indexing\EnsureFilterIndexesJob;
use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\AppTestCase;

final class EnsureFilterIndexesJobTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The registry table lives outside AppTestCase's truncate set; clear it per test.
        $this->connection()->table('filter_indexes')->where('id', '>', 0)->delete();
    }

    /** Drop any expression indexes a run may have created so reruns stay clean. */
    protected function tearDown(): void
    {
        $rows = $this->connection()->table('filter_indexes')->select(['index_name'])->get();
        foreach ($rows as $row) {
            $name = (string) $row['index_name'];
            if (preg_match('/\A[a-z0-9_]+\z/', $name) === 1) {
                $this->connection()->getPDO()->exec("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
        }
        $this->connection()->table('filter_indexes')->where('id', '>', 0)->delete();
        parent::tearDown();
    }

    private function createType(array $schema): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'product',
            'name' => 'Product',
            'schema' => $schema,
        ]);
    }

    private function updateSchema(string $uuid, array $schema): void
    {
        (new ContentTypeRepository($this->connection()))->updateSchema($uuid, $schema);
    }

    private function runJob(string $typeUuid): void
    {
        // tenant_uuid => null is the tenancy-off (explicit-null) payload the dispatcher pushes when no
        // tenant is resolved; handle()'s closed shape requires the key to be present.
        $job = new EnsureFilterIndexesJob(
            ['content_type_uuid' => $typeUuid, 'tenant_uuid' => null],
            $this->appContext(),
        );
        $job->handle();
    }

    /** @return array{name:string,def:string}|null */
    private function findExpressionIndex(string $expressionFragment): ?array
    {
        $rows = $this->connection()->table('pg_indexes')
            ->select(['indexname', 'indexdef'])
            ->where('tablename', '=', 'entry_versions')
            ->get();
        foreach ($rows as $row) {
            if (str_contains((string) $row['indexdef'], $expressionFragment)) {
                return ['name' => (string) $row['indexname'], 'def' => (string) $row['indexdef']];
            }
        }
        return null;
    }

    public function testCreatesExpressionIndexAndRecordsRegistry(): void
    {
        $type = $this->createType([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);

        $this->runJob($type);

        $idx = $this->findExpressionIndex("(fields ->> 'price'::text))::numeric");
        self::assertNotNull($idx, 'expected a numeric expression index on (fields->>price) on entry_versions');

        $reg = $this->connection()->table('filter_indexes')
            ->where('content_type_uuid', '=', $type)
            ->where('field', '=', 'price')
            ->first();
        self::assertNotNull($reg);
        self::assertSame('ready', $reg['status']);
        self::assertSame($idx['name'], $reg['index_name']);
    }

    public function testCreatesDatetimeExpressionIndexAsReady(): void
    {
        // Regression: datetime indexes formerly cast to ::timestamptz, which is not
        // IMMUTABLE, so CREATE INDEX always failed and the field was silently
        // unfilterable (registry row left `failed`). The planner now uses the IMMUTABLE
        // text expression `((fields ->> 'field'))`, which CREATE INDEX accepts.
        $type = $this->createType([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'published_at', 'type' => 'datetime', 'filterable' => true, 'filter_type' => 'datetime'],
        ]);

        $this->runJob($type);

        // Text expression renders in pg_indexes.indexdef as (fields ->> 'published_at'::text)
        // with no trailing cast (contrast the numeric case's ...::numeric).
        $idx = $this->findExpressionIndex("(fields ->> 'published_at'::text))");
        self::assertNotNull($idx, 'expected a text expression index on (fields->>published_at) on entry_versions');
        self::assertStringNotContainsString('timestamptz', $idx['def'], 'datetime index must not cast to timestamptz');

        $reg = $this->connection()->table('filter_indexes')
            ->where('content_type_uuid', '=', $type)
            ->where('field', '=', 'published_at')
            ->first();
        self::assertNotNull($reg);
        self::assertSame('ready', $reg['status'], 'datetime index must build (was failing as not-IMMUTABLE)');
        self::assertSame($idx['name'], $reg['index_name']);
    }

    public function testRerunIsIdempotent(): void
    {
        $type = $this->createType([
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);

        $this->runJob($type);
        $this->runJob($type); // must not error or duplicate

        $count = $this->connection()->table('filter_indexes')
            ->where('content_type_uuid', '=', $type)
            ->count();
        self::assertSame(1, $count);
        self::assertNotNull($this->findExpressionIndex("(fields ->> 'price'::text))::numeric"));
    }

    public function testFailedConcurrentBuildIsNotFlippedToReadyOnRetry(): void
    {
        // A row whose `price` is non-numeric makes ((fields->>'price')::numeric) fail to build,
        // leaving an INVALID index behind. Previously the retry's CREATE INDEX ... IF NOT EXISTS
        // no-opped over that dead index without error and the registry was flipped to 'ready' — a
        // silent seq-scan. It must stay 'failed' until a valid index actually builds.
        $type = $this->createType([
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);
        $this->connection()->table('entry_versions')->insert([
            'uuid' => 'badver00001',
            'entry_uuid' => 'badentry0001',
            'locale' => 'en',
            'version' => 1,
            'fields' => json_encode(['price' => 'not-a-number'], JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'created_by' => 'user00000001',
            'created_at' => '2026-06-16 00:00:00',
        ]);

        try {
            $this->runJob($type); // first build fails → 'failed', invalid index left behind
            $this->runJob($type); // retry must drop the invalid index and rebuild, not mark 'ready'

            $reg = $this->connection()->table('filter_indexes')
                ->where('content_type_uuid', '=', $type)
                ->where('field', '=', 'price')
                ->first();
            self::assertNotNull($reg);
            self::assertSame('failed', $reg['status'], 'a never-valid index must never be reported ready');
        } finally {
            // Remove the poison row so it can't break other tests' index builds on entry_versions.
            $this->connection()->table('entry_versions')->where('uuid', '=', 'badver00001')->delete();
        }
    }

    public function testRemovingFilterableFieldDropsIndexAndRegistry(): void
    {
        $type = $this->createType([
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);
        $this->runJob($type);
        self::assertNotNull($this->findExpressionIndex("(fields ->> 'price'::text))::numeric"));

        // price is no longer filterable.
        $this->updateSchema($type, [
            ['name' => 'price', 'type' => 'number'],
        ]);
        $this->runJob($type);

        self::assertNull(
            $this->findExpressionIndex("(fields ->> 'price'::text))::numeric"),
            'index should be dropped once the field is no longer filterable'
        );
        $count = $this->connection()->table('filter_indexes')
            ->where('content_type_uuid', '=', $type)
            ->count();
        self::assertSame(0, $count);
    }
}
