<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Collections;

use Thallo\Core\Collections\Audit\CollectionRowAuditEvent;
use PHPUnit\Framework\TestCase;

final class CollectionRowAuditEventTest extends TestCase
{
    public function testExposesActionCategoryTargetAndActor(): void
    {
        $event = new CollectionRowAuditEvent('created', 'posts', 'row-1', 'u-1');

        self::assertSame('created', $event->auditAction());
        self::assertSame('collections', $event->auditCategory());
        self::assertSame(
            ['type' => 'collection_row', 'uuid' => 'row-1', 'label' => 'posts'],
            $event->auditTarget(),
        );
        // Only the uuid — the Audit extension resolves the email/username label itself.
        self::assertSame(['uuid' => 'u-1'], $event->auditActor());
    }

    public function testAnonymousActorYieldsEmptyAuditActor(): void
    {
        self::assertSame([], (new CollectionRowAuditEvent('deleted', 'posts', 'row-9', null))->auditActor());
    }
}
