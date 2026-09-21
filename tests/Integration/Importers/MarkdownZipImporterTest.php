<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Importers;

use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportBatchResult;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportSource;
use Thallo\Contracts\Authoring\ContentUpserter;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Core\Content\Authoring\EngineContentWriter;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Importers\Markdown\MarkdownFolderImport;
use Thallo\Importers\MarkdownZipImporter;

/**
 * The docs folder, uploaded as a .zip from Settings › Import / Export: the same import the deploy
 * command runs, as an import job. Its report — what each file became, a link that leads nowhere,
 * a page whose file is gone — is the job's rows, because that is what the admin can show.
 */
final class MarkdownZipImporterTest extends AppTestCase
{
    private const JOB = 'jobmdzip0001';

    /** @var list<string> */
    private array $zips = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(DocsSetup::class)->run('docs', ['getting-started', 'guides']);
    }

    protected function tearDown(): void
    {
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
        $this->container()->get(SettingsStore::class)->forget('listing_types');
        parent::tearDown();
    }

    /** @param array<string,string> $files */
    private function zip(array $files): string
    {
        $path = sys_get_temp_dir() . '/thallo-docs-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $this->zips[] = $path;
        return $path;
    }

    private function importer(): MarkdownZipImporter
    {
        $c = $this->container();
        // The test app has the review workflow on, which gates a plain publish: the importer is
        // given a writer whose publish is the engine's own, as a site without review has.
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $validator = new FieldValidator($this->connection());
        $writer = new EngineContentWriter(
            $entries,
            new PublishService(
                $this->appContext(),
                $entries,
                new VersionRepository($this->connection()),
                $types,
                $validator,
                new ReferenceProjectionRepository($this->connection()),
            ),
            $types,
            $validator,
        );
        return new MarkdownZipImporter(
            $this->appContext(),
            $this->connection(),
            new MarkdownFolderImport(
                $writer,
                $c->get(ContentUpserter::class),
                $c->get(ContentTypeReader::class),
                $c->get(CapabilityRegistry::class),
            ),
            $c->get(ContentTypeReader::class),
            $c->get(CapabilityRegistry::class),
        );
    }

    /** @param array<string,mixed> $options */
    private function upload(string $zip, string $mode, array $options = []): ImportBatchResult
    {
        $this->connection()->table('import_export_files')->where('job_uuid', '=', self::JOB)->delete();
        $this->connection()->table('import_export_jobs')->where('uuid', '=', self::JOB)->delete();
        $this->connection()->table('import_export_jobs')->insert([
            'uuid' => self::JOB,
            'type' => 'import',
            'adapter' => 'markdown.folder',
            'status' => 'queued',
            'mode' => $mode,
            'source_disk' => 'storage',
            'source_path' => $zip,
            'total_records' => 1,
            'created_at' => '2026-09-21 00:00:00',
            'updated_at' => '2026-09-21 00:00:00',
        ]);
        $this->connection()->table('import_export_files')->insert([
            'uuid' => 'filemdzip001',
            'job_uuid' => self::JOB,
            'role' => 'source',
            'disk' => 'storage',
            'path' => $zip,
            'mime_type' => 'application/zip',
            'size_bytes' => filesize($zip) ?: 0,
            'created_at' => '2026-09-21 00:00:00',
        ]);
        return $this->importer()->process(
            new ImportBatch('batchmdzip01', self::JOB, 1, 0, 1),
            new ImportContext($this->appContext(), self::JOB, $mode, null, $options + ['content_type' => 'docs']),
        );
    }

    /** @return array<string,list<string>> code => messages */
    private static function rows(ImportBatchResult $result): array
    {
        $rows = [];
        foreach ($result->errors as $row) {
            $rows[(string) $row['code']][] = (string) $row['message'];
        }
        return $rows;
    }

    private function published(string $slug): ?array
    {
        $up = $this->container()->get(ContentUpserter::class);
        $type = (string) $this->container()->get(ContentTypeReader::class)->findUuidBySlug('docs');
        $entry = $up->findBySlug($type, 'en', $slug);
        return $entry === null ? null : $up->current($entry, 'en');
    }

    public function testItTakesAZipAndPlansOneRecordPerPage(): void
    {
        $zip = $this->zip(['docs/README.md' => '# Welcome', 'docs/guides/install.md' => '# Installing']);
        $importer = $this->importer();
        self::assertSame('markdown.folder', $importer->key());
        self::assertTrue($importer->supports(new ImportSource('storage', $zip, 'application/zip')));
        self::assertFalse($importer->supports(new ImportSource('storage', '/tmp/page.md')));

        $plan = $importer->plan(
            new ImportSource('storage', $zip, 'application/zip'),
            new ImportOptions(options: ['content_type' => 'docs']),
        );
        self::assertSame(2, $plan->totalRecords);
        self::assertCount(1, $plan->batches, 'one batch: the links between pages need every page at once');

        // A folder left out is not counted: the job's "2 of 2" has to be true.
        $withPrivate = $this->zip(['docs/a.md' => '# A', 'docs/internal/plan.md' => '# Private']);
        $plan = $importer->plan(
            new ImportSource('storage', $withPrivate),
            new ImportOptions(options: ['content_type' => 'docs', 'exclude' => ['internal']]),
        );
        self::assertSame(1, $plan->totalRecords);
    }

    public function testAPlanSaysWhatIsWrongBeforeAnythingIsQueued(): void
    {
        $empty = $this->zip(['notes.txt' => 'no markdown here']);
        try {
            $this->importer()->plan(
                new ImportSource('storage', $empty),
                new ImportOptions(options: ['content_type' => 'docs']),
            );
            self::fail('an archive with no pages was planned');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('no Markdown', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handbook');
        $this->importer()->plan(
            new ImportSource('storage', $this->zip(['a.md' => '# A'])),
            new ImportOptions(options: ['content_type' => 'handbook']),
        );
    }

    public function testADryRunReportsWhatWouldHappenAndWritesNothing(): void
    {
        $zip = $this->zip([
            'docs/README.md' => "# Welcome\n\nSee [the plan](internal/plan.md).\n",
            'docs/guides/install.md' => '# Installing',
            'docs/internal/plan.md' => '# Private',
        ]);

        $result = $this->upload($zip, 'dry_run', ['publish' => true, 'exclude' => ['internal']]);

        self::assertSame(2, $result->processedRecords, json_encode($result->errors));
        self::assertSame(0, $result->failedRecords);
        $rows = self::rows($result);
        self::assertSame(
            ['README.md would be created at /docs/index', 'guides/install.md would be created at /docs/install'],
            $rows['markdown_page_created'],
        );
        self::assertSame(
            ['README.md links to internal/plan.md, which is not in this import. The link was left as written.'],
            $rows['markdown_broken_link'],
        );
        self::assertNull($this->published('install'));
    }

    public function testACommitPublishesThePagesAndASecondUploadChangesOnlyWhatChanged(): void
    {
        $first = $this->zip([
            'docs/guides/install.md' => "# Installing\n\nOne.\n",
            'docs/guides/theming.md' => "# Theming\n",
        ]);
        $result = $this->upload($first, 'commit', [
            'publish' => true,
            'edit_base' => 'https://github.com/acme/site/edit/main/docs',
        ]);
        self::assertSame(2, $result->processedRecords, json_encode($result->errors));

        $install = $this->published('install');
        self::assertNotNull($install);
        self::assertTrue($install['published']);
        self::assertSame('guides', $install['fields']['section']);
        // The zipped folder is not part of the path: the same page the deploy command would find.
        self::assertSame('guides/install.md', $install['fields']['source_path']);
        self::assertSame(
            'https://github.com/acme/site/edit/main/docs/guides/install.md',
            $install['fields']['edit_url'],
        );

        // Packed WITHOUT the docs/ folder this time, one page revised, one page gone.
        $second = $this->zip(['guides/install.md' => "# Installing\n\nOne, revised.\n"]);
        $rows = self::rows($this->upload($second, 'commit', ['publish' => true]));
        self::assertSame(['guides/install.md was updated at /docs/install'], $rows['markdown_page_updated']);
        self::assertSame(
            [
                'guides/theming.md is not in this upload. Its page was left as it is: '
                . 'remove it under Content if you mean to.',
            ],
            $rows['markdown_page_gone'],
        );
        self::assertStringContainsString('revised', $this->published('install')['fields']['body']);
        self::assertNotNull($this->published('theming'), 'nothing is deleted by an import');
    }

    public function testOneBadPageFailsAloneAndNothingIsLeftOnDisk(): void
    {
        $before = glob(sys_get_temp_dir() . '/thallo-md-upload-*') ?: [];
        $zip = $this->zip(['a.md' => '# Same', 'b.md' => "---\nslug: a\n---\n# Also A\n"]);

        $result = $this->upload($zip, 'commit', ['publish' => true]);

        self::assertSame(1, $result->processedRecords);
        self::assertSame(1, $result->failedRecords);
        $failed = array_values(array_filter($result->errors, static fn (array $r): bool => $r['severity'] === 'error'));
        self::assertCount(1, $failed);
        self::assertSame('markdown_page_failed', $failed[0]['code']);
        self::assertStringContainsString('b.md', $failed[0]['message']);
        self::assertSame($before, glob(sys_get_temp_dir() . '/thallo-md-upload-*') ?: []);
    }
}
