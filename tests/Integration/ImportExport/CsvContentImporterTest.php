<?php

declare(strict_types=1);

namespace App\Tests\Integration\ImportExport;

use App\Capabilities\DefaultCapabilityRegistry;
use Thallo\Importers\CsvContentImporter;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Glueful\Http\Exceptions\Client\ForbiddenException;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Schema\ContentTypeReader;

final class CsvContentImporterTest extends AppTestCase
{
    /** @var array<string,mixed> */
    private const OPTIONS = [
        'content_type' => 'post',
        'mapping' => ['title' => 'Title', 'views' => 'Views', 'featured' => 'Featured'],
        'locale' => 'en',
    ];

    public function testSupportsAndPlansCsv(): void
    {
        $this->seedType();
        $path = $this->writeCsv("Title,Views,Featured\nHello,42,true\nWorld,7,false\n");
        $source = new ImportSource('storage', $path, 'text/csv');

        self::assertTrue($this->importer()->supports($source));

        $plan = $this->importer()->plan($source, new ImportOptions(batchSize: 1, options: self::OPTIONS));
        self::assertSame(2, $plan->totalRecords);
        self::assertCount(2, $plan->batches);
        self::assertTrue($plan->retryable);
    }

    public function testPlanRejectsAMappedColumnMissingFromTheCsv(): void
    {
        $this->seedType();
        $path = $this->writeCsv("Title,Views\nHello,42\n"); // no "Featured" column
        $this->expectExceptionMessageMatches('/Featured/');
        $this->importer()->plan(
            new ImportSource('storage', $path, 'text/csv'),
            new ImportOptions(options: self::OPTIONS),
        );
    }

    public function testDryRunValidatesWithoutWriting(): void
    {
        $this->seedType();
        $this->seedJob($this->writeCsv("Title,Views,Featured\nHello,42,true\nWorld,7,false\n"));

        $result = $this->importer()->process(
            new ImportBatch('batchcsv0001', 'jobcsv000001', 1, 0, 20),
            new ImportContext($this->appContext(), 'jobcsv000001', 'dry_run', null, self::OPTIONS),
        );

        self::assertSame(2, $result->processedRecords, json_encode($result->errors));
        self::assertSame(0, $result->failedRecords, json_encode($result->errors));
        self::assertSame(0, $this->connection()->table('entries')->count());
    }

    public function testCommitCreatesEntriesWithCoercedFields(): void
    {
        $this->seedType();
        $this->seedJob($this->writeCsv("Title,Views,Featured\nHello,42,true\nWorld,7,false\n"));

        $result = $this->importer()->process(
            new ImportBatch('batchcsv0001', 'jobcsv000001', 1, 0, 20),
            new ImportContext($this->appContext(), 'jobcsv000001', 'commit', null, self::OPTIONS),
        );

        self::assertSame(2, $result->processedRecords, json_encode($result->errors));
        self::assertSame(0, $result->failedRecords, json_encode($result->errors));
        self::assertSame(2, $this->connection()->table('entries')->count());

        $draft = $this->connection()->table('entry_drafts')->orderBy('id')->first();
        $fields = json_decode((string) $draft['fields'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Hello', $fields['title']);
        self::assertSame(42, $fields['views']); // CSV "42" coerced to int
        self::assertTrue($fields['featured']); // CSV "true" coerced to bool
    }

    public function testAnInvalidRowIsReportedAndDoesNotAbortTheBatch(): void
    {
        $this->seedType();
        // Row 2's Views is non-numeric → it fails validation; row 1 still imports.
        $this->seedJob($this->writeCsv("Title,Views,Featured\nGood,1,true\nBad,lots,false\n"));

        $result = $this->importer()->process(
            new ImportBatch('batchcsv0001', 'jobcsv000001', 1, 0, 20),
            new ImportContext($this->appContext(), 'jobcsv000001', 'commit', null, self::OPTIONS),
        );

        self::assertSame(1, $result->processedRecords);
        self::assertSame(1, $result->failedRecords);
        self::assertSame(2, $result->errors[0]['record_number']);
        self::assertSame(1, $this->connection()->table('entries')->count());
    }

    public function testProcessFailsClosedWhenTheCapabilityIsDisabled(): void
    {
        // A job retried after thallo.importers was disabled must not run its remaining batches.
        $disabled = new DefaultCapabilityRegistry(['thallo.importers' => false]);
        $disabled->register(new Capability('thallo.importers'));

        $importer = new CsvContentImporter(
            $this->appContext(),
            $this->connection(),
            $this->container()->get(ContentWriter::class),
            $this->container()->get(ContentTypeReader::class),
            $disabled,
        );

        $this->expectException(ForbiddenException::class);
        $importer->process(
            new ImportBatch('batchcsv0001', 'jobcsv000001', 1, 0, 20),
            new ImportContext($this->appContext(), 'jobcsv000001', 'commit', null, self::OPTIONS),
        );
    }

    private function importer(): CsvContentImporter
    {
        return $this->container()->get(CsvContentImporter::class);
    }

    private function writeCsv(string $contents): string
    {
        $dir = sys_get_temp_dir() . '/lemma-csv-import-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $path = $dir . '/data-' . bin2hex(random_bytes(6)) . '.csv';
        file_put_contents($path, $contents);

        return $path;
    }

    private function seedType(): void
    {
        $this->connection()->table('content_types')->insert([
            'uuid' => 'typecsv00001',
            'slug' => 'post',
            'name' => 'Post',
            'description' => null,
            'cache_ttl' => null,
            'public_delivery' => false,
            'status' => 'active',
            'schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'views', 'type' => 'number'],
                ['name' => 'featured', 'type' => 'boolean'],
            ], JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'created_by' => null,
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
    }

    private function seedJob(string $absolutePath): void
    {
        $this->connection()->table('import_export_jobs')->insert([
            'uuid' => 'jobcsv000001',
            'type' => 'import',
            'adapter' => 'csv.content',
            'status' => 'queued',
            'mode' => 'commit',
            'source_disk' => 'storage',
            'source_path' => $absolutePath,
            'total_records' => 2,
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
        $this->connection()->table('import_export_files')->insert([
            'uuid' => 'filecsv00001',
            'job_uuid' => 'jobcsv000001',
            'role' => 'source',
            'disk' => 'storage',
            'path' => $absolutePath,
            'mime_type' => 'text/csv',
            'size_bytes' => filesize($absolutePath) ?: 0,
            'created_at' => '2026-06-27 00:00:00',
        ]);
    }
}
