<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pipeline;

use App\Content\Events\AssetAttached;
use App\Content\Events\AssetDetached;
use App\Content\Events\EntryUpdated;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Support\OptimisticLockException;
use App\Tests\Support\AppTestCase;
use Glueful\Events\EventService;

/**
 * Proves the asset-delta contract for draft saves (V1_DESIGN §8): on each draft
 * save we diff the entry's PREVIOUS asset-field targets against the NEW ones and
 * emit one AssetAttached per newly-referenced blob and one AssetDetached per
 * removed blob. These are ADDITIVE delta events — they do not replace or affect
 * the single primary EntryUpdated, and a save that leaves asset fields unchanged
 * emits no asset event.
 *
 * Spy listeners are registered on the real container EventService and the save
 * runs through the container-resolved EntryRepository, so the wired
 * PublishEventEmitter is exercised exactly as production would.
 */
final class AssetEventsTest extends AppTestCase
{
    private string $type;
    private string $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'hero', 'type' => 'asset'],
                ['name' => 'gallery', 'type' => 'asset'],
            ],
        ]);
        $entries = $this->containerEntries();
        $this->entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
    }

    private function containerEntries(): EntryRepository
    {
        return $this->container()->get(EntryRepository::class);
    }

    private function events(): EventService
    {
        return $this->container()->get(EventService::class);
    }

    /**
     * Register spy listeners and return an ArrayObject the closures push into by
     * reference. (Returning a plain array would hand the caller a copy while the
     * closures kept writing to this method's local — so the deltas would never be
     * visible to the test.)
     *
     * @return \ArrayObject<string, list<object>>
     */
    private function spy(): \ArrayObject
    {
        /** @var \ArrayObject<string, list<object>> $captured */
        $captured = new \ArrayObject(['attached' => [], 'detached' => [], 'updated' => []]);
        $this->events()->addListener(
            AssetAttached::class,
            function (AssetAttached $e) use ($captured): void {
                $captured['attached'] = [...$captured['attached'], $e];
            }
        );
        $this->events()->addListener(
            AssetDetached::class,
            function (AssetDetached $e) use ($captured): void {
                $captured['detached'] = [...$captured['detached'], $e];
            }
        );
        $this->events()->addListener(
            EntryUpdated::class,
            function (EntryUpdated $e) use ($captured): void {
                $captured['updated'] = [...$captured['updated'], $e];
            }
        );
        return $captured;
    }

    private function lock(): int
    {
        return (int) $this->containerEntries()->findDraft($this->entry, 'en')['lock_version'];
    }

    public function testFirstAttachEmitsAttachedOnly(): void
    {
        $captured = $this->spy();

        $this->containerEntries()->saveDraft(
            $this->entry,
            'en',
            ['title' => 'V1', 'hero' => 'b1abcdefghij'],
            1,
            $this->lock(),
            'user00000001'
        );

        self::assertCount(1, $captured['attached'], 'exactly one asset.attached');
        self::assertSame('asset.attached', $captured['attached'][0]->name());
        self::assertSame('b1abcdefghij', $captured['attached'][0]->asset);
        self::assertSame($this->entry, $captured['attached'][0]->entry);
        self::assertCount(0, $captured['detached'], 'no detach on first attach');
        self::assertCount(1, $captured['updated'], 'primary EntryUpdated still fires once');
    }

    public function testReplaceEmitsDetachOldAndAttachNew(): void
    {
        // First save: hero = b1
        $this->containerEntries()->saveDraft(
            $this->entry,
            'en',
            ['title' => 'V1', 'hero' => 'b1abcdefghij'],
            1,
            $this->lock(),
            'user00000001'
        );

        $captured = $this->spy();

        // Replace hero b1 -> b2
        $this->containerEntries()->saveDraft(
            $this->entry,
            'en',
            ['title' => 'V1', 'hero' => 'b2abcdefghij'],
            1,
            $this->lock(),
            'user00000001'
        );

        self::assertCount(1, $captured['attached']);
        self::assertSame('b2abcdefghij', $captured['attached'][0]->asset);
        self::assertCount(1, $captured['detached']);
        self::assertSame('b1abcdefghij', $captured['detached'][0]->asset);
        self::assertSame($this->entry, $captured['detached'][0]->entry);
        self::assertCount(1, $captured['updated'], 'primary EntryUpdated still fires once');
    }

    public function testTitleOnlyChangeEmitsNoAssetEvent(): void
    {
        // First save: hero = b1
        $this->containerEntries()->saveDraft(
            $this->entry,
            'en',
            ['title' => 'V1', 'hero' => 'b1abcdefghij'],
            1,
            $this->lock(),
            'user00000001'
        );

        $captured = $this->spy();

        // Change only the title; hero unchanged.
        $this->containerEntries()->saveDraft(
            $this->entry,
            'en',
            ['title' => 'V2', 'hero' => 'b1abcdefghij'],
            1,
            $this->lock(),
            'user00000001'
        );

        self::assertCount(0, $captured['attached'], 'unchanged asset field emits no attach');
        self::assertCount(0, $captured['detached'], 'unchanged asset field emits no detach');
        self::assertCount(1, $captured['updated'], 'primary EntryUpdated still fires once');
    }

    public function testNoOpDraftSaveEmitsNoUpdatedEvent(): void
    {
        $this->containerEntries()->saveDraft(
            $this->entry,
            'en',
            ['title' => 'V1', 'hero' => 'b1abcdefghij'],
            1,
            $this->lock(),
            'user00000001'
        );

        $captured = $this->spy();

        $this->containerEntries()->saveDraft(
            $this->entry,
            'en',
            ['title' => 'V1', 'hero' => 'b1abcdefghij'],
            1,
            $this->lock(),
            'user00000001'
        );

        self::assertCount(0, $captured['attached'], 'unchanged asset field emits no attach');
        self::assertCount(0, $captured['detached'], 'unchanged asset field emits no detach');
        self::assertCount(0, $captured['updated'], 'unchanged draft emits no primary update');
    }

    public function testMultiAssetDiffOnlyEmitsDelta(): void
    {
        // First save: gallery = [b1, b2]
        $this->containerEntries()->saveDraft(
            $this->entry,
            'en',
            ['title' => 'V1', 'gallery' => ['b1abcdefghij', 'b2abcdefghij']],
            1,
            $this->lock(),
            'user00000001'
        );

        $captured = $this->spy();

        // gallery [b1, b2] -> [b2, b3]: detach b1, attach b3, b2 untouched.
        $this->containerEntries()->saveDraft(
            $this->entry,
            'en',
            ['title' => 'V1', 'gallery' => ['b2abcdefghij', 'b3abcdefghij']],
            1,
            $this->lock(),
            'user00000001'
        );

        $attached = array_map(static fn(AssetAttached $e): string => $e->asset, $captured['attached']);
        $detached = array_map(static fn(AssetDetached $e): string => $e->asset, $captured['detached']);

        self::assertSame(['b3abcdefghij'], $attached, 'only the newly-added blob attaches');
        self::assertSame(['b1abcdefghij'], $detached, 'only the removed blob detaches');
        self::assertCount(1, $captured['updated'], 'primary EntryUpdated still fires once');
    }

    public function testStaleLockEmitsNoEvents(): void
    {
        // First save: hero = b1 (advances lock_version to 1).
        $this->containerEntries()->saveDraft(
            $this->entry,
            'en',
            ['title' => 'V1', 'hero' => 'b1abcdefghij'],
            1,
            $this->lock(),
            'user00000001'
        );

        $captured = $this->spy();

        // Stale save: pass an outdated expected lock version (0) -> 409, rolls back,
        // emits NEITHER the primary nor any asset event.
        try {
            $this->containerEntries()->saveDraft(
                $this->entry,
                'en',
                ['title' => 'V1', 'hero' => 'b9abcdefghij'],
                1,
                0,
                'user00000001'
            );
            self::fail('expected OptimisticLockException on stale save');
        } catch (OptimisticLockException) {
            // expected
        }

        self::assertCount(0, $captured['attached'], 'stale save emits no attach');
        self::assertCount(0, $captured['detached'], 'stale save emits no detach');
        self::assertCount(0, $captured['updated'], 'stale save emits no primary event');
    }

    public function testSoftDeleteDetachesAllCurrentAssets(): void
    {
        $this->containerEntries()->saveDraft(
            $this->entry,
            'en',
            [
                'title' => 'V1',
                'hero' => 'b1abcdefghij',
                'gallery' => ['b1abcdefghij', 'b2abcdefghij'],
            ],
            1,
            $this->lock(),
            'user00000001'
        );

        $captured = $this->spy();

        $this->containerEntries()->softDelete($this->entry);

        $detached = array_map(static fn(AssetDetached $e): string => $e->asset, $captured['detached']);
        sort($detached);

        self::assertSame(['b1abcdefghij', 'b2abcdefghij'], $detached);
        self::assertCount(0, $captured['attached'], 'deleting an entry emits no attach events');
        self::assertSame($this->entry, $captured['detached'][0]->entry);
        self::assertNull($captured['detached'][0]->actor, 'repository softDelete currently has no actor input');
    }
}
