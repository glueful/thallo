<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Search\Engine\PostgresFtsBackend;
use Thallo\Search\Query\SearchRequest;

/**
 * Search with nothing to install: the database every Thallo site already has. The same contract
 * as the Meilisearch engine — documents by entry+locale, visibility enforced INSIDE the query, a
 * highlighted snippet that is safe to emit — and a docs search box's manners: it finds a word
 * from its first letters, and a word by its stem.
 */
final class PostgresFtsBackendTest extends AppTestCase
{
    private const DOCS = 'typedocs0001';
    private const BLOG = 'typeblog0001';

    private function backend(int $snippet = 12): PostgresFtsBackend
    {
        return new PostgresFtsBackend($this->connection(), $snippet);
    }

    /** @return array<string,mixed> */
    private static function doc(string $entry, string $title, string $body, array $more = []): array
    {
        $locale = (string) ($more['locale'] ?? 'en');
        return $more + [
            'id' => "{$entry}_{$locale}",
            'entry_uuid' => $entry,
            'locale' => $locale,
            'content_type_uuid' => self::DOCS,
            'content_type_slug' => 'docs',
            'href' => "https://site.test/docs/{$entry}",
            'title' => $title,
            'body' => $body,
        ];
    }

    private function seed(): PostgresFtsBackend
    {
        $backend = $this->backend();
        $backend->ensureIndex();
        $backend->upsert([
            self::doc(
                'install00001',
                'Installing Thallo',
                'Run composer to create a project, then provision the database.',
            ),
            self::doc('theming00001', 'Theming', 'A theme is a folder. Installing a theme is copying it into themes. '
                . str_repeat('Filler words about templates and stylesheets. ', 30)),
            self::doc('upgrade00001', 'Upgrading', 'Pull the tag, install dependencies, run the migrations.'),
            self::doc('post00000001', 'Install party', 'A blog post.', [
                'content_type_uuid' => self::BLOG, 'content_type_slug' => 'blog',
            ]),
            self::doc('install00001', 'Installer Thallo', 'Lancez composer pour créer un projet.', ['locale' => 'fr']),
        ]);
        return $backend;
    }

    /** @param list<string> $visible */
    private static function request(
        string $q,
        array $visible = [self::DOCS, self::BLOG],
        ?string $type = null,
        string $locale = 'en',
        int $limit = 10,
        int $offset = 0,
        bool $all = false,
    ): SearchRequest {
        return new SearchRequest($q, $locale, $type, $all, $visible, $limit, $offset);
    }

    /** @return list<string> */
    private static function entries(\Thallo\Search\Query\SearchResults $results): array
    {
        return array_map(static fn ($hit): string => $hit->entryUuid, $results->hits);
    }

    public function testItFindsAWordByItsStemAndATitleOutranksABody(): void
    {
        $results = $this->seed()->search(self::request('install'));
        // "Installing" (title), "Install party" (title), "Installing a theme" and "install
        // dependencies" (bodies): all four by the stem; titles first.
        self::assertSame(4, $results->total);
        $found = self::entries($results);
        self::assertEqualsCanonicalizing(['install00001', 'post00000001'], array_slice($found, 0, 2));
        self::assertEqualsCanonicalizing(['theming00001', 'upgrade00001'], array_slice($found, 2));
        self::assertGreaterThan($results->hits[3]->score, $results->hits[0]->score);
        $hit = $results->hits[array_search('install00001', $found, true)];
        self::assertSame('docs', $hit->contentTypeSlug);
        self::assertSame('en', $hit->locale);
        self::assertSame('https://site.test/docs/install00001', $hit->href);
        self::assertSame('Installing Thallo', $hit->title);
    }

    public function testItFindsAWordFromItsFirstLettersAsASearchBoxNeeds(): void
    {
        $backend = $this->seed();
        self::assertContains('theming00001', self::entries($backend->search(self::request('them'))));
        self::assertSame(['upgrade00001'], self::entries($backend->search(self::request('migr'))));
        // Every word has to be there: more words narrow, never widen.
        self::assertSame(['install00001'], self::entries($backend->search(self::request('composer prov'))));
        self::assertSame([], self::entries($backend->search(self::request('composer nonsenseword'))));
    }

    public function testTheSnippetShowsTheMatchAndIsSafeToEmit(): void
    {
        $backend = $this->backend();
        $backend->upsert([self::doc(
            'xss000000001',
            'Escaping',
            'Before <script>alert(1)</script> the needle & after it. ' . str_repeat('Padding text here. ', 40),
        )]);
        $hit = $backend->search(self::request('needle'))->hits[0];
        self::assertStringContainsString('<mark>needle</mark>', $hit->snippet);
        // Markup in a document is never live in a snippet: Postgres drops tags from a headline,
        // and whatever is left is escaped — the only tags are the highlight's own.
        self::assertStringNotContainsString('<script', $hit->snippet);
        self::assertSame(0, preg_match('~<(?!/?mark>)~', $hit->snippet), 'a tag other than <mark> survived');
        self::assertStringContainsString('&amp;', $hit->snippet);
        // An excerpt, not the page.
        self::assertLessThan(400, strlen($hit->snippet));
    }

    public function testVisibilityLocaleAndTypeAreEnforcedInsideTheQuery(): void
    {
        $backend = $this->seed();
        // Only what the caller may see — never found and then filtered away, so totals are true.
        $docsOnly = $backend->search(self::request('install', [self::DOCS]));
        self::assertNotContains('post00000001', self::entries($docsOnly));
        self::assertSame(3, $docsOnly->total);
        self::assertSame(0, $backend->search(self::request('install', []))->total);
        self::assertSame(4, $backend->search(self::request('install', [], all: true))->total);
        // A type asked for by name.
        self::assertSame(['post00000001'], self::entries($backend->search(self::request('install', type: 'blog'))));
        // A locale is its own index: French words, French stems.
        $fr = $backend->search(self::request('installer', locale: 'fr'));
        self::assertSame(['install00001'], self::entries($fr));
        self::assertSame('fr', $fr->hits[0]->locale);
        self::assertSame(['install00001'], self::entries($backend->search(self::request('projets', locale: 'fr'))));
    }

    public function testItPagesAndCountsTheWholeResult(): void
    {
        $backend = $this->seed();
        $first = $backend->search(self::request('install', limit: 3));
        $rest = $backend->search(self::request('install', limit: 3, offset: 3));
        self::assertSame([4, 3, 0, 3], [$first->total, $first->limit, $first->offset, count($first->hits)]);
        self::assertSame([4, 3, 1], [$rest->total, $rest->offset, count($rest->hits)]);
        self::assertSame([], array_intersect(self::entries($first), self::entries($rest)));
    }

    public function testAnUpsertReplacesAndADeleteRemovesOneLocaleOrTheWholeEntry(): void
    {
        $backend = $this->seed();
        $backend->upsert([self::doc('install00001', 'Getting set up', 'Nothing about that word any more.')]);
        self::assertNotContains('install00001', self::entries($backend->search(self::request('install'))));
        self::assertSame(['install00001'], self::entries($backend->search(self::request('getting'))));

        $backend->deleteEntry('install00001', 'en');
        self::assertSame(0, $backend->search(self::request('getting'))->total);
        $french = $backend->search(self::request('installer', locale: 'fr'));
        self::assertSame(1, $french->total, 'the other locale stays');
        $backend->deleteEntry('install00001');
        self::assertSame(0, $backend->search(self::request('installer', locale: 'fr'))->total);
    }

    public function testAQueryIsWordsAndNothingElse(): void
    {
        $backend = $this->seed();
        $odd = ["'); DROP TABLE zzq; --", ':* & | ! ( )', '\\', '<->', str_repeat('zq ', 500), "\0", 'zq:*'];
        foreach ($odd as $q) {
            $results = $backend->search(self::request($q));
            self::assertSame(0, $results->total, $q);
        }
        self::assertSame(4, $backend->search(self::request('install'))->total, 'and the index is still there');
        // One letter is a word, not the start of every word beginning with it.
        self::assertSame(0, $backend->search(self::request('t'))->total);
        // Punctuation around a real word is just punctuation.
        self::assertSame(4, $backend->search(self::request('"install!" & |'))->total);
    }

    public function testItReportsItsHealthAndItsName(): void
    {
        self::assertTrue($this->backend()->health());
        self::assertSame('Postgres full-text search', $this->backend()->name());
    }
}
