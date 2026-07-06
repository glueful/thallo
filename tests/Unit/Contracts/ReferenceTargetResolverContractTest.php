<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use Thallo\Contracts\Delivery\ReferenceTargetResolver;
use Thallo\Contracts\Schema\FieldDescriptor;
use PHPUnit\Framework\TestCase;

final class ReferenceTargetResolverContractTest extends TestCase
{
    public function testContractTakesFieldDescriptorNotEngineClass(): void
    {
        self::assertTrue(interface_exists(ReferenceTargetResolver::class));
        $p = (new \ReflectionMethod(ReferenceTargetResolver::class, 'resolve'))->getParameters();
        self::assertSame(FieldDescriptor::class, (string) $p[0]->getType());
        self::assertSame('locale', $p[1]->getName());
        self::assertSame('values', $p[2]->getName());
    }
}
