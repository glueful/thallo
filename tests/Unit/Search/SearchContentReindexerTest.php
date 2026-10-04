<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Thallo\Contracts\Search\ContentReindexer;
use Thallo\Contracts\Search\SearchIndex;
use Thallo\Search\Index\ResilientContentReindexer;
use Thallo\Search\Index\SearchContentReindexer;

/**
 * The core's entry events become journaled search changes (search block spec §3.5.2): one change per
 * entry, whatever the locale — the entries contributor re-reads every published locale when the
 * change is applied — and a failure never reaches the publish that caused it.
 */
final class SearchContentReindexerTest extends TestCase
{
    public function testEveryEntryEventJournalsOneEntriesChange(): void
    {
        $index = $this->index();
        $reindexer = new SearchContentReindexer($index);
        $reindexer->reindexEntry('entryuuid001', 'en');
        $reindexer->reindexEntry('entryuuid001', null);

        self::assertSame([['entries', 'entryuuid001'], ['entries', 'entryuuid001']], $index->changes);
    }

    public function testResilientDecoratorSwallowsFailures(): void
    {
        $failing = new class implements ContentReindexer {
            public function reindexEntry(string $entryUuid, ?string $locale): void
            {
                throw new \RuntimeException('journal down');
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        (new ResilientContentReindexer($failing, $logger))->reindexEntry('entryuuid001', 'en');
        self::assertNotSame([], $logger->messages);
    }

    private function index(): SearchIndex
    {
        return new class implements SearchIndex {
            /** @var list<array{0: string, 1: string}> */
            public array $changes = [];
            public function changed(string $kind, string $sourceId): void
            {
                $this->changes[] = [$kind, $sourceId];
            }
            public function kindChanged(string $kind, string $reason): void
            {
            }
        };
    }
}
