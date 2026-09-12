<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use Thallo\Core\Content\Events\EntryCreated;
use PHPUnit\Framework\TestCase;

/**
 * The audit actor carried by a content event must expose the uuid and, once resolved, a
 * human-readable label — so after-commit audit rows show email/username, not a bare uuid.
 */
final class ContentEventAuditActorTest extends TestCase
{
    private function event(?string $actor): EntryCreated
    {
        return new EntryCreated(
            entry: 'ent000000001',
            type: 'type00000001',
            locale: 'en',
            version: null,
            actor: $actor,
        );
    }

    public function testActorUuidOnlyWhenNoLabelResolved(): void
    {
        self::assertSame(['uuid' => 'usr000000001'], $this->event('usr000000001')->auditActor());
    }

    public function testResolvedLabelIsIncluded(): void
    {
        $e = $this->event('usr000000001');
        $e->setAuditActorLabel('jane@example.com');
        self::assertSame(['uuid' => 'usr000000001', 'label' => 'jane@example.com'], $e->auditActor());
    }

    public function testEmptyLabelIsIgnored(): void
    {
        $e = $this->event('usr000000001');
        $e->setAuditActorLabel('');
        self::assertSame(['uuid' => 'usr000000001'], $e->auditActor());
        $e->setAuditActorLabel(null);
        self::assertSame(['uuid' => 'usr000000001'], $e->auditActor());
    }

    public function testNoActorYieldsEmpty(): void
    {
        $e = $this->event(null);
        $e->setAuditActorLabel('jane@example.com'); // label without an actor uuid is meaningless
        self::assertSame([], $e->auditActor());
    }
}
