<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use Thallo\Contracts\Search\ContentReindexer;
use PHPUnit\Framework\TestCase;

final class ContentReindexerContractTest extends TestCase
{
    public function testContractExistsWithReindexEntry(): void
    {
        self::assertTrue(interface_exists(ContentReindexer::class));
        $m = new \ReflectionMethod(ContentReindexer::class, 'reindexEntry');
        self::assertSame('entryUuid', $m->getParameters()[0]->getName());
        self::assertSame('locale', $m->getParameters()[1]->getName());
    }
}
