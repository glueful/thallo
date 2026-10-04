<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Thallo\Search\Identity\DocumentId;

/**
 * A search document's identity (spec §3.3): `{kind}_{sourceId}_{loc}`, where `loc` is `L` plus the
 * locale or `A` for every locale. Neither kind nor source id may hold `_`, so the first two
 * underscores split it unambiguously, and every character is one Meilisearch accepts in a key.
 */
final class DocumentIdTest extends TestCase
{
    public function testEncodesAndDecodesBothLocaleForms(): void
    {
        self::assertSame('products_Ab12Cd34Ef56_A', DocumentId::encode('products', 'Ab12Cd34Ef56', '*'));
        self::assertSame(
            'entries_' . str_repeat('a', 32) . '_Len-US',
            DocumentId::encode('entries', str_repeat('a', 32), 'en-US'),
        );
        self::assertSame(
            ['kind' => 'products', 'sourceId' => 'Ab12Cd34Ef56', 'locale' => '*'],
            DocumentId::decode('products_Ab12Cd34Ef56_A'),
        );
        self::assertSame(
            ['kind' => 'entries', 'sourceId' => 'x1', 'locale' => 'fr-CA'],
            DocumentId::decode('entries_x1_Lfr-CA'),
        );
    }

    public function testEveryIdIsAValidMeilisearchKeyAndAtMost95Bytes(): void
    {
        $id = DocumentId::encode('abcdefghijklmnop', str_repeat('Z', 64), 'zh-Hant-TW12');
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $id);
        self::assertLessThanOrEqual(95, strlen($id));
    }

    public function testRejectsAnyPartOutsideItsPattern(): void
    {
        foreach ([['Products', 'a', 'en'], ['p', 'a_b', 'en'], ['p', 'a', 'en_US'], ['p', '', 'en']] as [$k, $s, $l]) {
            try {
                DocumentId::encode($k, $s, $l);
                self::fail("accepted {$k}/{$s}/{$l}");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        DocumentId::decode('products_only');
    }
}
