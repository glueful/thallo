<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Contracts\Search\SearchDocument;

/** The contract's value objects refuse what the index could not store (spec §3.2, §3.3). */
final class SearchIdentityTest extends TestCase
{
    public function testADocumentWithABadSourceIdIsRefusedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sourceId');
        new SearchDocument('products', 'a b', '*', null, '/x', 't', 'b');
    }

    public function testAValidDocumentKeepsItsFields(): void
    {
        $doc = new SearchDocument('products', 'Ab12', '*', null, '/shop/products/a', 'A', 'Body', ['price' => '$1.00']);
        self::assertSame('*', $doc->locale);
        self::assertSame(['price' => '$1.00'], $doc->meta);
    }

    public function testAnEmptySubtypeListIsNone(): void
    {
        self::assertSame(KindFilter::NONE, KindFilter::subtypes([])->mode);
        self::assertSame(['a', 'b'], KindFilter::subtypes(['a', 'b', 'a'])->subtypes);
    }

    public function testAudienceFingerprintsAreStable(): void
    {
        self::assertSame('public', SearchAudience::public()->fingerprint());
        self::assertSame(
            SearchAudience::apiKey(['b', 'a'])->fingerprint(),
            SearchAudience::apiKey(['a', 'b'])->fingerprint(),
        );
        self::assertNotSame('public', SearchAudience::apiKey([])->fingerprint());
    }
}
