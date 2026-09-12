<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Events;

use Thallo\Core\Events\MediaDeleted;
use Glueful\Extensions\Audit\Contracts\AuditableEvent;
use PHPUnit\Framework\TestCase;

final class MediaDeletedTest extends TestCase
{
    public function testAuditShapeForADeletion(): void
    {
        $e = new MediaDeleted('blob00000001', 'photo.jpg', 'usr000000001', 'jane@example.com');

        self::assertInstanceOf(AuditableEvent::class, $e);
        self::assertSame('deleted', $e->auditAction());
        self::assertSame('media', $e->auditCategory());
        self::assertSame(['type' => 'media', 'uuid' => 'blob00000001', 'label' => 'photo.jpg'], $e->auditTarget());
        self::assertSame(['uuid' => 'usr000000001', 'label' => 'jane@example.com'], $e->auditActor());
    }

    public function testActorUuidWithoutLabel(): void
    {
        $e = new MediaDeleted('blob00000001', 'photo.jpg', 'usr000000001', null);
        self::assertSame(['uuid' => 'usr000000001'], $e->auditActor());
    }

    public function testNoActorYieldsEmpty(): void
    {
        $e = new MediaDeleted('blob00000001', 'photo.jpg', null, 'jane@example.com');
        self::assertSame([], $e->auditActor());
    }
}
