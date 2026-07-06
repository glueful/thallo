<?php

declare(strict_types=1);

namespace App\Tests\Integration\ImportExport;

use Thallo\Importers\MarkdownContentImporter;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;

final class MarkdownContentImporterTest extends AppTestCase
{
    /** @var array<string,mixed> */
    private const OPTIONS = [
        'content_type' => 'post',
        'mapping' => ['title' => 'title', 'featured' => 'featured'],
        'body_field' => 'body',
        'locale' => 'en',
    ];

    private const DOCUMENT = "---\ntitle: Hello World\nfeatured: true\n---\n# Heading\n\nSome **bold** text.\n";

    public function testSupportsAndPlansAMarkdownFile(): void
    {
        $this->seedType();
        $path = $this->writeMarkdown(self::DOCUMENT);
        $source = new ImportSource('storage', $path, 'text/markdown');

        self::assertTrue($this->importer()->supports($source));
        self::assertFalse($this->importer()->supports(new ImportSource('storage', 'data.csv', null)));

        $plan = $this->importer()->plan($source, new ImportOptions(options: self::OPTIONS));
        self::assertSame(1, $plan->totalRecords);
        self::assertCount(1, $plan->batches);
    }

    public function testDryRunValidatesWithoutWriting(): void
    {
        $this->seedType();
        $this->seedJob($this->writeMarkdown(self::DOCUMENT));

        $result = $this->importer()->process(
            new ImportBatch('batchmd00001', 'jobmd000001', 1, 0, 1),
            new ImportContext($this->appContext(), 'jobmd000001', 'dry_run', null, self::OPTIONS),
        );

        self::assertSame(1, $result->processedRecords, json_encode($result->errors));
        self::assertSame(0, $result->failedRecords, json_encode($result->errors));
        self::assertSame(0, $this->connection()->table('entries')->count());
    }

    public function testCommitCreatesAnEntryWithFrontMatterAndHtmlBody(): void
    {
        $this->seedType();
        $this->seedJob($this->writeMarkdown(self::DOCUMENT));

        $result = $this->importer()->process(
            new ImportBatch('batchmd00001', 'jobmd000001', 1, 0, 1),
            new ImportContext($this->appContext(), 'jobmd000001', 'commit', null, self::OPTIONS),
        );

        self::assertSame(1, $result->processedRecords, json_encode($result->errors));
        self::assertSame(1, $this->connection()->table('entries')->count());

        $fields = json_decode(
            (string) $this->connection()->table('entry_drafts')->first()['fields'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('Hello World', $fields['title']);
        self::assertTrue($fields['featured']); // front-matter "true" coerced to bool
        self::assertStringContainsString('<h1>Heading</h1>', $fields['body']); // markdown → HTML
        self::assertStringContainsString('<strong>bold</strong>', $fields['body']);
    }

    public function testCommitStripsUnsafeHtmlAndLinksFromBody(): void
    {
        $this->seedType();
        $doc = "---\ntitle: XSS Test\n---\n# Safe Heading\n\n"
            . "<script>alert('xss')</script>\n\n"
            . "<a href=\"javascript:alert(1)\" onclick=\"steal()\">bad</a>\n\n"
            . "[evil link](javascript:alert('x'))\n";
        $this->seedJob($this->writeMarkdown($doc));

        $result = $this->importer()->process(
            new ImportBatch('batchmd00001', 'jobmd000001', 1, 0, 1),
            new ImportContext($this->appContext(), 'jobmd000001', 'commit', null, self::OPTIONS),
        );

        self::assertSame(1, $result->processedRecords, json_encode($result->errors));
        $fields = json_decode(
            (string) $this->connection()->table('entry_drafts')->first()['fields'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $body = (string) $fields['body'];

        // Legit markdown still renders.
        self::assertStringContainsString('<h1>Safe Heading</h1>', $body);
        // Raw HTML and unsafe link schemes are neutralized — no stored XSS.
        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('onclick', $body);
        self::assertStringNotContainsString('javascript:', $body);
    }

    public function testMissingRequiredFrontMatterIsReported(): void
    {
        $this->seedType();
        // No `title` in the front matter → required field fails validation.
        $this->seedJob($this->writeMarkdown("---\nfeatured: false\n---\nBody only.\n"));

        $result = $this->importer()->process(
            new ImportBatch('batchmd00001', 'jobmd000001', 1, 0, 1),
            new ImportContext($this->appContext(), 'jobmd000001', 'commit', null, self::OPTIONS),
        );

        self::assertSame(0, $result->processedRecords);
        self::assertSame(1, $result->failedRecords);
        self::assertSame(0, $this->connection()->table('entries')->count());
    }

    private function importer(): MarkdownContentImporter
    {
        return $this->container()->get(MarkdownContentImporter::class);
    }

    private function writeMarkdown(string $contents): string
    {
        $dir = sys_get_temp_dir() . '/lemma-md-import-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $path = $dir . '/doc-' . bin2hex(random_bytes(6)) . '.md';
        file_put_contents($path, $contents);

        return $path;
    }

    private function seedType(): void
    {
        $this->connection()->table('content_types')->insert([
            'uuid' => 'typemd000001',
            'slug' => 'post',
            'name' => 'Post',
            'description' => null,
            'cache_ttl' => null,
            'public_delivery' => false,
            'status' => 'active',
            'schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'featured', 'type' => 'boolean'],
                ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
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
            'uuid' => 'jobmd000001',
            'type' => 'import',
            'adapter' => 'markdown.content',
            'status' => 'queued',
            'mode' => 'commit',
            'source_disk' => 'storage',
            'source_path' => $absolutePath,
            'total_records' => 1,
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
        $this->connection()->table('import_export_files')->insert([
            'uuid' => 'filemd000001',
            'job_uuid' => 'jobmd000001',
            'role' => 'source',
            'disk' => 'storage',
            'path' => $absolutePath,
            'mime_type' => 'text/markdown',
            'size_bytes' => filesize($absolutePath) ?: 0,
            'created_at' => '2026-06-27 00:00:00',
        ]);
    }
}
