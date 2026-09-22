<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\ImportExport;

use Glueful\Extensions\ImportExport\Services\BatchRunner;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Glueful\Storage\StorageManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Content\ImportExport\ContentImporter;
use Thallo\Core\Http\Controllers\ImportExportController;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Importers\CsvContentImporter;
use Thallo\Importers\MarkdownContentImporter;
use Thallo\Importers\MarkdownZipImporter;
use Thallo\Importers\WordpressContentImporter;

/**
 * Every importer decides whether a file is its own by the file's extension. So the upload has to
 * take what the importers take, and keep the extension it was given: it used to accept NDJSON
 * only and name everything `.ndjson`, which left the CSV, Markdown and WordPress imports with
 * no way in from the admin.
 */
final class ImportUploadTest extends AppTestCase
{
    /** @var list<string> */
    private array $stored = [];

    protected function tearDown(): void
    {
        $storage = $this->container()->get(StorageManager::class);
        foreach ($this->stored as $path) {
            $storage->delete($path, 'uploads');
        }
        parent::tearDown();
    }

    /** @return array{status: int, data: array<string,mixed>} */
    private function upload(string $name, string $contents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'thallo-upload-');
        file_put_contents($tmp, $contents);
        $request = Request::create('/v1/admin/import-export/upload', 'POST', files: [
            'file' => new UploadedFile($tmp, $name, null, null, true),
        ]);
        $response = $this->container()->get(ImportExportController::class)->upload($request);
        @unlink($tmp);
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (isset($data['path'])) {
            $this->stored[] = (string) $data['path'];
        }
        return ['status' => $response->getStatusCode(), 'data' => $data];
    }

    public function testEachImportersFileIsTakenAndStillItsAfterTheUpload(): void
    {
        $c = $this->container();
        $cases = [
            'bundle.ndjson' => ContentImporter::class,
            'bundle.jsonl' => ContentImporter::class,
            'posts.CSV' => CsvContentImporter::class,
            'page.md' => MarkdownContentImporter::class,
            'page.mdx' => MarkdownContentImporter::class,
            'site.xml' => WordpressContentImporter::class,
            'site.wxr' => WordpressContentImporter::class,
            'docs.zip' => MarkdownZipImporter::class,
        ];
        foreach ($cases as $name => $importer) {
            $result = $this->upload($name, "contents of {$name}");
            self::assertSame(201, $result['status'], $name);
            self::assertSame('uploads', $result['data']['disk']);
            self::assertTrue(
                $c->get($importer)->supports(new ImportSource('uploads', (string) $result['data']['path'])),
                "{$name} was stored as {$result['data']['path']}, which its importer does not take",
            );
            // The name on disk is the server's: nothing of the visitor's name but its kind.
            self::assertMatchesRegularExpression(
                '#\Aimport-export/[A-Za-z0-9_-]{16}\.[a-z]+\z#',
                (string) $result['data']['path'],
            );
            self::assertTrue($c->get(StorageManager::class)->fileExists((string) $result['data']['path'], 'uploads'));
        }
    }

    public function testAnUploadedDocsZipRunsAsAJobAndItsReportIsKept(): void
    {
        // The whole way, as the admin drives it: upload, create the import, the worker's batch.
        $c = $this->container();
        $c->get(DocsSetup::class)->run('docs', ['guides']);
        $tmp = tempnam(sys_get_temp_dir(), 'thallo-zip-');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp, \ZipArchive::OVERWRITE));
        $zip->addFromString('docs/guides/install.md', "# Installing\n\nSee [theming](theming.md).\n");
        $zip->addFromString('docs/internal/plan.md', '# Private');
        $zip->close();
        $uploaded = $this->upload('docs.zip', (string) file_get_contents($tmp));
        @unlink($tmp);
        self::assertSame(201, $uploaded['status']);

        $job = $c->get(ImportExportService::class)->createImport(
            'markdown.folder',
            new ImportSource('uploads', (string) $uploaded['data']['path'], 'application/zip'),
            new ImportOptions(
                mode: 'dry_run',
                options: ['content_type' => 'docs', 'publish' => true, 'exclude' => ['internal']],
            ),
        );
        self::assertSame(1, (int) $job['total_records']);

        $batch = $this->connection()->table('import_export_batches')
            ->where('job_uuid', '=', $job['uuid'])->first();
        if (($batch['status'] ?? '') === 'pending') {
            $c->get(BatchRunner::class)
                ->runImportBatch((string) $batch['uuid']);
        }

        $done = $this->connection()->table('import_export_jobs')->where('uuid', '=', $job['uuid'])->first();
        self::assertSame('completed', $done['status']);
        self::assertSame(1, (int) $done['processed_records']);
        self::assertSame(0, (int) $done['failed_records']);
        $rows = $this->connection()->table('import_export_errors')
            ->where('job_uuid', '=', $job['uuid'])->orderBy('id')->get();
        self::assertSame(
            [
                'info' => 'guides/install.md would be created at /docs/install',
                'warning' => 'guides/install.md links to theming.md, which is not in this import. '
                    . 'The link was left as written.',
            ],
            array_column($rows, 'message', 'severity'),
        );
        self::assertSame(0, $this->connection()->table('entries')->count(), 'a dry run made nothing');
        $c->get(SettingsStore::class)->forget('listing_types');
    }

    public function testTheMergedUploadsRootIsTheSitesStorageNotThePackages(): void
    {
        // The site's config/import_export.php wins over the core package's empty default.
        $root = config($this->appContext(), 'import_export.source_roots.uploads');
        self::assertSame($this->appContext()->getBasePath() . '/storage/uploads', $root);
        self::assertStringNotContainsString('/vendor/', (string) $root);
    }

    public function testAPlainJsonBundleIsStillTakenAsNdjson(): void
    {
        $result = $this->upload('bundle.json', '{}');
        self::assertSame(201, $result['status']);
        self::assertStringEndsWith('.ndjson', (string) $result['data']['path']);
    }

    public function testAnythingElseIsRefused(): void
    {
        foreach (['shell.php', 'page.html', 'archive.tar', 'noextension', 'double.md.php'] as $name) {
            self::assertSame(422, $this->upload($name, 'x')['status'], $name);
        }
    }
}
