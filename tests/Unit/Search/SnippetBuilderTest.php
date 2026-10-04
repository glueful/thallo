<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Thallo\Search\Query\SnippetBuilder;

/**
 * Snippets come from current text (search block spec §3.7): match spans are found by best-effort
 * literal matching in the plain text — never in escaped HTML — and each segment is escaped before the
 * trusted `<mark>` tags go between them.
 */
final class SnippetBuilderTest extends TestCase
{
    public function testFindsSpansInPlainTextThenEscapesEachSegment(): void
    {
        self::assertSame(
            'Tom &amp; <mark>Jerry</mark> &lt;3 &quot;cats&quot;',
            SnippetBuilder::build('Tom & Jerry <3 "cats"', 'jer'),
        );
        // Matching runs on plain text: "&" never becomes "&amp;" to be matched by "amp".
        self::assertSame('Tom &amp; Jerry', SnippetBuilder::build('Tom & Jerry', 'amp'));
    }

    public function testMultibyteAndPrefixAndNoMatch(): void
    {
        self::assertSame('Le <mark>Café</mark> noir', SnippetBuilder::build('Le Café noir', 'caf'));
        self::assertSame('Le Café noir', SnippetBuilder::build('Le Café noir', 'zzz'));
        self::assertSame('<mark>Ünïcode</mark> words', SnippetBuilder::build('Ünïcode words', 'ünï'));
    }

    public function testMatchesStartAtWordBoundaries(): void
    {
        self::assertSame('a prose and <mark>roses</mark>', SnippetBuilder::build('a prose and roses', 'roses'));
    }

    public function testALongTextIsCutAroundTheFirstMatchOnCharacterBoundaries(): void
    {
        $text = str_repeat('é ', 200) . 'needle ' . str_repeat('ü ', 200);
        $snippet = SnippetBuilder::build($text, 'needle', 60);
        self::assertStringContainsString('<mark>needle</mark>', $snippet);
        self::assertTrue(mb_check_encoding(strip_tags(html_entity_decode($snippet)), 'UTF-8'));
        self::assertLessThan(200, mb_strlen(strip_tags($snippet)));
    }
}
