# Taxonomy Term Index Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/{type}/terms/{field}` renders all terms of a filterable reference field with counts, each linking to its archive page.

**Architecture:** The resolver gains a THIN `kind: 'terms'` (allowlist-gated grammar only — `terms` reserved at 3 segments before archive lookup, like `page`); the render controller fetches counts itself via the soft `FacetCountsReader` dependency and dispatches on the reader's pinned invariant (`cache_tags: []` ⇔ gate failure → themed 404; tags present → 200 even with zero items), builds archive hrefs with the default-locale collapse, and merges `cache_tags` into `Cache-Tag` for structural purging.

**Tech Stack:** PHP 8.3 (core resolver + lemma-render pack), Twig, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-02-term-index-pages-design.md`

## Global Constraints

- **Commit only when authorized.** The single grouping step STAGES and stops. No attribution trailers. Never stage CLAUDE.md.
- Grammar: `terms` parses BEFORE archive lookup at 3 segments AND the 5-segment paged form — a static reserved word like `page`. Allowlist (`lemma_render.listing_types`) is the ONLY resolver-side gate; field/visibility gates live in the reader. No pagination: `/{type}/terms/{field}/page/{n≥2}` is `not_found` **via an explicit 5-segment `terms` special-case** (review P1: without it, a type with a REAL filterable reference field named `terms` would leak rendered archive pages at page ≥ 2 while its page-1 paths render term indexes); `/page/1` still 301s to the index (harmless canonical — characterized). Regression: a filterable reference field literally named `terms` gets NO rendered archive at any page number.
- **Spec correction (one-line spec edit in Task 1):** an entry slugged `terms` is NOT shadowed — 2-segment `/post/terms` still parses as an entry path; the only reservation cost is an archive FIELD named `terms` (its rendered archives are unreachable; its term INDEX still works). Characterization tests pin both.
- Contract docblock (review note, verbatim requirement): the `PublicRouteResolver` kind union gains `'terms'`, with all payload keys null except `type`/`field`/`locale`, and `preview: false`.
- **Reader invariant promoted to contract** (spec §2 pin): `FacetCountsReader::counts()` returns non-empty `cache_tags` for every VALID facet (even zero items) and empty `cache_tags` ONLY on gate failure — the docblock states consumers may dispatch on it. No reader code changes (shipped behavior already conforms; `FacetsTwigTest` proves both directions).
- **hrefs are built by the render controller** (spec §3 pin): `/{type}/{field}/{slug}` with the locale prefix for non-default locales, `rawurlencode`d segments; `null`-slugged terms get `href: null`. The reader stays counts + tags.
- Ordering is the reader's (`count DESC, slug ASC`); limit 500 (the reader's max).
- Template family `terms/{type-slug}.twig` → `terms.twig`; gate-failure 404s go through the ordinary `errors->themed404` path.
- phpcs via real exit code (`vendor/bin/phpcs -q; echo $?`); boundaries; suite env already allowlists `blog,post`.

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php` | Modify | kind union + `terms` docblock |
| `packages/lemma-contracts/src/Delivery/FacetCountsReader.php` | Modify | invariant promoted in the docblock |
| `app/Content/Delivery/EnginePublicRouteResolver.php` | Modify | `terms` branch + `resolveTerms()` |
| `packages/lemma-render/src/Http/Controllers/RenderController.php` | Modify | `renderTerms()` arm + soft reader dep |
| `packages/lemma-render/src/LemmaRenderServiceProvider.php` | Modify | pass the soft reader to the controller |
| `packages/lemma-render/themes/default/templates/terms.twig` | Create | default index template |
| `docs/superpowers/specs/2026-07-02-term-index-pages-design.md` | Modify | the entry-shadowing correction |
| `tests/Integration/Render/TermIndexPagesTest.php` | Create | grammar + kernel + purge tests |
| `packages/lemma-render/README.md`, `CHANGELOG.md`, `docs/V2_DESIGN.md`, `docs/NEXT.md` | Modify | docs + tracker flips |

Codebase facts: seeds/patterns copy from `tests/Integration/Render/ListingArchivePagesTest.php` (category+post types, `seedTerm`/`seedMemberPost` with routes + projection, `render:*` tearDown purge); `RenderController` already has `mergeCacheTags(Response, list<string>)`, `errors->themed404(callable)`, `defaultLocale()`, the `render(..., array $extra)` signature, and the extension collector (reset/drain inside `render()` — the terms arm's `mergeCacheTags` composes with it); the controller constructor currently ends `(..., ReservedPaths $reserved, RenderErrorCache $errors, LoggerInterface $logger)` with `makeRenderController` mirroring it; `FacetCountsReader::class` is container-bound (core) — the render provider passes it softly with `$container->has(...)`.

---

### Task 1: Resolver grammar + contract docblocks

**Files:**
- Modify: `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php`
- Modify: `packages/lemma-contracts/src/Delivery/FacetCountsReader.php`
- Modify: `app/Content/Delivery/EnginePublicRouteResolver.php`
- Modify: `docs/superpowers/specs/2026-07-02-term-index-pages-design.md`
- Test: `tests/Integration/Render/TermIndexPagesTest.php` (created here)

**Interfaces:**
- Produces: `kind: 'terms'` results — `{kind: 'terms', locale, type, field, preview: false, all other payload keys null}`. Task 2's controller consumes `type`/`field`/`locale`.

- [ ] **Step 1: Write the failing resolver tests**

Create `tests/Integration/Render/TermIndexPagesTest.php`:

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
 * Taxonomy term index pages (term-index spec §1–§4): the reserved `terms` segment,
 * the thin kind, the reader-invariant 404/200 dispatch, controller-built archive
 * hrefs, and structural cache purging.
 *
 * Suite env: RENDER_LISTING_TYPES=blog,post.
 */
final class TermIndexPagesTest extends LemmaTestCase
{
    private const CAT_TYPE_UUID = 'cattypetidx0';
    private string $postType;

    protected function setUp(): void
    {
        parent::setUp();
        // Locale-prefixed grammar consults the i18n registry — seed en (default) + fr,
        // exactly like PublicRouteResolverTest.
        $this->connection()->getPDO()->exec("DELETE FROM i18n_locales WHERE code IN ('en', 'fr')");
        $now = gmdate('Y-m-d H:i:s');
        foreach ([['en', true], ['fr', false]] as [$code, $isDefault]) {
            $this->connection()->table('i18n_locales')->insert([
                'uuid' => \Glueful\Helpers\Utils::generateNanoID(),
                'code' => $code,
                'name' => strtoupper($code),
                'enabled' => true,
                'is_default' => $isDefault,
                'fallback_locale' => $isDefault ? null : 'en',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->connection()->table('content_types')->insert([
            'uuid' => self::CAT_TYPE_UUID, 'slug' => 'category', 'name' => 'Category',
            'description' => null, 'cache_ttl' => null, 'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode([['name' => 'slug', 'type' => 'string']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_by' => null,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $this->postType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Post', 'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
                 'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function resolver(): PublicRouteResolver
    {
        return $this->container()->get(PublicRouteResolver::class);
    }

    private function seedTerm(
        string $entryUuid,
        string $versionUuid,
        ?string $slug,
        string $locale = 'en',
    ): void {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => self::CAT_TYPE_UUID, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => $locale, 'version' => 1,
            'fields' => json_encode($slug === null ? [] : ['slug' => $slug], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => $locale, 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
    }

    private function seedMemberPost(
        string $entryUuid,
        string $versionUuid,
        array $categoryUuids,
        string $locale = 'en',
    ): void {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => $locale, 'version' => 1,
            'fields' => json_encode(['title' => 'P', 'category' => $categoryUuids], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => $locale, 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
        $this->container()->get(PublishedReferenceRepository::class)
            ->projectFromPublished($entryUuid, $this->postType, $locale);
    }

    // ---- grammar ---------------------------------------------------------------------

    public function testTermsPathResolvesThinTermsKind(): void
    {
        $r = $this->resolver()->resolvePath('/post/terms/category');
        self::assertSame('terms', $r['kind']);
        self::assertSame('post', $r['type']);
        self::assertSame('category', $r['field']);
        self::assertSame('en', $r['locale']);
        self::assertNull($r['listing']);   // THIN kind: no payload
        self::assertNull($r['content']);
        self::assertFalse($r['preview']);
    }

    public function testLocalePrefixedTermsPath(): void
    {
        // Review P2: the terms branch's locale shift is pinned DIRECTLY (fr seeded the
        // same way PublicRouteResolverTest does — see setUp).
        $r = $this->resolver()->resolvePath('/fr/post/terms/category');
        self::assertSame('terms', $r['kind']);
        self::assertSame('fr', $r['locale']);
        self::assertSame('post', $r['type']);
        self::assertSame('category', $r['field']);
    }

    public function testUnlistedTypeAndPaginationStayNotFound(): void
    {
        // 'category' is not allowlisted → grammar dormant for it.
        self::assertSame('not_found', $this->resolver()->resolvePath('/category/terms/x')['kind']);
        // No pagination: the explicit 5-segment `terms` special-case (review P1).
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/terms/category/page/2')['kind']);
        // page/1 hits the shared canonical 301 — a harmless redirect to the index
        // (characterized, term-index spec §1).
        $r = $this->resolver()->resolvePath('/post/terms/category/page/1');
        self::assertSame('redirect', $r['kind']);
        self::assertSame('/post/terms/category', $r['redirect']['location']);
    }

    public function testFieldLiterallyNamedTermsHasNoRenderedArchiveAtAnyPage(): void
    {
        // Review P1 regression: WITHOUT the 5-segment special-case, a type with a REAL
        // filterable reference field named `terms` would leak rendered archive pages at
        // page ≥ 2 (while its page-1 paths render term indexes) — the reservation must
        // hold at every segment count.
        $this->connection()->table('content_types')
            ->where('uuid', '=', $this->postType)
            ->update(['schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
                 'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
                ['name' => 'terms', 'type' => 'reference', 'reference_type' => 'category',
                 'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
            ], JSON_THROW_ON_ERROR)]);
        $this->seedTerm('tidxrsrv0001', 'vtidxrsrv001', 'php');
        // A member referencing the term through the `terms` field, projected.
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'tidxrsrvpst1', 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vtidxrsrvps1', 'entry_uuid' => 'tidxrsrvpst1', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => 'R', 'terms' => ['tidxrsrv0001']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'tidxrsrvpst1', 'locale' => 'en', 'version_uuid' => 'vtidxrsrvps1',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        $this->container()->get(PublishedReferenceRepository::class)
            ->projectFromPublished('tidxrsrvpst1', $this->postType, 'en');

        // /post/terms/php parses as the term INDEX of field `php` (nonexistent field →
        // reader gate → the controller 404s at kernel level) — NEVER the archive of
        // field `terms`.
        self::assertSame('terms', $this->resolver()->resolvePath('/post/terms/php')['kind']);
        // And the paged archive form must be sealed too — the leak the special-case
        // closes:
        self::assertSame(
            'not_found',
            $this->resolver()->resolvePath('/post/terms/php/page/2')['kind'],
        );
    }

    public function testEntrySluggedTermsIsNotShadowed(): void
    {
        // Spec correction: `terms` is reserved ONLY at 3 segments — the 2-segment
        // /post/terms is still an entry path and keeps working.
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'termsentry01', 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vtermsentry1', 'entry_uuid' => 'termsentry01', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => 'About terms'], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'termsentry01', 'locale' => 'en', 'version_uuid' => 'vtermsentry1',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        (new RouteRepository($this->connection()))->assign('termsentry01', $this->postType, 'en', 'terms');

        $r = $this->resolver()->resolvePath('/post/terms');
        self::assertSame('content', $r['kind']);
        self::assertSame('About terms', $r['content']['fields']['title']);
    }
}
```

- [ ] **Step 2: Run to verify the grammar tests fail**

```bash
vendor/bin/phpunit tests/Integration/Render/TermIndexPagesTest.php
```

Expected: `testTermsPathResolvesThinTermsKind` FAILS (archive-parses `terms` as a field → `not_found`); the entry-not-shadowed and pagination tests may already pass (they exercise existing behavior — that is the characterization).

- [ ] **Step 3: Docblocks + resolver branch**

`packages/lemma-contracts/src/Delivery/PublicRouteResolver.php` — in the `@return`
shape, change the kind union to
`'content'|'listing'|'archive'|'terms'|'redirect'|'gone'|'not_found'` and append to the
prose: "`terms` is the THIN term-index kind: only `type`, `field`, `locale` are set
(`preview: false`, every other payload key null) — the renderer fetches counts via
FacetCountsReader and dispatches on its invariant."

`packages/lemma-contracts/src/Delivery/FacetCountsReader.php` — extend the interface
docblock with the promoted invariant:

```php
 * INVARIANT (consumers may dispatch on it): a VALID facet ALWAYS returns non-empty
 * cache_tags — even with zero items — and cache_tags is empty ONLY on gate failure.
 * The rendered term-index page's 404-vs-200 split relies on exactly this.
```

`app/Content/Delivery/EnginePublicRouteResolver.php` — in the `case 3:` branch, after
the `page` check and BEFORE the archive return:

```php
                // `terms` is RESERVED like `page` (term-index spec §1): parses before
                // archive lookup, so an archive FIELD named `terms` is shadowed
                // (documented cost); 2-segment entry paths are untouched.
                if ($decoded[1] === 'terms') {
                    return $this->resolveTerms($decoded[0], $decoded[2], $locale);
                }
```

And in the `case 5:` branch, INSIDE the `$decoded[3] === 'page'` block BEFORE the
`pagedOrCanonical` call, seal the paged archive form (review P1 — without this, a
REAL filterable reference field named `terms` leaks rendered archives at page ≥ 2):

```php
                if ($decoded[3] === 'page') {
                    // The `terms` reservation holds at 5 segments too: term indexes
                    // have no pagination, and an archive FIELD named `terms` must not
                    // leak its paged form. page/1 keeps the shared canonical 301
                    // (redirects to the 3-segment path, which parses as the index).
                    if ($decoded[1] === 'terms') {
                        if (ctype_digit($decoded[4]) && (int) $decoded[4] === 1) {
                            return $this->redirect(
                                $prefix . '/' . implode('/', array_slice($segments, 0, 3)),
                                301,
                            );
                        }
                        return $this->notFound();
                    }
                    return $this->pagedOrCanonical(
```

(The existing `pagedOrCanonical(...)` call and its closure stay as they are — only the
`terms` guard is inserted above it.)

Add the method (near `resolveListing`):

```php
    /**
     * /{type}/terms/{field} → term index (term-index spec §2): a THIN kind — the
     * render controller fetches counts itself via FacetCountsReader and dispatches on
     * its invariant (empty cache_tags = gate failure). Only the listing allowlist is
     * resolver-side (grammar gate); field/visibility gates live in the reader.
     *
     * @return array<string,mixed>
     */
    private function resolveTerms(string $typeSlug, string $field, string $locale): array
    {
        if (!in_array($typeSlug, $this->listingTypes(), true)) {
            return $this->notFound();
        }
        return [
            'kind' => 'terms', 'locale' => $locale, 'type' => $typeSlug,
            'content' => null, 'redirect' => null,
            'listing' => null, 'term' => null, 'term_type' => null, 'field' => $field,
            'preview' => false,
        ];
    }
```

- [ ] **Step 4: Fix the spec's shadowing line**

In `docs/superpowers/specs/2026-07-02-term-index-pages-design.md` §1, replace
"an entry slugged `terms` is shadowed; an archive FIELD named `terms` cannot have
rendered archives" with "an archive FIELD named `terms` cannot have rendered archives
AT ANY PAGE NUMBER — the reservation is enforced at 3 segments AND via an explicit
5-segment seal on the paged form (its term INDEX still works); entries slugged `terms`
are UNAFFECTED — 2-segment entry paths never contain the reserved segment (corrected
from the original draft, characterization-tested)". Also update the §1 no-pagination
sentence: `/{type}/terms/{field}/page/{n≥2}` is not_found "via the explicit 5-segment
`terms` seal" (not via implicit archive-gate fallthrough). Make the matching §4
test-list tweaks ("entry slugged `terms` NOT shadowed — characterized"; "field named
`terms` has no rendered archive at any page — regression").

- [ ] **Step 5: Run the tests**

```bash
vendor/bin/phpunit tests/Integration/Render/TermIndexPagesTest.php
vendor/bin/phpunit tests/Integration/Render/ tests/Integration/Seo/
```

Expected: PASS (grammar green; existing suites undisturbed). No staging yet.

---

### Task 2: Controller `terms` arm + template

**Files:**
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php`
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php`
- Create: `packages/lemma-render/themes/default/templates/terms.twig`
- Test: `tests/Integration/Render/TermIndexPagesTest.php`

**Interfaces:**
- Consumes: `kind: 'terms'` (Task 1); `FacetCountsReader::counts()` + its invariant; existing `mergeCacheTags`/`errors->themed404`/`render(..., $extra)`.
- Produces: rendered term index — context `terms` (items + controller-built `href`), `type`, `field`; `Cache-Tag` = the reader's `cache_tags`.

- [ ] **Step 1: Write the failing kernel tests**

Add to `TermIndexPagesTest.php` (import to add: `use App\Content\Events\EntryPublished;`, `use Glueful\Events\EventService;`):

```php
    // ---- kernel ----------------------------------------------------------------------

    public function testTermIndexRendersCountsWithArchiveHrefs(): void
    {
        $this->seedTerm('tidxterm0001', 'vtidxterm001', 'php');
        $this->seedTerm('tidxterm0002', 'vtidxterm002', 'laravel');
        $this->seedMemberPost('tidxpost0001', 'vtidxpost001', ['tidxterm0001']);
        $this->seedMemberPost('tidxpost0002', 'vtidxpost002', ['tidxterm0001', 'tidxterm0002']);

        $res = $this->handle(Request::create('/post/terms/category', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('php', $html);
        self::assertStringContainsString('(2)', $html);                       // count
        self::assertStringContainsString('href="/post/category/php"', $html); // controller-built href
        self::assertStringContainsString('href="/post/category/laravel"', $html);
        $cacheTag = (string) $res->headers->get('Cache-Tag');
        self::assertStringContainsString('lemma:type:post', $cacheTag);      // count changes
        self::assertStringContainsString('lemma:type:category', $cacheTag);  // slug/term changes
    }

    public function testValidEmptyIndexIs200AndGateFailureIsThemed404(): void
    {
        // Valid field, zero members → 200 with the empty-state branch.
        $ok = $this->handle(Request::create('/post/terms/category', 'GET'));
        self::assertSame(200, $ok->getStatusCode());
        self::assertStringContainsString('No terms yet', (string) $ok->getContent());

        // Non-filterable field → the reader's gate → themed 404 (ordinary path).
        $bad = $this->handle(Request::create('/post/terms/title', 'GET'));
        self::assertSame(404, $bad->getStatusCode());
        self::assertStringContainsString('text/html', (string) $bad->headers->get('Content-Type'));
    }

    public function testNullSlugTermRendersUnlinked(): void
    {
        $this->seedTerm('tidxnoslug01', 'vtidxnoslug1', null); // no slug field value
        $this->seedMemberPost('tidxpost0003', 'vtidxpost003', ['tidxnoslug01']);

        $res = $this->handle(Request::create('/post/terms/category', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('tidxnoslug01', $html);              // uuid shown
        self::assertStringNotContainsString('href="/post/category/', $html);  // but no archive link
    }

    public function testLocalePrefixedIndexRendersLocaleAwareHrefs(): void
    {
        // Review P2: the fr variant is pinned end-to-end — kind/locale at the resolver
        // (grammar test above) AND locale-prefixed archive hrefs through the kernel.
        $this->seedTerm('tidxfrterm01', 'vtidxfrterm1', 'francais', 'fr');
        $this->seedMemberPost('tidxfrpost01', 'vtidxfrpost1', ['tidxfrterm01'], 'fr');

        $res = $this->handle(Request::create('/fr/post/terms/category', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('francais', $html);
        self::assertStringContainsString('href="/fr/post/category/francais"', $html);
    }

    public function testPublishPurgesCachedIndexThroughTheRealListener(): void
    {
        $this->seedTerm('tidxterm0003', 'vtidxterm003', 'purge');
        $this->handle(Request::create('/post/terms/category', 'GET'));
        self::assertIsArray(
            $this->container()->get(CacheStore::class)->get('render:default:/post/terms/category'),
        );

        // A brand-new post publishes → lemma:type:post purges the index (its counts
        // just changed) with zero new invalidation code.
        $this->seedMemberPost('tidxpostnew1', 'vtidxpostnw1', ['tidxterm0003']);
        $this->container()->get(EventService::class)
            ->dispatch(new EntryPublished('tidxpostnew1', $this->postType, 'en'));

        self::assertNull(
            $this->container()->get(CacheStore::class)->get('render:default:/post/terms/category'),
        );
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/TermIndexPagesTest.php
```

Expected: kernel tests FAIL — `kind: 'terms'` falls into the controller's `default` arm (themed 404).

- [ ] **Step 3: Controller arm + wiring + template**

`RenderController`: add the import
`use Glueful\Lemma\Contracts\Delivery\FacetCountsReader;`; append an optional
constructor param AFTER `$logger`:

```php
        private readonly ?FacetCountsReader $facetReader = null,
```

Extend the `page()` match:

```php
            'terms' => $this->renderTerms($result),
```

(placed with the other collection arms). Add the method (near `renderCollection`):

```php
    /**
     * Term index pages (term-index spec §2–§3): the resolver classified the path
     * (thin kind); THIS side fetches counts and dispatches on the FacetCountsReader
     * invariant — empty cache_tags means a gate failed (unknown/non-filterable field,
     * non-visible type) and a VALID facet always carries tags, even with zero items.
     * hrefs are built HERE (the reader stays counts + tags): the term's archive path
     * with the same default-locale collapse the other rendered hrefs use.
     *
     * @param array<string,mixed> $result
     */
    private function renderTerms(array $result): Response
    {
        $notFound = fn (): Response => $this->errors->themed404(
            fn (): Response => $this->render('404.twig', $this->defaultLocale(), null, 404),
        );
        if ($this->facetReader === null) {
            return $notFound();
        }
        $typeSlug = (string) $result['type'];
        $field = (string) $result['field'];
        $locale = (string) $result['locale'];

        $counts = $this->facetReader->counts($typeSlug, $field, $locale, 500);
        if ($counts['cache_tags'] === []) {
            return $notFound(); // the pinned invariant: empty tags ⇔ gate failure
        }

        $prefix = $locale === $this->defaultLocale() ? '' : '/' . rawurlencode($locale);
        $terms = [];
        foreach ($counts['items'] as $item) {
            $slug = $item['slug'];
            $item['href'] = $slug === null
                ? null
                : $prefix . '/' . rawurlencode($typeSlug) . '/' . rawurlencode($field)
                    . '/' . rawurlencode($slug);
            $terms[] = $item;
        }

        $candidate = "terms/{$typeSlug}.twig";
        $template = $this->twig()->getLoader()->exists($candidate) ? $candidate : 'terms.twig';
        $response = $this->render($template, $locale, null, 200, [
            'terms' => $terms,
            'type' => $typeSlug,
            'field' => $field,
        ]);
        $this->mergeCacheTags($response, $counts['cache_tags']);
        return $response;
    }
```

`LemmaRenderServiceProvider::makeRenderController`: append the soft reader (import
`use Glueful\Lemma\Contracts\Delivery\FacetCountsReader;` is already present from the
extension factory — verify; add if not):

```php
            $container->get(\Psr\Log\LoggerInterface::class),
            $container->has(FacetCountsReader::class)
                ? $container->get(FacetCountsReader::class)
                : null,
```

Create `packages/lemma-render/themes/default/templates/terms.twig`:

```twig
{% extends 'layout.twig' %}
{% block title %}{{ field }} — {{ site.name }}{% endblock %}
{% block content %}
  <h1>{{ field }}</h1>
  <ul class="terms">
    {% for term in terms %}
      <li>
        {% if term.href %}
          <a href="{{ term.href }}">{{ term.slug ?? term.uuid }}</a>
        {% else %}
          {{ term.slug ?? term.uuid }}
        {% endif %}
        <span class="count">({{ term.count }})</span>
      </li>
    {% else %}
      <li class="empty">No terms yet.</li>
    {% endfor %}
  </ul>
{% endblock %}
```

- [ ] **Step 4: Run the render suite**

```bash
vendor/bin/phpunit tests/Integration/Render/
```

Expected: PASS — all TermIndexPagesTest methods plus the pre-existing suite. No staging yet.

---

### Task 3: Docs + full verification + STAGE

**Files:**
- Modify: `packages/lemma-render/README.md`, `CHANGELOG.md`, `docs/V2_DESIGN.md`, `docs/NEXT.md`

- [ ] **Step 1: README** — in the "Listing & archive pages" section, append:

```markdown
Term INDEX pages live at `/{type}/terms/{field}` — every term of the field with its
count, each linking to its archive page (500-term cap, no pagination). `terms` is a
reserved word alongside `page`: an archive field literally named `terms` cannot have
rendered archive pages (entries slugged `terms` are unaffected — the reservation only
applies at three segments). A valid field with zero terms renders an empty index;
unknown/non-filterable fields render the themed 404.
```

- [ ] **Step 2: CHANGELOG `[Unreleased]` (prepend under `### Added`)**

```markdown
- **Taxonomy term index pages**: `/{type}/terms/{field}` renders every term of a
  filterable reference field with counts and archive links (`terms` joins `page` as a
  reserved segment; allowlist-gated; 500-term cap). The resolver kind is THIN — the
  render controller fetches via `FacetCountsReader` and dispatches on its now-pinned
  invariant (empty `cache_tags` ⇔ gate failure → themed 404; valid-but-empty → 200);
  index pages carry both type tags so publishes purge them structurally.
```

- [ ] **Step 3: Tracker flips** — `docs/V2_DESIGN.md` §6: change the
"taxonomy term INDEX pages" line to
`- ✅ taxonomy term INDEX pages — **shipped 2026-07-02**
(docs/superpowers/specs/2026-07-02-term-index-pages-design.md)`.
`docs/NEXT.md`: append to the rendered-delivery sequencing item, same style:
`✅ **Term index pages** also shipped (2026‑07‑02): /{type}/terms/{field} with counts
and archive links.`

- [ ] **Step 4: Full verification + STAGE** *(single grouping; commit only when authorized)*

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Integration
git add packages/lemma-contracts/src/Delivery/PublicRouteResolver.php \
        packages/lemma-contracts/src/Delivery/FacetCountsReader.php \
        app/Content/Delivery/EnginePublicRouteResolver.php \
        packages/lemma-render \
        docs/superpowers/specs/2026-07-02-term-index-pages-design.md \
        tests/Integration/Render/TermIndexPagesTest.php \
        CHANGELOG.md docs/V2_DESIGN.md docs/NEXT.md
```

Expected: `PHPCS_EXIT=0`, boundaries OK, Integration green (same pre-existing single
skip). STOP — when the human partner authorizes:

```bash
git commit -m "feat(render): taxonomy term index pages at /{type}/terms/{field}

terms joins page as a reserved segment (3-segment parse before archive lookup;
entries slugged terms unaffected — spec corrected + characterized); thin
kind:'terms' resolver result; render controller fetches via FacetCountsReader
and dispatches on its pinned invariant (empty cache_tags = gate failure);
controller-built archive hrefs with default-locale collapse; both type tags
merged into Cache-Tag for structural purging; default-theme terms.twig."
```

---

## Self-Review Notes (already applied)

- **Spec coverage:** §1 grammar/reserved/allowlist/no-pagination → Task 1 (incl. the page/1-301 characterization, the 5-segment `terms` seal with the field-literally-named-`terms` regression — review P1 — and the entry-NOT-shadowed correction, with the spec edit folded in); §2 thin kind + invariant promotion + 404/200 dispatch + structural tags → Task 1 docblocks + Task 2 `renderTerms` (+ purge-through-real-listener test); §3 controller hrefs/template/ordering → Task 2 (default-locale collapse asserted via `/post/category/php`, fr-prefixed hrefs asserted end-to-end — review P2 — null-slug unlinked test); §4 test list fully mapped; §5 out-of-scope untouched.
- **Type consistency:** `resolveTerms` return keys match the contract docblock note (all null except type/field/locale, preview false); `renderTerms` reads exactly `type`/`field`/`locale`; `counts(type, field, locale, 500)` matches the shipped signature; the controller ctor's optional param order (after `$logger`) matches `makeRenderController`'s append.
- **Judgement calls, stated:** `/terms/{field}/page/1` 301s to the index via the shared `pagedOrCanonical` (harmless canonical, characterized rather than special-cased); the reader-null case (pack running without the core binding) renders the themed 404 — same posture as a gate failure.
