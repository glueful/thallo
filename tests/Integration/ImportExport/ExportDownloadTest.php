<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\ImportExport;

use Glueful\Extensions\ImportExport\Services\BatchRunner;
use Glueful\Extensions\ImportExport\Services\ImportExportService;
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Glueful\Storage\StorageManager;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Http\Controllers\ImportExportController;
use Thallo\Core\Tests\Support\AppTestCase;

use function config;

/**
 * An export's result files are recorded on `import_export.result_disk` and read back through that
 * disk. It was `local`, which no storage config defined, so every Download failed on a stock
 * install; the exporter also wrote under storage/ by hand, whatever disk the job recorded.
 */
final class ExportDownloadTest extends AppTestCase
{
    /** @var list<array{disk: string, path: string}> */
    private array $written = [];

    protected function tearDown(): void
    {
        $storage = $this->container()->get(StorageManager::class);
        foreach ($this->written as $file) {
            $storage->delete($file['path'], $file['disk']);
        }
        parent::tearDown();
    }

    public function testACompletedExportDownloadsThroughTheDiskItWasRecordedOn(): void
    {
        $c = $this->container();
        $c->get(DocsSetup::class)->run('docs', ['guides']);

        $job = $c->get(ImportExportService::class)->createExport('thallo.content', new ExportOptions());
        foreach (
            $this->connection()->table('import_export_batches')
                ->where('job_uuid', '=', $job['uuid'])->where('status', '=', 'pending')->get() as $batch
        ) {
            $c->get(BatchRunner::class)->runExportBatch((string) $batch['uuid']);
        }

        $files = $this->connection()->table('import_export_files')
            ->where('job_uuid', '=', $job['uuid'])->where('role', '=', 'result')->get();
        self::assertNotSame([], $files);
        $disk = (string) config($this->appContext(), 'import_export.result_disk');
        $storage = $c->get(StorageManager::class);
        foreach ($files as $file) {
            $this->written[] = ['disk' => (string) $file['disk'], 'path' => (string) $file['path']];
            self::assertSame($disk, $file['disk']);
            self::assertTrue($storage->disk($disk)->fileExists((string) $file['path']), 'written on that disk');
        }

        $response = $c->get(ImportExportController::class)->download((string) $job['uuid']);
        self::assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        $kinds = array_map(
            static fn (string $line): string => (string) json_decode($line, true, flags: JSON_THROW_ON_ERROR)['kind'],
            array_values(array_filter(explode("\n", trim($body)))),
        );
        self::assertContains('content_type', $kinds);
    }
}
