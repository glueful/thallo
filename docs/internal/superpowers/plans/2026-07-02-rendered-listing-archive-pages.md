# Rendered Listing / Archive Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Type listing pages (`/blog`, `/blog/page/2`) and term-archive pages (`/blog/category/php`) rendered through the lemma-render Twig pipeline, resolver-owned end to end.

**Architecture:** `PublicRouteResolver` gains `listing`/`archive` result kinds; core's `EnginePublicRouteResolver` extends its path grammar (path-based pagination, locale-wins rule at every length) and resolves the data with existing machinery (`paginatePublished`, the `published_entry_references` membership predicate, `ReferenceTargetResolver`, `DeliveryItemShaper`), adding a batch-rendered `href` per list item. The render pack adds two controller arms, three default-theme templates, and emits the broad `lemma:type:{type}` Cache-Tag that makes purging structural. `RenderPageCache` is untouched.

**Tech Stack:** PHP 8.3, lemma core (`app/Content/Delivery`) + `lemma-contracts` + `packages/lemma-render`, Twig, PHPUnit integration tests.

**Spec:** `docs/superpowers/specs/2026-07-02-rendered-listing-archive-pages-design.md`

## Global Constraints

- URL grammar per spec §1 (optional active-locale prefix at EVERY length — locale wins over type-slug collisions): `/{type}`, `/{type}/page/{n}` (n ≥ 2), `/{type}/{field}/{term}`, `/{type}/{field}/{term}/page/{n}`; existing `/{type}/{slug}` unchanged.
- Page-number pins: `/page/1` → **301 to the bare path** (listing AND archive); `page/0`, negative, or non-numeric → not_found; `{n} > total_pages` → not_found; **`total_pages = max(1, ceil(total / per_page))`** — page 1 always valid (empty listing = 200, `total_pages: 1`).
- `page` is a **reserved word as a `{field}` segment** (pagination parse wins) — documented + characterization-tested.
- Config is RENDER-owned: `lemma_render.listing_types` (`RENDER_LISTING_TYPES`, comma-separated, default `[]` = grammar dormant) and `lemma_render.listing_per_page` (`RENDER_LISTING_PER_PAGE`, default 10). Core's resolver reads these as a **soft optional config namespace** (string keys only — no class/package dependency; pack absent → dormant). Archives gate on the same allowlist.
- **Membership = `published_entry_references`** (the same single source as the API archive endpoint — the surfaces cannot diverge). Term resolution: uuid-first then `referenceSlugField` via `ReferenceTargetResolver`; ambiguity → not_found on this anonymous surface (the API 422s — deliberate divergence, spec §2).
- **List items use the delivery LIST shape + `href`** (never `shapePublic` — no per-item seo). `href` is batch-rendered (ONE `entry_routes` query per page), default locale collapses via `renderDefaultLocale` (the `CanonicalProjector` rule); routeless item → `href: null`. The TERM in an archive payload IS `shapePublic` (a real page subject).
- **Cache-Tag on every listing/archive 200 carries `lemma:type:{type}`** — the correctness mechanism, not an optimization (page 2 changes when one new entry publishes); plus per-item entry tags; archives add `lemma:entry:{termUuid}` + `lemma:type:{termTypeSlug}`. `RenderPageCache` needs NO changes.
- Contract change is breaking (docblock kinds + keys) — accepted, monorepo. NOTE: the payload adds `term_type: ?string` beyond the spec §2 sketch — required by §4's term-type tag pin (the controller cannot otherwise know the term's type slug); flag it in the spec's §2 shape when Task 1 lands (one-line spec edit).
- Anonymous surface: all visibility via `DeliveryVisibility` with null scopes, as the resolver already does.
- Boundaries: pack code never references `App\`. Provider convention: `use` imports in services()/factories. PSR-12, 120 cols. Verify phpcs with its real exit code (`vendor/bin/phpcs -q; echo $?`) — never through a pipe.
- **Commit only when authorized.** The two grouping steps below STAGE the work and stop; run the commit command only after the human partner authorizes it. No attribution trailers. Never stage CLAUDE.md.

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php` | Modify | docblock: new kinds + payload keys |
| `app/Content/Delivery/EnginePublicRouteResolver.php` | Modify | grammar + listing/archive resolution + item hrefs |
| `packages/lemma-render/config/lemma-render.php` | Modify | `listing_types` + `listing_per_page` |
| `phpunit.xml` | Modify | `RENDER_LISTING_TYPES=blog,post`, `RENDER_LISTING_PER_PAGE=2` for the suite |
| `packages/lemma-render/src/Http/Controllers/RenderController.php` | Modify | listing/archive arms, context, Cache-Tag, prev/next paths |
| `packages/lemma-render/themes/default/templates/{listing,archive,_pagination}.twig` | Create | default reference templates |
| `packages/lemma-render/README.md` | Modify | listing docs (config, grammar, reserved `page`) |
| `tests/Integration/Render/PublicRouteResolverTest.php` | Modify | grammar + listing resolution tests |
| `tests/Integration/Render/ListingArchivePagesTest.php` | Create | archive resolution + kernel pipeline + caching |
| `CHANGELOG.md`, `docs/NEXT.md`, `docs/V2_DESIGN.md` | Modify | changelog + tracker flips |

Codebase facts the implementer needs:
- `EnginePublicRouteResolver` is bound to the contract with `autowire` — new constructor deps (`ApplicationContext`, `DeliveryRepository`, `PublishedReferenceRepository`, `ReferenceTargetResolver` (bound to `ReferenceFilterResolver`), `PathRenderer`) are ALL container-registered, so autowire keeps working; config reads happen at resolve time via the injected context.
- `DeliveryRepository::paginatePublished(string $typeUuid, string $locale, int $page, int $perPage, ?array $filter, ?array $order): array{data, total, current_page, per_page}`; `PublishedReferenceRepository::membershipPredicate(string $sourceTypeUuid, string $field, string $targetEntryUuid): array{sql, bindings}` rides the `$filter` slot.
- `DeliveryItemShaper::shape($rows, $schema, $selector, $locale, $typeUuid, null)` + `item($row)` produce the delivery LIST shape `{uuid, locale, version, published_at, fields}` (no seo); the empty selector is `FieldSelector::fromRequest(Request::create('/'))` (the `shapePublic` precedent).
- `PathRenderer::render($typeSlug, $locale, $slug)` / `renderDefaultLocale($typeSlug, $slug)` — relative paths when `lemma.seo.public_url_base` is unset (the default); item hrefs use whatever it returns so they match the `path()` Twig function and canonicals.
- `entry_routes` columns: `entry_uuid, content_type_uuid, locale, slug` (unique on type+locale+slug). Tests seed routes via `(new RouteRepository($conn))->assign($entryUuid, $typeUuid, $locale, $slug)`.
- The default theme's `layout.twig` defines `{% block title %}` and `{% block content %}`; templates extend `'layout.twig'`.
- Test env: `composer test:reset-db && composer test:migrate` once; `LemmaTestCase::TABLES` already truncates every table this feature touches; `PublicRouteResolverTest` seeds `i18n_locales` en/fr in setUp and uses `SeedsPublishedContent` (blog type, `/blog/hello` published, routes assigned via the trait); render kernel tests purge `render:*` in tearDown.

---

### Task 1: Contract + resolver grammar + listing resolution

**Files:**
- Modify: `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php`
- Modify: `app/Content/Delivery/EnginePublicRouteResolver.php`
- Modify: `packages/lemma-render/config/lemma-render.php`
- Modify: `phpunit.xml`
- Modify: `docs/superpowers/specs/2026-07-02-rendered-listing-archive-pages-design.md` (one line: add `term_type` to the §2 shape)
- Test: `tests/Integration/Render/PublicRouteResolverTest.php`

**Interfaces:**
- Produces: extended resolver result — every return now carries
  `listing: ?array{items: list<array>, page: int, per_page: int, total: int, total_pages: int}`,
  `term: ?array`, `term_type: ?string`, `field: ?string` (null except on the new kinds).
  Kind `'listing'` fills `listing`; `'archive'` (Task 2) fills all four. List items are
  `{uuid, locale, version, published_at, fields, href: ?string}`. Task 3's controller
  consumes exactly these keys.

- [ ] **Step 1: phpunit env + config keys**

In `phpunit.xml`, next to the existing `<env name="CACHE_DRIVER" value="array"/>`:

```xml
        <env name="RENDER_LISTING_TYPES" value="blog,post"/>
        <env name="RENDER_LISTING_PER_PAGE" value="2"/>
```

Append to `packages/lemma-render/config/lemma-render.php` (after the render-cache keys):

```php
    // Content types with rendered listing pages at /{type} (and term archives at
    // /{type}/{field}/{term}) — comma-separated slugs. EMPTY (the default) keeps the
    // whole listing/archive grammar dormant. Types must also be publicly deliverable.
    'listing_types' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('RENDER_LISTING_TYPES', '')),
    ))),

    // Items per rendered listing/archive page (path-based pagination: /{type}/page/2).
    'listing_per_page' => (int) env('RENDER_LISTING_PER_PAGE', 10),
```

- [ ] **Step 2: Write the failing resolver tests**

Add to `tests/Integration/Render/PublicRouteResolverTest.php` (it already resolves via the container `PublicRouteResolver` binding and seeds `/blog/hello` published in en + fr; the suite env allowlists `blog,post` with `per_page=2`). Match the file's existing helper style — if it resolves through a `$this->resolver()->resolvePath(...)` helper, use it; the assertions below are the content:

```php
    public function testBareTypePathResolvesListingPageOne(): void
    {
        $this->seedBilingualPublishedEntry();
        $r = $this->resolver()->resolvePath('/blog');
        self::assertSame('listing', $r['kind']);
        self::assertSame('blog', $r['type']);
        self::assertSame('en', $r['locale']);
        self::assertSame(1, $r['listing']['page']);
        self::assertSame(1, $r['listing']['total']);
        self::assertSame(1, $r['listing']['total_pages']); // max(1, ceil) pin
        self::assertCount(1, $r['listing']['items']);
        $item = $r['listing']['items'][0];
        self::assertSame('/blog/hello', $item['href']);       // batch-rendered, default-locale collapse
        self::assertArrayNotHasKey('seo', $item);             // LIST shape, not shapePublic
    }

    public function testLocalePrefixedListing(): void
    {
        $this->seedBilingualPublishedEntry();
        $r = $this->resolver()->resolvePath('/fr/blog');
        self::assertSame('listing', $r['kind']);
        self::assertSame('fr', $r['locale']);
        self::assertSame('/fr/blog/bonjour', $r['listing']['items'][0]['href']);
    }

    public function testListingPaginationGrammar(): void
    {
        $this->seedBilingualPublishedEntry();
        // /page/1 → 301 to the bare path (canonical).
        $r = $this->resolver()->resolvePath('/blog/page/1');
        self::assertSame('redirect', $r['kind']);
        self::assertSame('/blog', $r['redirect']['location']);
        self::assertSame(301, $r['redirect']['status']);
        // page 0 / non-numeric / beyond total_pages → not_found.
        self::assertSame('not_found', $this->resolver()->resolvePath('/blog/page/0')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/blog/page/abc')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/blog/page/9')['kind']);
    }

    public function testListingPageTwoWithPerPageTwo(): void
    {
        // Suite env pins listing_per_page=2; three published entries → 2 pages.
        $this->seedBilingualPublishedEntry();
        $this->seedExtraPublishedPost('extra0000001', 'vextra000001', 'second-post', 'Second');
        $this->seedExtraPublishedPost('extra0000002', 'vextra000002', 'third-post', 'Third');

        $r = $this->resolver()->resolvePath('/blog/page/2');
        self::assertSame('listing', $r['kind']);
        self::assertSame(2, $r['listing']['page']);
        self::assertSame(3, $r['listing']['total']);
        self::assertSame(2, $r['listing']['total_pages']);
        self::assertCount(1, $r['listing']['items']);
    }

    public function testUnlistedTypeStaysNotFound(): void
    {
        // 'category' is not in RENDER_LISTING_TYPES — the grammar is dormant for it.
        $this->connection()->table('content_types')->insert([
            'uuid' => 'cattypelist0', 'slug' => 'category', 'name' => 'Category',
            'description' => null, 'cache_ttl' => null, 'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode([['name' => 'slug', 'type' => 'string']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_by' => null,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        self::assertSame('not_found', $this->resolver()->resolvePath('/category')['kind']);
    }

    public function testRoutelessItemHasNullHref(): void
    {
        $this->seedBilingualPublishedEntry();
        // A published entry with NO entry_routes row (seeded directly, skipping assign()).
        $this->seedExtraPublishedPost('norel0000001', 'vnorel000001', null, 'No route');
        $r = $this->resolver()->resolvePath('/blog');
        $hrefs = array_column($r['listing']['items'], 'href');
        self::assertContains(null, $hrefs);
        self::assertContains('/blog/hello', $hrefs);
    }

    public function testHrefIgnoresStaleRouteRowsOfOtherTypes(): void
    {
        // Regression (review P2): entry_routes' identity is (content_type_uuid, locale,
        // slug) and entry_uuid is only indexed — a stale row for the same entry+locale
        // under ANOTHER type uuid must never leak into the listing href.
        $entry = $this->seedBilingualPublishedEntry();
        $this->connection()->table('entry_routes')->insert([
            'entry_uuid' => $entry,
            'content_type_uuid' => 'staletype001', // not the blog type
            'locale' => 'en',
            'slug' => 'stale-slug',
        ]);

        $r = $this->resolver()->resolvePath('/blog');
        $hrefs = array_column($r['listing']['items'], 'href');
        self::assertContains('/blog/hello', $hrefs);
        self::assertNotContains('/blog/stale-slug', $hrefs);
    }

    /** Seed a published blog entry directly; $slug null = no route row. */
    private function seedExtraPublishedPost(
        string $entryUuid,
        string $versionUuid,
        ?string $slug,
        string $title,
    ): void {
        $db = $this->connection();
        $typeUuid = (string) $db->table('content_types')
            ->select(['uuid'])->where('slug', '=', 'blog')->first()['uuid'];
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => $typeUuid, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => $title], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => 'en', 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-02 01:00:00',
        ]);
        if ($slug !== null) {
            (new \App\Content\Repositories\RouteRepository($db))
                ->assign($entryUuid, $typeUuid, 'en', $slug);
        }
    }
```

(If `RouteRepository`'s constructor differs — the `SeedsPublishedContent` trait constructs it with extra deps in some versions — copy the trait's construction exactly.)

- [ ] **Step 3: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/PublicRouteResolverTest.php
```

Expected: new tests FAIL (`/blog` resolves `not_found`; no `listing` key); existing tests PASS.

- [ ] **Step 4: Extend the contract docblock**

Replace the `@return` docblock of `resolvePath` in `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php` (interface docblock updated to match; `resolveEntry`'s "Same result shape" line stays true):

```php
    /**
     * @return array{kind: 'content'|'listing'|'archive'|'redirect'|'gone'|'not_found',
     *   locale: ?string, type: ?string, content: ?array,
     *   redirect: ?array{location: string, status: int},
     *   listing: ?array{items: list<array<string,mixed>>, page: int, per_page: int,
     *     total: int, total_pages: int},
     *   term: ?array, term_type: ?string, field: ?string}
     *   `type` is the content-type slug (content/listing/archive kinds) — template
     *   hierarchies select on it. `listing` (listing + archive kinds) carries LIST-shaped
     *   items each with a ready `href` (?string; null = routeless) and
     *   total_pages = max(1, ceil(total / per_page)) — never 0. `term` (archive kind) is
     *   the SHOW-shaped term entry (seo included); `term_type` its content-type slug
     *   (for surrogate cache tags); `field` the source reference field.
     */
```

- [ ] **Step 5: Implement grammar + listing in the resolver**

In `app/Content/Delivery/EnginePublicRouteResolver.php`:

New imports:

```php
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Seo\PathRenderer;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Lemma\Contracts\Delivery\ReferenceTargetResolver;
use Glueful\Support\FieldSelection\FieldSelector;
use Symfony\Component\HttpFoundation\Request;

use function config;
```

Constructor gains (append after `$shaper`; all autowire-resolvable):

```php
        private readonly ApplicationContext $context,
        private readonly DeliveryRepository $delivery,
        private readonly PublishedReferenceRepository $projection,
        private readonly ReferenceTargetResolver $terms,
        private readonly PathRenderer $paths,
```

Replace the segment-classification block of `resolvePath()` (everything from the
`$segments = ...` assignment through the current 2/3-segment if/elseif/else) with:

```php
        $segments = array_values(array_filter(
            explode('/', trim($normalized, '/')),
            static fn(string $s): bool => $s !== '',
        ));
        $decoded = array_map(rawurldecode(...), $segments);

        // Locale-wins rule at EVERY length (listing spec §1): an ACTIVE-locale first
        // segment is always the locale, never a type slug.
        $locale = $this->locales->default();
        $prefix = '';
        if (count($decoded) >= 2 && $this->isActiveLocale($decoded[0])) {
            $locale = array_shift($decoded);
            $prefix = '/' . rawurlencode($locale);
            array_shift($segments);
        }

        switch (count($decoded)) {
            case 1: // /{type} → listing page 1
                return $this->resolveListing($decoded[0], $locale, 1);
            case 2: // /{type}/{slug} → entry (existing behavior)
                [$typeSlug, $slug] = $decoded;
                break;
            case 3:
                // `page` is RESERVED as a field segment: pagination parses first.
                if ($decoded[1] === 'page') {
                    return $this->pagedOrCanonical(
                        $decoded[2],
                        $prefix . '/' . $segments[0],
                        fn(int $n): array => $this->resolveListing($decoded[0], $locale, $n),
                    );
                }
                // /{type}/{field}/{term} → archive page 1
                return $this->resolveArchive($decoded[0], $decoded[1], $decoded[2], $locale, 1);
            case 5:
                if ($decoded[3] === 'page') {
                    return $this->pagedOrCanonical(
                        $decoded[4],
                        $prefix . '/' . implode('/', array_slice($segments, 0, 3)),
                        fn(int $n): array => $this->resolveArchive(
                            $decoded[0],
                            $decoded[1],
                            $decoded[2],
                            $locale,
                            $n,
                        ),
                    );
                }
                return $this->notFound();
            default:
                return $this->notFound();
        }
```

(The existing entry-resolution code after the removed block continues to use `$typeSlug`, `$slug`, `$locale` unchanged.)

Add the private methods (below `resolveEntry()`):

```php
    /**
     * Shared page-number handling (listing spec §1 pins): non-numeric or < 1 →
     * not_found; exactly 1 → 301 to the canonical bare path; otherwise resolve page n.
     *
     * @param callable(int): array<string,mixed> $resolve
     * @return array<string,mixed>
     */
    private function pagedOrCanonical(string $rawPage, string $basePath, callable $resolve): array
    {
        if (!ctype_digit($rawPage)) {
            return $this->notFound();
        }
        $n = (int) $rawPage;
        if ($n === 1) {
            return $this->redirect($basePath, 301);
        }
        if ($n < 1) {
            return $this->notFound();
        }
        return $resolve($n);
    }

    /**
     * /{type}[/page/n] → listing. Dormant unless the type is allowlisted in
     * lemma_render.listing_types (a render-owned key read softly — this grammar exists
     * only for rendered delivery; pack absent / key empty ⇒ not_found).
     *
     * @return array<string,mixed>
     */
    private function resolveListing(string $typeSlug, string $locale, int $page): array
    {
        if (!in_array($typeSlug, $this->listingTypes(), true)) {
            return $this->notFound();
        }
        $typeRow = $this->types->findBySlug($typeSlug);
        if ($typeRow === null || !$this->isPubliclyDeliverable($typeRow)) {
            return $this->notFound();
        }
        $typeUuid = (string) $typeRow['uuid'];

        $page = $this->paginate($typeUuid, $locale, $page, null);
        if ($page === null) {
            return $this->notFound();
        }
        [$listing, $rows] = $page;
        $listing['items'] = $this->listItems($rows, $typeRow, $locale);

        return [
            'kind' => 'listing', 'locale' => $locale, 'type' => $typeSlug,
            'content' => null, 'redirect' => null,
            'listing' => $listing, 'term' => null, 'term_type' => null, 'field' => null,
        ];
    }

    /**
     * Paginate the published spine; null = page beyond range. total_pages is pinned to
     * max(1, ceil(total / per_page)) so page 1 of an EMPTY listing is valid (spec §1 —
     * a naive ceil(0/n) = 0 would make page 1 "beyond range").
     *
     * @param array{sql: string, bindings: list<mixed>}|null $filter
     * @return array{0: array{page: int, per_page: int, total: int, total_pages: int},
     *   1: list<array<string,mixed>>}|null
     */
    private function paginate(string $typeUuid, string $locale, int $page, ?array $filter): ?array
    {
        $perPage = max(1, (int) config($this->context, 'lemma_render.listing_per_page', 10));
        $result = $this->delivery->paginatePublished($typeUuid, $locale, $page, $perPage, $filter, null);
        $totalPages = max(1, (int) ceil($result['total'] / $perPage));
        if ($page > $totalPages) {
            return null;
        }
        return [
            [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) $result['total'],
                'total_pages' => $totalPages,
            ],
            $result['data'],
        ];
    }

    /**
     * LIST-shaped items + a ready per-item `href` (listing spec §2 pin): the delivery
     * list shape (no per-item seo — shapePublic is a SHOW shape), hrefs batch-rendered
     * from ONE entry_routes query, default locale collapsed exactly like canonicals.
     * Routeless entries carry href: null.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $typeRow
     * @return list<array<string,mixed>>
     */
    private function listItems(array $rows, array $typeRow, string $locale): array
    {
        if ($rows === []) {
            return [];
        }
        $typeUuid = (string) $typeRow['uuid'];
        $typeSlug = (string) $typeRow['slug'];
        $schema = \App\Content\Schema\ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));
        $selector = FieldSelector::fromRequest(Request::create('/')); // empty = full item

        $shaped = $this->shaper->shape($rows, $schema, $selector, $locale, $typeUuid, null);

        $uuids = array_values(array_filter(array_map(
            static fn(array $r): string => (string) ($r['entry_uuid'] ?? ''),
            $shaped,
        )));
        $slugByEntry = [];
        if ($uuids !== []) {
            $placeholders = implode(', ', array_fill(0, count($uuids), '?'));
            // Constrained by content_type_uuid: the route table's real identity is
            // (content_type_uuid, locale, slug) — entry_uuid alone can carry stale
            // rows under another type, which would render a wrong-type href.
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
            $item['href'] = $slug === null ? null : $this->href($typeSlug, $locale, $slug);
            $items[] = $item;
        }
        return $items;
    }

    /** Default locale collapses (no /en/ prefix) — the CanonicalProjector rule. */
    private function href(string $typeSlug, string $locale, string $slug): string
    {
        return $locale === $this->locales->default()
            ? $this->paths->renderDefaultLocale($typeSlug, $slug)
            : $this->paths->render($typeSlug, $locale, $slug);
    }

    /** @return list<string> the render-owned listing allowlist (soft config read). */
    private function listingTypes(): array
    {
        return array_values(array_filter(array_map(
            strval(...),
            (array) config($this->context, 'lemma_render.listing_types', []),
        )));
    }
```

Add a `resolveArchive` STUB for now (Task 2 fills it) so the grammar compiles:

```php
    /** @return array<string,mixed> */
    private function resolveArchive(
        string $typeSlug,
        string $field,
        string $term,
        string $locale,
        int $page,
    ): array {
        return $this->notFound(); // implemented in the archive task
    }
```

Update `notFound()` and `redirect()` (and the two `'content'` returns + the `'gone'` return) to carry the four new keys — every return path gains `'listing' => null, 'term' => null, 'term_type' => null, 'field' => null`.

- [ ] **Step 6: Add `term_type` to the spec's §2 shape**

In `docs/superpowers/specs/2026-07-02-rendered-listing-archive-pages-design.md` §2, after the `term:` line add:

```
term_type: ?string // archive kind: the term's content-type slug (drives its cache tag)
```

- [ ] **Step 7: Run the tests**

```bash
vendor/bin/phpunit tests/Integration/Render/PublicRouteResolverTest.php
vendor/bin/phpunit tests/Integration/Render/ tests/Integration/Seo/
```

Expected: PASS — new listing tests AND every existing render/seo test (the grammar change must not disturb entry resolution; `/sitemap-history`-style 1-segment paths now parse as listings of unlisted types → still not_found). CAVEAT: if `PublicRouteResolverTest` already asserts a 1-segment path like `/blog` is `not_found`, that expectation legitimately changes to `listing` (blog is suite-allowlisted) — update the assertion, don't weaken the grammar. No commit yet.

---

### Task 2: Archive resolution

**Files:**
- Modify: `app/Content/Delivery/EnginePublicRouteResolver.php` (fill the `resolveArchive` stub)
- Test: `tests/Integration/Render/ListingArchivePagesTest.php` (created here — resolver-level archive tests; kernel tests come in Task 3)

**Interfaces:**
- Consumes: Task 1's `paginate`/`listItems`/`pagedOrCanonical`/`listingTypes` helpers; `PublishedReferenceRepository::membershipPredicate`; `ReferenceTargetResolver::resolve` (throws `InvalidFilterException` on ambiguity); `DeliveryRepository::findPublishedByUuid`; `DeliveryItemShaper::shapePublic`.
- Produces: kind `'archive'` results with `listing` + `term` + `term_type` + `field` filled.

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/Render/ListingArchivePagesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Repositories\RouteRepository;
use App\Tests\Support\LemmaTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Lemma\Contracts\Delivery\PublicRouteResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Rendered listing/archive pages (listing spec §1–§5): archive resolution over the
 * published-reference projection, kernel pipeline through the catch-all, template
 * fallback, and the broad type-tag cache purge.
 *
 * Suite env: RENDER_LISTING_TYPES=blog,post, RENDER_LISTING_PER_PAGE=2.
 */
final class ListingArchivePagesTest extends LemmaTestCase
{
    private const CAT_TYPE_UUID = 'cattyperlst0';
    private string $postType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->table('content_types')->insert([
            'uuid' => self::CAT_TYPE_UUID,
            'slug' => 'category',
            'name' => 'Category',
            'description' => null,
            'cache_ttl' => null,
            'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode(
                [['name' => 'slug', 'type' => 'string', 'required' => true],
                 ['name' => 'title', 'type' => 'string']],
                JSON_THROW_ON_ERROR,
            ),
            'schema_version' => 1,
            'created_by' => null,
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);
        $this->postType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                [
                    'name' => 'category',
                    'type' => 'reference',
                    'reference_type' => 'category',
                    'reference_slug_field' => 'slug',
                    'multiple' => true,
                    'filterable' => true,
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->cache()->deletePattern('render:*');
        parent::tearDown();
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function resolver(): PublicRouteResolver
    {
        return $this->container()->get(PublicRouteResolver::class);
    }

    private function seedTerm(string $entryUuid, string $versionUuid, string $slug, string $title): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => self::CAT_TYPE_UUID, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['slug' => $slug, 'title' => $title], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => 'en', 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
    }

    /** Published post with route + projection rows — a full archive member. */
    private function seedMemberPost(
        string $entryUuid,
        string $versionUuid,
        string $slug,
        array $categoryUuids,
        string $title = 'P',
    ): void {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => $title, 'category' => $categoryUuids], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => 'en', 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
        (new RouteRepository($db))->assign($entryUuid, $this->postType, 'en', $slug);
        $this->container()->get(PublishedReferenceRepository::class)
            ->projectFromPublished($entryUuid, $this->postType, 'en');
    }

    public function testArchiveResolvesTermAndProjectionMembers(): void
    {
        $this->seedTerm('term00000001', 'vterm0000001', 'php', 'PHP');
        $this->seedMemberPost('lpost0000001', 'vlpost000001', 'in-php', ['term00000001'], 'In PHP');
        $this->seedMemberPost('lpost0000002', 'vlpost000002', 'no-cat', [], 'No cat');

        $r = $this->resolver()->resolvePath('/post/category/php');
        self::assertSame('archive', $r['kind']);
        self::assertSame('post', $r['type']);
        self::assertSame('category', $r['field']);
        self::assertSame('category', $r['term_type']);
        self::assertSame('term00000001', $r['term']['uuid']);
        self::assertArrayHasKey('seo', $r['term']); // the term IS shapePublic (show shape)
        self::assertSame(1, $r['listing']['total']);
        self::assertSame('/post/in-php', $r['listing']['items'][0]['href']);
    }

    public function testArchiveMembershipComesFromTheProjection(): void
    {
        // JSONB claims membership; the projection does not — the projection wins (§2 pin).
        $this->seedTerm('term00000002', 'vterm0000002', 'div', 'Div');
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'ldiv00000001', 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vldiv0000001', 'entry_uuid' => 'ldiv00000001', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => 'D', 'category' => ['term00000002']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'ldiv00000001', 'locale' => 'en', 'version_uuid' => 'vldiv0000001',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        // NO projection row.
        $r = $this->resolver()->resolvePath('/post/category/div');
        self::assertSame('archive', $r['kind']);
        self::assertSame([], $r['listing']['items']);
        self::assertSame(1, $r['listing']['total_pages']); // empty page 1 valid (max(1,…))
    }

    public function testArchiveGrammarEdges(): void
    {
        $this->seedTerm('term00000003', 'vterm0000003', 'edge', 'Edge');
        $this->seedMemberPost('lpost0000003', 'vlpost000003', 'edge-post', ['term00000003']);

        // page/1 → 301 to the archive base.
        $r = $this->resolver()->resolvePath('/post/category/edge/page/1');
        self::assertSame('redirect', $r['kind']);
        self::assertSame('/post/category/edge', $r['redirect']['location']);
        // Unknown term / non-filterable field / unlisted type → not_found.
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/category/nope')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/title/edge')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/category/category/edge')['kind']);
        // Beyond total_pages → not_found (per_page=2, 1 member → 1 page).
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/category/edge/page/2')['kind']);
    }

    public function testFieldNamedPageIsShadowedByPagination(): void
    {
        // The reserved-word cost (§1, characterized): a reference field literally named
        // `page` cannot have rendered archives when the term is numeric — and any
        // /post/page/{digits} parses as pagination.
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/page/7')['kind']);
        // Non-numeric third segment falls through to archive parsing (field `page`
        // doesn't exist on `post`) → not_found either way.
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/page/seven')['kind']);
    }
}
```

- [ ] **Step 2: Run to verify the archive tests fail**

```bash
vendor/bin/phpunit tests/Integration/Render/ListingArchivePagesTest.php
```

Expected: FAIL — archive paths resolve `not_found` (the stub).

- [ ] **Step 3: Implement `resolveArchive`**

Replace the Task 1 stub in `EnginePublicRouteResolver` (add import `use App\Content\Schema\ContentTypeSchema;` if not present — Task 1's `listItems` referenced it FQCN-inline; normalize to an import while here):

```php
    /**
     * /{type}/{field}/{term}[/page/n] → term archive. Same allowlist + anonymous gates
     * as listings; membership is the published-reference projection (the API archive
     * endpoint's single source — the surfaces cannot diverge); the term resolves
     * uuid-first then referenceSlugField, and ambiguity is not_found on this anonymous
     * surface (the API 422s — deliberate, spec §2).
     *
     * @return array<string,mixed>
     */
    private function resolveArchive(
        string $typeSlug,
        string $field,
        string $term,
        string $locale,
        int $page,
    ): array {
        if (!in_array($typeSlug, $this->listingTypes(), true)) {
            return $this->notFound();
        }
        $typeRow = $this->types->findBySlug($typeSlug);
        if ($typeRow === null || !$this->isPubliclyDeliverable($typeRow)) {
            return $this->notFound();
        }
        $typeUuid = (string) $typeRow['uuid'];
        $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));

        $fieldDef = $schema->field($field);
        if ($fieldDef === null || $fieldDef->type !== 'reference' || !$fieldDef->filterable) {
            return $this->notFound();
        }
        $targetRow = $this->types->findBySlug((string) ($fieldDef->referenceType ?? ''));
        if ($targetRow === null || !$this->isPubliclyDeliverable($targetRow)) {
            return $this->notFound(); // fail closed — the term body is page content
        }
        $targetUuid = (string) $targetRow['uuid'];
        $targetSlug = (string) $targetRow['slug'];

        try {
            $targets = $this->terms->resolve($fieldDef, $locale, [$term]);
        } catch (InvalidFilterException) {
            return $this->notFound(); // ambiguous slug: anonymous surface has no 422
        }
        $termUuid = $targets[0] ?? null;
        if ($termUuid === null) {
            return $this->notFound();
        }
        $termRow = $this->delivery->findPublishedByUuid($targetUuid, $locale, $termUuid);
        if ($termRow === null) {
            return $this->notFound();
        }

        $result = $this->paginate(
            $typeUuid,
            $locale,
            $page,
            $this->projection->membershipPredicate($typeUuid, $field, $termUuid),
        );
        if ($result === null) {
            return $this->notFound();
        }
        [$listing, $rows] = $result;
        $listing['items'] = $this->listItems($rows, $typeRow, $locale);

        return [
            'kind' => 'archive', 'locale' => $locale, 'type' => $typeSlug,
            'content' => null, 'redirect' => null,
            'listing' => $listing,
            'term' => $this->shaper->shapePublic($termRow, $targetUuid, $targetSlug),
            'term_type' => $targetSlug,
            'field' => $field,
        ];
    }
```

- [ ] **Step 4: Run the tests + phpcs, then STAGE** *(grouping 1 — resolver core; commit only when authorized)*

```bash
vendor/bin/phpunit tests/Integration/Render/ tests/Integration/Seo/ tests/Integration/Content/Delivery/
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
git add packages/lemma-contracts/src/Delivery/PublicRouteResolver.php \
        app/Content/Delivery/EnginePublicRouteResolver.php \
        packages/lemma-render/config/lemma-render.php phpunit.xml \
        docs/superpowers/specs/2026-07-02-rendered-listing-archive-pages-design.md \
        tests/Integration/Render/PublicRouteResolverTest.php \
        tests/Integration/Render/ListingArchivePagesTest.php
```

Expected: all PASS, `PHPCS_EXIT=0`, boundaries OK. STOP HERE — when (and only when)
the human partner authorizes the commit:

```bash
git commit -m "feat(content): listing/archive kinds on PublicRouteResolver

Path grammar (/{type}, /{type}/page/n, /{type}/{field}/{term}[/page/n], locale
prefixes) with page/1 canonical 301s and total_pages = max(1, ceil(total/per));
render-owned listing_types allowlist read softly; LIST-shaped items with
batch-rendered hrefs; archive membership pinned to published_entry_references."
```

---

### Task 3: Render pack — controller arms, templates, caching

**Files:**
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php`
- Create: `packages/lemma-render/themes/default/templates/listing.twig`
- Create: `packages/lemma-render/themes/default/templates/archive.twig`
- Create: `packages/lemma-render/themes/default/templates/_pagination.twig`
- Test: `tests/Integration/Render/ListingArchivePagesTest.php`

**Interfaces:**
- Consumes: resolver payload keys from Tasks 1–2 (`listing`, `term`, `term_type`, `field`); existing `render()`/`tagResponse()`/`RenderPageCache` mechanics.
- Produces: kernel-rendered `listing/{type}.twig → listing.twig` and `archive/{type}.twig → archive.twig` pages; template context `items`, `pagination{page, per_page, total, total_pages, prev_path, next_path}`, `type`, `term`, `field`; `Cache-Tag` per Global Constraints.

- [ ] **Step 1: Write the failing kernel tests**

Add to `ListingArchivePagesTest.php` (imports to add: `use App\Content\Events\EntryPublished;`, `use Glueful\Events\EventService;`):

```php
    public function testListingRendersThroughTheKernelWithLinks(): void
    {
        $this->seedTerm('term00000004', 'vterm0000004', 'k1', 'K1');
        $this->seedMemberPost('kpost0000001', 'vkpost000001', 'kernel-post', ['term00000004'], 'Kernel post');

        $res = $this->handle(Request::create('/post', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('Kernel post', $html);
        self::assertStringContainsString('href="/post/kernel-post"', $html); // ready hrefs, no path() loop
        // The broad type tag is on the response (the §4 purge pin).
        self::assertStringContainsString('lemma:type:post', (string) $res->headers->get('Cache-Tag'));
        self::assertStringContainsString('lemma:entry:kpost0000001', (string) $res->headers->get('Cache-Tag'));
    }

    public function testArchiveRendersTermAndMembersWithTags(): void
    {
        $this->seedTerm('term00000005', 'vterm0000005', 'tagged', 'Tagged');
        $this->seedMemberPost('kpost0000002', 'vkpost000002', 'tagged-post', ['term00000005'], 'Tagged post');

        $res = $this->handle(Request::create('/post/category/tagged', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('Tagged', $html);       // term heading
        self::assertStringContainsString('Tagged post', $html);  // member
        $cacheTag = (string) $res->headers->get('Cache-Tag');
        self::assertStringContainsString('lemma:type:post', $cacheTag);
        self::assertStringContainsString('lemma:type:category', $cacheTag);   // term type
        self::assertStringContainsString('lemma:entry:term00000005', $cacheTag); // the term
    }

    public function testPaginationPathsAndDistinctCacheEntries(): void
    {
        $this->seedTerm('term00000006', 'vterm0000006', 'p', 'P');
        $this->seedMemberPost('kpost0000003', 'vkpost000003', 'p1', ['term00000006'], 'Post one');
        $this->seedMemberPost('kpost0000004', 'vkpost000004', 'p2', ['term00000006'], 'Post two');
        $this->seedMemberPost('kpost0000005', 'vkpost000005', 'p3', ['term00000006'], 'Post three');

        $one = $this->handle(Request::create('/post', 'GET'));
        $two = $this->handle(Request::create('/post/page/2', 'GET'));
        self::assertSame(200, $one->getStatusCode());
        self::assertSame(200, $two->getStatusCode());
        self::assertNotSame((string) $one->getContent(), (string) $two->getContent());
        // Distinct cache entries (path-based pagination pin).
        self::assertIsArray($this->cache()->get('render:default:/post'));
        self::assertIsArray($this->cache()->get('render:default:/post/page/2'));
        // Page 2's prev is the BARE path (canonical), rendered by the pagination partial.
        self::assertStringContainsString('href="/post"', (string) $two->getContent());
        // /page/1 through the kernel → 301 to the bare path.
        $canonical = $this->handle(Request::create('/post/page/1', 'GET'));
        self::assertSame(301, $canonical->getStatusCode());
        self::assertSame('/post', $canonical->headers->get('Location'));
    }

    public function testNewPublishPurgesCachedListingViaTheBroadTypeTag(): void
    {
        // The §4 pin proven STRICTLY: the cached page must NOT contain the newly
        // published entry, so its per-item entry tags cannot explain the purge — only
        // the broad lemma:type:post tag can. Cache page 2 (per_page=2: it holds only
        // the third-oldest post), then publish a brand-new entry that was never
        // rendered anywhere.
        $this->seedMemberPost('kpost0000006', 'vkpost000006', 'one', [], 'One');
        $this->seedMemberPost('kpost0000007', 'vkpost000007', 'two', [], 'Two');
        $this->seedMemberPost('kpost0000008', 'vkpost000008', 'three', [], 'Three');
        $two = $this->handle(Request::create('/post/page/2', 'GET'));
        self::assertSame(200, $two->getStatusCode());
        self::assertIsArray($this->cache()->get('render:default:/post/page/2'));
        self::assertStringNotContainsString(
            'kpostnew0001',
            (string) $two->headers->get('Cache-Tag'),
            'precondition: the soon-to-publish entry is not tagged on the cached page',
        );

        $this->seedMemberPost('kpostnew0001', 'vkpostnew001', 'brand-new', [], 'Brand new');
        $this->container()->get(EventService::class)
            ->dispatch(new EntryPublished('kpostnew0001', $this->postType, 'en'));

        self::assertNull(
            $this->cache()->get('render:default:/post/page/2'),
            'page 2 must purge via lemma:type:post — no per-entry tag links it to the new entry',
        );
    }

    public function testEmptyListingRendersPageOne(): void
    {
        // 'blog' is allowlisted but has no entries in this test → 200, empty items,
        // and /blog/page/2 is beyond total_pages (=1) → themed 404.
        $this->connection()->table('content_types')->insert([
            'uuid' => 'blogtypelst0', 'slug' => 'blog', 'name' => 'Blog',
            'description' => null, 'cache_ttl' => null, 'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode([['name' => 'title', 'type' => 'string']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_by' => null,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $res = $this->handle(Request::create('/blog', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $miss = $this->handle(Request::create('/blog/page/2', 'GET'));
        self::assertSame(404, $miss->getStatusCode());
        self::assertStringContainsString('text/html', (string) $miss->headers->get('Content-Type'));
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/ListingArchivePagesTest.php
```

Expected: the new kernel tests FAIL (listing/archive kinds fall into the controller's `default` arm → themed 404); the Task 2 resolver tests still PASS.

- [ ] **Step 3: Controller arms + collection rendering**

In `packages/lemma-render/src/Http/Controllers/RenderController.php`:

Extend the `page()` match:

```php
        return match ($result['kind']) {
            'redirect' => new Response('', $result['redirect']['status'], [
                'Location' => $result['redirect']['location'],
            ]),
            'gone' => $this->errors->themed410(
                fn (): Response => $this->render('error.twig', $this->defaultLocale(), null, 410),
            ),
            'content' => $this->renderEntry($result),
            'listing', 'archive' => $this->renderCollection($result, '/' . ltrim($path, '/')),
            default => $this->errors->themed404(
                fn (): Response => $this->render('404.twig', $this->defaultLocale(), null, 404),
            ),
        };
```

Change the private `render()` to accept extra context (existing callers unchanged):

```php
    /** @param array<string,mixed>|null $entry */
    private function render(
        string $template,
        string $locale,
        ?array $entry,
        int $status,
        array $extra = [],
    ): Response {
```

…and inside it, after the `$context['entry']` block, add:

```php
        $context += $extra;
```

Add the collection renderer (below `renderEntry()`):

```php
    /**
     * Listing/archive pages (listing spec §4). Template family follows the kind
     * (listing/{type}.twig → listing.twig; archive/{type}.twig → archive.twig); the
     * context ships ready pagination paths so themes never build page URLs; the
     * Cache-Tag ALWAYS carries the broad lemma:type:{type} — page contents change when
     * one new entry publishes, so per-item tags alone cannot keep cached pages fresh.
     *
     * @param array<string,mixed> $result
     */
    private function renderCollection(array $result, string $path): Response
    {
        $family = $result['kind'] === 'archive' ? 'archive' : 'listing';
        $typeSlug = (string) $result['type'];
        $locale = (string) $result['locale'];
        /** @var array<string,mixed> $listing */
        $listing = $result['listing'];

        $candidate = "{$family}/{$typeSlug}.twig";
        $template = $this->twig()->getLoader()->exists($candidate) ? $candidate : "{$family}.twig";

        $page = (int) $listing['page'];
        $totalPages = (int) $listing['total_pages'];
        // The base path strips a trailing /page/{n}; page 2's prev is the BARE base
        // (canonical — /page/1 301s).
        $base = $page > 1 ? (string) preg_replace('#/page/\d+$#', '', $path) : $path;
        $pagination = [
            'page' => $page,
            'per_page' => (int) $listing['per_page'],
            'total' => (int) $listing['total'],
            'total_pages' => $totalPages,
            'prev_path' => $page <= 1 ? null : ($page === 2 ? $base : $base . '/page/' . ($page - 1)),
            'next_path' => $page < $totalPages ? $base . '/page/' . ($page + 1) : null,
        ];

        $extra = [
            'items' => $listing['items'],
            'pagination' => $pagination,
            'type' => $typeSlug,
        ];
        if ($result['kind'] === 'archive') {
            $extra['term'] = $result['term'];
            $extra['field'] = $result['field'];
        }

        $response = $this->render($template, $locale, null, 200, $extra);
        $this->tagCollection($response, $result);
        return $response;
    }

    /**
     * Surrogate tags for a collection page: per-item entry tags + the BROAD type tag
     * (the correctness mechanism — see renderCollection); archives add the term's entry
     * tag and its type's tag so term edits and term-type events purge too.
     *
     * @param array<string,mixed> $result
     */
    private function tagCollection(Response $response, array $result): void
    {
        $typeSlug = (string) $result['type'];
        $tags = [];
        foreach ((array) ($result['listing']['items'] ?? []) as $item) {
            $uuid = is_string($item['uuid'] ?? null) ? $item['uuid'] : '';
            if ($uuid !== '') {
                $tags[] = 'lemma:entry:' . $uuid;
            }
        }
        $termUuid = is_string($result['term']['uuid'] ?? null) ? $result['term']['uuid'] : '';
        if ($termUuid !== '') {
            $tags[] = 'lemma:entry:' . $termUuid;
        }
        $tags[] = 'lemma:type:' . $typeSlug;
        $termType = is_string($result['term_type'] ?? null) ? $result['term_type'] : '';
        if ($termType !== '' && $termType !== $typeSlug) {
            $tags[] = 'lemma:type:' . $termType;
        }
        $response->headers->set('Cache-Tag', implode(', ', array_values(array_unique($tags))));
    }
```

- [ ] **Step 4: Default theme templates**

Create `packages/lemma-render/themes/default/templates/listing.twig`:

```twig
{% extends 'layout.twig' %}
{% block title %}{{ type }} — {{ site.name }}{% endblock %}
{% block content %}
  <h1>{{ type }}</h1>
  <ul class="listing">
    {% for item in items %}
      <li>
        {% if item.href %}
          <a href="{{ item.href }}">{{ item.fields.title|default(item.uuid) }}</a>
        {% else %}
          {{ item.fields.title|default(item.uuid) }}
        {% endif %}
      </li>
    {% else %}
      <li class="empty">No entries yet.</li>
    {% endfor %}
  </ul>
  {% include '_pagination.twig' %}
{% endblock %}
```

Create `packages/lemma-render/themes/default/templates/archive.twig`:

```twig
{% extends 'layout.twig' %}
{% block title %}{{ term.fields.title|default(term.fields.slug)|default(term.uuid) }} — {{ site.name }}{% endblock %}
{% block content %}
  <h1>{{ term.fields.title|default(term.fields.slug)|default(term.uuid) }}</h1>
  <ul class="listing archive">
    {% for item in items %}
      <li>
        {% if item.href %}
          <a href="{{ item.href }}">{{ item.fields.title|default(item.uuid) }}</a>
        {% else %}
          {{ item.fields.title|default(item.uuid) }}
        {% endif %}
      </li>
    {% else %}
      <li class="empty">Nothing here yet.</li>
    {% endfor %}
  </ul>
  {% include '_pagination.twig' %}
{% endblock %}
```

Create `packages/lemma-render/themes/default/templates/_pagination.twig`:

```twig
{% if pagination.total_pages > 1 %}
  <nav class="pagination">
    {% if pagination.prev_path %}<a href="{{ pagination.prev_path }}" rel="prev">Newer</a>{% endif %}
    <span>Page {{ pagination.page }} of {{ pagination.total_pages }}</span>
    {% if pagination.next_path %}<a href="{{ pagination.next_path }}" rel="next">Older</a>{% endif %}
  </nav>
{% endif %}
```

- [ ] **Step 5: Run the render suite**

```bash
vendor/bin/phpunit tests/Integration/Render/
```

Expected: PASS — all new kernel tests plus the pre-existing suite (listing/archive responses are 200 `text/html`, so `RenderPageCache` stores them per-path with the controller's Cache-Tag, no middleware changes). No commit yet.

---

### Task 4: Docs, changelog, full verification

**Files:**
- Modify: `packages/lemma-render/README.md`
- Modify: `CHANGELOG.md` (`[Unreleased]` → prepend under `### Added`)
- Modify: `docs/NEXT.md`, `docs/V2_DESIGN.md` §6

- [ ] **Step 1: README section**

Add to `packages/lemma-render/README.md` (after the Homepage section):

```markdown
## Listing & archive pages

Allowlisted types get a rendered listing at `/{type}` and term archives at
`/{type}/{field}/{term}` (the field must be a filterable reference; membership
comes from the same published-reference projection as the delivery archive
endpoint). Pagination is path-based — `/{type}/page/2` — because the render
cache is keyed by path; `/page/1` 301s to the bare path. `page` is a reserved
word as an archive field segment. Templates: `listing/{type}.twig` →
`listing.twig`, `archive/{type}.twig` → `archive.twig`; context ships `items`
(each with a ready `href`; `null` = routeless), `pagination`
(`prev_path`/`next_path` precomputed), and for archives `term` + `field`.
Cached pages carry the broad `lemma:type:{type}` surrogate tag, so ANY publish
of the type purges every listing page immediately.

| Key (env) | Default |
|---|---|
| `lemma_render.listing_types` (`RENDER_LISTING_TYPES`, comma-separated) | `''` — feature dormant |
| `lemma_render.listing_per_page` (`RENDER_LISTING_PER_PAGE`) | `10` |
```

Also update the README's "Out of scope" list: remove "listing/archive pages" from the deferred items (keep the rest).

- [ ] **Step 2: CHANGELOG `[Unreleased]` (prepend under `### Added`)**

```markdown
- **Rendered listing & archive pages** (V2 render follow-up): `/{type}` and
  `/{type}/{field}/{term}` (+ `/page/n`, + locale prefixes) through the render
  catch-all — `PublicRouteResolver` gained `listing`/`archive` kinds with
  LIST-shaped items carrying batch-rendered `href`s; archive membership rides the
  `published_entry_references` projection; path-based pagination with `/page/1`
  canonical 301s and `total_pages = max(1, ceil(total/per_page))`; cached pages
  carry the broad `lemma:type:{type}` tag so any publish purges them. Opt-in via
  `RENDER_LISTING_TYPES` (default off). New default-theme templates
  `listing.twig`/`archive.twig`/`_pagination.twig`; `page` is reserved as an
  archive field segment.
```

- [ ] **Step 3: Tracker flips**

`docs/V2_DESIGN.md` §6: in the "Follow-up tracks" list, change the first item to:

```markdown
- ✅ listing/archive pages — **shipped 2026-07-02**
  (`docs/superpowers/specs/2026-07-02-rendered-listing-archive-pages-design.md`)
```

`docs/NEXT.md`: in "Recommended sequencing" item 2, replace the final sentence ("The
natural next pick is the **rendered listing/archive pages** track…") with:

```markdown
   ✅ The rendered listing/archive pages track it unblocked also **shipped**
   (2026‑07‑02): `/{type}` listings + `/{type}/{field}/{term}` archives through the
   render catch‑all, opt‑in via `RENDER_LISTING_TYPES`. Spec:
   `docs/superpowers/specs/2026-07-02-rendered-listing-archive-pages-design.md`.
```

- [ ] **Step 4: Full verification + STAGE** *(grouping 2 — render surface + docs; commit only when authorized)*

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Integration
git add packages/lemma-render tests/Integration/Render/ListingArchivePagesTest.php \
        CHANGELOG.md docs/NEXT.md docs/V2_DESIGN.md
```

Expected: `PHPCS_EXIT=0`, boundaries OK, Integration green (same pre-existing single
skip; render tearDowns already purge `render:*`). STOP HERE — when the human partner
authorizes the commit:

```bash
git commit -m "feat(lemma-render): rendered listing and term-archive pages

listing/{type}.twig and archive/{type}.twig families with ready item hrefs and
precomputed pagination paths; broad lemma:type Cache-Tag makes publish purges
structural; default-theme templates; opt-in via RENDER_LISTING_TYPES."
```

---

## Self-Review Notes (already applied)

- **Spec coverage:** §1 grammar + page pins → Task 1 (`pagedOrCanonical`, grammar tests) + Task 2 edges (archive 301/beyond-range) + Task 3 kernel 301; reserved `page` → `testFieldNamedPageIsShadowedByPagination`; `max(1, ceil)` → `paginate()` + empty-listing tests in Tasks 2–3; §2 contract/items/term shape → Task 1 docblock + `listItems` (no-seo assertion, null-href test) + Task 2 `shapePublic` term (seo-key assertion); §2 membership pin → divergence test; §3 config placement + dormant grammar → `listingTypes()` soft read + unlisted-type test; §4 templates/context/caching → Task 3 (`renderCollection`, `tagCollection`, purge-through-real-listener test, distinct-cache-entries test, prev-path-canonical assertion); §5 test list → all mapped except the explicit template-fallback-ladder test (`listing/blog.twig` absent → `listing.twig` is what every kernel test exercises, since no per-type templates ship — the ladder's positive case rides `renderEntry`'s existing characterization; add a per-type-template test during execution if a reviewer wants it).
- **Type consistency:** result keys `listing/term/term_type/field` identical across contract docblock, both resolver methods, and `renderCollection`/`tagCollection`; `paginate()` return tuple destructured the same way in both callers; `href()`/`listItems()` signatures match call sites; test helper `seedExtraPublishedPost(entry, version, ?slug, title)` matches all three uses.
- **Judgement calls, stated:** `term_type` payload addition (required by the §4 tag pin) lands with a one-line spec §2 edit in Task 1; item hrefs are whatever `PathRenderer` returns (relative when `public_url_base` unset — the default), matching `path()`/canonicals rather than forcing relativity; prev/next paths computed in the controller from the request path (trivial string ops on the current path; the resolver stays the grammar owner for INBOUND parsing).
