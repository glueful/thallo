<?php

declare(strict_types=1);

namespace App\Tests\Integration\Importers;

use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\ImportExport\Repositories\ImportExportFileRepository;
use Glueful\Extensions\ImportExport\Repositories\ImportExportJobRepository;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Thallo\Importers\CsvContentImporter;
use Thallo\Importers\CsvUserImporter;
use Thallo\Importers\MarkdownContentImporter;
use Thallo\Importers\WordpressContentImporter;

final class ImporterHardeningTest extends AppTestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function tmpFile(string $suffix, string $contents): string
    {
        $path = sys_get_temp_dir() . '/lemma-importer-test-' . bin2hex(random_bytes(6)) . $suffix;
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** Seeds a content type with a required title and a RICH body (rich → sanitizer path). */
    private function seedImportType(string $slug): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'text', 'required' => false, 'format' => 'rich'],
            ],
        ]);
    }

    /** Registers a job + source file row so process() can resolve it (absolute path passes through). */
    private function seedSourceFile(string $jobUuid, string $absolutePath, string $adapter): void
    {
        $this->container()->get(ImportExportJobRepository::class)->create([
            'uuid' => $jobUuid,
            'type' => 'import',
            'adapter' => $adapter,
            'status' => 'processing',
            'mode' => 'commit',
        ]);
        $this->container()->get(ImportExportFileRepository::class)->create([
            'job_uuid' => $jobUuid,
            'role' => 'source',
            'disk' => 'local',
            'path' => $absolutePath,
        ]);
    }

    public function testPlanBatchUuidsDifferAcrossImportsOfTheSameFile(): void
    {
        // import_export_batches.uuid is globally UNIQUE and rows outlive the job — the old
        // deterministic hash(adapter:sequence:offset) made the SECOND import ever run with the
        // same adapter collide on insert.
        $this->seedImportType('uuid-posts');
        $csv = $this->tmpFile('.csv', "title\nOne\nTwo\nThree\n");
        $importer = $this->container()->get(CsvContentImporter::class);
        $source = new ImportSource('local', $csv);
        $options = new ImportOptions(batchSize: 1, options: [
            'content_type' => 'uuid-posts',
            'mapping' => ['title' => 'title'],
        ]);

        $first = array_map(static fn(ImportBatch $b): string => $b->uuid, $importer->plan($source, $options)->batches);
        $second = array_map(static fn(ImportBatch $b): string => $b->uuid, $importer->plan($source, $options)->batches);

        self::assertCount(3, $first);
        self::assertCount(3, array_unique($first), 'uuids must be unique within a plan');
        self::assertSame([], array_intersect($first, $second), 'uuids must differ across plans');
    }

    public function testCsvPlanRejectsFieldMissingFromSchema(): void
    {
        $this->seedImportType('csv-strict');
        $csv = $this->tmpFile('.csv', "headline\nHello\n");
        $importer = $this->container()->get(CsvContentImporter::class);

        // A typo'd field name was silently skipped per row — every entry imported without
        // that column's data and zero errors reported.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no field "headlin"');
        $importer->plan(new ImportSource('local', $csv), new ImportOptions(options: [
            'content_type' => 'csv-strict',
            'mapping' => ['headlin' => 'headline'],
        ]));
    }

    public function testMarkdownPlanRejectsUnknownBodyField(): void
    {
        $this->seedImportType('md-strict');
        $importer = $this->container()->get(MarkdownContentImporter::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no field "bodi"');
        $importer->plan(new ImportSource('local', 'post.md'), new ImportOptions(options: [
            'content_type' => 'md-strict',
            'body_field' => 'bodi',
        ]));
    }

    public function testWordpressPlanRejectsUnknownWxrKey(): void
    {
        $this->seedImportType('wxr-strict');
        $importer = $this->container()->get(WordpressContentImporter::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown WXR key "post_title"');
        $importer->plan(new ImportSource('local', 'export.xml'), new ImportOptions(options: [
            'content_type' => 'wxr-strict',
            'mapping' => ['title' => 'post_title'],
            'body_field' => 'body',
        ]));
    }

    public function testWordpressStoredBodyIsSanitized(): void
    {
        $this->seedImportType('wxr-posts');
        $wxr = $this->tmpFile('.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/">
  <channel>
    <item>
      <title>Hello</title>
      <dc:creator>admin</dc:creator>
      <content:encoded><![CDATA[<p>Safe paragraph</p><script>alert(1)</script><a
 href="javascript:alert(2)">x</a>]]></content:encoded>
      <excerpt:encoded><![CDATA[]]></excerpt:encoded>
      <wp:post_type>post</wp:post_type>
      <wp:status>publish</wp:status>
      <wp:post_name>hello</wp:post_name>
      <wp:post_date>2026-01-01 00:00:00</wp:post_date>
    </item>
  </channel>
</rss>
XML);
        $jobUuid = 'jobwxrsan001'; // import_export_files.job_uuid is varchar(12)
        $this->seedSourceFile($jobUuid, $wxr, 'wordpress.content');

        $importer = $this->container()->get(WordpressContentImporter::class);
        $result = $importer->process(
            new ImportBatch(uuid: 'b-wxr-1', jobUuid: $jobUuid, sequence: 1, offset: 0, limit: 10),
            new ImportContext($this->appContext(), $jobUuid, 'commit', 'user00000001', [
                'content_type' => 'wxr-posts',
                'mapping' => ['title' => 'title'],
                'body_field' => 'body',
            ]),
        );

        self::assertSame(0, $result->failedRecords, var_export($result->errors, true));
        self::assertSame(1, $result->processedRecords);

        $draft = $this->connection()->getPDO()->query(
            "SELECT d.fields FROM entry_drafts d
               JOIN entries e ON e.uuid = d.entry_uuid
               JOIN content_types t ON t.uuid = e.content_type_uuid
              WHERE t.slug = 'wxr-posts'"
        )->fetchColumn();
        self::assertIsString($draft);
        $fields = json_decode($draft, true);
        $body = (string) ($fields['body'] ?? '');
        self::assertStringContainsString('Safe paragraph', $body);
        self::assertStringNotContainsString('<script', $body, 'script must be stripped (stored XSS)');
        self::assertStringNotContainsString('javascript:', $body, 'unsafe link scheme must be dropped');
    }

    public function testCsvUserImportRejectsIntraFileDuplicatesInDryRun(): void
    {
        // Two rows sharing an email both passed dry-run (each only checked against the DB);
        // the collision then surfaced on commit. Both duplicate rows must fail identically
        // in dry-run and commit.
        $csv = $this->tmpFile('.csv', implode("\n", [
            'username,email',
            'alice,dup@example.test',
            'bob,DUP@example.test',
            'carol,carol@example.test',
            '',
        ]));
        $jobUuid = 'jobusrdup001';
        $this->seedSourceFile($jobUuid, $csv, 'csv.users');

        $importer = $this->container()->get(CsvUserImporter::class);
        $result = $importer->process(
            new ImportBatch(uuid: 'b-usr-1', jobUuid: $jobUuid, sequence: 1, offset: 0, limit: 10),
            new ImportContext($this->appContext(), $jobUuid, 'dry_run', 'user00000001', [
                'mapping' => ['username' => 'username', 'email' => 'email'],
            ]),
        );

        self::assertSame(1, $result->processedRecords, 'the non-duplicate row still validates');
        self::assertSame(2, $result->failedRecords, 'BOTH duplicate rows are rejected (case-insensitive)');
        foreach ($result->errors as $error) {
            self::assertStringContainsString('appears more than once', (string) $error['message']);
        }
    }

    public function testCsvUserImportRejectsUnknownStatus(): void
    {
        $csv = $this->tmpFile('.csv', "username,email,status\ndave,dave@example.test,superuser\n");
        $jobUuid = 'jobusrsts001';
        $this->seedSourceFile($jobUuid, $csv, 'csv.users');

        $importer = $this->container()->get(CsvUserImporter::class);
        $result = $importer->process(
            new ImportBatch(uuid: 'b-usr-2', jobUuid: $jobUuid, sequence: 1, offset: 0, limit: 10),
            new ImportContext($this->appContext(), $jobUuid, 'dry_run', 'user00000001', [
                'mapping' => ['username' => 'username', 'email' => 'email', 'status' => 'status'],
            ]),
        );

        self::assertSame(1, $result->failedRecords);
        self::assertStringContainsString('Invalid status "superuser"', (string) $result->errors[0]['message']);
    }
}
