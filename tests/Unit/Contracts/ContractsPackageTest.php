<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use Thallo\Contracts\ContractsManifest;
use PHPUnit\Framework\TestCase;

final class ContractsPackageTest extends TestCase
{
    public function testPackageAutoloadsUnderContractsNamespace(): void
    {
        self::assertTrue(class_exists(ContractsManifest::class));
        self::assertSame('0.1.0', ContractsManifest::VERSION);
    }
}
