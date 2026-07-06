<?php

declare(strict_types=1);

namespace App\Tests\Integration\ImportExport;

use App\Content\ImportExport\ContentImporter;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;

/**
 * Proves that a multi-valued reference field (fields.category = uuid array)
 * survives an export→import round-trip verbatim, order preserved.
 *
 * Mirrors the setup established in ContentImporterTest: write a bundle to
 * a temp NDJSON file, seed an import_export_jobs + import_export_files row,
 * then drive ContentImporter::process() in dry_run (no writes) and in
 * commit (upsert) mode.  The entry_draft's fields.category must equal the
 * original uuid array in the same order after the commit pass.
 */
final class MultiValueReferenceRoundTripTest extends AppTestCase
{
    /** Two category UUIDs that travel as an ordered array through the bundle. */
    private const CATEGORY_A = 'catA0000001';
    private const CATEGORY_B = 'catB0000002';

    public function testMultiValueReferenceArraySurvivesExportImport(): void
    {
        $expectedArray = [self::CATEGORY_A, self::CATEGORY_B];

        $path = $this->writeBundle($this->bundleRecords($expectedArray));
        $this->seedImportJob($path);

        // Dry-run: validates the bundle but must not persist anything.
        $dryRun = $this->importer()->process(
            new ImportBatch('batch0000001', 'job000000001', 1, 0, 20),
            new ImportContext($this->appContext(), 'job000000001', 'dry_run')
        );

        self::assertSame(7, $dryRun->processedRecords, json_encode($dryRun->errors, JSON_PRETTY_PRINT));
        self::assertSame(0, $dryRun->failedRecords, json_encode($dryRun->errors, JSON_PRETTY_PRINT));
        self::assertSame(0, $this->connection()->table('content_types')->count());
        self::assertSame(0, $this->connection()->table('entries')->count());

        // Commit: upsert every record.
        $commit = $this->importer()->process(
            new ImportBatch('batch0000002', 'job000000001', 2, 0, 20),
            new ImportContext($this->appContext(), 'job000000001', 'commit')
        );

        self::assertSame(7, $commit->processedRecords, json_encode($commit->errors, JSON_PRETTY_PRINT));
        self::assertSame(0, $commit->failedRecords, json_encode($commit->errors, JSON_PRETTY_PRINT));

        // Assert the draft's fields.category is the same uuid array, order preserved.
        $draft = $this->connection()->table('entry_drafts')->first();
        self::assertNotNull($draft, 'entry_drafts must contain one row after commit');

        $fields = json_decode((string) $draft['fields'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fields);
        self::assertArrayHasKey('category', $fields, 'fields.category must survive the round-trip');
        self::assertSame(
            $expectedArray,
            $fields['category'],
            'multi-valued reference array must be identical and order-preserved after import'
        );

        // Also assert the version carries the same array.
        $version = $this->connection()->table('entry_versions')->first();
        self::assertNotNull($version, 'entry_versions must contain one row after commit');

        $versionFields = json_decode((string) $version['fields'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($versionFields);
        self::assertArrayHasKey('category', $versionFields, 'version fields.category must survive the round-trip');
        self::assertSame(
            $expectedArray,
            $versionFields['category'],
            'version multi-valued reference array must be identical and order-preserved after import'
        );
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
        $path = $dir . '/lemma-multival-ref.ndjson';
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
            'source_path' => 'imports/lemma-multival-ref.ndjson',
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
     * Build a Lemma content NDJSON bundle whose entry has a `category` field
     * carrying a multi-valued reference array (two uuid strings, ordered).
     *
     * The bundle intentionally omits `entry_route` and `asset_manifest` because
     * multi-valued *reference* fields point to other entries, not blobs.
     *
     * @param list<string> $categoryUuids
     * @return list<array<string,mixed>>
     */
    private function bundleRecords(array $categoryUuids): array
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
                        [
                            'name' => 'category',
                            'type' => 'reference',
                            'multiple' => true,
                            'filterable' => true,
                            'reference_type' => 'category',
                            'reference_slug_field' => 'slug',
                        ],
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
                    'fields' => ['title' => 'Multi-cat post', 'category' => $categoryUuids],
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
                    'fields' => ['title' => 'Multi-cat post', 'category' => $categoryUuids],
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
                'kind' => 'entry_reference',
                'data' => [
                    'source_entry_uuid' => 'entry0000001',
                    'source_field' => 'category',
                    'target_entry_uuid' => self::CATEGORY_A,
                ],
            ],
            [
                'kind' => 'entry_reference',
                'data' => [
                    'source_entry_uuid' => 'entry0000001',
                    'source_field' => 'category',
                    'target_entry_uuid' => self::CATEGORY_B,
                ],
            ],
        ];
    }
}
