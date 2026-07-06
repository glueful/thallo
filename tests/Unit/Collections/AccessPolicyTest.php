<?php

declare(strict_types=1);

namespace App\Tests\Unit\Collections;

use Thallo\Collections\Schema\AccessPolicy;
use PHPUnit\Framework\TestCase;

final class AccessPolicyTest extends TestCase
{
    public function testDefaultIsScopedForEveryOperation(): void
    {
        $policy = AccessPolicy::default();
        self::assertSame('scoped', $policy->read);
        self::assertSame('scoped', $policy->write);
        self::assertSame('scoped', $policy->delete);
    }

    public function testFromArrayKeepsValidLevelsAndNormalizesUnknownToScoped(): void
    {
        $policy = AccessPolicy::fromArray(['read' => 'public', 'write' => 'scoped', 'delete' => 'bogus']);
        self::assertSame('public', $policy->read);
        self::assertSame('scoped', $policy->write);
        self::assertSame('scoped', $policy->delete);
    }

    public function testFromArrayDefaultsMissingKeysToScoped(): void
    {
        $policy = AccessPolicy::fromArray(['read' => 'public']);
        self::assertSame('public', $policy->read);
        self::assertSame('scoped', $policy->write);
        self::assertSame('scoped', $policy->delete);
    }

    public function testForOperationMapsVerbsToLevelsAndDefaultsToRead(): void
    {
        $policy = new AccessPolicy('public', 'scoped', 'public');
        self::assertSame('public', $policy->forOperation('read'));
        self::assertSame('scoped', $policy->forOperation('write'));
        self::assertSame('public', $policy->forOperation('delete'));
        self::assertSame('public', $policy->forOperation('unknown'));
    }

    public function testConstructorRejectsInvalidLevel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccessPolicy('public', 'nope', 'scoped');
    }

    public function testToArrayRoundTrips(): void
    {
        $policy = new AccessPolicy('public', 'scoped', 'public');
        self::assertSame(['read' => 'public', 'write' => 'scoped', 'delete' => 'public'], $policy->toArray());
    }
}
