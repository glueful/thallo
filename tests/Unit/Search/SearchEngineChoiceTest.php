<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Thallo\Search\Engine\SearchEngineChoice;

/**
 * Which engine answers a search. A site says so with SEARCH_ENGINE, or leaves it to `auto`:
 * Meilisearch where the site has configured one, otherwise the database it already has — so a
 * site that ran Meilisearch before this choice existed keeps it, and a new site's search works
 * with nothing to install. A choice that cannot be honoured is never quietly swapped for the
 * other engine: it is unavailable, with the reason.
 */
final class SearchEngineChoiceTest extends TestCase
{
    public function testAutoIsMeilisearchWhereOneIsConfiguredAndPostgresOtherwise(): void
    {
        self::assertSame(['postgres', null], SearchEngineChoice::resolve('auto', 'pgsql', false, true));
        self::assertSame(['meilisearch', null], SearchEngineChoice::resolve('auto', 'pgsql', true, true));
        // Configured, but the extension that talks to it is not there: not a reason to index
        // the site into a second engine behind the operator's back.
        [$engine, $why] = SearchEngineChoice::resolve('auto', 'pgsql', true, false);
        self::assertSame('unavailable', $engine);
        self::assertStringContainsString('glueful/meilisearch', (string) $why);
    }

    public function testAnExplicitChoiceIsHonouredOrRefusedNeverSwapped(): void
    {
        self::assertSame(['postgres', null], SearchEngineChoice::resolve('postgres', 'pgsql', true, true));
        self::assertSame(['meilisearch', null], SearchEngineChoice::resolve('meilisearch', 'pgsql', false, true));

        [$engine, $why] = SearchEngineChoice::resolve('postgres', 'mysql', false, true);
        self::assertSame('unavailable', $engine);
        self::assertStringContainsString('PostgreSQL', (string) $why);

        [$engine, $why] = SearchEngineChoice::resolve('meilisearch', 'pgsql', true, false);
        self::assertSame('unavailable', $engine);
        self::assertStringContainsString('extensions:enable', (string) $why);
    }

    public function testADatabaseWithNoFullTextSearchLeavesAutoWithMeilisearchOrNothing(): void
    {
        self::assertSame(['meilisearch', null], SearchEngineChoice::resolve('auto', 'mysql', true, true));
        [$engine, $why] = SearchEngineChoice::resolve('auto', 'sqlite', false, true);
        self::assertSame('unavailable', $engine);
        self::assertStringContainsString('SEARCH_ENGINE', (string) $why);
    }

    public function testAnythingElseIsAMisspelling(): void
    {
        [$engine, $why] = SearchEngineChoice::resolve('elastic', 'pgsql', false, true);
        self::assertSame('unavailable', $engine);
        self::assertStringContainsString('elastic', (string) $why);
    }
}
