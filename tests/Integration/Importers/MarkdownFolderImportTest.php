<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Importers;

use Thallo\Contracts\Authoring\ContentUpserter;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Core\Content\Authoring\EngineContentWriter;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Seo\RedirectRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Importers\Markdown\MarkdownFolderImport;

/**
 * Documentation is Markdown in git, published by a deploy: `thallo:import:markdown docs --type=docs
 * --publish`. So the import is REPEATABLE — a second run lands on the pages the first made, changes
 * only what changed, follows a renamed page with a redirect, and never deletes on its own.
 */
final class MarkdownFolderImportTest extends AppTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/thallo-md-import-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/guides', 0777, true);
        mkdir($this->dir . '/internal', 0777, true);
        $this->container()->get(DocsSetup::class)->run('docs', ['getting-started', 'guides']);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
        $this->container()->get(SettingsStore::class)->forget('listing_types');
        parent::tearDown();
    }

    private function write(string $path, string $contents): void
    {
        file_put_contents($this->dir . '/' . $path, $contents);
    }

    private function import(bool $gatedPublish = false): MarkdownFolderImport
    {
        $c = $this->container();
        // The test app has the review workflow on, which gates a plain publish: the importer is
        // given a writer whose publish is the engine's own, as a site without review has.
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $writer = $gatedPublish ? $c->get(ContentWriter::class) : new EngineContentWriter(
            $entries,
            new PublishService(
                $this->appContext(),
                $entries,
                new VersionRepository($this->connection()),
                $types,
                new FieldValidator($this->connection()),
                new ReferenceProjectionRepository($this->connection()),
            ),
            $types,
            new FieldValidator($this->connection()),
        );
        return new MarkdownFolderImport(
            $writer,
            $c->get(ContentUpserter::class),
            $c->get(ContentTypeReader::class),
            $c->get(CapabilityRegistry::class),
        );
    }

    /** @return array<string,string> path => status */
    private static function statuses(array $report): array
    {
        return array_column($report['files'], 'status', 'path');
    }

    public function testAFolderBecomesPublishedPagesWithTheirUrlsSectionsAndLinks(): void
    {
        $this->write('README.md', "# Welcome\n\nStart with [installing](guides/01-install.md).\n");
        $this->write(
            'guides/01-install.md',
            "---\nsummary: Get it running.\n---\n# Installing\n\n```bash\n$ composer install\n```\n",
        );
        $this->write('guides/theming.md', "# Theming\n\nSee [install](01-install.md#requirements).\n");
        $this->write('internal/plan.md', "# A private plan\n");
        $this->write('guides/wip.md', "---\ndraft: true\n---\n# Not yet\n");

        $report = $this->import()->run($this->dir, [
            'type' => 'docs', 'publish' => true, 'exclude' => ['internal'],
            'edit_base' => 'https://github.com/acme/site/edit/main/docs',
        ]);

        self::assertSame([
            'README.md' => 'created',
            'guides/01-install.md' => 'created',
            'guides/theming.md' => 'created',
            'guides/wip.md' => 'skipped',
        ], self::statuses($report));
        self::assertSame(
            ['created' => 3, 'updated' => 0, 'unchanged' => 0, 'skipped' => 1, 'failed' => 0],
            $report['counts'],
        );

        $up = $this->container()->get(ContentUpserter::class);
        $type = (string) $this->container()->get(ContentTypeReader::class)->findUuidBySlug('docs');
        $install = $up->current((string) $up->findBySlug($type, 'en', 'install'), 'en');
        self::assertTrue($install['published']);
        self::assertSame('Installing', $install['fields']['title']);
        self::assertSame('guides', $install['fields']['section']);
        self::assertSame(1, (int) $install['fields']['order']);
        self::assertSame('Get it running.', $install['fields']['summary']);
        self::assertSame('guides/01-install.md', $install['fields']['source_path']);
        self::assertSame(
            'https://github.com/acme/site/edit/main/docs/guides/01-install.md',
            $install['fields']['edit_url'],
        );
        // The Markdown is kept as written — code fence and all — for the theme to render.
        self::assertStringContainsString("```bash\n$ composer install\n```", $install['fields']['body']);
        self::assertStringNotContainsString('# Installing', $install['fields']['body']);

        $theming = $up->current((string) $up->findBySlug($type, 'en', 'theming'), 'en');
        self::assertStringContainsString('[install](/docs/install#requirements)', $theming['fields']['body']);
        $home = $up->current((string) $up->findBySlug($type, 'en', 'index'), 'en');
        self::assertStringContainsString('[installing](/docs/install)', $home['fields']['body']);
        self::assertNull($up->findBySlug($type, 'en', 'plan'), 'an excluded folder is not read');
    }

    public function testASecondRunChangesOnlyWhatChanged(): void
    {
        $this->write('a.md', "# A\n\nOne.\n");
        $this->write('b.md', "# B\n\nTwo.\n");
        $this->import()->run($this->dir, ['type' => 'docs', 'publish' => true]);

        $this->write('b.md', "# B\n\nTwo, revised.\n");
        $report = $this->import()->run($this->dir, ['type' => 'docs', 'publish' => true]);
        self::assertSame(['a.md' => 'unchanged', 'b.md' => 'updated'], self::statuses($report));

        $up = $this->container()->get(ContentUpserter::class);
        $type = (string) $this->container()->get(ContentTypeReader::class)->findUuidBySlug('docs');
        $b = $up->current((string) $up->findBySlug($type, 'en', 'b'), 'en');
        self::assertStringContainsString('revised', $b['fields']['body']);
        self::assertCount(2, $up->fieldValues($type, 'en', 'source_path'), 'no page was made twice');
    }

    public function testARenamedPageKeepsItsEntryAndLeavesARedirect(): void
    {
        $this->write('setup.md', "---\nslug: setup\n---\n# Setup\n");
        $this->import()->run($this->dir, ['type' => 'docs', 'publish' => true]);
        $up = $this->container()->get(ContentUpserter::class);
        $type = (string) $this->container()->get(ContentTypeReader::class)->findUuidBySlug('docs');
        $entry = $up->findBySlug($type, 'en', 'setup');

        $this->write('setup.md', "---\nslug: installation\n---\n# Setup\n");
        $report = $this->import()->run($this->dir, ['type' => 'docs', 'publish' => true]);
        self::assertSame(['setup.md' => 'updated'], self::statuses($report));
        self::assertSame($entry, $up->findBySlug($type, 'en', 'installation'), 'the same entry, at its new URL');
        self::assertNotNull(
            $this->container()->get(RedirectRepository::class)->findBySource($type, 'en', 'setup'),
        );
    }

    public function testADryRunWritesNothingAndAFileThatIsGoneIsReportedNotDeleted(): void
    {
        $this->write('keep.md', "# Keep\n");
        $this->write('gone.md', "# Gone\n");
        $this->import()->run($this->dir, ['type' => 'docs', 'publish' => true]);
        unlink($this->dir . '/gone.md');
        $this->write('new.md', "# New\n");

        $report = $this->import()->run($this->dir, ['type' => 'docs', 'publish' => true, 'dry_run' => true]);
        self::assertSame(['keep.md' => 'unchanged', 'new.md' => 'created'], self::statuses($report));
        self::assertTrue($report['dry_run']);
        self::assertSame(['gone.md'], array_column($report['missing'], 'source_path'));

        $up = $this->container()->get(ContentUpserter::class);
        $type = (string) $this->container()->get(ContentTypeReader::class)->findUuidBySlug('docs');
        self::assertNull($up->findBySlug($type, 'en', 'new'), 'a dry run made nothing');
        self::assertNotNull($up->findBySlug($type, 'en', 'gone'), 'and nothing is deleted, dry run or not');
    }

    public function testOneBadFileDoesNotStopTheRestAndTwoFilesCannotClaimOneUrl(): void
    {
        $this->write('a.md', "---\nslug: same\n---\n# A\n");
        $this->write('b.md', "---\nslug: same\n---\n# B\n");
        $this->write('c.md', "# C\n\n[broken](nowhere.md)\n");
        $report = $this->import()->run($this->dir, ['type' => 'docs', 'publish' => true]);
        $status = self::statuses($report);
        self::assertSame('created', $status['a.md']);
        self::assertSame('failed', $status['b.md']);
        self::assertSame('created', $status['c.md']);
        $byPath = array_column($report['files'], null, 'path');
        self::assertStringContainsString('a.md', $byPath['b.md']['message']);
        self::assertSame(['nowhere.md'], $byPath['c.md']['broken_links']);
    }

    public function testAPublishTheSiteGatesIsSaidPerFileAndTheDraftIsStillWritten(): void
    {
        $this->write('a.md', "# A\n");
        $report = $this->import(gatedPublish: true)->run($this->dir, ['type' => 'docs', 'publish' => true]);
        $file = $report['files'][0];
        self::assertSame('created', $file['status']);
        self::assertStringContainsString('review', strtolower((string) $file['message']));
        $up = $this->container()->get(ContentUpserter::class);
        $type = (string) $this->container()->get(ContentTypeReader::class)->findUuidBySlug('docs');
        self::assertFalse($up->current((string) $up->findBySlug($type, 'en', 'a'), 'en')['published']);
    }

    public function testItRefusesATypeThatIsNotThereOrHasNoBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->import()->run($this->dir, ['type' => 'nope']);
    }

    public function testAChangelogOrLicencePageOffersNoEditLink(): void
    {
        // Written by the release process, or held word for word to the project's LICENSE: never
        // pages to invite an edit of — and CHANGELOG.md's own file is not in the docs folder at all.
        $this->write('guides/01-install.md', "# Installing\n\nRun it.\n");
        $this->write('CHANGELOG.md', "# Changelog\n\n## [1.0.0] - 2026-01-01\n\n- First.\n");
        mkdir($this->dir . '/reference');
        $this->write('reference/07-license.md', "---\ntitle: MIT License\nslug: license\n---\nCopyright.\n");
        $this->write('licence.md', "# Licence\n\nTerms.\n");
        $this->import()->run($this->dir, [
            'type' => 'docs', 'publish' => true,
            'edit_base' => 'https://github.com/acme/site/edit/main/docs',
        ]);

        $up = $this->container()->get(ContentUpserter::class);
        $type = (string) $this->container()->get(ContentTypeReader::class)->findUuidBySlug('docs');
        $edit = fn (string $slug): ?string
            => $up->current((string) $up->findBySlug($type, 'en', $slug), 'en')['fields']['edit_url'] ?? null;
        self::assertSame('https://github.com/acme/site/edit/main/docs/guides/01-install.md', $edit('install'));
        self::assertNull($edit('changelog'));
        self::assertNull($edit('license'));
        self::assertNull($edit('licence'));
    }
}
