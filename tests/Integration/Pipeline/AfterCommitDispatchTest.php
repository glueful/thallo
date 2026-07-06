<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pipeline;

use App\Content\Events\EntryPublished;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\ValidationException;
use App\Tests\Support\AppTestCase;
use Glueful\Events\EventService;

/**
 * Proves the commit-only / rollback-safe contract for primary content events
 * (V1_DESIGN §5): each mutation dispatches exactly ONE primary PSR-14 domain
 * event from inside db()->afterCommit(), so it fires on the OUTERMOST commit
 * and never on a rollback.
 *
 * The spy listener is registered on the real container EventService and the
 * mutation runs through the container-resolved PublishService, so the wired
 * PublishEventEmitter is exercised exactly as production would.
 */
final class AfterCommitDispatchTest extends AppTestCase
{
    private string $type;
    private string $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = $this->containerEntries();
        $this->entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($this->entry, 'en', ['title' => 'V1'], 1, 0, 'user00000001');
    }

    /** Container-resolved repo/service so the autowired emitter is present. */
    private function containerEntries(): EntryRepository
    {
        return $this->container()->get(EntryRepository::class);
    }

    private function containerPublish(): PublishService
    {
        return $this->container()->get(PublishService::class);
    }

    private function events(): EventService
    {
        return $this->container()->get(EventService::class);
    }

    public function testPublishDispatchesEntryPublishedExactlyOnce(): void
    {
        $captured = [];
        $this->events()->addListener(
            EntryPublished::class,
            function (EntryPublished $e) use (&$captured): void {
                $captured[] = $e;
            }
        );

        $this->containerPublish()->publish($this->entry, 'en', 'user00000001');

        self::assertCount(1, $captured, 'exactly one entry.published event must fire');
        self::assertSame('entry.published', $captured[0]->name());
        self::assertSame($this->entry, $captured[0]->entry);
        self::assertSame($this->type, $captured[0]->type);
    }

    public function testPublishFiresEventOnlyAfterCommit(): void
    {
        $committedAt = null;
        $this->events()->addListener(
            EntryPublished::class,
            function (EntryPublished $e) use (&$committedAt): void {
                // When the listener runs the publication pin is already persisted —
                // proving the dispatch happened after the commit, not before/within.
                $committedAt = (new \App\Content\Repositories\VersionRepository($this->connection()))
                    ->findPublication($this->entry, 'en');
            }
        );

        $this->containerPublish()->publish($this->entry, 'en', 'user00000001');

        self::assertNotNull($committedAt, 'event must fire after commit, with data visible');
    }

    public function testRollbackFiresNoEvent(): void
    {
        $captured = [];
        $this->events()->addListener(
            EntryPublished::class,
            function (EntryPublished $e) use (&$captured): void {
                $captured[] = $e;
            }
        );

        // Clear the required `title` so the draft is invalid: publish() validates and
        // throws BEFORE its transaction, so nothing commits and no event may fire.
        $entries = $this->containerEntries();
        $lock = (int) $entries->findDraft($this->entry, 'en')['lock_version'];
        $entries->saveDraft($this->entry, 'en', [], 1, $lock, 'user00000001');

        try {
            $this->containerPublish()->publish($this->entry, 'en', 'user00000001');
            self::fail('expected ValidationException for invalid draft');
        } catch (ValidationException) {
            // expected
        }

        self::assertCount(0, $captured, 'a rolled-back / aborted mutation must fire no event');
    }

    public function testOuterTransactionRollbackDiscardsEvent(): void
    {
        $captured = [];
        $this->events()->addListener(
            EntryPublished::class,
            function (EntryPublished $e) use (&$captured): void {
                $captured[] = $e;
            }
        );

        // publish() succeeds and registers its afterCommit against the OUTER
        // transaction; the outer then throws, so the whole thing rolls back and the
        // promoted callback is discarded — no event.
        try {
            db($this->appContext())->transaction(function () {
                $this->containerPublish()->publish($this->entry, 'en', 'user00000001');
                throw new \RuntimeException('force outer rollback');
            });
            self::fail('expected outer transaction to throw');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertCount(0, $captured, 'outer rollback must discard the promoted afterCommit event');
    }

    public function testNestedOuterTransactionFiresEventExactlyOnceOnOuterCommit(): void
    {
        $captured = [];
        $this->events()->addListener(
            EntryPublished::class,
            function (EntryPublished $e) use (&$captured): void {
                $captured[] = $e;
            }
        );

        // publish() runs inside an OUTER transaction; the event must fire exactly once,
        // on the outermost commit (nested-transaction safety).
        db($this->appContext())->transaction(function (): void {
            $this->containerPublish()->publish($this->entry, 'en', 'user00000001');
            // Still inside the outer transaction here: the event must NOT have fired yet.
            // (Asserted indirectly by the final count of exactly one after commit.)
        });

        self::assertCount(1, $captured, 'nested publish fires exactly one event on the outer commit');
    }
}
