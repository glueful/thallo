<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Delivery\ContentDeliveryReader;

final class ContentDeliveryReaderContractTest extends AppTestCase
{
    public function testContractResolvesToEngineAdapter(): void
    {
        self::assertInstanceOf(ContentDeliveryReader::class, $this->container()->get(ContentDeliveryReader::class));
    }

    public function testListPublishedReturnsArrayForUnknownTypeWithoutError(): void
    {
        $reader = $this->container()->get(ContentDeliveryReader::class);
        // No published content seeded: an empty, well-typed result, not an exception.
        self::assertSame([], $reader->listPublished('type00000001', 'en', 10));
        self::assertNull($reader->findPublished('type00000001', 'en', 'missing-slug'));
    }
}
