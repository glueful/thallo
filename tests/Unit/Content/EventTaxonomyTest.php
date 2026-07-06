<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Events\AssetAttached;
use App\Content\Events\AssetDetached;
use App\Content\Events\BaseContentEvent;
use App\Content\Events\BaseEntryEvent;
use App\Content\Events\BaseModelEvent;
use App\Content\Events\EntryCreated;
use App\Content\Events\EntryDeleted;
use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUnpublished;
use App\Content\Events\EntryUpdated;
use App\Content\Events\ModelCreated;
use App\Content\Events\ModelDeleted;
use App\Content\Events\ModelUpdated;
use Glueful\Extensions\Audit\Contracts\AuditableEvent;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Thallo content-event taxonomy as a frozen API contract.
 *
 * The event names returned by name() are a public contract (V1_DESIGN §5):
 * any rename must break this test. Payloads NEVER carry a `fields` key —
 * receivers fetch the full record via the delivery API with their own key.
 */
final class EventTaxonomyTest extends TestCase
{
    /**
     * The 10 frozen event names. A rename anywhere breaks this map.
     *
     * @return array<class-string<BaseContentEvent>, string>
     */
    private const FROZEN_NAMES = [
        EntryCreated::class => 'entry.created',
        EntryUpdated::class => 'entry.updated',
        EntryPublished::class => 'entry.published',
        EntryUnpublished::class => 'entry.unpublished',
        EntryDeleted::class => 'entry.deleted',
        ModelCreated::class => 'model.created',
        ModelUpdated::class => 'model.updated',
        ModelDeleted::class => 'model.deleted',
        AssetAttached::class => 'asset.attached',
        AssetDetached::class => 'asset.detached',
    ];

    public function testTaxonomyCoversExactlyTenEvents(): void
    {
        self::assertCount(10, self::FROZEN_NAMES);
        self::assertSame(
            ['entry.created', 'entry.updated', 'entry.published', 'entry.unpublished',
             'entry.deleted', 'model.created', 'model.updated', 'model.deleted',
             'asset.attached', 'asset.detached'],
            array_values(self::FROZEN_NAMES)
        );
    }

    /**
     * @return iterable<string, array{class-string<BaseContentEvent>}>
     */
    public static function entryEventProvider(): iterable
    {
        yield 'entry.created' => [EntryCreated::class];
        yield 'entry.updated' => [EntryUpdated::class];
        yield 'entry.published' => [EntryPublished::class];
        yield 'entry.unpublished' => [EntryUnpublished::class];
        yield 'entry.deleted' => [EntryDeleted::class];
    }

    /**
     * @param class-string<BaseContentEvent> $eventClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entryEventProvider')]
    public function testEntryEventNameAndPayloadShape(string $eventClass): void
    {
        $event = new $eventClass(
            entry: 'entry-uuid-1',
            type: 'article',
            locale: 'en',
            version: 3,
            actor: 'user-uuid-7',
        );

        self::assertSame(self::FROZEN_NAMES[$eventClass], $event->name());

        $payload = $event->payload();

        self::assertSame(
            ['entry', 'type', 'locale', 'version', 'actor', 'timestamp'],
            array_keys($payload)
        );
        self::assertSame('entry-uuid-1', $payload['entry']);
        self::assertSame('article', $payload['type']);
        self::assertSame('en', $payload['locale']);
        self::assertSame(3, $payload['version']);
        self::assertSame('user-uuid-7', $payload['actor']);
        self::assertIsFloat($payload['timestamp']);

        self::assertArrayNotHasKey('fields', $payload);
    }

    public function testEntryEventAllowsNullLocaleAndVersion(): void
    {
        $event = new EntryCreated(
            entry: 'entry-uuid-2',
            type: 'page',
            locale: null,
            version: null,
            actor: null,
        );

        $payload = $event->payload();

        self::assertNull($payload['locale']);
        self::assertNull($payload['version']);
        self::assertNull($payload['actor']);
        self::assertArrayNotHasKey('fields', $payload);
    }

    /**
     * @return iterable<string, array{class-string<BaseContentEvent>}>
     */
    public static function modelEventProvider(): iterable
    {
        yield 'model.created' => [ModelCreated::class];
        yield 'model.updated' => [ModelUpdated::class];
        yield 'model.deleted' => [ModelDeleted::class];
    }

    /**
     * @param class-string<BaseContentEvent> $eventClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelEventProvider')]
    public function testModelEventNameAndPayloadShape(string $eventClass): void
    {
        $event = new $eventClass(
            type: 'article',
            actor: 'user-uuid-7',
        );

        self::assertSame(self::FROZEN_NAMES[$eventClass], $event->name());

        $payload = $event->payload();

        // Model events describe a content-type change: no locale/version.
        self::assertSame(['type', 'actor', 'timestamp'], array_keys($payload));
        self::assertSame('article', $payload['type']);
        self::assertSame('user-uuid-7', $payload['actor']);
        self::assertIsFloat($payload['timestamp']);

        self::assertArrayNotHasKey('locale', $payload);
        self::assertArrayNotHasKey('version', $payload);
        self::assertArrayNotHasKey('fields', $payload);
    }

    /**
     * @return iterable<string, array{class-string<BaseContentEvent>}>
     */
    public static function assetEventProvider(): iterable
    {
        yield 'asset.attached' => [AssetAttached::class];
        yield 'asset.detached' => [AssetDetached::class];
    }

    /**
     * @param class-string<BaseContentEvent> $eventClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('assetEventProvider')]
    public function testAssetEventNameAndPayloadShape(string $eventClass): void
    {
        $event = new $eventClass(
            asset: 'blob-uuid-9',
            entry: 'entry-uuid-1',
            actor: 'user-uuid-7',
        );

        self::assertSame(self::FROZEN_NAMES[$eventClass], $event->name());

        $payload = $event->payload();

        self::assertSame(['asset', 'entry', 'actor', 'timestamp'], array_keys($payload));
        self::assertSame('blob-uuid-9', $payload['asset']);
        self::assertSame('entry-uuid-1', $payload['entry']);
        self::assertSame('user-uuid-7', $payload['actor']);
        self::assertIsFloat($payload['timestamp']);

        self::assertArrayNotHasKey('fields', $payload);
    }

    public function testEventsExtendBaseContentEventAndCarryFrameworkMetadata(): void
    {
        $event = new EntryPublished('entry-uuid-1', 'article', 'en', 1, 'actor-1');

        self::assertInstanceOf(BaseContentEvent::class, $event);
        // BaseEvent assigns an event id + timestamp via parent::__construct().
        self::assertNotEmpty($event->getEventId());
        self::assertGreaterThan(0.0, $event->getTimestamp());
    }

    // ---- glueful/audit integration (AuditableEvent) ---------------------------

    public function testEveryContentEventIsAuditable(): void
    {
        foreach (array_keys(self::FROZEN_NAMES) as $class) {
            self::assertInstanceOf(AuditableEvent::class, $this->makeEvent($class), $class);
        }
    }

    public function testAuditActionIsTheSegmentAfterTheDotForEveryEvent(): void
    {
        foreach (self::FROZEN_NAMES as $class => $name) {
            $expected = substr($name, (int) strrpos($name, '.') + 1);
            self::assertSame($expected, $this->makeEvent($class)->auditAction(), $name);
        }
    }

    public function testEntryEventAuditMapping(): void
    {
        $event = new EntryPublished('entry-uuid-1', 'article', 'en', 3, 'actor-1');

        self::assertSame('published', $event->auditAction());
        self::assertSame('content', $event->auditCategory());
        self::assertSame(
            ['type' => 'content_entry', 'uuid' => 'entry-uuid-1', 'label' => 'article'],
            $event->auditTarget(),
        );
        self::assertNull($event->auditChanges());
        // Identity context only — actor/timestamp become their own audit columns.
        self::assertSame(
            ['entry' => 'entry-uuid-1', 'type' => 'article', 'locale' => 'en', 'version' => 3],
            $event->auditMetadata(),
        );
    }

    public function testModelEventAuditMapping(): void
    {
        $event = new ModelUpdated(type: 'article', actor: 'actor-1');

        self::assertSame('updated', $event->auditAction());
        self::assertSame('content', $event->auditCategory());
        self::assertSame(
            ['type' => 'content_type', 'uuid' => 'article', 'label' => 'article'],
            $event->auditTarget(),
        );
        self::assertSame(['type' => 'article'], $event->auditMetadata());
    }

    public function testAssetEventAuditMapping(): void
    {
        $event = new AssetAttached(asset: 'blob-9', entry: 'entry-1', actor: 'actor-1');

        self::assertSame('attached', $event->auditAction());
        self::assertSame('content', $event->auditCategory());
        self::assertSame(
            ['type' => 'asset', 'uuid' => 'blob-9', 'label' => null],
            $event->auditTarget(),
        );
        self::assertSame(['asset' => 'blob-9', 'entry' => 'entry-1'], $event->auditMetadata());
    }

    public function testAuditMetadataDropsNullIdentityFields(): void
    {
        // A create with no locale/version must not leak nulls into the audit context.
        $event = new EntryCreated('entry-2', 'page', null, null, null);

        self::assertSame(['entry' => 'entry-2', 'type' => 'page'], $event->auditMetadata());
    }

    public function testAuditActorComesFromTheEventActor(): void
    {
        // The event carries the actor uuid (the audit subscriber uses this when no request resolves
        // one — after-commit dispatch / CLI). No actor → empty, deferring to request resolution.
        $published = new EntryPublished('e-1', 'article', 'en', 1, 'actor-7');
        self::assertSame(['uuid' => 'actor-7'], $published->auditActor());
        self::assertSame([], (new EntryCreated('e-2', 'page', null, null, null))->auditActor());
    }

    /**
     * @param class-string<BaseContentEvent> $class
     */
    private function makeEvent(string $class): BaseContentEvent
    {
        if (is_subclass_of($class, BaseEntryEvent::class)) {
            return new $class('entry-1', 'article', 'en', 1, 'actor-1');
        }
        if (is_subclass_of($class, BaseModelEvent::class)) {
            return new $class('article', 'actor-1');
        }

        return new $class('blob-1', 'entry-1', 'actor-1'); // asset events
    }
}
