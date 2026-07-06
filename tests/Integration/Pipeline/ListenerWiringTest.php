<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pipeline;

use App\Content\Events\AssetAttached;
use App\Content\Events\AssetDetached;
use App\Content\Events\EntryCreated;
use App\Content\Events\EntryDeleted;
use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUnpublished;
use App\Content\Events\EntryUpdated;
use App\Content\Events\ModelCreated;
use App\Content\Events\ModelDeleted;
use App\Content\Events\ModelUpdated;
use App\Tests\Support\AppTestCase;
use Glueful\Events\EventService;

/**
 * Regression guard for ThalloServiceProvider::boot() -> registerEventListeners().
 *
 * The pipeline is "events emitted afterCommit -> listeners react". If the boot-time
 * addListener() calls silently never run (the classic "listeners not registered"
 * failure mode — a guard flag left true, an exception swallowed in boot, the map
 * losing an entry), every event would dispatch into the void: no cache invalidation,
 * no webhooks, no CDN purge, no reindex — and NO error. The per-effect tests
 * (CacheInvalidationTest, WebhookDispatchTest, ...) each prove their own listener fires
 * end-to-end via a real publish, but this test pins the WHOLE wiring map in one place so
 * a dropped event/listener row is caught directly rather than as a confusing absence of
 * a side effect somewhere downstream.
 *
 * EventService API (vendor/glueful/framework/src/Events/EventService.php):
 *   - hasListeners(string $eventClass): bool   -> count(getListeners()) > 0
 *   - getListeners(string $eventClass): array  -> resolved listener callables
 * Lazy '@'serviceId listeners resolve to ContainerListener instances, so the COUNT of
 * listeners per event is a faithful, stable assertion of how many listeners are wired.
 */
final class ListenerWiringTest extends AppTestCase
{
    private function events(): EventService
    {
        return $this->container()->get(EventService::class);
    }

    /**
     * Every event that should drive at least one listener must report hasListeners().
     * This is the core "the addListener calls actually ran at boot" assertion.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function listenedEvents(): iterable
    {
        yield 'EntryPublished' => [EntryPublished::class];
        yield 'EntryUnpublished' => [EntryUnpublished::class];
        yield 'EntryDeleted' => [EntryDeleted::class];
        yield 'EntryUpdated' => [EntryUpdated::class];
        yield 'EntryCreated' => [EntryCreated::class];
        yield 'ModelCreated' => [ModelCreated::class];
        yield 'ModelUpdated' => [ModelUpdated::class];
        yield 'ModelDeleted' => [ModelDeleted::class];
        yield 'AssetAttached' => [AssetAttached::class];
        yield 'AssetDetached' => [AssetDetached::class];
    }

    /**
     * @param class-string $eventClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('listenedEvents')]
    public function testEventHasListenersRegisteredAtBoot(string $eventClass): void
    {
        self::assertTrue(
            $this->events()->hasListeners($eventClass),
            $eventClass . ' must have its pipeline listener(s) registered by '
                . 'ThalloServiceProvider::boot() — none found, so the boot-time '
                . 'addListener() calls did not run for this event.'
        );
    }

    /**
     * The SPECIFIC listener count per event is part of the contract — a partial map
     * (e.g. EntryPublished wired to webhook but not cache) is a real bug a plain
     * hasListeners() check would miss. Counts mirror the map in registerEventListeners():
     *   - entry lifecycle (publish/unpublish/delete/update): cache + webhook + cdn + reindex = 4
     *   - entry/model create + remaining model events:       cache + webhook + cdn        = 3
     *   - asset delta events:                                webhook only                 = 1
     *
     * Asserted as a MINIMUM, not an exact equality: the ListenerProvider is a shared
     * singleton and sibling pipeline tests (e.g. AfterCommitDispatchTest) attach extra
     * spy listeners to events like EntryPublished that are never torn down, so the live
     * count in a full-suite process is >= the wired count. assertGreaterThanOrEqual still
     * catches the failure this test guards against — a dropped map row drops the count
     * below the wired minimum — without being brittle to test-time spies.
     *
     * @return iterable<string, array{class-string, int}>
     */
    public static function listenerCounts(): iterable
    {
        yield 'EntryPublished -> 4' => [EntryPublished::class, 4];
        yield 'EntryUnpublished -> 4' => [EntryUnpublished::class, 4];
        yield 'EntryDeleted -> 4' => [EntryDeleted::class, 4];
        yield 'EntryUpdated -> 4' => [EntryUpdated::class, 4];
        yield 'EntryCreated -> 3' => [EntryCreated::class, 3];
        yield 'ModelCreated -> 3' => [ModelCreated::class, 3];
        yield 'ModelUpdated -> 3' => [ModelUpdated::class, 3];
        yield 'ModelDeleted -> 3' => [ModelDeleted::class, 3];
        yield 'AssetAttached -> 1' => [AssetAttached::class, 1];
        yield 'AssetDetached -> 1' => [AssetDetached::class, 1];
    }

    /**
     * @param class-string $eventClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('listenerCounts')]
    public function testEventHasExpectedListenerCount(string $eventClass, int $expected): void
    {
        self::assertGreaterThanOrEqual(
            $expected,
            count($this->events()->getListeners($eventClass)),
            $eventClass . ' is wired to fewer listeners than expected — a row dropped out '
                . 'of the pipeline map in ThalloServiceProvider::registerEventListeners().'
        );
    }
}
