<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use App\Content\Events\EntryCreated;
use Thallo\Contracts\Events\ContentLifecycleEvent;
use PHPUnit\Framework\TestCase;

final class ContentLifecycleEventContractTest extends TestCase
{
    public function testConcreteEventIsALifecycleEvent(): void
    {
        $e = new EntryCreated(
            entry: 'ent000000001',
            type: 'type00000001',
            locale: 'en',
            version: null,
            actor: null,
        );
        self::assertInstanceOf(ContentLifecycleEvent::class, $e);
        self::assertSame('entry.created', $e->name());
        self::assertIsArray($e->payload());
    }
}
