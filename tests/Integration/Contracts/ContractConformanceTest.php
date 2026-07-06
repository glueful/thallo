<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Context\Context;
use Thallo\Contracts\Delivery\ContentDeliveryReader;

final class ContractConformanceTest extends AppTestCase
{
    /**
     * Contracts that core binds to a concrete implementation (Tasks 6–8). These MUST
     * resolve from the container.
     *
     * @return list<array{0:class-string}>
     */
    public static function boundContractProvider(): array
    {
        return [
            [ContentWriter::class],
            [ContentDeliveryReader::class],
            [Context::class],
        ];
    }

    /** @dataProvider boundContractProvider */
    public function testBoundContractResolvesToAConcreteImplementation(string $contract): void
    {
        $impl = $this->container()->get($contract);
        self::assertInstanceOf($contract, $impl);
        self::assertFalse((new \ReflectionClass($impl))->isAbstract());
    }
}
