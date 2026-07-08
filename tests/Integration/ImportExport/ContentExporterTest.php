<?php

declare(strict_types=1);

namespace App\Tests\Integration\ImportExport;

use App\Content\ImportExport\ContentExporter;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\ImportExport\Support\ExportBatch;
use Glueful\Extensions\ImportExport\Support\ExportContext;
use Glueful\Extensions\ImportExport\Support\ExportOptions;

final class ContentExporterTest extends AppTestCase
{
    public function testPlansOneDeterministicBatchPerRequestedWindow(): void
    {
        $this->seedPublishedEntry();

        $plan = $this->exporter()->plan(new ExportOptions(batchSize: 2));

        self::assertSame(8, $plan->totalRecords);
        self::assertCount(4, $plan->batches);
        self::assertFalse($plan->retryable);
        self::assertSame(0, $plan->batches[0]->offset);
        self::assertSame(2, $plan->batches[0]->limit);
        self::assertSame(6, $plan->batches[3]->offset);
        self::assertSame(2, $plan->batches[3]->limit);
    }

    public function testProcessWritesContentNdjsonBundle(): void
    {
        $this->seedPublishedEntry();

        $result = $this->exporter()->process(
            new ExportBatch('batch0000001', 'job000000001', 1, 0, 20),
            new ExportContext($this->appContext(), 'job000000001', 'ndjson')
        );

        self::assertSame(8, $result->processedRecords);
        self::assertSame(0, $result->failedRecords);
        self::assertNotNull($result->resultPath);

        $path = $this->appContext()->getBasePath() . '/storage/' . $result->resultPath;
        self::assertFileExists($path);

        $records = array_map(
            static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))))
        );

        self::assertSame([
            'content_type',
            'entry',
            'entry_draft',
            'entry_version',
            'entry_publication',
            'entry_route',
            'entry_reference',
            'asset_manifest',
        ], array_column($records, 'kind'));
        self::assertSame('post', $records[0]['data']['slug']);
        self::assertSame('hello-world', $records[5]['data']['slug']);
        self::assertSame('hero_image', $records[6]['data']['source_field']);
        self::assertSame('blob00000001', $records[7]['data']['uuid']);
        self::assertSame('/uploads/blob00000001.jpg', $records[7]['data']['fetch_path']);
    }

    public function testWindowedBatchesReassembleToTheFullOrderedBundle(): void
    {
        $this->seedPublishedEntry();

        // Export in 2-record windows across 4 batches; the concatenation must equal the full,
        // single-batch bundle in the same order (windowed reads must not drop/duplicate/reorder).
        $exporter = $this->exporter();
        $ctx = new ExportContext($this->appContext(), 'job000000002', 'ndjson');
        $assembled = [];
        foreach ([0, 2, 4, 6] as $seq => $offset) {
            $result = $exporter->process(
                new ExportBatch('batchwin' . $seq, 'job000000002', $seq + 1, $offset, 2),
                $ctx,
            );
            $path = $this->appContext()->getBasePath() . '/storage/' . $result->resultPath;
            foreach (array_values(array_filter(explode("\n", trim((string) file_get_contents($path))))) as $line) {
                $assembled[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        self::assertSame([
            'content_type',
            'entry',
            'entry_draft',
            'entry_version',
            'entry_publication',
            'entry_route',
            'entry_reference',
            'asset_manifest',
        ], array_column($assembled, 'kind'));
        self::assertSame('post', $assembled[0]['data']['slug']);
        self::assertSame('hello-world', $assembled[5]['data']['slug']);
        self::assertSame('hero_image', $assembled[6]['data']['source_field']);
        self::assertSame('blob00000001', $assembled[7]['data']['uuid']);
    }

    private function exporter(): ContentExporter
    {
        return new ContentExporter($this->appContext(), $this->connection());
    }

    private function seedPublishedEntry(): void
    {
        $this->connection()->getPDO()->prepare("DELETE FROM blobs WHERE uuid = 'blob00000001'")->execute();
        $this->connection()->table('blobs')->insert([
            'uuid' => 'blob00000001',
            'name' => 'hero.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 123,
            'url' => '/uploads/blob00000001.jpg',
            'storage_type' => 'uploads',
            'visibility' => 'private',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => '2026-06-16 00:00:00',
        ]);

        $type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'hero_image', 'type' => 'asset'],
            ],
        ]);

        $entries = new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', [
            'title' => 'Hello world',
            'hero_image' => 'blob00000001',
        ], 1, 0, 'user00000001');

        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');

        (new RouteRepository($this->connection()))->assign($entry, $type, 'en', 'hello-world');
    }
}
