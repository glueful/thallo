<?php

declare(strict_types=1);

namespace App\Tests\Integration\ImportExport;

use Thallo\Importers\WordpressContentImporter;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;

final class WordpressContentImporterTest extends AppTestCase
{
    /** @var array<string,mixed> */
    private const OPTIONS = [
        'content_type' => 'post',
        'mapping' => ['title' => 'title', 'slug' => 'slug'],
        'body_field' => 'body',
        'locale' => 'en',
        'publish' => true,
    ];

    private const WXR = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"
          xmlns:content="http://purl.org/rss/1.0/modules/content/"
          xmlns:dc="http://purl.org/dc/elements/1.1/"
          xmlns:wp="http://wordpress.org/export/1.2/"
          xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/">
        <channel>
          <item>
            <title>Hello World</title>
            <dc:creator>admin</dc:creator>
            <content:encoded><![CDATA[<p>Some <strong>bold</strong> text.</p>]]></content:encoded>
            <wp:post_name>hello-world</wp:post_name>
            <wp:status>publish</wp:status>
            <wp:post_type>post</wp:post_type>
            <wp:post_date>2026-01-01 12:00:00</wp:post_date>
          </item>
          <item>
            <title>About</title>
            <content:encoded><![CDATA[<p>About page.</p>]]></content:encoded>
            <wp:post_name>about</wp:post_name>
            <wp:status>draft</wp:status>
            <wp:post_type>page</wp:post_type>
          </item>
          <item>
            <title>An image</title>
            <wp:post_type>attachment</wp:post_type>
          </item>
        </channel>
        </rss>
        XML;

    public function testSupportsAndPlansPostsAndPagesOnly(): void
    {
        $this->seedType();
        $path = $this->writeWxr();
        $source = new ImportSource('storage', $path, 'application/xml');

        self::assertTrue($this->importer()->supports($source));
        self::assertFalse($this->importer()->supports(new ImportSource('storage', 'data.csv', null)));

        // The attachment item is skipped → 2 records (post + page).
        $plan = $this->importer()->plan($source, new ImportOptions(options: self::OPTIONS));
        self::assertSame(2, $plan->totalRecords);
    }

    public function testDryRunValidatesWithoutWriting(): void
    {
        $this->seedType();
        $this->seedJob($this->writeWxr());

        $result = $this->importer()->process(
            new ImportBatch('batchwp00001', 'jobwp000001', 1, 0, 20),
            new ImportContext($this->appContext(), 'jobwp000001', 'dry_run', null, self::OPTIONS),
        );

        self::assertSame(2, $result->processedRecords, json_encode($result->errors));
        self::assertSame(0, $result->failedRecords, json_encode($result->errors));
        self::assertSame(0, $this->connection()->table('entries')->count());
    }

    public function testCommitCreatesEntriesAndPublishesPublishedItems(): void
    {
        $this->seedType();
        $this->seedJob($this->writeWxr());

        $result = $this->importer()->process(
            new ImportBatch('batchwp00001', 'jobwp000001', 1, 0, 20),
            // Actor: in production createImport passes the authenticated admin's uuid; the
            // publish step goes through the workflow gate, so a NULL actor cannot publish
            // (no bypass). The seed actor holds workflow.bypass in this harness.
            new ImportContext($this->appContext(), 'jobwp000001', 'commit', 'user00000001', self::OPTIONS),
        );

        self::assertSame(2, $result->processedRecords, json_encode($result->errors));
        self::assertSame(2, $this->connection()->table('entries')->count());

        $first = $this->connection()->table('entry_drafts')->orderBy('id')->first();
        $fields = json_decode((string) $first['fields'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Hello World', $fields['title']);
        self::assertSame('hello-world', $fields['slug']);
        self::assertStringContainsString('<strong>bold</strong>', $fields['body']); // HTML body preserved

        // Only the `publish`-status item was published (the draft page wasn't).
        self::assertSame(1, $this->connection()->table('entry_publications')->count());
    }

    private function importer(): WordpressContentImporter
    {
        return $this->container()->get(WordpressContentImporter::class);
    }

    private function writeWxr(): string
    {
        $dir = sys_get_temp_dir() . '/lemma-wp-import-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $path = $dir . '/export-' . bin2hex(random_bytes(6)) . '.xml';
        file_put_contents($path, self::WXR);

        return $path;
    }

    private function seedType(): void
    {
        $this->connection()->table('content_types')->insert([
            'uuid' => 'typewp000001',
            'slug' => 'post',
            'name' => 'Post',
            'description' => null,
            'cache_ttl' => null,
            'public_delivery' => false,
            'status' => 'active',
            'schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'slug', 'type' => 'string'],
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
            'uuid' => 'jobwp000001',
            'type' => 'import',
            'adapter' => 'wordpress.content',
            'status' => 'queued',
            'mode' => 'commit',
            'source_disk' => 'storage',
            'source_path' => $absolutePath,
            'total_records' => 2,
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
        $this->connection()->table('import_export_files')->insert([
            'uuid' => 'filewp000001',
            'job_uuid' => 'jobwp000001',
            'role' => 'source',
            'disk' => 'storage',
            'path' => $absolutePath,
            'mime_type' => 'application/xml',
            'size_bytes' => filesize($absolutePath) ?: 0,
            'created_at' => '2026-06-27 00:00:00',
        ]);
    }
}
