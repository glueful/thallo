<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Symfony\Component\HttpClient\Response\MockResponse;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Authoring\PublishBlocked;
use Thallo\Core\Content\Docs\DocsSetup;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;

/** A docs sync run from archive to pages (docs sync design §6.3, §7). */
final class DocsSyncRunnerTest extends AppTestCase
{
    use DocsSyncTestHelpers;
    use DocsSyncRunnerHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(DocsSetup::class)->run('docs', ['guides']);
        $this->saveSource();
    }

    protected function tearDown(): void
    {
        $this->cleanDocsSync();
        $this->container()->get(SettingsStore::class)->forget('listing_types');
        parent::tearDown();
    }

    private function refusingPublish(\Throwable $error): ContentWriter
    {
        $inner = $this->writer();
        return new class ($inner, $error) implements ContentWriter {
            public function __construct(private readonly ContentWriter $inner, private readonly \Throwable $error)
            {
            }

            public function createDraft(
                string $contentTypeUuid,
                string $locale,
                array $fields,
                ?string $actor = null,
            ): string {
                return $this->inner->createDraft($contentTypeUuid, $locale, $fields, $actor);
            }

            public function validate(string $contentTypeUuid, string $locale, array $fields): array
            {
                return $this->inner->validate($contentTypeUuid, $locale, $fields);
            }

            public function publish(string $entryUuid, string $locale, ?string $actor = null): string
            {
                throw $this->error;
            }
        };
    }

    public function testASyncWritesEveryPageAndLands(): void
    {
        $this->repoFiles = ['guides/one.md' => "# One\n\n[two](two.md)\n", 'guides/two.md' => "# Two\n"];
        $run = $this->startRun();

        $this->runner()->run($run);

        $row = $this->runRow($run);
        self::assertSame('finished', $row['status']);
        self::assertSame('success', $row['outcome']);
        self::assertSame(2, (int) $row['created']);
        self::assertSame(2, (int) $row['total'], 'the card shows progress as page N of total');
        self::assertSame(['guides/one.md' => 'created', 'guides/two.md' => 'created'], $this->pageStatuses($run));
        self::assertSame('v1.0.0', $row['ref']);
        self::assertSame(self::SHA, $row['sha']);
        self::assertTrue($this->slugExists('one'));
        self::assertFalse($this->slugExists('repo-readme'), 'Markdown outside docs/ is never read');
        $state = $this->stateRow();
        self::assertSame($run, $state['synced_run']);
        self::assertNull($state['active_run']);
    }

    public function testPartialFailedAndRefusedOutcomes(): void
    {
        $this->repoFiles = ['a.md' => "---\nslug: same\n---\n# A\n", 'b.md' => "---\nslug: same\n---\n# B\n"];
        $run = $this->startRun();
        $this->runner()->run($run);
        self::assertSame('partial', $this->runRow($run)['outcome'], 'b.md claims a.md\'s URL');

        $this->repoFiles = ['c.md' => "# C\n"];
        $run = $this->startRun();
        $this->runner($this->refusingPublish(new \RuntimeException('publishing is broken')))->run($run);
        self::assertSame('failed', $this->runRow($run)['outcome'], 'every page failed');

        $this->saveSource(['folder' => 'Docs']); // Review Focus 1: the repo's folder is `docs`
        $run = $this->startRun();
        $this->runner()->run($run);
        $row = $this->runRow($run);
        self::assertSame('refused', $row['outcome']);
        self::assertStringContainsString('`Docs` holds no pages', (string) $row['message']);
        self::assertSame([], $this->pageStatuses($run));
    }

    public function testAPageThatThrowsAfterItsDraftChangedLeavesNothing(): void
    {
        $this->repoFiles = ['bad.md' => "# Bad\n", 'good.md' => "# Good\n"];
        $run = $this->startRun();
        $failing = $this->refusingPublish(new \RuntimeException('after the draft'));

        $this->runner($failing)->run($run);

        self::assertSame(['bad.md' => 'failed', 'good.md' => 'failed'], $this->pageStatuses($run));
        self::assertFalse($this->slugExists('bad'), 'the draft created before the failure was rolled back');
        self::assertFalse($this->slugExists('good'));
    }

    public function testAHeldPublicationKeepsTheDraft(): void
    {
        $this->repoFiles = ['held.md' => "# Held\n"];
        $run = $this->startRun();

        $this->runner($this->refusingPublish(new PublishBlocked('review', 'needs a reviewer')))->run($run);

        self::assertSame(['held.md' => 'created'], $this->pageStatuses($run));
        self::assertTrue($this->slugExists('held'));
        $pages = $this->container()->get(\Thallo\Core\Content\DocsSync\DocsSyncRunRepository::class)->pages($run);
        self::assertStringContainsString('Saved as a draft', (string) $pages[0]['message']);
    }

    public function testPreviewWritesNothing(): void
    {
        $this->repoFiles = ['one.md' => "# One\n"];
        $run = $this->startRun('dry_run');

        $this->runner()->run($run);

        self::assertSame('success', $this->runRow($run)['outcome']);
        self::assertSame(['one.md' => 'created'], $this->pageStatuses($run), 'what it would do');
        self::assertFalse($this->slugExists('one'));
        self::assertNull($this->stateRow()['synced_run']);

        $run = $this->startRun('commit');
        $this->runner()->run($run);
        self::assertTrue($this->slugExists('one'));
    }

    public function testMoreThanAThousandPagesCountExactly(): void
    {
        for ($i = 0; $i < 1200; $i++) {
            $this->repoFiles[sprintf('p%04d.md', $i)] = "# Page {$i}\n";
        }
        $run = $this->startRun('dry_run');

        $this->runner()->run($run);

        self::assertSame(1200, (int) $this->runRow($run)['created']);
        self::assertCount(1200, $this->pageStatuses($run));
    }

    public function testDeletedContentTypeIsRefused(): void
    {
        // Review Focus 4: the saved type no longer exists when the run reads the folder.
        $this->saveSource(['content_type' => 'gone-type']);
        $this->repoFiles = ['one.md' => "# One\n"];
        $run = $this->startRun();

        $this->runner()->run($run);

        $row = $this->runRow($run);
        self::assertSame('refused', $row['outcome']);
        self::assertStringContainsString('There is no content type', (string) $row['message']);
        self::assertNull($this->stateRow()['active_run']);
    }

    public function testImportersSwitchedOffIsRefusedWithItsReason(): void
    {
        // Review M4: the capability gate throws a ForbiddenException; the run is refused, not left
        // to die five times with no reason.
        $this->repoFiles = ['one.md' => "# One\n"];
        $inner = $this->container()->get(\Thallo\Contracts\Capability\CapabilityRegistry::class);
        $off = new class ($inner) implements \Thallo\Contracts\Capability\CapabilityRegistry {
            public function __construct(private readonly \Thallo\Contracts\Capability\CapabilityRegistry $inner)
            {
            }

            public function register(\Thallo\Contracts\Capability\Capability $capability): void
            {
                $this->inner->register($capability);
            }

            public function all(): array
            {
                return $this->inner->all();
            }

            public function enabled(): array
            {
                return $this->inner->enabled();
            }

            public function isEnabled(string $id): bool
            {
                return $id !== 'thallo.importers' && $this->inner->isEnabled($id);
            }

            public function isRequestedEnabled(string $id): bool
            {
                return $this->inner->isRequestedEnabled($id);
            }

            public function availability(string $id): \Thallo\Contracts\Capability\CapabilityAvailability
            {
                return $this->inner->availability($id);
            }
        };
        $c = $this->container();
        $folders = new \Thallo\Importers\Markdown\MarkdownFolderImport(
            $this->writer(),
            $c->get(\Thallo\Contracts\Authoring\ContentUpserter::class),
            $c->get(\Thallo\Contracts\Schema\ContentTypeReader::class),
            $off,
        );
        $runner = new \Thallo\Core\Content\DocsSync\DocsSyncRunner(
            $this->connection(),
            $c->get(\Thallo\Core\Content\DocsSync\DocsSyncStore::class),
            $c->get(\Thallo\Core\Content\DocsSync\DocsSyncRunRepository::class),
            $this->github(),
            $folders,
            $c->get(\Glueful\Storage\StorageManager::class),
            new RecordingQueue(),
            $this->appContext(),
        );
        $run = $this->startRun();

        $runner->run($run);

        $row = $this->runRow($run);
        self::assertSame('refused', $row['outcome']);
        self::assertStringContainsString('thallo.importers', (string) $row['message']);
        self::assertNull($this->stateRow()['active_run']);
    }

    public function testAHandImportFollowedBySyncUpdatesNotDuplicates(): void
    {
        $this->repoFiles = ['guides/one.md' => "# One\n"];
        $checkout = sys_get_temp_dir() . '/thallo-checkout-' . bin2hex(random_bytes(4));
        mkdir($checkout . '/guides', 0777, true);
        file_put_contents($checkout . '/guides/one.md', "# One\n");
        $this->folders()->run($checkout, [
            'type' => 'docs', 'publish' => true,
            'edit_base' => 'https://github.com/glueful/thallo/edit/main/docs',
        ]);
        \Thallo\Importers\Markdown\MarkdownZip::remove($checkout);

        $run = $this->startRun();
        $this->runner()->run($run);

        self::assertSame(['guides/one.md' => 'unchanged'], $this->pageStatuses($run));
        $type = (string) $this->container()->get(\Thallo\Contracts\Schema\ContentTypeReader::class)
            ->findUuidBySlug('docs');
        $paths = $this->container()->get(\Thallo\Contracts\Authoring\ContentUpserter::class)
            ->fieldValues($type, 'en', 'source_path');
        self::assertSame(['guides/one.md'], array_values($paths), 'found again, not duplicated');
    }

    public function testAnUnreachableGithubIsRecordedAndEndsFailedWithItsReason(): void
    {
        // Review I2: retried within the budget; once it is spent, the run is `failed` with the
        // reason, not `interrupted` with none.
        $this->repoFiles = ['one.md' => "# One\n"];
        $this->archiveOnce = fn (): MockResponse => new MockResponse('', ['error' => 'connection refused']);
        $run = $this->startRun();

        try {
            $this->runner()->run($run);
            self::fail('The attempt ends, for recovery to retry.');
        } catch (\Thallo\Core\Content\DocsSync\GithubRefused $e) {
            self::assertTrue($e->transient);
        }
        self::assertStringContainsString('could not be reached', (string) $this->runRow($run)['message']);

        $this->setRun($run, ['attempts' => 5]);
        $this->setState(['lease_at' => self::ago(200)]);
        $this->service()->recover();

        $row = $this->runRow($run);
        self::assertSame('failed', $row['outcome']);
        self::assertStringContainsString('could not be reached', (string) $row['message']);
    }

    public function testARunThatEndsWithoutLandingDeletesItsArchive(): void
    {
        // Review M6: a run refused after its download keeps no 13 MB archive for the daily prune.
        $this->saveSource(['folder' => 'Docs']);
        $this->repoFiles = ['one.md' => "# One\n"];
        $run = $this->startRun();

        $this->runner()->run($run);

        self::assertSame('refused', $this->runRow($run)['outcome']);
        self::assertFalse(
            $this->container()->get(\Glueful\Storage\StorageManager::class)->disk('uploads')
                ->directoryExists('docs-sync/' . $run),
        );
    }

    public function testAFinishedRunDeletesItsArchiveFolder(): void
    {
        $this->repoFiles = ['one.md' => "# One\n"];
        $run = $this->startRun();

        $this->runner()->run($run);

        self::assertFalse(
            $this->container()->get(\Glueful\Storage\StorageManager::class)->disk('uploads')
                ->directoryExists('docs-sync/' . $run),
        );
    }

    public function testPageRowsRecordTheirEntryAndGoneRowsTheEntryLeftBehind(): void
    {
        // Review M9: the pages list can link to each entry.
        $this->repoFiles = ['one.md' => "# One\n", 'two.md' => "# Two\n"];
        $run = $this->startRun();
        $this->runner()->run($run);
        $type = (string) $this->container()->get(\Thallo\Contracts\Schema\ContentTypeReader::class)
            ->findUuidBySlug('docs');
        $up = $this->container()->get(\Thallo\Contracts\Authoring\ContentUpserter::class);
        $pages = array_column(
            $this->container()->get(\Thallo\Core\Content\DocsSync\DocsSyncRunRepository::class)->pages($run),
            null,
            'path',
        );
        self::assertSame($up->findBySlug($type, 'en', 'one'), $pages['one.md']['entry_uuid']);

        $this->repoFiles = ['one.md' => "# One\n"];
        $next = $this->startRun();
        $this->runner()->run($next);
        $gone = array_column(
            $this->container()->get(\Thallo\Core\Content\DocsSync\DocsSyncRunRepository::class)->pages($next),
            null,
            'path',
        )['two.md'];
        self::assertSame('gone', $gone['status']);
        self::assertSame($up->findBySlug($type, 'en', 'two'), $gone['entry_uuid']);
    }

    public function testTheRepositoryRootHoldingNoPagesSaysSo(): void
    {
        // Review M10: not "The folder `` holds no pages."
        $this->saveSource(['folder' => '']);
        $this->repoFiles = [];
        $this->withReadme = false;
        $run = $this->startRun();

        $this->runner()->run($run);

        self::assertSame('refused', $this->runRow($run)['outcome']);
        self::assertStringNotContainsString('``', (string) $this->runRow($run)['message']);
    }

    public function testATruncatedDownloadIsDownloadedAgain(): void
    {
        $this->repoFiles = ['one.md' => "# One\n"];
        $this->archiveOnce = fn (): MockResponse => new MockResponse(
            'PK',
            ['response_headers' => ['content-length' => '9999']],
        );
        $run = $this->startRun();

        try {
            $this->runner()->run($run);
            self::fail('A download cut short ends the attempt, for the next one to try again.');
        } catch (\Thallo\Core\Content\DocsSync\GithubRefused $e) {
            self::assertTrue($e->transient);
        }
        self::assertNull($this->runRow($run)['archive_path']);
        self::assertSame('running', $this->runRow($run)['status']);

        $this->setState(['lease_at' => self::ago(200)]);
        $this->runner()->run($run);

        self::assertSame('success', $this->runRow($run)['outcome']);
        self::assertSame(2, $this->archiveRequests);
    }
}
