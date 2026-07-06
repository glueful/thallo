<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\AppTestCase;
use Thallo\Analytics\Facts\ActorHasher;

final class ActorHasherTest extends AppTestCase
{
    public function testHashIsStableAndSalted(): void
    {
        $hasher = $this->container()->get(ActorHasher::class);

        $a = $hasher->hash('user-uuid-1');
        self::assertSame($a, $hasher->hash('user-uuid-1'), 'stable for the same id');
        self::assertNotSame($a, $hasher->hash('user-uuid-2'), 'distinct for different ids');
        self::assertNotSame('user-uuid-1', $a, 'never the raw id');
        self::assertSame(64, strlen($a), 'sha256 hex');

        // Salted with a different key → different digest.
        $other = new ActorHasher('a-different-key');
        self::assertNotSame($a, $other->hash('user-uuid-1'));
    }
}
