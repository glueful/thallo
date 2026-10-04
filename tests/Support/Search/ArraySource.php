<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Search;

use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\ResultDisplay;
use Thallo\Contracts\Search\SearchAudience;
use Thallo\Contracts\Search\SearchDocument;
use Thallo\Contracts\Search\SearchDocumentPage;
use Thallo\Contracts\Search\SearchSourceContributor;

/**
 * A search source backed by an array tests edit: `$items[sourceId][locale] = title`. Removing an
 * item or a locale is what a withdrawal or a dropped translation looks like to the index.
 */
final class ArraySource implements SearchSourceContributor
{
    /** @var array<string, array<string, string>> */
    public array $items = [];
    /** @var array<string, true> items present() refuses (withdrawn since indexing) */
    public array $withdrawn = [];
    /** @var (\Closure(string): void)|null runs before each enumerated page is returned */
    public ?\Closure $onEnumerate = null;
    public int $schemaVersion = 1;

    /** @param list<string> $requires */
    public function __construct(
        private readonly string $kind = 'entries',
        private readonly array $requires = [],
    ) {
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function label(): string
    {
        return ucfirst($this->kind);
    }

    public function requiredCapabilities(): array
    {
        return $this->requires;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function documents(string $sourceId): array
    {
        $docs = [];
        foreach ($this->items[$sourceId] ?? [] as $locale => $title) {
            $docs[] = new SearchDocument(
                $this->kind,
                $sourceId,
                $locale,
                $this->kind === 'entries' ? 't1' : null,
                '/' . $sourceId,
                $title,
                $title . ' body',
            );
        }
        return $docs;
    }

    public function enumerate(?string $after, int $size): SearchDocumentPage
    {
        $ids = array_keys($this->items);
        sort($ids);
        $ids = array_values(array_filter(
            $ids,
            static fn (string $id): bool => $after === null || strcmp($id, $after) > 0,
        ));
        $page = array_slice($ids, 0, $size);
        $docs = [];
        foreach ($page as $id) {
            array_push($docs, ...$this->documents($id));
        }
        if ($this->onEnumerate !== null) {
            ($this->onEnumerate)((string) end($page));
        }
        return new SearchDocumentPage($docs, count($page) === $size ? (string) end($page) : null);
    }

    public function visibilityFilter(SearchAudience $audience): KindFilter
    {
        return $this->kind === 'entries' ? KindFilter::subtypes(['t1']) : KindFilter::all();
    }

    public function present(SearchAudience $audience, string $locale, array $sourceIds): array
    {
        $out = [];
        foreach ($sourceIds as $id) {
            $title = $this->items[$id][$locale] ?? $this->items[$id]['*'] ?? null;
            $out[$id] = $title === null || isset($this->withdrawn[$id]) ? null : new ResultDisplay(
                $title,
                '/' . $id,
                $title . ' body',
            );
        }
        return $out;
    }
}
