<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\Sources\PublishedEntriesSource;
use Thallo\Core\Content\Palette\BrandColorUsage;
use Thallo\Core\Content\Palette\ColorTokenWalker;
use Thallo\Core\Content\Palette\Http\PaletteController;
use Thallo\Core\Content\Palette\PaletteCacheEffects;
use Thallo\Core\Content\Palette\PaletteDocumentSources;
use Thallo\Core\Content\Palette\PaletteFence;
use Thallo\Core\Content\Palette\PaletteJobRepository;
use Thallo\Core\Content\Palette\PaletteNormalizer;
use Thallo\Core\Content\Palette\PaletteReplaceRunner;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;
use Thallo\Core\Tests\Support\Palette\PaletteReplaceFixtures;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The Replace job against the world moving under it (custom palette spec §4.3, §4.4): an editor's
 * save or a publication landing mid-write, a job failing midway and resuming, cancellation reaching a
 * running worker, stale workers waking after their job finished, and each rewritten page purged after
 * its own write even when the job later fails.
 */
final class PaletteReplaceConcurrencyTest extends AppTestCase
{
    use PaletteFixtures;
    use PaletteReplaceFixtures;
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replaceWorld();
    }

    /** A source double that runs `$before` ahead of each persist (by number, from 0), then delegates. */
    private function wrapping(BlockDocumentSource $inner, \Closure $before): BlockDocumentSource
    {
        return new class ($inner, $before) implements BlockDocumentSource {
            public int $persists = 0;

            public function __construct(private readonly BlockDocumentSource $inner, private readonly \Closure $before)
            {
            }

            public function id(): string
            {
                return $this->inner->id();
            }

            public function each(callable $fn): void
            {
                $this->inner->each($fn);
            }

            public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
            {
                ($this->before)($this->persists++);
                return $this->inner->persist($ref, $fields, $actor);
            }
        };
    }

    private function drafts(): EntryDraftsSource
    {
        return $this->container()->get(EntryDraftsSource::class);
    }

    private function throwingAfter(BlockDocumentSource $inner, int $n): BlockDocumentSource
    {
        return $this->wrapping($inner, static function (int $i) use ($n): void {
            if ($i >= $n) {
                throw new \RuntimeException('the store went away');
            }
        });
    }

    /** @return list<object> the events of a class fired from now on */
    private function recordEvents(string $class): \ArrayObject
    {
        $fired = new \ArrayObject();
        $listener = static function (object $e) use ($fired): void {
            $fired->append($e);
        };
        $this->container()->get(EventService::class)->addListener($class, $listener);
        return $fired;
    }

    public function testAConcurrentEditorSaveMakesPersistFailAndTheJobRewritesTheFreshDocument(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$uuid] = $this->entry();
        $this->saveBody($uuid, [self::heading('color.brand-1')], 'a');
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        // an editor saves (title b, still Brand 1 → mapped to Accent by the fence) just before the job's
        // first write lands, so the job's conditional write fails; the retry re-reads the fresh draft
        $editor = $this->wrapping($this->drafts(), function (int $i) use ($uuid): void {
            if ($i === 0) {
                $this->saveBody($uuid, [self::heading('color.brand-1')], 'b');
            }
        });
        self::assertSame('completed', $this->runnerWith($editor)->run($job)['status']);
        self::assertSame('b', $this->draftFields($uuid)['title'], 'the editor\'s save survived');
        self::assertSame('color.accent', $this->draftToken($uuid));
    }

    public function testAPublicationLandingMidJobIsRewrittenOnRetry(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$uuid] = $this->publishedEntryNaming('color.brand-1');
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $versions = $this->container()->get(VersionRepository::class);
        $racing = $this->wrapping(
            $this->container()->get(PublishedEntriesSource::class),
            function (int $i) use ($uuid, $versions): void {
                if ($i === 0) { // a publication pinned directly, bypassing the fence, as a racing writer would
                    $number = $versions->reserveNextVersionNumber($uuid, 'en');
                    $v = $versions->appendVersion($uuid, 'en', $number, ['title' => 'Racing',
                        'body' => [self::heading('color.brand-1')]], 1, 'user00000001');
                    $versions->pin($uuid, 'en', $v, 'user00000001');
                }
            },
        );
        self::assertSame('completed', $this->runnerWith($racing)->run($job)['status']);
        self::assertSame('color.accent', $this->publishedToken($uuid));
    }

    public function testAJobForcedToFailMidwayLeavesTheSlotUsableAndResumeCompletes(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->draftsNaming('color.brand-1', 3);
        [$published] = $this->publishedEntryNaming('color.brand-1');
        $versionsBefore = $this->versionCount($published);
        $fired = $this->recordEvents(ThemeAppearanceChanged::class);
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $this->runnerWith($this->throwingAfter($this->drafts(), 1))->run($job);
        self::assertSame('failed', $this->jobs()->find($job)?->status);
        self::assertNotEmpty($this->jobs()->find($job)?->failureReport);
        self::assertNotNull($this->palette()->brand(1), 'still configured');
        self::assertSame(0, count($fired));
        $this->service()->resume($job);
        self::assertSame('completed', $this->runner()->run($job)['status']);
        self::assertSame($versionsBefore + 1, $this->versionCount($published), 'one new publication version in all');
        self::assertEquals(
            ['entry_draft' => 4, 'entry_published' => 1],
            $this->jobs()->find($job)?->counts,
            'what the failed run rewrote is counted with what the resume rewrote',
        );
        self::assertCount(1, $fired);
    }

    public function testAnInterruptedJobIsReportedAndResumable(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $this->connection()->table('palette_jobs')->where('id', '=', $job)
            ->update(['heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 300)]);
        $res = $this->container()->get(PaletteController::class)->job($job);
        self::assertSame('interrupted', json_decode((string) $res->getContent(), true)['data']['job']['status']);
        $this->service()->resume($job);
        self::assertSame('running', $this->jobs()->find($job)?->status);
    }

    public function testARunningJobWithAFreshHeartbeatCannotBeResumed(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $this->expectException(\Thallo\Core\Content\Palette\PaletteConflict::class);
        $this->service()->resume($job);
    }

    public function testCancelStopsARunningWorkerAndItNeverClears(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->draftsNaming('color.brand-1', 2);
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        // the cancel commits during the first document's write: the second document is never written
        $cancelling = $this->wrapping($this->drafts(), function (int $i) use ($job): void {
            if ($i === 0) {
                $this->connection()->afterCommit(fn () => $this->service()->cancel($job));
            }
        });
        $this->runnerWith($cancelling)->run($job);
        self::assertSame('cancelled', $this->jobs()->find($job)?->status);
        self::assertNotNull($this->palette()->brand(1));
        self::assertSame(1, $this->countDraftsNaming('color.brand-1'), 'the second document was never written');
    }

    public function testAWorkerResumedAfterCancelWritesNothingEvenAfterTheSlotWasEditedOrANewJobStarted(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->draftsNaming('color.brand-1', 2);
        $old = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $this->service()->cancel($old);
        $recolour = ['theme_brand_1' => '{"name":"Gold","hex":"#99772e"}'];
        $this->mutations()->save($recolour, 'user00000001');
        $new = $this->service()->start(1, 'color.surface', null, 'user00000001');
        $result = $this->runner()->run($old); // the stale worker wakes up
        self::assertSame('cancelled', $result['status']);
        self::assertSame(2, $this->countDraftsNaming('color.brand-1'));
        self::assertNotNull($this->palette()->brand(1));
        self::assertSame('running', $this->jobs()->find($new)?->status);
    }

    public function testAnOldWorkerReachingFailureAfterAnotherCompletedChangesNothing(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $this->runner()->run($job);
        self::assertSame('completed', $this->jobs()->find($job)?->status);
        $this->container()->get(PaletteFence::class)->within(function () use ($job): void {
            $this->state()->lock();
            self::assertFalse($this->jobs()->transition($job, 'failed'), 'what an old worker\'s exhausted path does');
        });
        self::assertSame('completed', $this->jobs()->find($job)?->status, 'terminal');
        self::assertSame([], $this->state()->snapshot()->reservedSlots());
        self::assertSame([], $this->state()->snapshot()->activeJobs);
    }

    public function testAnOldWorkerReachingFailureAfterCancellationChangesNothing(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        $this->draftNaming('color.brand-1');
        $job = $this->service()->start(1, 'color.brand-2', null, 'user00000001');
        // a worker whose writes never land exhausts five passes (three attempts each) and fails — but
        // a cancel commits with its very last attempt, so the failure it then reaches is refused
        $never = new class ($this->drafts(), fn () => $this->service()->cancel($job)) implements BlockDocumentSource {
            private int $calls = 0;

            public function __construct(private readonly BlockDocumentSource $inner, private readonly \Closure $cancel)
            {
            }

            public function id(): string
            {
                return $this->inner->id();
            }

            public function each(callable $fn): void
            {
                $this->inner->each($fn);
            }

            public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
            {
                if (++$this->calls === PaletteReplaceRunner::MAX_PASSES * 3) {
                    ($this->cancel)(); // under the same palette row, committed with this attempt
                }
                return false;
            }
        };
        $result = $this->runnerWith($never)->run($job);
        self::assertSame('cancelled', $result['status']);
        self::assertSame('cancelled', $this->jobs()->find($job)?->status);
        self::assertSame([], $this->state()->snapshot()->reservedSlots(), 'Brand 2 is not reserved again');
        $this->mutations()->clear(2, 'user00000001'); // a reserved slot could not clear
        self::assertNull($this->palette()->brand(2));
    }

    public function testRewrittenPublicationsArePurgedAfterTheirOwnWriteEvenWhenTheJobLaterFails(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$a] = $this->publishedEntryNaming('color.brand-1');
        [$b] = $this->publishedEntryNaming('color.brand-1');
        $purged = new \ArrayObject();
        $recorder = new class ($purged, $this->container()->get(Connection::class)) implements RenderedPageCachePurge {
            public function __construct(private readonly \ArrayObject $purged, private readonly Connection $db)
            {
            }

            public function purge(array $tags): void
            {
                $this->purged->append(['tags' => $tags, 'in_transaction' => $this->db->withinTransaction()]);
            }

            public function purgeAll(): bool
            {
                $this->purge(['*']);
                return true;
            }

            public function purgeWorkspace(string $tenantUuid): bool
            {
                $this->purge(['workspace:' . $tenantUuid]);
                return true;
            }
        };
        $c = $this->container();
        $runner = (new PaletteReplaceRunner(
            $c->get(Connection::class),
            $c->get(PaletteJobRepository::class),
            $c->get(PaletteDocumentSources::class),
            $c->get(PaletteFence::class),
            $this->state(),
            $c->get(PaletteNormalizer::class),
            $c->get(ColorTokenWalker::class),
            $c->get(BrandColorUsage::class),
            $c->get(GeneralSettings::class),
            new PaletteCacheEffects($recorder),
        ))->withSources($c->get(PaletteDocumentSources::class)
            ->replacing($this->throwingAfter($c->get(PublishedEntriesSource::class), 1)));
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $runner->run($job);
        self::assertSame('failed', $this->jobs()->find($job)?->status);
        $first = $purged->getArrayCopy()[0] ?? null;
        self::assertNotNull($first, 'the rewritten publication was purged');
        self::assertContains('thallo:entry:' . $a, $first['tags']);
        self::assertContains('thallo:type:pfrep', $first['tags']);
        self::assertFalse($first['in_transaction'], 'after its write committed');
        self::assertSame('color.accent', $this->publishedToken($a));
        self::assertSame('color.brand-1', $this->publishedToken($b));
    }

    public function testTheHeartbeatKeepsBeatingThroughALongScan(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->draftNaming('color.brand-1');
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $db = $this->connection();
        $seen = new \ArrayObject();
        // a site of many documents: the worker is never mistaken for a dead one mid-scan
        $many = new class ($this->drafts(), $db, $job, $seen) implements BlockDocumentSource {
            public function __construct(
                private readonly BlockDocumentSource $inner,
                private readonly \Glueful\Database\Connection $db,
                private readonly string $job,
                private readonly \ArrayObject $seen,
            ) {
            }

            public function id(): string
            {
                return $this->inner->id();
            }

            public function each(callable $fn): void
            {
                $this->db->table('palette_jobs')->where('id', '=', $this->job)
                    ->update(['heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 600)]);
                $schema = \Thallo\Core\Content\Schema\ContentTypeSchema::fromArray(
                    [['name' => 'body', 'type' => 'blocks']],
                );
                for ($i = 0; $i < 250; $i++) {
                    $fn(new DocumentRef('entry_draft', 'filler' . $i, 'en', '1', $schema, ['body' => []]));
                }
                $row = $this->db->table('palette_jobs')->where('id', '=', $this->job)->first();
                $beat = (string) $row['heartbeat_at'];
                $this->seen->append(strtotime($beat . ' UTC') > time() - 120);
                $this->inner->each($fn);
            }

            public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
            {
                return $this->inner->persist($ref, $fields, $actor);
            }
        };
        $this->runnerWith($many)->run($job);
        self::assertNotEmpty($seen->getArrayCopy());
        self::assertTrue($seen[0], 'beat during the scan, not only before and after');
    }
}
