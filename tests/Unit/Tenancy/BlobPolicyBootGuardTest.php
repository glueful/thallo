<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use App\Providers\ThalloServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class BlobPolicyBootGuardTest extends TestCase
{
    public function testMissingPolicyIsAllowedWhileTenancyIsOff(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('has');

        ThalloServiceProvider::assertBlobPolicyReady($container, false);
        self::addToAssertionCount(1);
    }

    public function testMissingPolicyFailsClosedWhenTenancyIsEnabled(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $this->expectException(RuntimeException::class);
        ThalloServiceProvider::assertBlobPolicyReady($container, true);
    }
}
