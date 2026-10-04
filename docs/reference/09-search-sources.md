---
title: "Search sources"
slug: search-sources
section: reference
order: 9
summary: "The contracts a pack implements to add a kind of search result, report changes, and offer a block field its choices."
---

A pack adds a kind of search result — beside **Pages & posts** and **Products** — by registering
one class. The search pack then owns the index: it builds it, keeps it in step, shows the kind in
**Settings › Search**, offers it as a Search block scope, and answers it on `/search` and
`/v1/search?kind=<kind>`. This page lists the contracts, all in `glueful/thallo-contracts`, and
the rules they carry. Making a pack at all is [make an extension](../guides/22-make-an-extension.md).

## SearchSourceContributor

`Thallo\Contracts\Search\SearchSourceContributor`, one per kind.

| Method | Returns | What it must do |
|---|---|---|
| `kind()` | `string` | The kind's stable id: a lower-case letter, then up to 15 lower-case letters or digits (`products`). Never changes. |
| `label()` | `string` | What the admin and the Search block call it (`Products`). |
| `requiredCapabilities()` | `list<string>` | Capability ids the kind needs beyond `thallo.search`. The kind is available only while every one is on. |
| `schemaVersion()` | `int` | Bump it when what `documents()` produces changes shape; every site rebuilds the kind. |
| `documents(string $sourceId)` | `list<SearchDocument>` | The item's current documents, one per language. An empty list removes the item from the index. |
| `enumerate(?string $after, int $size)` | `SearchDocumentPage` | Up to `$size` publicly listed items, in source-id order, strictly after `$after`. `nextAfter` is `null` on the last page. A rebuild pages through this. |
| `visibilityFilter(SearchAudience $audience)` | `KindFilter` | Which of the kind's documents an audience may match: `KindFilter::none()`, `KindFilter::all()`, or `KindFilter::subtypes([...])`. Applied inside the engine's query. |
| `present(SearchAudience $audience, string $locale, list<string> $sourceIds)` | `array<string, ?ResultDisplay>` | What to show for each candidate, read from current records. Return `null` for one that must not be shown. |

`present()` is the authority. The index only proposes candidates; a result is shown only if
`present()` returns a `ResultDisplay` for it, and everything shown comes from that object, never
from the index. An item unpublished since it was indexed is dropped here, not by a rebuild. A
page that `present()` thins out is refilled from further matches.

`SearchAudience` is either `SearchAudience::public()` or an API key's scopes
(`SearchAudience::apiKey($scopes)`); `isPublic()` tells them apart. The site's own Search block and
`/search` page always search as the public.

## SearchDocument

What goes in the index for one item in one language.

| Property | Type | Meaning |
|---|---|---|
| `kind` | `string` | The contributor's `kind()`. |
| `sourceId` | `string` | The item's id within the kind: 1 to 64 letters or digits. |
| `locale` | `string` | A language code of up to 12 letters, digits or hyphens, or `*` for an item that is the same in every language. |
| `subtype` | `?string` | What `KindFilter::subtypes()` matches against (entries use the content type). |
| `href` | `string` | The item's link. |
| `title` | `string` | Matched with more weight than the body. |
| `body` | `string` | The words to match: plain text, no markup. |
| `meta` | `array` | Optional `image` and `price` hints. |

None of `kind`, `sourceId` or `locale` may contain `_`: the document id joins them as
`{kind}_{sourceId}_L{locale}`, or `{kind}_{sourceId}_A` for `*`. The constructor refuses a value
that breaks these shapes, and `Thallo\Contracts\Search\SearchIdentity` holds the patterns.

## ResultDisplay

What a visitor is shown for one result.

| Property | Type | Meaning |
|---|---|---|
| `title` | `string` | The title as it is now. |
| `href` | `string` | The link as it is now. |
| `text` | `string` | Plain text the snippet is cut from, at most 20 KB. Only text the visitor may read. |
| `image` | `?string` | A picture URL. |
| `price` | `?string` | A formatted price. |

## Register a contributor

Register it from your pack's service provider, only when the search pack is installed. Register it
whether or not your own capability is on: the kind then shows in **Settings › Search** as
**Requires** your feature instead of being missing.

```php
use Thallo\Contracts\Search\SearchSourceRegistry;

$container = $context->getContainer();
if ($container->has(SearchSourceRegistry::class)) {
    $container->get(SearchSourceRegistry::class)->register(new RecipesSearchContributor($container));
}
```

A duplicate or invalid kind throws `\LogicException`.

## A minimal contributor

Recipes, in one language, every published recipe visible to everyone:

```php
use Thallo\Contracts\Search\{KindFilter, ResultDisplay, SearchAudience, SearchDocument,
    SearchDocumentPage, SearchSourceContributor};

final class RecipesSearchContributor implements SearchSourceContributor
{
    public function __construct(private RecipeRepository $recipes)
    {
    }

    public function kind(): string { return 'recipes'; }
    public function label(): string { return 'Recipes'; }
    public function requiredCapabilities(): array { return ['acme.recipes']; }
    public function schemaVersion(): int { return 1; }

    public function documents(string $sourceId): array
    {
        $recipe = $this->recipes->findPublished($sourceId);
        return $recipe === null ? [] : [$this->document($recipe)];
    }

    public function enumerate(?string $after, int $size): SearchDocumentPage
    {
        $rows = $this->recipes->publishedAfter($after, $size); // ordered by id
        $last = end($rows);
        return new SearchDocumentPage(
            array_map($this->document(...), $rows),
            count($rows) === $size && $last !== false ? $last->id : null,
        );
    }

    public function visibilityFilter(SearchAudience $audience): KindFilter
    {
        return KindFilter::all();
    }

    public function present(SearchAudience $audience, string $locale, array $sourceIds): array
    {
        $out = array_fill_keys($sourceIds, null);
        foreach ($this->recipes->findPublishedMany($sourceIds) as $recipe) {
            $out[$recipe->id] = new ResultDisplay($recipe->name, '/recipes/' . $recipe->slug, $recipe->method);
        }
        return $out;
    }

    private function document(Recipe $recipe): SearchDocument
    {
        return new SearchDocument('recipes', $recipe->id, '*', null, '/recipes/' . $recipe->slug,
            $recipe->name, $recipe->ingredients . ' ' . $recipe->method);
    }
}
```

## SearchIndex

`Thallo\Contracts\Search\SearchIndex` is how a pack tells the index an item changed. Call it after
your own transaction commits, never inside it.

| Method | Use it when |
|---|---|
| `changed(string $kind, string $sourceId)` | One item was created, edited or removed. The index asks `documents()` for its current state. |
| `kindChanged(string $kind, string $reason)` | A change touches many items with no single id, such as renaming a category. The kind is rebuilt. |

It is bound while the search pack is installed and does nothing while search is off. Resolve it
when the change happens, and fall back to doing nothing when it is not bound:

```php
use Thallo\Contracts\Search\SearchIndex;

if ($container->has(SearchIndex::class)) {
    $container->get(SearchIndex::class)->changed('recipes', $recipe->id);
}
```

A change lost between your commit and this call is repaired by the next change to the same item,
and by the daily full rebuild.

## FieldOptionSource

`Thallo\Contracts\Fields\FieldOptionSource` gives a block type's field its choices from the
server. A field names it with `options_source` in its schema:

```php
['name' => 'scope', 'type' => 'string', 'options_source' => 'thallo-search.scopes'],
```

| Method | Returns | Meaning |
|---|---|---|
| `id()` | `string` | What `options_source` names, such as `thallo-search.scopes`. |
| `permission()` | `string` | The permission a caller needs to load the choices, checked as well as `content.edit`. |
| `options()` | `list<array{value, label, available, reason}>` | Each choice; `available: false` with a `reason` keeps it listed but not selectable. |

Register it with `Thallo\Contracts\Fields\FieldOptionSourceRegistry`, from your provider:

```php
use Thallo\Contracts\Fields\FieldOptionSourceRegistry;

if ($container->has(FieldOptionSourceRegistry::class)) {
    $container->get(FieldOptionSourceRegistry::class)->register(new RecipeCategoriesOptionSource());
}
```

The inspector shows the choices as a select. A saved value that is no longer among them stays
selected, marked unavailable with its reason, so opening a page never changes it silently.
