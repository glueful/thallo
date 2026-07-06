<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Collections\Audit\CollectionRowAuditEvent;
use App\Collections\Audit\CollectionSchemaAuditEvent;
use App\Tests\Support\AppTestCase;
use Glueful\Events\EventService;
use Thallo\Collections\Data\Actor;
use Thallo\Collections\Events\CollectionCreated;
use Thallo\Collections\Events\CollectionDropped;
use Thallo\Collections\Events\CollectionRowCreated;
use Thallo\Collections\Events\CollectionRowDeleted;
use Thallo\Collections\Events\CollectionUpdated;

/**
 * Proves the App audit listener is wired to the pack's pure CollectionRow* events: dispatching one
 * through the app EventService triggers CollectionAuditListener (registered in ThalloServiceProvider),
 * which bridges it to a CollectionRowAuditEvent — the AuditableEvent the Audit extension records.
 */
final class CollectionAuditWiringTest extends AppTestCase
{
    public function testCollectionRowEventsAreBridgedToAuditableEvents(): void
    {
        $events = $this->container()->get(EventService::class);

        /** @var list<CollectionRowAuditEvent> $captured */
        $captured = [];
        $events->addListener(
            CollectionRowAuditEvent::class,
            static function (CollectionRowAuditEvent $e) use (&$captured): void {
                $captured[] = $e;
            },
        );

        $events->dispatch(new CollectionRowCreated('posts', 'row-1', ['uuid' => 'row-1'], new Actor('admin', 'u-1')));
        $events->dispatch(new CollectionRowDeleted('posts', 'row-9', new Actor('api_key', 'k-2')));

        self::assertCount(2, $captured, 'Each CollectionRow* event must bridge to one CollectionRowAuditEvent');
        self::assertSame(
            ['created', 'deleted'],
            array_map(static fn (CollectionRowAuditEvent $e): string => $e->auditAction(), $captured),
        );
        self::assertSame('collections', $captured[0]->auditCategory());
        self::assertSame(['uuid' => 'u-1'], $captured[0]->auditActor());
        self::assertSame('row-9', $captured[1]->auditTarget()['uuid']);
        self::assertSame(['uuid' => 'k-2'], $captured[1]->auditActor());
    }

    public function testCollectionSchemaEventsAreBridgedToAuditableEvents(): void
    {
        $events = $this->container()->get(EventService::class);

        /** @var list<CollectionSchemaAuditEvent> $captured */
        $captured = [];
        $events->addListener(
            CollectionSchemaAuditEvent::class,
            static function (CollectionSchemaAuditEvent $e) use (&$captured): void {
                $captured[] = $e;
            },
        );

        $events->dispatch(new CollectionCreated('posts', 'admin', 'u-1'));
        $events->dispatch(new CollectionUpdated('posts', 'field_added', 'title', 'admin', 'u-1'));
        $events->dispatch(new CollectionDropped('posts', 'admin', 'u-1'));

        self::assertCount(3, $captured, 'Each Collection* schema event must bridge to one CollectionSchemaAuditEvent');
        self::assertSame(
            ['created', 'updated', 'deleted'],
            array_map(static fn (CollectionSchemaAuditEvent $e): string => $e->auditAction(), $captured),
        );
        self::assertSame('collections', $captured[0]->auditCategory());
        self::assertSame(
            ['type' => 'collection', 'uuid' => 'posts', 'label' => 'posts'],
            $captured[0]->auditTarget(),
        );
        self::assertSame(['uuid' => 'u-1'], $captured[0]->auditActor());
        self::assertSame(['change' => 'field_added', 'detail' => 'title'], $captured[1]->auditMetadata());
    }
}
