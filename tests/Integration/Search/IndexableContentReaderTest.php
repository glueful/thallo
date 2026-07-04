<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;

final class IndexableContentReaderTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    private function reader(): IndexableContentReader
    {
        return $this->container()->get(IndexableContentReader::class);
    }

    public function testGetIndexablePublishedReturnsPublishedRecord(): void
    {
        // Helper publishes a `blog` entry in en + fr and returns the entry uuid (a string).
        $entry = $this->seedBilingualPublishedEntry();

        $record = $this->reader()->getIndexablePublished($entry, 'en');

        self::assertNotNull($record);
        self::assertSame($entry, $record->entryUuid);
        self::assertSame('en', $record->locale);
        self::assertSame('blog', $record->contentTypeSlug);
        self::assertArrayHasKey('title', $record->fields);
        // Search hrefs are canonical: the default locale collapses.
        self::assertStringContainsString('/blog/', $record->href);
        self::assertStringNotContainsString('/en/', $record->href);
    }

    public function testGetIndexablePublishedReturnsNullForUnpublishedLocale(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        self::assertNull($this->reader()->getIndexablePublished($entry, 'zz'));
    }

    public function testEnumerateIndexablePublishedPagesAndScopesByType(): void
    {
        $this->seedBilingualPublishedEntry();

        $page = $this->reader()->enumerateIndexablePublished(limit: 10, offset: 0, typeSlug: 'blog');

        // IndexablePage carries no total — callers page until a short page.
        self::assertNotSame([], $page->items);
        self::assertSame('blog', $page->items[0]->contentTypeSlug);

        // Unknown type slug yields an empty page, never an error.
        $empty = $this->reader()->enumerateIndexablePublished(limit: 10, offset: 0, typeSlug: 'no-such-type');
        self::assertSame([], $empty->items);
    }
}
