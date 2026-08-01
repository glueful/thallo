# Blog Posts Block Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `blog_posts` leaf block that dynamically lists published `post` entries, via a reusable `EntryListReader` delivery seam exposed to Twig as `entries()`.

**Architecture:** A new `Thallo\Contracts\Delivery\EntryListReader` contract, implemented by `App\Content\Delivery\EngineEntryListReader`, modeled exactly on the existing `FacetCountsReader`/`EngineFacetCountsReader` + `facets()` path. The shaped-item+href tail of `EnginePublicRouteResolver::listItems()` is extracted into a shared `ListingItemShaper` so the resolver and the reader use one path. `RenderContextExtension` gains `entries()` and `is_preview()`; the render controller already drains collected cache tags into the `Cache-Tag` header. `blog_posts` renders each shaped item as an inline card.

**Tech Stack:** PHP 8.3, Twig, plain CSS, PHPUnit. Reuses `DeliveryRepository::paginatePublished`, `SortCompiler`, `DeliveryItemShaper`, `CanonicalPathBuilder`, `PublishedReferenceRepository::membershipPredicate`, `ReferenceTargetResolver`.

**Spec:** `docs/superpowers/specs/2026-07-08-blog-posts-block-design.md`

## Global Constraints

- Repo `/Users/michaeltawiahsowah/Sites/glueful/thallo`; work on `dev`; **hold all commits until explicit go-ahead** (per-task Commit steps are staged-ready but MUST NOT run until told). Do not commit this plan.
- No AI/Anthropic attribution in any commit or artifact.
- One new block type `blog_posts` (snake_case); seed 42 → 43.
- **Server-side limit clamp** `1..12` in the reader — never trust the block's field.
- **Deterministic category field**: first `type:reference` + `filterable:true` field in schema declaration order.
- **Cache tags**: broad `thallo:type:{slug}` from the **resolved type row's slug** + per-item `thallo:entry:{uuid}` + expansion tags + (category) term entry tag + term-type slug tag. **No `thallo:type:{uuid}` tag.**
- No `json` field type; no new admin widgets.
- Contracts live in `packages/thallo-contracts/src/Delivery/`; app delivery in `app/Content/Delivery/`; templates in `packages/thallo-render/themes/default/templates/blocks/`.
- Tests: `vendor/bin/phpunit`; style `vendor/bin/phpcs` (≤120 cols); `composer ci` = phpcs + test.

## File Structure

**Created:**
- `packages/thallo-contracts/src/Delivery/EntryListReader.php` — the contract (Task 2)
- `app/Content/Delivery/ListingItemShaper.php` — shared row→item+href shaper (Task 1)
- `app/Content/Delivery/EngineEntryListReader.php` — the reader (Task 2)
- `packages/thallo-render/themes/default/templates/blocks/blog_posts.twig` — block + inline card (Task 4)
- `tests/Integration/Content/EntryListReaderTest.php` (Task 2)
- `tests/Integration/Render/BlogPostsRenderTest.php` (Task 4)

**Modified:**
- `app/Content/Delivery/EnginePublicRouteResolver.php` — `listItems()` delegates to `ListingItemShaper` (Task 1)
- `app/Providers/ThalloServiceProvider.php` — bind `EntryListReader`; pass to extension (Tasks 2–3)
- `packages/thallo-render/src/RenderContextExtension.php` — inject reader, add `entries()` + `is_preview()` (Task 3)
- `app/Content/Blocks/StarterBlockTypes.php` — `blog_posts` definition (Task 4)
- `app/Setup/SetupService.php` — `cover` field on the `post` seed (Task 4)
- `packages/thallo-render/themes/default/assets/blocks.css` — blog CSS (Task 4)
- `tests/Integration/Content/SeedBlockTypesTest.php` — 42 → 43 (Task 4)

---

### Task 1: Extract `ListingItemShaper` (behavior-preserving refactor)

Extract the shaping+href tail of `EnginePublicRouteResolver::listItems()` into a reusable collaborator so the reader shares one path. Behavior-preserving — the existing listing/archive tests are the safety net.

**Scope guard:** `ListingItemShaper` is an **app-internal** plain `final class` in `app/Content/Delivery/` — no interface, no entry in `packages/thallo-contracts/` — matching the existing internal collaborators there (`DeliveryItemShaper`, `SortCompiler`, `CanonicalPathBuilder`). The **only** public contract this feature introduces is `EntryListReader`. Do not elevate the shaper to a contract; this is a behavior-preserving extraction, nothing more.

**Files:**
- Create: `app/Content/Delivery/ListingItemShaper.php`
- Modify: `app/Content/Delivery/EnginePublicRouteResolver.php` (constructor + `listItems()`)

**Interfaces:**
- Produces: `ListingItemShaper::shape(array $rows, array $typeRow, string $locale, ExpandedTargets $expanded): array` — returns a list of items each `{uuid, locale, version, published_at, fields, href}`.

- [ ] **Step 1: Create `ListingItemShaper` with the exact current logic**

```php
<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Schema\ContentTypeSchema;
use App\Support\FieldSelection\FieldSelector;
use Glueful\Database\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shapes published rows into template list items (full item + canonical href),
 * collecting expansion targets. Extracted from EnginePublicRouteResolver::listItems
 * so the route resolver and EntryListReader share ONE shaping path.
 */
final class ListingItemShaper
{
    public function __construct(
        private readonly Connection $db,
        private readonly DeliveryItemShaper $shaper,
        private readonly CanonicalPathBuilder $canonical,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $typeRow
     * @return list<array<string,mixed>>
     */
    public function shape(array $rows, array $typeRow, string $locale, ExpandedTargets $expanded): array
    {
        if ($rows === []) {
            return [];
        }
        $typeUuid = (string) $typeRow['uuid'];
        $typeSlug = (string) $typeRow['slug'];
        $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));
        $selector = FieldSelector::fromRequest(Request::create('/')); // empty = full item

        $shaped = $this->shaper->shape($rows, $schema, $selector, $locale, $typeUuid, null, $expanded);

        $uuids = array_values(array_filter(array_map(
            static fn(array $r): string => (string) ($r['entry_uuid'] ?? ''),
            $shaped,
        )));
        $slugByEntry = [];
        if ($uuids !== []) {
            $placeholders = implode(', ', array_fill(0, count($uuids), '?'));
            $routeRows = $this->db->table('entry_routes')
                ->select(['entry_uuid', 'slug'])
                ->whereRaw("entry_uuid IN ({$placeholders})", $uuids)
                ->where('content_type_uuid', '=', $typeUuid)
                ->where('locale', '=', $locale)
                ->get();
            foreach ($routeRows as $r) {
                $slugByEntry[(string) $r['entry_uuid']] = (string) $r['slug'];
            }
        }

        $items = [];
        foreach ($shaped as $row) {
            $item = $this->shaper->item($row);
            $slug = $slugByEntry[(string) ($row['entry_uuid'] ?? '')] ?? null;
            $item['href'] = $slug === null
                ? null
                : $this->canonical->pathFor(
                    $typeSlug,
                    (bool) ($typeRow['mount_at_root'] ?? false),
                    $locale,
                    $slug,
                );
            $items[] = $item;
        }
        return $items;
    }
}
```

- [ ] **Step 2: Inject `ListingItemShaper` into `EnginePublicRouteResolver`**

Add to the constructor (after `CanonicalPathBuilder $canonical`):

```php
        private readonly ListingItemShaper $listShaper,
```

(The DI container autowires it; the class is `final` with typed constructor deps.)

- [ ] **Step 3: Replace `listItems()` body with a delegation**

```php
    private function listItems(array $rows, array $typeRow, string $locale, ?ExpandedTargets $expanded = null): array
    {
        return $this->listShaper->shape($rows, $typeRow, $locale, $expanded ?? new ExpandedTargets());
    }
```

- [ ] **Step 4: Run the existing delivery/render tests to prove no regression**

Run: `vendor/bin/phpunit --filter='RenderPipelineTest|EnginePublicRouteResolver|Listing|Archive'`
Expected: PASS (listing + archive rendering unchanged).

- [ ] **Step 5: phpcs**

Run: `vendor/bin/phpcs app/Content/Delivery/ListingItemShaper.php app/Content/Delivery/EnginePublicRouteResolver.php`
Expected: no errors.

- [ ] **Step 6: Commit (HOLD)**

```bash
git add app/Content/Delivery/ListingItemShaper.php app/Content/Delivery/EnginePublicRouteResolver.php
git commit -m "Extract ListingItemShaper from EnginePublicRouteResolver::listItems"
```

---

### Task 2: `EntryListReader` contract + `EngineEntryListReader` + binding

**Files:**
- Create: `packages/thallo-contracts/src/Delivery/EntryListReader.php`
- Create: `app/Content/Delivery/EngineEntryListReader.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (bind the contract)
- Create: `tests/Integration/Content/EntryListReaderTest.php`

**Interfaces:**
- Consumes: `ListingItemShaper::shape(...)` (Task 1); `DeliveryRepository::paginatePublished(typeUuid, locale, page, perPage, ?filter, ?order)`; `SortCompiler::defaultOrder()`; `PublishedReferenceRepository::membershipPredicate(sourceTypeUuid, field, targetEntryUuid)`; `ReferenceTargetResolver::resolve(fieldDef, locale, [slug])`.
- Produces: `EntryListReader::list(string $type, array $opts, string $locale): array` → `['items' => list, 'cache_tags' => list<string>]`. `$opts`: `limit` (int), `order` ('newest'|'oldest'), `category` (?string).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Delivery\EngineEntryListReader;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Delivery\EntryListReader;

final class EntryListReaderTest extends AppTestCase
{
    private function reader(): EntryListReader
    {
        return $this->container()->get(EntryListReader::class);
    }

    public function testUnknownTypeReturnsEmpty(): void
    {
        $out = $this->reader()->list('does-not-exist', ['limit' => 3], 'en');
        self::assertSame([], $out['items']);
        self::assertSame([], $out['cache_tags']);
    }

    public function testListReaderIsBoundToEngineImpl(): void
    {
        self::assertInstanceOf(EngineEntryListReader::class, $this->reader());
    }

    public function testLimitIsClampedToTwelve(): void
    {
        // Even absurd limits never exceed 12 (server-side clamp); with no posts
        // seeded the list is empty but the call must not error.
        $out = $this->reader()->list('post', ['limit' => 500], 'en');
        self::assertLessThanOrEqual(12, count($out['items']));
    }
}
```

(Note: this suite exercises the gate + binding + clamp without requiring seeded posts. Data-bearing assertions — order, category membership, broad-tag coverage of a post outside the top-N — are added in Step 6 once the impl exists, seeding `post` entries via the content pipeline the app already uses in delivery tests. Model the seeding on an existing delivery/listing integration test.)

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter=EntryListReaderTest`
Expected: FAIL — `EntryListReader` not bound / class missing.

- [ ] **Step 3: Create the contract**

```php
<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Lists published entries of a type for templates (the blog_posts block, etc.).
 * Like FacetCountsReader, the result carries its OWN surrogate cache tags —
 * including the BROAD thallo:type:{slug} dependency so a newly published entry or
 * changed membership that alters the top-N still purges the page. Gate failures
 * (unknown/non-deliverable type, unresolved category) return {[], []} — never throw.
 */
interface EntryListReader
{
    /**
     * @param array{limit?: int, order?: string, category?: ?string} $opts
     * @return array{items: list<array<string,mixed>>, cache_tags: list<string>}
     */
    public function list(string $type, array $opts, string $locale): array;
}
```

- [ ] **Step 4: Create `EngineEntryListReader`**

```php
<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Schema\ContentTypeSchema;
use Thallo\Contracts\Delivery\EntryListReader;

/**
 * Template-facing published-entry listing. Same visibility gate as the listing page,
 * but a template fail (unknown type, unresolved category) returns {[], []} and renders
 * nothing. Limit is clamped 1..12 server-side regardless of caller input.
 */
final class EngineEntryListReader implements EntryListReader
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly DeliveryRepository $delivery,
        private readonly PublishedReferenceRepository $projection,
        private readonly ReferenceTargetResolver $terms,
        private readonly ListingItemShaper $listShaper,
    ) {
    }

    public function list(string $type, array $opts, string $locale): array
    {
        $none = ['items' => [], 'cache_tags' => []];

        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null || !$this->visible($typeRow)) {
            return $none;
        }
        $typeUuid = (string) $typeRow['uuid'];
        $typeSlug = (string) $typeRow['slug'];

        $limit = max(1, min(12, (int) ($opts['limit'] ?? 3)));
        $order = ($opts['order'] ?? 'newest') === 'oldest'
            ? [
                'sql' => 'ORDER BY p.published_at ASC, v.id DESC',
                'expr' => 'p.published_at', 'direction' => 'ASC',
                'field' => null, 'column' => 'published_at',
            ]
            : SortCompiler::defaultOrder();

        // Category filter — deterministic: first filterable reference field in order.
        $filter = null;
        $termUuid = null;
        $targetSlug = null;
        $category = trim((string) ($opts['category'] ?? ''));
        if ($category !== '') {
            $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));
            $catField = null;
            foreach ($schema->fields() as $f) {
                if ($f->type === 'reference' && $f->filterable) {
                    $catField = $f;
                    break;
                }
            }
            if ($catField === null) {
                return $none;
            }
            $targetRow = $this->types->findBySlug((string) ($catField->referenceType ?? ''));
            if ($targetRow === null || !$this->visible($targetRow)) {
                return $none;
            }
            try {
                $targets = $this->terms->resolve($catField, $locale, [$category]);
            } catch (InvalidFilterException) {
                return $none;
            }
            $termUuid = $targets[0] ?? null;
            if ($termUuid === null) {
                return $none;
            }
            $targetSlug = (string) $targetRow['slug'];
            $filter = $this->projection->membershipPredicate($typeUuid, $catField->name, $termUuid);
        }

        $result = $this->delivery->paginatePublished($typeUuid, $locale, 1, $limit, $filter, $order);
        $expanded = new ExpandedTargets();
        $items = $this->listShaper->shape($result['data'], $typeRow, $locale, $expanded);

        // Broad listing dependency FIRST (correctness), then per-item + expansion + term.
        $tags = ['thallo:type:' . $typeSlug];
        foreach ($items as $it) {
            if (($it['uuid'] ?? null) !== null) {
                $tags[] = 'thallo:entry:' . (string) $it['uuid'];
            }
        }
        foreach ($expanded->entryUuids() as $u) {
            $tags[] = 'thallo:entry:' . $u;
        }
        if ($termUuid !== null) {
            $tags[] = 'thallo:entry:' . $termUuid;
            $tags[] = 'thallo:type:' . (string) $targetSlug;
        }

        return ['items' => $items, 'cache_tags' => array_values(array_unique($tags))];
    }

    /** @param array<string,mixed> $typeRow */
    private function visible(array $typeRow): bool
    {
        return (bool) ($typeRow['public_delivery'] ?? false);
    }
}
```

(Verify `visible()` matches `EngineFacetCountsReader::visible()` — if that method also checks a not-deleted flag, mirror it exactly here for consistency.)

- [ ] **Step 5: Bind the contract in `ThalloServiceProvider`**

Next to the `FacetCountsReader` binding (~line 376), add (and `use App\Content\Delivery\EngineEntryListReader;` + `use Thallo\Contracts\Delivery\EntryListReader;` at the top):

```php
            EntryListReader::class => [
                'class'    => EngineEntryListReader::class,
                'shared'   => true,
            ],
```

- [ ] **Step 6: Add data-bearing tests** (order, category, broad-tag coverage)

Extend `EntryListReaderTest` with a helper that publishes 2–3 `post` entries + a `category` term (model the seeding on an existing delivery/listing integration test in `tests/Integration/`), then assert:
- `list('post', ['limit'=>2,'order'=>'newest'])` returns the 2 newest, `order'=>'oldest'` flips them;
- `cache_tags` always contains `'thallo:type:post'`, and a published post NOT in the returned top-N is still covered by that broad tag;
- `category` filter returns only members; an unknown category slug → `{[], []}`.

- [ ] **Step 7: Run tests + phpcs**

Run: `vendor/bin/phpunit --filter=EntryListReaderTest` → Expected: PASS.
Run: `vendor/bin/phpcs packages/thallo-contracts/src/Delivery/EntryListReader.php app/Content/Delivery/EngineEntryListReader.php app/Providers/ThalloServiceProvider.php tests/Integration/Content/EntryListReaderTest.php` → Expected: no errors.

- [ ] **Step 8: Commit (HOLD)**

```bash
git add packages/thallo-contracts/src/Delivery/EntryListReader.php \
  app/Content/Delivery/EngineEntryListReader.php \
  app/Providers/ThalloServiceProvider.php \
  tests/Integration/Content/EntryListReaderTest.php
git commit -m "Add EntryListReader delivery seam for template entry listing"
```

---

### Task 3: `entries()` + `is_preview()` Twig functions

**Files:**
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (inject reader, add functions)
- Modify: `app/Providers/ThalloServiceProvider.php` (pass the reader to the extension definition, mirroring how `FacetCountsReader` is passed)
- Modify: `tests/Integration/Render/BlockLibraryRenderTest.php` (add a small entries()/is_preview test) — OR add to the Task 4 test file; keep it here for the seam-level check.

**Interfaces:**
- Consumes: `EntryListReader` (Task 2); the extension's existing `collectTags()`, `locale`, `annotateBlocks`.
- Produces: Twig `entries(string $type, array $opts = []): array` (returns items) and `is_preview(): bool`.

- [ ] **Step 1: Write the failing test** (append to `BlockLibraryRenderTest`)

```php
    public function testEntriesFunctionReturnsListAndIsPreviewReflectsAnnotation(): void
    {
        $env = $this->env();
        // No posts seeded → entries('post') is an empty list, and the call is safe.
        $out = $env->createTemplate('{{ entries("post", {limit: 3})|length }}|{{ is_preview() ? "p" : "n" }}')
            ->render([]);
        self::assertStringStartsWith('0|', $out);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter=testEntriesFunctionReturnsListAndIsPreviewReflectsAnnotation`
Expected: FAIL — unknown function `entries`.

- [ ] **Step 3: Inject the reader into `RenderContextExtension`**

Add `use Thallo\Contracts\Delivery\EntryListReader;` and a constructor param mirroring the existing `?FacetCountsReader $facetReader = null` (same nullable/default posture):

```php
        private readonly ?EntryListReader $entryReader = null,
```

- [ ] **Step 4: Register the two functions in `getFunctions()`**

Add alongside the existing `new TwigFunction('facets', ...)`:

```php
            new TwigFunction('entries', $this->entries(...)),
            new TwigFunction('is_preview', $this->isPreview(...)),
```

- [ ] **Step 5: Implement the two methods** (near `facets()`)

```php
    /**
     * @param array{limit?: int, order?: string, category?: ?string} $opts
     * @return list<array<string,mixed>>
     */
    public function entries(string $type, array $opts = []): array
    {
        if ($this->entryReader === null) {
            return [];
        }
        $result = $this->entryReader->list($type, $opts, $this->locale);
        $this->collectTags($result['cache_tags']);
        return $result['items'];
    }

    public function isPreview(): bool
    {
        return $this->annotateBlocks;
    }
```

**Scope guard:** `isPreview()` returns the existing **block-annotation/canvas render mode** (`$this->annotateBlocks`) and nothing else. It must NOT become a general "a preview session exists" check (token/session semantics) — the empty-state is about editor/canvas *visibility*, not session state. Return the annotation flag verbatim; do not consult `PreviewSession` or any token.

- [ ] **Step 6: Pass the reader in the provider's extension definition**

In `ThalloServiceProvider`, where `RenderContextExtension` is defined, add the `EntryListReader` argument mirroring the `FacetCountsReader` argument already passed (same position discipline the container uses — match the existing arguments list).

- [ ] **Step 7: Run tests + phpcs**

Run: `vendor/bin/phpunit --filter='BlockLibraryRenderTest|PricingBlockRenderTest'` → Expected: PASS (no regressions; new test green).
Run: `vendor/bin/phpcs packages/thallo-render/src/RenderContextExtension.php app/Providers/ThalloServiceProvider.php` → Expected: no errors.

- [ ] **Step 8: Commit (HOLD)**

```bash
git add packages/thallo-render/src/RenderContextExtension.php \
  app/Providers/ThalloServiceProvider.php \
  tests/Integration/Render/BlockLibraryRenderTest.php
git commit -m "Expose entries() and is_preview() Twig functions"
```

---

### Task 4: `blog_posts` block + `cover` field + template + CSS

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php` (add `blog_posts`)
- Modify: `app/Setup/SetupService.php` (add `cover` to the `post` seed)
- Create: `packages/thallo-render/themes/default/templates/blocks/blog_posts.twig`
- Modify: `packages/thallo-render/themes/default/assets/blocks.css`
- Modify: `tests/Integration/Content/SeedBlockTypesTest.php` (42 → 43)
- Create: `tests/Integration/Render/BlogPostsRenderTest.php`

**Interfaces:**
- Consumes: `entries()`/`is_preview()` (Task 3). A shaped item is `{uuid, locale, version, published_at, fields, href}`; `fields` holds `cover`/`title`/`excerpt`/`categories`.
- Produces: block slug `blog_posts`; root class `thallo-block-blog_posts` + `--columns-{n}`, `--variant-{v}`, `--orientation-{o}`; card parts `__grid __card __image __title __description __meta __date __badge __empty`.

- [ ] **Step 1: Write the failing render test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Twig\Environment;

final class BlogPostsRenderTest extends AppTestCase
{
    private function env(): Environment
    {
        $base = $this->appContext()->getBasePath();
        return (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $base . '/storage/cache/twig',
        ))->environment();
    }

    /** @param list<array<string,mixed>> $list */
    private function render(array $list): string
    {
        return $this->env()->createTemplate('{{ blocks(list) }}')->render(['list' => $list]);
    }

    public function testBlogPostsEmptyRendersPlaceholderOnlyInPreview(): void
    {
        // No posts seeded → entries('post') is empty. Public render (annotateBlocks
        // false by default in this harness) shows nothing; the block still emits its
        // root so CSS binds, but no __empty placeholder and no __card.
        $out = $this->render([[
            'id' => 'bp1', 'type' => 'blog_posts',
            'data' => ['type' => 'post', 'limit' => 3, 'columns' => '3'],
        ]]);
        self::assertStringContainsString('thallo-block-blog_posts', $out);
        self::assertStringContainsString('thallo-block-blog_posts--columns-3', $out);
        self::assertStringNotContainsString('thallo-block-blog_posts__card', $out);
        self::assertStringNotContainsString('thallo-block-blog_posts__empty', $out);
    }
}
```

(Data-bearing assertions — N cards, a card without `cover` omitting `__image`, `date`/`title`/`href` present, and the `__empty` placeholder appearing under preview — are added in Step 8 after seeding `post` entries and rendering with the preview annotation enabled, modeled on how `BlockLibraryRenderTest` exercises preview annotation.)

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter=BlogPostsRenderTest`
Expected: FAIL — `Unable to find template "blocks/blog_posts.twig"`.

- [ ] **Step 3: Add the `blog_posts` definition** to `StarterBlockTypes` (after the Pricing group, before the closing `];`)

```php
            ['slug' => 'blog_posts', 'label' => 'Blog posts', 'icon' => 'i-lucide-newspaper',
                'category' => 'Content',
                'description' => 'Lists published posts as cards (dynamic).',
                'schema' => [
                    ['name' => 'type', 'type' => 'string', 'pattern' => '[a-z0-9]+(-[a-z0-9]+)*'],
                    ['name' => 'limit', 'type' => 'number', 'min' => 1, 'max' => 12],
                    ['name' => 'order', 'type' => 'enum', 'enum' => ['newest', 'oldest']],
                    ['name' => 'category', 'type' => 'string'],
                    ['name' => 'columns', 'type' => 'enum', 'enum' => ['1', '2', '3', '4']],
                    ['name' => 'variant', 'type' => 'enum',
                        'enum' => ['outline', 'soft', 'subtle', 'ghost', 'naked']],
                    ['name' => 'orientation', 'type' => 'enum', 'enum' => ['vertical', 'horizontal']],
                ]],
```

- [ ] **Step 4: Add `cover` to the `post` seed** in `SetupService` (inside the `post` content type `schema`, after `body`)

```php
                    ['name' => 'cover', 'type' => 'asset'],
```

- [ ] **Step 5: Create `blog_posts.twig`** (block + inline card macro)

```twig
{# blog_posts — dynamically lists published posts as cards (refs.md blogPosts). Leaf
   block: fetches via entries() (no child blocks). The card is an inline same-file
   macro (no separate blogPost block/partial in v1). Empty result shows a placeholder
   ONLY in preview; public renders nothing. #}
{% import _self as bp %}
{% set columns = ({'1':'1','2':'2','3':'3','4':'4'}[data.columns|default('3')] ?? '3') %}
{% set variant = ({outline:'outline',soft:'soft',subtle:'subtle',ghost:'ghost',naked:'naked'}[data.variant|default('outline')] ?? 'outline') %}
{% set orientation = ({vertical:'vertical',horizontal:'horizontal'}[data.orientation|default('vertical')] ?? 'vertical') %}
{% set rootClass = [
  'thallo-block thallo-block-blog_posts',
  'thallo-block-blog_posts--columns-' ~ columns,
  'thallo-block-blog_posts--variant-' ~ variant,
  'thallo-block-blog_posts--orientation-' ~ orientation,
]|join(' ')|trim %}
{% set posts = entries(data.type|default('post'), {
  limit: data.limit|default(3),
  order: data.order|default('newest'),
  category: data.category|default(null),
}) %}
<div class="{{ rootClass }}">
  {% if posts is not empty %}
    <div class="thallo-block-blog_posts__grid">
      {% for post in posts %}{{ bp.card(post) }}{% endfor %}
    </div>
  {% elseif is_preview() %}
    <div class="thallo-block-blog_posts__empty">No posts found.</div>
  {% endif %}
</div>
{% macro card(post) %}
{% set f = post.fields|default({}) %}
{% set cover = f.cover|default(null) %}
{% set url = post.href|default(null) %}
<article class="thallo-block-blog_posts__card">
  {% if cover %}<a class="thallo-block-blog_posts__image" href="{{ url|default('#') }}"><img src="{{ media(cover) }}" alt="" loading="lazy"></a>{% endif %}
  <div class="thallo-block-blog_posts__body">
    {% if f.categories is defined and f.categories is not empty %}
    <div class="thallo-block-blog_posts__meta">
      {% for cat in f.categories %}<span class="thallo-block-blog_posts__badge">{{ cat.title|default(cat.fields.title)|default('') }}</span>{% endfor %}
    </div>
    {% endif %}
    {% if f.title %}<h3 class="thallo-block-blog_posts__title">{% if url %}<a href="{{ url }}">{{ f.title }}</a>{% else %}{{ f.title }}{% endif %}</h3>{% endif %}
    {% if f.excerpt %}<p class="thallo-block-blog_posts__description">{{ f.excerpt }}</p>{% endif %}
    {% if post.published_at %}<time class="thallo-block-blog_posts__date" datetime="{{ post.published_at }}">{{ post.published_at|date('M j, Y') }}</time>{% endif %}
  </div>
</article>
{% endmacro %}
```

(Confirm the shaped `categories` shape during implementation — the macro reads `cat.title` then `cat.fields.title` then falls back to empty, so it is robust to either an expanded object or a nested-fields object; if categories arrive as bare uuid strings, render nothing for the badge label. Adjust only if needed.)

- [ ] **Step 6: Append blog CSS** to the Pricing section's end in `blocks.css`

```css
/* ── Blog posts (refs.md blogPosts/blogPost) ────────────────────────────────
   Dynamic listing of post cards; rounded self-contained cards. */
.thallo-block-blog_posts__grid { display: grid; gap: var(--space-4); grid-template-columns: 1fr; }
@media (min-width: 48em) {
  .thallo-block-blog_posts--columns-2 .thallo-block-blog_posts__grid { grid-template-columns: repeat(2, 1fr); }
  .thallo-block-blog_posts--columns-3 .thallo-block-blog_posts__grid { grid-template-columns: repeat(3, 1fr); }
  .thallo-block-blog_posts--columns-4 .thallo-block-blog_posts__grid { grid-template-columns: repeat(4, 1fr); }
}
.thallo-block-blog_posts__card {
  display: flex; flex-direction: column; overflow: hidden;
  border-radius: var(--radius-lg); background: var(--bg); border: 1px solid var(--line);
}
.thallo-block-blog_posts--variant-soft .thallo-block-blog_posts__card { background: var(--surface); border-color: transparent; }
.thallo-block-blog_posts--variant-subtle .thallo-block-blog_posts__card { background: var(--surface); border-color: var(--line); }
.thallo-block-blog_posts--variant-ghost .thallo-block-blog_posts__card,
.thallo-block-blog_posts--variant-naked .thallo-block-blog_posts__card { background: transparent; border-color: transparent; }
.thallo-block-blog_posts__image { display: block; aspect-ratio: 16 / 9; overflow: hidden; }
.thallo-block-blog_posts__image img { width: 100%; height: 100%; object-fit: cover; }
.thallo-block-blog_posts__body { display: flex; flex-direction: column; gap: 0.5rem; padding: var(--space-4); }
.thallo-block-blog_posts__meta { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.thallo-block-blog_posts__badge {
  padding: 0.1rem 0.5rem; border-radius: var(--radius);
  background: var(--surface-2); color: var(--muted); font-size: 0.75rem; font-weight: 600;
}
.thallo-block-blog_posts__title { margin: 0; font-size: 1.25rem; font-weight: 600; color: var(--ink); }
.thallo-block-blog_posts__title a { color: inherit; text-decoration: none; }
.thallo-block-blog_posts__description { margin: 0; color: var(--muted); }
.thallo-block-blog_posts__date { font-size: 0.85rem; color: var(--muted); }
.thallo-block-blog_posts__empty {
  padding: var(--space-4); border: 1px dashed var(--line); border-radius: var(--radius-lg);
  color: var(--muted); text-align: center;
}
/* horizontal card: image beside body */
@media (min-width: 48em) {
  .thallo-block-blog_posts--orientation-horizontal .thallo-block-blog_posts__card { flex-direction: row; }
  .thallo-block-blog_posts--orientation-horizontal .thallo-block-blog_posts__image { flex: 0 0 40%; aspect-ratio: auto; }
}
```

- [ ] **Step 7: Bump seed count** `SeedBlockTypesTest.php` `42` → `43`.

- [ ] **Step 8: Add data-bearing render tests** (seed posts + preview)

Extend `BlogPostsRenderTest`: seed 2 `post` entries (one with a `cover`, one without), render, and assert: two `__card`s; the one without `cover` has no `__image`; `__title`/`__date`/`href` present; then render with the preview annotation enabled and an unknown type and assert the `__empty` placeholder appears (preview) while a public render of the same emits nothing. Model the seeding + preview-annotation toggling on `BlockLibraryRenderTest`.

- [ ] **Step 9: Run tests + phpcs**

Run: `vendor/bin/phpunit --filter='BlogPostsRenderTest|SeedBlockTypesTest'` → Expected: PASS.
Run: `vendor/bin/phpcs app/Content/Blocks/StarterBlockTypes.php app/Setup/SetupService.php tests/Integration/Render/BlogPostsRenderTest.php` → Expected: no errors.

- [ ] **Step 10: Reseed + sync the dev DB**

Run: `php glueful thallo:blocks:seed` → Expected: "Created 1, skipped 42." (creates `blog_posts`).
Then add the `cover` field to the current dev DB's `post` content type (pre-launch manual sync — via the admin Content-Types editor, or a throwaway script that appends `{name: cover, type: asset}` to the `post` row's schema). Fresh installs get it from the seed.

- [ ] **Step 11: Commit (HOLD)**

```bash
git add app/Content/Blocks/StarterBlockTypes.php app/Setup/SetupService.php \
  packages/thallo-render/themes/default/templates/blocks/blog_posts.twig \
  packages/thallo-render/themes/default/assets/blocks.css \
  tests/Integration/Content/SeedBlockTypesTest.php \
  tests/Integration/Render/BlogPostsRenderTest.php
git commit -m "Add blog_posts block and cover field on the post type"
```

---

### Task 5: Full CI

- [ ] **Step 1: Run the full suite**

Run: `composer ci`
Expected: phpcs + full phpunit green. Fix any line-length (≤120) or failing invariant (e.g. `ShadowTokensTest` — use borders, not raw `box-shadow`, for any rings) before proceeding.

- [ ] **Step 2: Commit (HOLD)** — only if Step 1 required fixes

```bash
git add -A -- ':!admin' ':!CLAUDE.md'
git commit -m "CI fixes for blog_posts block"
```

---

## Self-Review

**Spec coverage:**
- One `blog_posts` leaf block, card inline → Task 4. ✓
- `EntryListReader` contract + `EngineEntryListReader` modeled on `facets()` → Task 2. ✓
- Shared shaping path (extract from `listItems`) → Task 1. ✓
- `entries()` + `is_preview()` twig fns, null-safe, tag collection → Task 3. ✓
- Server-side limit clamp 1..12 → Task 2 impl + `testLimitIsClampedToTwelve`. ✓
- Deterministic category = first filterable reference field → Task 2 impl + test. ✓
- Broad `thallo:type:{slug}` from resolved row + per-item + expansion + term tags, no UUID type tag → Task 2 impl + broad-tag test. ✓
- Empty-state preview-only → Task 4 template + test. ✓
- `cover` on post seed + dev sync → Task 4. ✓
- Seed 42→43 → Task 4. ✓
- Author deferred → not built (no task). ✓
- Hold commits, no attribution → Global Constraints + every Commit HOLD. ✓

**Placeholder scan:** The two "data-bearing tests" steps (2.6, 4.8) describe assertions + point to a concrete model test rather than inlining seeded-entry fixtures, because entry seeding is harness-specific and must be copied from an existing delivery/render integration test; every production-code step has complete code. Acceptable — flagged, not a silent gap.

**Type/name consistency:** `EntryListReader::list(string, array, string): {items, cache_tags}`, `ListingItemShaper::shape(rows, typeRow, locale, expanded)`, `entries()`/`is_preview()`, class `thallo-block-blog_posts` + `__grid/__card/__image/__title/__description/__meta/__date/__badge/__empty` are consistent across contract, reader, extension, template, CSS, and tests.
