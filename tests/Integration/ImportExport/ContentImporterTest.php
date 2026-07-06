<?php

declare(strict_types=1);

namespace App\Tests\Integration\ImportExport;

use App\Content\ImportExport\ContentImporter;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;

final class ContentImporterTest extends AppTestCase
{
    public function testSupportsAndPlansContentNdjsonSource(): void
    {
        $path = $this->writeBundle($this->bundleRecords());
        $importer = $this->importer();
        $source = new ImportSource('storage', $path, 'application/x-ndjson');

        self::assertTrue($importer->supports($source));

        $plan = $importer->plan($source, new ImportOptions(batchSize: 2));

        self::assertSame(8, $plan->totalRecords);
        self::assertCount(4, $plan->batches);
        self::assertTrue($plan->retryable);
        self::assertSame(0, $plan->batches[0]->offset);
        self::assertSame(2, $plan->batches[0]->limit);
    }

    public function testDryRunValidatesBundleWithoutWritingRows(): void
    {
        $path = $this->writeBundle($this->bundleRecords());
        $this->seedImportJob($path);

        $result = $this->importer()->process(
            new ImportBatch('batch0000001', 'job000000001', 1, 0, 20),
            new ImportContext($this->appContext(), 'job000000001', 'dry_run')
        );

        self::assertSame(8, $result->processedRecords, json_encode($result->errors, JSON_PRETTY_PRINT));
        self::assertSame(0, $result->failedRecords, json_encode($result->errors, JSON_PRETTY_PRINT));
        self::assertSame(0, $this->connection()->table('content_types')->count());
        self::assertSame(0, $this->connection()->table('entries')->count());
    }

    public function testCommitUpsertsContentBundle(): void
    {
        $path = $this->writeBundle($this->bundleRecords());
        $this->seedImportJob($path);

        $result = $this->importer()->process(
            new ImportBatch('batch0000001', 'job000000001', 1, 0, 20),
            new ImportContext($this->appContext(), 'job000000001', 'commit')
        );

        self::assertSame(8, $result->processedRecords);
        self::assertSame(0, $result->failedRecords);
        self::assertSame('post', $this->connection()->table('content_types')->first()['slug']);
        self::assertSame('entry0000001', $this->connection()->table('entries')->first()['uuid']);
        self::assertSame('Hello world', json_decode(
            (string) $this->connection()->table('entry_drafts')->first()['fields'],
            true,
            flags: JSON_THROW_ON_ERROR
        )['title']);
        self::assertSame('hello-world', $this->connection()->table('entry_routes')->first()['slug']);
        self::assertSame('hero_image', $this->connection()->table('entry_references')->first()['source_field']);
        self::assertSame('blob00000001', $this->connection()->table('blobs')
            ->where('uuid', '=', 'blob00000001')
            ->first()['uuid']);

        $second = $this->importer()->process(
            new ImportBatch('batch0000002', 'job000000001', 2, 0, 20),
            new ImportContext($this->appContext(), 'job000000001', 'commit')
        );

        self::assertSame(8, $second->processedRecords);
        self::assertSame(1, $this->connection()->table('content_types')->count());
        self::assertSame(1, $this->connection()->table('entries')->count());
        self::assertSame(1, $this->connection()->table('entry_routes')->count());
    }

    public function testAssetManifestUpsertRollsBackOnFailedInsertPreservingLiveBlob(): void
    {
        // Clear any residue from a prior run (blobs is not auto-truncated between tests).
        $this->connection()->table('blobs')->where('uuid', '=', 'blobrbk00001')->forceDelete();
        // A live, already-uploaded blob whose metadata a re-import will try to replace.
        $this->connection()->table('blobs')->insert([
            'uuid' => 'blobrbk00001',
            'name' => 'hero.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 123,
            'url' => '/uploads/blobrbk00001.jpg',
            'storage_type' => 'local',
            'visibility' => 'private',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => '2026-06-16 00:00:00',
        ]);

        // Re-import the same uuid with a malformed asset_manifest row (a non-numeric value for the
        // bigint `size` column) so the insert fails. The forceDelete+insert must be atomic: the
        // live metadata must survive an unsuccessful re-import.
        $importer = $this->importer();
        $upsert = new \ReflectionMethod($importer, 'upsert');
        $upsert->setAccessible(true);
        try {
            $upsert->invoke($importer, 'asset_manifest', [
                'uuid' => 'blobrbk00001',
                'name' => 'replacement.jpg',
                'size' => 'not-an-integer',
            ]);
            self::fail('expected the malformed asset_manifest insert to throw');
        } catch (\Throwable) {
            // expected — 'not-an-integer' is not a valid bigint for the `size` column.
        }

        $survivor = $this->connection()->table('blobs')->where('uuid', '=', 'blobrbk00001')->first();
        self::assertNotNull($survivor, 'live blob metadata must survive a failed re-import');
        self::assertSame('hero.jpg', $survivor['name']);
        self::assertSame('/uploads/blobrbk00001.jpg', $survivor['url']);
    }

    private function importer(): ContentImporter
    {
        return new ContentImporter($this->appContext(), $this->connection());
    }

    /**
     * @param list<array<string,mixed>> $records
     */
    private function writeBundle(array $records): string
    {
        $dir = sys_get_temp_dir() . '/lemma-import-export-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $path = $dir . '/lemma-content.ndjson';
        file_put_contents($path, implode("\n", array_map(
            static fn(array $record): string => json_encode($record, JSON_THROW_ON_ERROR),
            $records
        )) . "\n");

        return $path;
    }

    private function seedImportJob(string $absolutePath): void
    {
        $this->connection()->table('import_export_jobs')->insert([
            'uuid' => 'job000000001',
            'type' => 'import',
            'adapter' => 'lemma.content',
            'status' => 'queued',
            'mode' => 'commit',
            'source_disk' => 'storage',
            'source_path' => 'imports/lemma-content.ndjson',
            'total_records' => 7,
            'created_at' => '2026-06-16 00:00:00',
            'updated_at' => '2026-06-16 00:00:00',
        ]);
        $this->connection()->table('import_export_files')->insert([
            'uuid' => 'file00000001',
            'job_uuid' => 'job000000001',
            'role' => 'source',
            'disk' => 'storage',
            'path' => $absolutePath,
            'mime_type' => 'application/x-ndjson',
            'size_bytes' => filesize($absolutePath) ?: 0,
            'created_at' => '2026-06-16 00:00:00',
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function bundleRecords(): array
    {
        return [
            [
                'kind' => 'content_type',
                'data' => [
                    'uuid' => 'type00000001',
                    'slug' => 'post',
                    'name' => 'Post',
                    'description' => null,
                    'cache_ttl' => 120,
                    'status' => 'active',
                    'schema' => [
                        ['name' => 'title', 'type' => 'string', 'required' => true],
                        ['name' => 'hero_image', 'type' => 'asset'],
                    ],
                    'schema_version' => 1,
                    'created_by' => 'user00000001',
                    'created_at' => '2026-06-16 00:00:00',
                    'updated_at' => '2026-06-16 00:00:00',
                ],
            ],
            [
                'kind' => 'entry',
                'data' => [
                    'uuid' => 'entry0000001',
                    'content_type_uuid' => 'type00000001',
                    'status' => 'active',
                    'created_by' => 'user00000001',
                    'created_at' => '2026-06-16 00:00:00',
                    'updated_at' => '2026-06-16 00:00:00',
                ],
            ],
            [
                'kind' => 'entry_draft',
                'data' => [
                    'entry_uuid' => 'entry0000001',
                    'locale' => 'en',
                    'fields' => ['title' => 'Hello world', 'hero_image' => 'blob00000001'],
                    'schema_version' => 1,
                    'lock_version' => 1,
                    'updated_by' => 'user00000001',
                    'updated_at' => '2026-06-16 00:00:00',
                ],
            ],
            [
                'kind' => 'entry_version',
                'data' => [
                    'uuid' => 'vers00000001',
                    'entry_uuid' => 'entry0000001',
                    'locale' => 'en',
                    'version' => 1,
                    'fields' => ['title' => 'Hello world', 'hero_image' => 'blob00000001'],
                    'schema_version' => 1,
                    'created_by' => 'user00000001',
                    'created_at' => '2026-06-16 00:00:00',
                ],
            ],
            [
                'kind' => 'entry_publication',
                'data' => [
                    'entry_uuid' => 'entry0000001',
                    'locale' => 'en',
                    'version_uuid' => 'vers00000001',
                    'published_by' => 'user00000001',
                    'published_at' => '2026-06-16 00:00:00',
                ],
            ],
            [
                'kind' => 'entry_route',
                'data' => [
                    'entry_uuid' => 'entry0000001',
                    'content_type_uuid' => 'type00000001',
                    'locale' => 'en',
                    'slug' => 'hello-world',
                ],
            ],
            [
                'kind' => 'entry_reference',
                'data' => [
                    'source_entry_uuid' => 'entry0000001',
                    'source_field' => 'hero_image',
                    'target_entry_uuid' => 'blob00000001',
                ],
            ],
            [
                'kind' => 'asset_manifest',
                'data' => [
                    'uuid' => 'blob00000001',
                    'name' => 'hero.jpg',
                    'description' => null,
                    'mime_type' => 'image/jpeg',
                    'size' => 123,
                    'url' => '/uploads/blob00000001.jpg',
                    'fetch_path' => '/uploads/blob00000001.jpg',
                    'storage_type' => 'local',
                    'visibility' => 'private',
                    'status' => 'active',
                    'created_by' => 'user00000001',
                    'created_at' => '2026-06-16 00:00:00',
                    'updated_at' => null,
                    'deleted_at' => null,
                ],
            ],
        ];
    }
}
