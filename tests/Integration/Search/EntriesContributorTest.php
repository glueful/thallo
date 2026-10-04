<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Search\IndexableContentReader;
use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Core\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Search\Index\DocumentBuilder;
use Thallo\Search\Query\VisibilityResolver;
use Thallo\Search\Sources\EntriesContributor;

/**
 * Entries as a search source (search block spec §3.2): one document per published locale, enumerated
 * by whole entry so a page boundary never splits an entry's translations; visibility by content type;
 * and display read from the current published record, never from the index.
 */
final class EntriesContributorTest extends AppTestCase
{
    use SeedsPublishedContent;

    public function testDocumentsCoverEveryPublishedLocaleAndNothingUnpublished(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $docs = $this->contributor()->documents($entry);
        $locales = array_map(static fn ($d): string => $d->locale, $docs);
        sort($locales);
        self::assertSame(['en', 'fr'], $locales);
        self::assertSame('entries', $docs[0]->kind);
        self::assertSame($this->seedType, $docs[0]->subtype);

        $draft = $this->seedEntries->createEntry($this->seedType, 'en', 1, 'user00000001');
        self::assertSame([], $this->contributor()->documents($draft));
    }

    public function testEnumerationIsOrderedAndResumable(): void
    {
        $this->seedBilingualPublishedEntry();
        foreach (['b', 'c', 'd', 'e'] as $slug) {
            $this->publishLocale('en', 'post-' . $slug, 'Post ' . $slug);
        }
        $seen = [];
        $after = null;
        do {
            $page = $this->contributor()->enumerate($after, 2);
            foreach ($page->documents as $doc) {
                $seen[] = $doc->sourceId;
            }
            $after = $page->nextAfter;
        } while ($after !== null);

        // Keyset paging follows the database's own ordering of uuids (its collation, not PHP's
        // byte order); what matters is that every entry comes back exactly once.
        self::assertCount(5, array_unique($seen));
        $entries = array_map(
            static fn ($d): string => $d->sourceId,
            $this->contributor()->enumerate(null, 100)->documents,
        );
        self::assertSame(
            array_values(array_unique($entries)),
            array_values(array_unique($seen)),
            'pages resume in one order',
        );
    }

    public function testATranslatedEntryIsNeverSplitAcrossPages(): void
    {
        $this->seedBilingualPublishedEntry();
        $this->publishLocale('en', 'alpha', 'Alpha');
        $this->publishLocale('en', 'omega', 'Omega');
        foreach ([1, 2] as $size) {
            $pairs = [];
            $after = null;
            do {
                $page = $this->contributor()->enumerate($after, $size);
                foreach ($page->documents as $doc) {
                    $pairs[] = $doc->sourceId . ':' . $doc->locale;
                }
                $after = $page->nextAfter;
            } while ($after !== null);
            self::assertCount(4, $pairs, "page size {$size}");
            self::assertCount(4, array_unique($pairs), "page size {$size}: every (entry, locale) once");
        }
    }

    public function testPresentReadsCurrentRecords(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->seedEntries->saveDraft($entry, 'en', ['title' => 'Hello again'], 1, 1, 'user00000001');
        $this->publishSvc()->publish($entry, 'en', 'user00000001');

        $display = $this->contributor()->present(SearchAudience::public(), 'en', [$entry])[$entry];
        self::assertNotNull($display);
        self::assertStringContainsString('Hello again', $display->title . ' ' . $display->text);
    }

    public function testPresentDropsUnpublishedAndPrivateTypes(): void
    {
        $public = $this->seedBilingualPublishedEntry();
        $private = $this->seedPublishedEntryInType('memo', false, 'en', 'secret', 'Secret memo');
        $present = $this->contributor()->present(SearchAudience::public(), 'en', [$public, $private, 'nosuchentry1']);
        self::assertNotNull($present[$public]);
        self::assertNull($present[$private], 'a private type is never shown to the public');
        self::assertNull($present['nosuchentry1']);

        $keyed = $this->contributor()->present(SearchAudience::apiKey(['read:content:memo']), 'en', [$private]);
        self::assertNotNull($keyed[$private]);
    }

    public function testVisibilityFilterNamesTheVisibleTypes(): void
    {
        $this->seedBilingualPublishedEntry();
        $filter = $this->contributor()->visibilityFilter(SearchAudience::public());
        self::assertSame(KindFilter::SUBTYPES, $filter->mode);
        self::assertContains($this->seedType, $filter->subtypes);
        self::assertSame(
            KindFilter::ALL,
            $this->contributor()->visibilityFilter(SearchAudience::apiKey(['read:content']))->mode,
        );
    }

    public function testTheReindexerJournalsTheEntryWhileSearchIsOn(): void
    {
        // The core's after-commit entry events reach search through ContentReindexer; while search is
        // on, that is the journal (a second boot's events still reach the first boot's listeners in
        // tests, so the reindexer is called as the listener calls it).
        $entry = $this->seedBilingualPublishedEntry();
        $on = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.search' => true]]);
        $on->getContainer()->get(\Thallo\Contracts\Search\ContentReindexer::class)->reindexEntry($entry, 'en');

        self::assertSame(
            1,
            $this->connection()->table('search_index_changes')->where('kind', '=', 'entries')
                ->where('source_id', '=', $entry)->count(),
        );
    }

    private function contributor(): EntriesContributor
    {
        $c = $this->container();
        return new EntriesContributor(
            $c->get(IndexableContentReader::class),
            $c->get(DocumentBuilder::class),
            $c->get(ContentTypeReader::class),
            $c->get(VisibilityResolver::class),
        );
    }
}
