# Preview-Through-Theme (+ facets() in Twig, OpenAPI exclusion) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Editors preview drafts rendered through the real Twig theme via the existing preview tokens; themes gain a tag-collected `facets()` function; the render pack's HTML routes leave the OpenAPI spec.

**Architecture:** `PublicRouteResolver` gains `resolvePreview(token)` returning `kind: 'content'` + a new `preview: true` result flag (core wraps the fail-closed `PreviewReader`, shaping the draft with the LIST shaper — no seo). The render pack registers `GET /_preview/{token}` WITHOUT `RenderPageCache` (structural bypass), renders through the normal entry hierarchy with a `preview` context flag, `no-store` + `noindex` headers, and 404s rendered fresh (never via `RenderErrorCache`). `PreviewController::mint` gains a server-decided `theme_url`; the SPA mints on click and opens-or-warns. `FacetCountsReader` returns `{items, cache_tags}` so a render-request-scoped collector (reset before render, drained after success) merges facet tags into `Cache-Tag`. OpenAPI drops the `Default`-tagged HTML routes via the existing tag deny-list.

**Tech Stack:** PHP 8.3 (core + lemma-contracts + lemma-render), Twig, Vue 3 SPA (openapi-typed client), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-02-preview-through-theme-design.md`

## Global Constraints

- **Commit only when authorized.** The two grouping steps below STAGE and stop; run commit commands only after the human partner authorizes. No attribution trailers. Never stage CLAUDE.md.
- `resolvePreview` success = **`kind: 'content'` + `preview: true`** — never a new kind; every other resolver return carries `preview: false`. Preview failures (malformed/expired/missing — `PreviewTokenException`, `PreviewNotFoundException`) → `not_found`, reason logged at info. Token-is-authorization: `public_delivery` gating does NOT apply (JSON-door parity).
- Preview content has **no `seo` key** (LIST shaping, not `shapePublic`); response headers `Cache-Control: no-store` + `X-Robots-Tag: noindex`; preview responses never touch `RenderPageCache` (route has no middleware) nor `RenderErrorCache` (404s render fresh).
- `theme_url` (mint response): `"/_preview/{token}"` when `lemma.render` is enabled; **`null` when disabled or unavailable** (`CapabilityRegistry::isEnabled` covers pack-absent AND switched-off). JSON preview URL unaffected. SPA behavior pinned: action ALWAYS shown → mint on click → open `theme_url` or `warning('Theme preview unavailable — rendered delivery is disabled')`. The SPA never builds theme URLs or consults capability state.
- `FacetCountsReader::counts()` returns `array{items: list<array{uuid: string, slug: ?string, count: int}>, cache_tags: list<string>}` — gate failure `{[], []}`; **valid facet (even zero counts)** `{…, ['lemma:type:{source}', 'lemma:type:{termType}']}`. Gates mirror the facets endpoint (anonymous visibility both sides, filterable reference, limit clamped 1..500); never throws into a template.
- **Collector scoping pinned:** reset before EVERY render; drained only after a successful render; a Twig exception must not leak tags into the next response. Existing `Cache-Tag` writers must MERGE (append-unique), never blind-`set`, so drained tags survive. Preview strips `Cache-Tag` entirely (no-store).
- OpenAPI: add `Default` to the `API_DOCS_EXCLUDE_TAGS` default in `config/documentation.php` (the render HTML routes are the ONLY `Default`-tagged operations — verified; an untagged API route is itself a bug). Regenerate `docs/openapi.json` + admin types (`pnpm gen:api`) deliberately.
- Boundaries (pack code never `App\`), PSR-12/120 cols, phpcs via real exit code (`vendor/bin/phpcs -q; echo $?` — no pipes), `pnpm type-check` for the SPA (not bare vue-tsc).

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php` | Modify | `resolvePreview()` + `preview: bool` in the shape |
| `packages/lemma-contracts/src/Delivery/FacetCountsReader.php` | Create | facet counts + cache-tags seam |
| `app/Content/Delivery/EnginePublicRouteResolver.php` | Modify | `resolvePreview` impl (+`preview => false` on all returns) |
| `app/Content/Delivery/EngineFacetCountsReader.php` | Create | gated counts + tags over `PublishedReferenceRepository` |
| `app/Content/Http/Controllers/PreviewController.php` | Modify | `theme_url` in mint response |
| `app/Content/Http/DTOs/Responses/Preview/PreviewMintData.php` | Modify | `?string $theme_url` |
| `app/Providers/LemmaServiceProvider.php` | Modify | `FacetCountsReader` binding |
| `packages/lemma-render/routes/public-routes.php` | Modify | `GET /_preview/{token}` (no page-cache middleware) |
| `packages/lemma-render/src/Http/Controllers/RenderController.php` | Modify | `preview()` action; collector reset/drain + Cache-Tag merge |
| `packages/lemma-render/src/RenderContextExtension.php` | Modify | `facets()` + tag collector |
| `packages/lemma-render/src/LemmaRenderServiceProvider.php` | Modify | extension factory gains the reader |
| `packages/lemma-render/themes/default/templates/layout.twig` | Modify | preview banner |
| `config/documentation.php` | Modify | `Default` in the exclude default |
| `docs/openapi.json`, `admin/src/api/*` (generated) | Regenerate | after theme_url + exclusion |
| `admin/src/queries/preview.ts`, `admin/.../PublishPanel.vue` | Modify | theme-preview action |
| `tests/Integration/Render/PreviewThemeTest.php` | Create | resolver + kernel preview tests |
| `tests/Integration/Render/FacetsTwigTest.php` | Create | reader/collector/merge tests |
| `packages/lemma-render/README.md`, `CHANGELOG.md`, `docs/V2_DESIGN.md`, `docs/NEXT.md` | Modify | docs + tracker flips |

Codebase facts:
- `PreviewMinter::mint(entry, locale, ?version): string`; `PreviewReader::read(token): array{entry_uuid, locale, version_uuid, version, schema_version, fields}` — throws `PreviewTokenException` / `PreviewNotFoundException`; both container-registered. `PreviewToken::mint(entry, locale, ?version, expiresAt, key)` for expired-token tests; the key comes via the `ResolvesPreviewKey` trait (`config('app.key')`).
- `EnginePublicRouteResolver` is autowired; new deps (`PreviewReader`, `EntryRepository`) are registered. `EntryRepository::findEntry(uuid): ?array` (has `content_type_uuid`, `status`).
- Shaping: `shape($rows, $schema, $emptySelector, $locale, $typeUuid, null)` + `item($row)` = LIST shape (`{uuid, locale, version, published_at, fields}`, no seo). Synthesize the row as `{entry_uuid, locale, version, version_uuid, schema_version, fields, published_at: null}` — **with `schema_version` set to the CURRENT type schema version, NOT `$read['schema_version']`** (review P1): `PreviewReader` already projected the fields forward but returns the ORIGINAL draft/version schema version; carrying that through would make `shape()` project AGAIN (`schema_version < current` triggers it), and double projection is unsafe when a rename chain re-uses a field name (`a→b` then `c→a`: the second pass moves the new `a` into `b`, clobbering it).
- `PreviewController::__construct(PreviewMinter, PreviewReader, ContentLocaleService)`; mint returns `Response::success(['token', 'expires_at', 'expires_in'])`. Capability check: `app($this->getContext?…)` — PreviewController is a plain controller with no context; inject `ApplicationContext` (autowired) and use `app($context, CapabilityRegistry::class)` (`Glueful\Lemma\Contracts\Capability\CapabilityRegistry` — a contracts class, fine in core).
- SPA: `admin/src/queries/preview.ts` `mintPreview()` returns `data?.data?.token`; `PublishPanel.vue` `onPreview()` mints → `buildPreviewUrl` (external `sitePreviewUrl`) or `warning('No preview URL is configured')`. Typed client regenerates via `pnpm gen:api` (reads the spec — see `admin/scripts/gen-api.mjs`).
- `RenderContextExtension.__construct(?MenuReader, EntryTargetResolver, string $defaultLocale)`; functions registered in `getFunctions()`; provider factory `makeRenderContextExtension`.
- OpenAPI tags: `config/documentation.php` `API_DOCS_EXCLUDE_TAGS` default `'Admin,Data,Documentation,Health,Security'`; only `GET /` and `GET /{path}` carry `Default` (verified against the committed spec).
- Test harness: suite env has `RENDER_LISTING_TYPES=blog,post`; `LemmaTestCase::TABLES` truncation list needs NO new tables here; render kernel tests purge `render:*` in tearDown; app-theme kernel tests aren't established — the facets merge test creates a real `themes/facetstest/` dir in the repo basePath, override-boots with `lemma_render.theme = facetstest`, drives `RenderController` from the override container (route-latch precedent), and removes the dir in tearDown.

---

### Task 1: `resolvePreview` — contract + core implementation

**Files:**
- Modify: `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php`
- Modify: `app/Content/Delivery/EnginePublicRouteResolver.php`
- Test: `tests/Integration/Render/PreviewThemeTest.php` (created here)

**Interfaces:**
- Produces: `resolvePreview(string $token): array` — success `{kind: 'content', preview: true, locale, type, content (LIST shape, NO seo), …nulls}`; all failures `not_found`. Every resolver return gains `preview: bool` (false elsewhere). Task 2's controller consumes `preview`, `type`, `content`, `locale`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/Render/PreviewThemeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewToken;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Lemma\Contracts\Delivery\PublicRouteResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Preview-through-theme (preview spec §1–§3): the resolvePreview seam (kind content +
 * preview flag, fail-closed), the uncached /_preview/{token} route, headers, and the
 * banner. Uses the REAL token mechanism (PreviewMinter / PreviewToken).
 */
final class PreviewThemeTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function resolver(): PublicRouteResolver
    {
        return $this->container()->get(PublicRouteResolver::class);
    }

    /** Seed a blog entry with a DRAFT (never published); returns its uuid. */
    private function seedDraftEntry(string $title = 'Draft title'): string
    {
        $types = new ContentTypeRepository($this->connection());
        if ($types->findBySlug('blog') === null) {
            $this->seedBilingualPublishedEntry(); // creates the type (and one published entry)
        }
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $uuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'user00000001');
        return $uuid;
    }

    public function testResolvePreviewReturnsContentKindWithPreviewFlag(): void
    {
        $entry = $this->seedDraftEntry('Unpublished words');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $r = $this->resolver()->resolvePreview($token);
        self::assertSame('content', $r['kind']);      // pinned: a content render…
        self::assertTrue($r['preview']);               // …with the preview flag
        self::assertSame('blog', $r['type']);
        self::assertSame('en', $r['locale']);
        self::assertSame('Unpublished words', $r['content']['fields']['title']);
        self::assertArrayNotHasKey('seo', $r['content']); // LIST shape — no seo (spec §2)
    }

    public function testResolvePreviewFailsClosed(): void
    {
        // Garbage token.
        self::assertSame('not_found', $this->resolver()->resolvePreview('garbage')['kind']);

        // Expired token (minted with a past expiry via PreviewToken directly).
        $entry = $this->seedDraftEntry();
        $key = (string) base64_decode(
            (string) preg_replace('/^base64:/', '', (string) config($this->appContext(), 'app.key')),
            true,
        );
        $expired = PreviewToken::mint($entry, 'en', null, time() - 60, $key);
        self::assertSame('not_found', $this->resolver()->resolvePreview($expired)['kind']);

        // Valid token whose draft has since been deleted.
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $entry)->delete();
        self::assertSame('not_found', $this->resolver()->resolvePreview($token)['kind']);
    }

    public function testNonPreviewResultsCarryPreviewFalse(): void
    {
        $this->seedBilingualPublishedEntry();
        self::assertFalse($this->resolver()->resolvePath('/blog/hello')['preview']);
        self::assertFalse($this->resolver()->resolvePath('/no/such')['preview']);
    }

    public function testOldSchemaPreviewProjectsExactlyOnce(): void
    {
        // Review P1 regression: PreviewReader projects fields forward but reports the
        // ORIGINAL schema_version; the resolver must pin the synthesized row to the
        // CURRENT version or shape() re-runs the migration chain. The rename chain
        // re-uses a name (a→b, then c→a): double projection would clobber `b` with the
        // new `a`. Old draft (v1): {a: 'first', c: 'second'} → current (v3) must render
        // {b: 'first', a: 'second'} exactly once.
        $entry = $this->seedDraftEntry('seed'); // creates type + a draft we overwrite below
        $types = new ContentTypeRepository($this->connection());
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $this->connection()->table('content_types')->where('uuid', '=', $typeUuid)->update([
            'schema_version' => 3,
            'schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'b', 'type' => 'string'],
                ['name' => 'a', 'type' => 'string'],
            ], JSON_THROW_ON_ERROR),
        ]);
        foreach ([
            ['schmigprev01', 1, 2, [['op' => 'rename', 'from' => 'a', 'to' => 'b']]],
            ['schmigprev02', 2, 3, [['op' => 'rename', 'from' => 'c', 'to' => 'a']]],
        ] as [$uuid, $from, $to, $ops]) {
            $this->connection()->table('entry_schema_migrations')->insert([
                'uuid' => $uuid, 'content_type_uuid' => $typeUuid,
                'from_version' => $from, 'to_version' => $to,
                'ops' => json_encode($ops, JSON_THROW_ON_ERROR),
                'status' => 'completed', 'created_at' => '2026-06-01 00:00:00',
            ]);
        }
        // Rewrite the draft as an OLD-schema (v1) draft with both legacy field names.
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $entry)->update([
            'schema_version' => 1,
            'fields' => json_encode(
                ['title' => 'Old draft', 'a' => 'first', 'c' => 'second'],
                JSON_THROW_ON_ERROR,
            ),
        ]);

        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $r = $this->resolver()->resolvePreview($token);
        self::assertSame('content', $r['kind']);
        self::assertSame('first', $r['content']['fields']['b']);  // a→b, applied once
        self::assertSame('second', $r['content']['fields']['a']); // c→a, applied once
        self::assertArrayNotHasKey('c', $r['content']['fields']);
    }
}
```

(If the `app.key` accessor differs from this decode, copy the exact derivation from the
`ResolvesPreviewKey` trait — the test must sign with the same key the reader verifies.)

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/PreviewThemeTest.php
```

Expected: ERRORS — `resolvePreview()` undefined.

- [ ] **Step 3: Extend the contract**

In `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php`: add `preview: bool`
to the `@return` shape docblock of `resolvePath` (after `field: ?string`, with the note
"true only on resolvePreview successes — preview is a content render, not a kind") and
add the method:

```php
    /**
     * Resolve a signed preview token to its draft/pinned-version content, rendered-side.
     * Success is `kind: 'content'` with `preview: true` (same shape — preview is a
     * content render with different headers/context, never a separate kind). Content
     * carries NO `seo` key. EVERY failure (malformed, expired, missing draft/version)
     * is `not_found`. The token is the authorization (public_delivery does not apply).
     */
    public function resolvePreview(string $token): array;
```

- [ ] **Step 4: Implement in `EnginePublicRouteResolver`**

Add imports + ctor deps (both registered/autowirable):

```php
use App\Content\Preview\PreviewNotFoundException;
use App\Content\Preview\PreviewReader;
use App\Content\Preview\PreviewTokenException;
use App\Content\Repositories\EntryRepository;
use Psr\Log\LoggerInterface;
```

Constructor gains (append):

```php
        private readonly PreviewReader $preview,
        private readonly EntryRepository $entries,
        private readonly LoggerInterface $logger,
```

Add `'preview' => false` to every existing return (both `content` returns, `gone`,
`redirect()`, `notFound()`, and the `listing`/`archive` returns). Add the method:

```php
    /**
     * Signed-token preview (preview spec §2): kind 'content' + preview: true — a
     * content render, not a new kind. Fields come from the fail-closed PreviewReader
     * (draft or pinned version, schema-projected); shaping is the LIST shape — no seo
     * (a draft may be routeless; the page is noindex). Token-is-authorization:
     * public_delivery gating deliberately does NOT apply (JSON-door parity). Every
     * failure is not_found; the reason is logged for editor debuggability.
     */
    public function resolvePreview(string $token): array
    {
        try {
            $read = $this->preview->read($token);
        } catch (PreviewTokenException | PreviewNotFoundException $e) {
            $this->logger->info('lemma-render: preview token rejected', [
                'reason' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            return $this->notFound();
        }

        $entry = $this->entries->findEntry($read['entry_uuid']);
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return $this->notFound();
        }
        $typeRow = $this->types->findByUuid((string) $entry['content_type_uuid']);
        if ($typeRow === null) {
            return $this->notFound();
        }
        $typeUuid = (string) $typeRow['uuid'];
        $typeSlug = (string) $typeRow['slug'];
        $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));

        // Synthesize a spine-shaped row from the reader's output; references expand
        // against the PUBLISHED spine (referenced entries appear as the public sees
        // them). schema_version is pinned to the CURRENT type version: PreviewReader
        // already projected the fields forward but reports the ORIGINAL version, and
        // letting shape() see the old number would re-run the migration chain over
        // already-projected fields — unsafe when a rename chain re-uses a name.
        $row = [
            'entry_uuid' => $read['entry_uuid'],
            'locale' => $read['locale'],
            'version' => $read['version'],
            'version_uuid' => $read['version_uuid'],
            'schema_version' => (int) ($typeRow['schema_version'] ?? $read['schema_version']),
            'fields' => $read['fields'],
            'published_at' => null,
        ];
        $selector = FieldSelector::fromRequest(Request::create('/'));
        $shaped = $this->shaper->shape([$row], $schema, $selector, $read['locale'], $typeUuid, null);
        $content = $this->shaper->item($shaped[0]);

        return [
            'kind' => 'content', 'locale' => $read['locale'], 'type' => $typeSlug,
            'content' => $content, 'redirect' => null,
            'listing' => null, 'term' => null, 'term_type' => null, 'field' => null,
            'preview' => true,
        ];
    }
```

- [ ] **Step 5: Run the tests**

```bash
vendor/bin/phpunit tests/Integration/Render/PreviewThemeTest.php
vendor/bin/phpunit tests/Integration/Render/ tests/Integration/Seo/
```

Expected: PASS (the `preview => false` additions must not disturb anything). No staging yet.

---

### Task 2: `/_preview/{token}` route + controller arm + banner

**Files:**
- Modify: `packages/lemma-render/routes/public-routes.php`
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php`
- Modify: `packages/lemma-render/themes/default/templates/layout.twig`
- Test: `tests/Integration/Render/PreviewThemeTest.php`

**Interfaces:**
- Consumes: `resolvePreview` (Task 1); the existing `render()`/template hierarchy.
- Produces: `RenderController::preview(Request $request, string $token): Response` — 200 draft HTML with `preview: true` context + `Cache-Control: no-store` + `X-Robots-Tag: noindex`; failures render `404.twig` FRESH (no `RenderErrorCache`) with the same no-store headers.

- [ ] **Step 1: Write the failing kernel tests**

Add to `PreviewThemeTest.php`:

```php
    public function testPreviewRouteRendersDraftUncachedWithHeadersAndBanner(): void
    {
        $entry = $this->seedDraftEntry('Only in preview');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('Only in preview', $html);
        self::assertStringContainsString('preview-banner', $html); // default-theme banner
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        self::assertSame('noindex', $res->headers->get('X-Robots-Tag'));
        self::assertNull($res->headers->get('Cache-Tag'));         // no-store pages carry no tags

        // Structural cache bypass: NOTHING entered the page cache.
        self::assertSame(
            [],
            $this->container()->get(CacheStore::class)->getKeys('render:*'),
        );
    }

    public function testPreviewFailuresRenderThemed404WithNoStore(): void
    {
        $res = $this->handle(Request::create('/_preview/garbage-token', 'GET'));
        self::assertSame(404, $res->getStatusCode());
        self::assertStringContainsString('text/html', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        // The FIXED 404 body was not consulted or filled (spec §3).
        self::assertNull($this->container()->get(CacheStore::class)->get('render:default:404'));
    }

    public function testVersionPinnedTokenRendersThePinnedFieldsNotTheDraft(): void
    {
        // Publish v1 ("Old words"), then save a NEWER draft ("New draft words"); a token
        // pinned to v1's version uuid must render the pinned content.
        $entry = $this->seedBilingualPublishedEntry(); // published v1: title "Hello"
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entries->saveDraft($entry, 'en', ['title' => 'New draft words'], 1, 1, 'user00000001');
        $versionUuid = (string) $this->connection()->table('entry_versions')
            ->select(['uuid'])->where('entry_uuid', '=', $entry)
            ->where('locale', '=', 'en')->first()['uuid'];

        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', $versionUuid);
        $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('<h1>Hello</h1>', (string) $res->getContent());
        self::assertStringNotContainsString('New draft words', (string) $res->getContent());
    }

    public function testNonPublicTypeDraftPreviewsFine(): void
    {
        // Token-is-authorization (spec §1): flip the type non-public; preview still works.
        $entry = $this->seedDraftEntry('Secret draft');
        $this->connection()->table('content_types')
            ->where('slug', '=', 'blog')->update(['public_delivery' => false]);
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('Secret draft', (string) $res->getContent());
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/PreviewThemeTest.php
```

Expected: the kernel tests FAIL (no `/_preview` route → catch-all → themed 404 without no-store).

- [ ] **Step 3: Route + controller arm + banner**

`packages/lemma-render/routes/public-routes.php` — BEFORE the `GET /` registration:

```php
// Preview-through-theme (preview spec §1): the signed token IS the authorization.
// Deliberately NO RenderPageCache middleware — the cache bypass is structural; a
// preview response can never enter or read the shared page cache. The static first
// segment wins over the '*'-bucket catch-all.
$router->get('/_preview/{token}', [RenderController::class, 'preview']);
```

`RenderController` — add the action (below `page()`):

```php
    /**
     * Preview-through-theme (preview spec §2–§3): a content render with different
     * headers and context — kind 'content' + preview flag, never a separate kind. The
     * fixed-key RenderErrorCache is NOT consulted for failures (a preview 404 renders
     * fresh; it must never serve or fill the shared body). Responses are no-store +
     * noindex and carry no Cache-Tag.
     */
    public function preview(Request $request, string $token): Response
    {
        $result = $this->resolver->resolvePreview($token);

        if ($result['kind'] !== 'content') {
            $response = $this->render('404.twig', $this->defaultLocale(), null, 404, ['preview' => true]);
        } else {
            $entry = $result['content'];
            $locale = (string) $result['locale'];
            $typeSlug = (string) ($result['type'] ?? '');
            $candidate = $typeSlug !== '' ? "entry/{$typeSlug}.twig" : '';
            $template = $candidate !== '' && $this->twig()->getLoader()->exists($candidate)
                ? $candidate
                : 'entry.twig';
            $response = $this->render($template, $locale, $entry, 200, ['preview' => true]);
        }

        $response->headers->remove('Cache-Tag'); // no-store pages carry no surrogate tags
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');
        return $response;
    }
```

`layout.twig` — right after `<body>`'s `<header>` opening content (before the nav):

```twig
  {% if preview|default(false) %}
    <div class="preview-banner">Preview — unpublished content</div>
  {% endif %}
```

(Place it as the first child of `<header>`; escape-by-default already applies.)

- [ ] **Step 4: Run + STAGE** *(grouping 1 — preview backend; commit only when authorized)*

```bash
vendor/bin/phpunit tests/Integration/Render/ tests/Integration/PreviewFlowTest.php
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
git add packages/lemma-contracts/src/Delivery/PublicRouteResolver.php \
        app/Content/Delivery/EnginePublicRouteResolver.php \
        packages/lemma-render/routes/public-routes.php \
        packages/lemma-render/src/Http/Controllers/RenderController.php \
        packages/lemma-render/themes/default/templates/layout.twig \
        tests/Integration/Render/PreviewThemeTest.php
```

Expected: PASS / `PHPCS_EXIT=0` / boundaries OK. STOP — when authorized:

```bash
git commit -m "feat(render): preview-through-theme via /_preview/{token}

resolvePreview on PublicRouteResolver (kind content + preview flag, fail-closed
over PreviewReader, LIST-shaped content without seo); uncached dedicated route
(no RenderPageCache middleware — structural bypass); no-store + noindex; themed
404s render fresh, never via RenderErrorCache; default-theme preview banner."
```

---

### Task 3: `theme_url` + OpenAPI exclusion + SPA action

**Files:**
- Modify: `app/Content/Http/Controllers/PreviewController.php`
- Modify: `app/Content/Http/DTOs/Responses/Preview/PreviewMintData.php`
- Modify: `config/documentation.php`
- Regenerate: `docs/openapi.json`, admin generated API types
- Modify: `admin/src/queries/preview.ts`, `admin/src/pages/content/[type]/[uuid]/components/PublishPanel.vue`
- Test: existing preview controller/flow tests + SPA type-check

**Interfaces:**
- Consumes: `/_preview/{token}` (Task 2); `Glueful\Lemma\Contracts\Capability\CapabilityRegistry::isEnabled('lemma.render')` (false when pack absent OR disabled — exactly the spec's "disabled or unavailable").
- Produces: mint response gains `theme_url: ?string`; SPA "Preview in theme" action.

- [ ] **Step 1: theme_url server-side**

`PreviewController`: add imports `use Glueful\Bootstrap\ApplicationContext;` and
`use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;`; constructor gains
`private readonly ApplicationContext $context,` (autowired). In `mint()`, replace the
success return with:

```php
        // theme_url: the SERVER decides (preview spec §4) — null when lemma.render is
        // disabled or the pack is absent (isEnabled covers both); the JSON preview URL
        // is unaffected either way. The SPA never builds theme URLs.
        $renderEnabled = app($this->context, CapabilityRegistry::class)->isEnabled('lemma.render');

        return Response::success([
            'token' => $token,
            'expires_at' => date('c', $exp),
            'expires_in' => $ttl,
            'theme_url' => $renderEnabled ? '/_preview/' . $token : null,
        ], 'Preview token minted.');
```

`PreviewMintData`: add `public readonly ?string $theme_url,` (last ctor param) and a
docblock line: "`theme_url` is the rendered-site preview path (`/_preview/{token}`), or
null when the render capability is disabled/absent."

Add a test to whichever file covers `mint()` today (`tests/Integration/PreviewFlowTest.php`
has the flow; follow its mint invocation style):

```php
    public function testMintIncludesThemeUrlWhenRenderEnabled(): void
    {
        // Suite runs with lemma.render enabled → theme_url present and token-bound.
        // (Disabled case: bootAppWithConfigOverride('lemma', ['capabilities' =>
        // ['lemma.render' => false]]) + controller-direct — route-latch precedent.)
        // …mint via the file's existing helper, then:
        // self::assertSame('/_preview/' . $body['data']['token'], $body['data']['theme_url']);
    }
```

(Write the real body following the file's existing mint helper — the two assertions
above are the content; add the disabled-case override-boot variant asserting null.)

- [ ] **Step 2: OpenAPI exclusion + regenerate**

`config/documentation.php`: change the exclude default to
`'Admin,Data,Default,Documentation,Health,Security'` and extend the comment:
"`Default` drops untagged HTML routes — today that is exactly the render pack's
catch-all (`GET /`, `GET /{path}`) and `/_preview/{token}`; an UNTAGGED API route is
itself a bug (give it a tag)."

Regenerate + verify:

```bash
php glueful generate:openapi -f --clean
python3 -c "
import json; spec = json.load(open('docs/openapi.json'))
assert '/' not in spec['paths'] and '/{path}' not in spec['paths'], 'render routes still documented'
assert '/_preview/{token}' not in spec['paths'], 'preview route leaked into the spec'
assert not any(p.startswith('/theme-assets') for p in spec['paths']), 'static mounts leaked'
assert any(p.startswith('/v1/content/') for p in spec['paths'])
print('openapi OK,', len(spec['paths']), 'paths')
"
cd admin && pnpm gen:api && pnpm type-check && cd ..
```

Expected: render routes gone, taxonomy endpoints present, admin types include `theme_url`, type-check clean.

- [ ] **Step 3: SPA action**

`admin/src/queries/preview.ts` — extend the mint helper and add a theme mutation:

```ts
export interface PreviewMintResult {
  token: string
  themeUrl: string | null
}

// Mints a preview token; theme_url is server-decided (null = rendered delivery off).
export async function mintPreviewData(uuid: string, locale: string): Promise<PreviewMintResult> {
  const { data, error, response } = await client.POST('/entries/{uuid}/preview/{locale}', {
    params: { path: { uuid, locale } },
  })
  if (error) throw toApiError(error, response)
  return { token: data?.data?.token ?? '', themeUrl: data?.data?.theme_url ?? null }
}

export function useThemePreview(uuid: string, locale: string) {
  return useMutation({
    mutation: () => mintPreviewData(uuid, locale),
  })
}
```

(Keep `mintPreview`/`usePreview`/`buildPreviewUrl` unchanged — the external-frontend
preview path still uses them.)

`PublishPanel.vue` — beside the existing preview handler:

```ts
// ── Preview in theme ─────────────────────────────────────────────────────────
const themePreview = useThemePreview(props.uuid, props.locale)
async function onThemePreview() {
  try {
    const { themeUrl } = await themePreview.mutateAsync()
    if (themeUrl) window.open(themeUrl, '_blank', 'noopener')
    else warning('Theme preview unavailable — rendered delivery is disabled')
  } catch (e) {
    notifyError(e, 'Preview failed')
  }
}
```

Template: add a "Preview in theme" button next to the existing Preview button, same
component/props style as its neighbor (read the template block and mirror the existing
preview button exactly — always visible, `:loading="themePreview.isLoading.value"` if
the neighbor does the equivalent).

```bash
cd admin && pnpm type-check && pnpm test && cd ..
```

Expected: clean. No staging yet — grouped with Tasks 4–5.

---

### Task 4: `FacetCountsReader` + `facets()` + the tag collector

**Files:**
- Create: `packages/lemma-contracts/src/Delivery/FacetCountsReader.php`
- Create: `app/Content/Delivery/EngineFacetCountsReader.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (binding)
- Modify: `packages/lemma-render/src/RenderContextExtension.php` (+provider factory)
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php` (reset/drain/merge)
- Test: `tests/Integration/Render/FacetsTwigTest.php`

**Interfaces:**
- Produces: contract `counts(string $typeSlug, string $field, string $locale, int $limit = 100): array{items: list<array{uuid: string, slug: ?string, count: int}>, cache_tags: list<string>}`; extension methods `resetTags(): void` / `drainTags(): list<string>`; Twig `facets(type, field, limit)` returning items only.

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/Render/FacetsTwigTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Delivery\FacetCountsReader;
use Glueful\Lemma\Render\RenderContextExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * facets() in Twig (preview spec §5): the {items, cache_tags} contract (incl. the
 * valid-empty case), the render-scoped tag collector, and gate fail-safety.
 */
final class FacetsTwigTest extends LemmaTestCase
{
    private const CAT_TYPE_UUID = 'cattypefctw0';
    private string $postType;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function reader(): FacetCountsReader
    {
        return $this->container()->get(FacetCountsReader::class);
    }

    private function seedTermAndMember(): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'ftwterm00001', 'content_type_uuid' => self::CAT_TYPE_UUID, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vftwterm0001', 'entry_uuid' => 'ftwterm00001', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['slug' => 'php'], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'ftwterm00001', 'locale' => 'en', 'version_uuid' => 'vftwterm0001',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        $db->table('entries')->insert([
            'uuid' => 'ftwpost00001', 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vftwpost0001', 'entry_uuid' => 'ftwpost00001', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => 'P', 'category' => ['ftwterm00001']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'ftwpost00001', 'locale' => 'en', 'version_uuid' => 'vftwpost0001',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        $this->container()->get(PublishedReferenceRepository::class)
            ->projectFromPublished('ftwpost00001', $this->postType, 'en');
    }

    public function testReaderReturnsItemsAndTags(): void
    {
        $this->seedTermAndMember();
        $r = $this->reader()->counts('post', 'category', 'en');
        self::assertSame(
            [['uuid' => 'ftwterm00001', 'slug' => 'php', 'count' => 1]],
            $r['items'],
        );
        self::assertSame(['lemma:type:post', 'lemma:type:category'], $r['cache_tags']);
    }

    public function testValidEmptyFacetStillCarriesTags(): void
    {
        // No members at all — items empty, but the tags MUST be present so a page
        // showing this facet purges when the first matching entry publishes (review P1).
        $r = $this->reader()->counts('post', 'category', 'en');
        self::assertSame([], $r['items']);
        self::assertSame(['lemma:type:post', 'lemma:type:category'], $r['cache_tags']);
    }

    public function testGateFailuresReturnEmptyItemsAndEmptyTags(): void
    {
        self::assertSame(
            ['items' => [], 'cache_tags' => []],
            $this->reader()->counts('post', 'title', 'en'),   // not a reference field
        );
        self::assertSame(
            ['items' => [], 'cache_tags' => []],
            $this->reader()->counts('nope', 'category', 'en'), // unknown type
        );
        $this->connection()->table('content_types')
            ->where('uuid', '=', self::CAT_TYPE_UUID)->update(['public_delivery' => false]);
        self::assertSame(
            ['items' => [], 'cache_tags' => []],
            $this->reader()->counts('post', 'category', 'en'), // non-visible target
        );
    }

    public function testTwigFacetsCollectsTagsAndCollectorScopesPerRender(): void
    {
        $this->seedTermAndMember();
        $extension = $this->container()->get(RenderContextExtension::class);
        $twig = new Environment(new ArrayLoader([
            'ok.twig' => '{% for f in facets("post", "category") %}{{ f.slug }}:{{ f.count }}{% endfor %}',
            'boom.twig' => '{{ facets("post", "category")|length }}{{ undefined_fn() }}',
        ]));
        $twig->addExtension($extension);
        $extension->setLocale('en');

        // Successful render: items in output, tags in the collector.
        $extension->resetTags();
        self::assertSame('php:1', $twig->render('ok.twig'));
        self::assertSame(['lemma:type:post', 'lemma:type:category'], $extension->drainTags());
        self::assertSame([], $extension->drainTags()); // drain clears

        // A failing render must not leak tags into the NEXT render (review pin):
        // the controller resets BEFORE every render, so the next reset wipes whatever
        // the exploded render collected.
        $extension->resetTags();
        try {
            $twig->render('boom.twig');
            self::fail('boom.twig should have thrown');
        } catch (\Throwable) {
        }
        $extension->resetTags(); // the next render's reset
        self::assertSame([], $extension->drainTags());
    }
}
```

- [ ] **Step 2: Run to verify they fail**

```bash
vendor/bin/phpunit tests/Integration/Render/FacetsTwigTest.php
```

Expected: ERRORS — `FacetCountsReader` not found.

- [ ] **Step 3: Contract + core reader + binding**

Create `packages/lemma-contracts/src/Delivery/FacetCountsReader.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Facet counts for rendered templates (preview spec §5). The result CARRIES ITS OWN
 * surrogate cache tags: consumers (the render pack) cannot derive the term type's tag
 * from a bare counts list, and a VALID facet with zero counts must still tag the page
 * (it changes when the first matching entry publishes). Gate failures (unknown type,
 * non-filterable field, non-visible type on either side) return {[], []} — never throw.
 */
interface FacetCountsReader
{
    /**
     * @return array{items: list<array{uuid: string, slug: ?string, count: int}>,
     *               cache_tags: list<string>}
     */
    public function counts(string $typeSlug, string $field, string $locale, int $limit = 100): array;
}
```

Create `app/Content/Delivery/EngineFacetCountsReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Schema\ContentTypeSchema;
use Glueful\Lemma\Contracts\Delivery\FacetCountsReader;

/**
 * Template-facing facet counts over the published-reference projection, with the SAME
 * gates as the facets endpoint (anonymous visibility on source AND target, filterable
 * reference field, limit clamped) — but the fail posture differs by consumer: the
 * endpoint 404s fail-closed; a TEMPLATE gets {[], []} and renders nothing.
 */
final class EngineFacetCountsReader implements FacetCountsReader
{
    private const LIMIT_MAX = 500;

    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly PublishedReferenceRepository $projection,
    ) {
    }

    public function counts(string $typeSlug, string $field, string $locale, int $limit = 100): array
    {
        $none = ['items' => [], 'cache_tags' => []];

        $typeRow = $this->types->findBySlug($typeSlug);
        if ($typeRow === null || !$this->visible($typeRow)) {
            return $none;
        }
        $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));
        $fieldDef = $schema->field($field);
        if ($fieldDef === null || $fieldDef->type !== 'reference' || !$fieldDef->filterable) {
            return $none;
        }
        $targetRow = $this->types->findBySlug((string) ($fieldDef->referenceType ?? ''));
        if ($targetRow === null || !$this->visible($targetRow)) {
            return $none;
        }

        $items = $this->projection->facetCounts(
            (string) $typeRow['uuid'],
            $fieldDef,
            (string) $targetRow['uuid'],
            $locale,
            max(1, min($limit, self::LIMIT_MAX)),
        );

        return [
            'items' => $items,
            // Valid facet — even with zero counts — tags the page (review P1).
            'cache_tags' => [
                'lemma:type:' . (string) $typeRow['slug'],
                'lemma:type:' . (string) $targetRow['slug'],
            ],
        ];
    }

    /** @param array<string,mixed> $typeRow */
    private function visible(array $typeRow): bool
    {
        return DeliveryVisibility::isAccessible(
            (bool) ($typeRow['public_delivery'] ?? false),
            (string) ($typeRow['slug'] ?? ''),
            null, // templates are an anonymous surface
        );
    }
}
```

`LemmaServiceProvider`: import `App\Content\Delivery\EngineFacetCountsReader` +
`Glueful\Lemma\Contracts\Delivery\FacetCountsReader`; register next to the
`PublicRouteResolver` binding:

```php
            FacetCountsReader::class => [
                'class' => EngineFacetCountsReader::class,
                'shared' => true,
                'autowire' => true,
            ],
```

- [ ] **Step 4: Extension + collector + controller merge**

`RenderContextExtension`: constructor gains `private readonly ?FacetCountsReader $facetReader = null,`
(import the contract; nullable like `MenuReader` — no reader means `facets()` returns `[]`;
named `$facetReader`, not `$facets`, so the property never reads ambiguously against the
`facets()` method).
Add a `private array $collectedTags = [];` property, register
`new TwigFunction('facets', $this->facets(...))` in `getFunctions()`, and add:

```php
    /**
     * Facet counts for templates (preview spec §5): returns ITEMS to Twig; the result's
     * cache_tags go into the render-scoped collector so the controller can merge them
     * into the page's Cache-Tag. No reader bound (or any gate failing) → [] — a
     * template never explodes over facets.
     *
     * @return list<array{uuid: string, slug: ?string, count: int}>
     */
    public function facets(string $type, string $field, int $limit = 100): array
    {
        if ($this->facetReader === null) {
            return [];
        }
        $result = $this->facetReader->counts($type, $field, $this->locale, $limit);
        $this->collectTags($result['cache_tags']);
        return $result['items'];
    }

    /** @param list<string> $tags */
    private function collectTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->collectedTags[$tag] = $tag;
        }
    }

    /** Reset the render-scoped collector — the controller calls this BEFORE every render. */
    public function resetTags(): void
    {
        $this->collectedTags = [];
    }

    /** @return list<string> drained (and cleared) tags collected during the render */
    public function drainTags(): array
    {
        $tags = array_values($this->collectedTags);
        $this->collectedTags = [];
        return $tags;
    }
```

(PHP permits a `$facets` property beside a `facets()` method, but the `$facetReader`
name keeps the code unambiguous; the Twig function name stays `facets`.)

`makeRenderContextExtension` in the render provider: pass the reader softly —
`$container->has(FacetCountsReader::class) ? $container->get(FacetCountsReader::class) : null`
(import the contract with a `use` statement).

`RenderController::render()`: FIRST line of the method body: `$this->extension->resetTags();`
(before building context — reset-before-every-render is the leak guard). After a
successful `$html = $this->twig()->render(...)`, build the response then merge:

```php
        $response = new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        $this->mergeCacheTags($response, $this->extension->drainTags());
        return $response;
```

Add the helper + convert the two existing `Cache-Tag` writers to MERGE:

```php
    /**
     * Append-unique Cache-Tag merge: drained facet tags (set at render time) and the
     * caller-side taggers (tagResponse/tagCollection) must compose, so nobody may
     * blind-set the header.
     *
     * @param list<string> $tags
     */
    private function mergeCacheTags(Response $response, array $tags): void
    {
        if ($tags === []) {
            return;
        }
        $existing = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $response->headers->get('Cache-Tag', '')),
        )));
        $response->headers->set('Cache-Tag', implode(', ', array_values(array_unique([...$existing, ...$tags]))));
    }
```

In `tagResponse()` replace `$response->headers->set('Cache-Tag', …)` with
`$this->mergeCacheTags($response, ["lemma:entry:{$uuid}", "lemma:type:{$typeSlug}"])`;
in `tagCollection()` replace the final `set()` with `$this->mergeCacheTags($response, $tags)`.

- [ ] **Step 5: Run the tests**

```bash
vendor/bin/phpunit tests/Integration/Render/ 
```

Expected: PASS — FacetsTwigTest plus the whole render suite (the merge refactor must
not change any existing Cache-Tag assertion; they assert containment, not exact order).

---

### Task 5: Docs + full verification

**Files:**
- Modify: `packages/lemma-render/README.md`, `CHANGELOG.md`, `docs/V2_DESIGN.md`, `docs/NEXT.md`

- [ ] **Step 1: README** — add after the Listing & archive section:

```markdown
## Preview in the theme

`GET /_preview/{token}` renders a draft (or pinned version) through the active theme —
the signed token from `POST /v1/admin/entries/{uuid}/preview/{locale}` is the only
credential (its response's `theme_url` carries this path; `null` = capability off).
Responses are `Cache-Control: no-store` + `X-Robots-Tag: noindex`, never enter the
page cache, and carry a `preview` flag templates can read (the default theme shows a
banner). Preview content has NO `entry.seo` object. All token failures render the
themed 404.

## Facet counts in templates

`facets('post', 'category', limit = 100)` returns `[{uuid, slug, count}, …]` (count
DESC) for filterable reference fields — `[]` on any gate failure, so templates never
break. Pages using it are automatically tagged with both type tags, so counts purge
event-driven like everything else.
```

- [ ] **Step 2: CHANGELOG `[Unreleased]` (prepend under `### Added`)**

```markdown
- **Preview-through-theme**: `GET /_preview/{token}` renders drafts/pinned versions
  through the active Twig theme (structurally uncached dedicated route; `no-store` +
  `noindex`; fail-closed themed 404s; `preview` template flag + default-theme banner).
  `PublicRouteResolver` gained `resolvePreview()` (kind `content` + `preview: true`
  flag); the mint response gained a server-decided `theme_url` (`null` when rendered
  delivery is off) and the admin editor a "Preview in theme" action.
- **`facets()` in Twig** over the new `FacetCountsReader` contract (`{items,
  cache_tags}` — a valid empty facet still tags the page); a render-scoped tag
  collector merges facet tags into `Cache-Tag`, so facet sidebars purge event-driven.
- **OpenAPI**: the render pack's HTML routes (`GET /`, `GET /{path}`, `/_preview/…`)
  are excluded from the generated spec (`Default` joined the tag deny-list).
```

- [ ] **Step 3: Tracker flips** — `docs/V2_DESIGN.md` §6: `- ✅ preview-through-theme —
**shipped 2026-07-02** (docs/superpowers/specs/2026-07-02-preview-through-theme-design.md)`;
`docs/NEXT.md`: note it shipped in the rendered-delivery sequencing item, same style as
the earlier flips. Also flip the render README "Out of scope" line (remove
preview-through-theme; keep the rest).

- [ ] **Step 4: Full verification + STAGE** *(grouping 2; commit only when authorized)*

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Integration
cd admin && pnpm type-check && pnpm test && cd ..
git add packages/lemma-contracts/src/Delivery/FacetCountsReader.php \
        app/Content/Delivery/EngineFacetCountsReader.php \
        app/Content/Http/Controllers/PreviewController.php \
        app/Content/Http/DTOs/Responses/Preview/PreviewMintData.php \
        app/Providers/LemmaServiceProvider.php \
        config/documentation.php docs/openapi.json \
        packages/lemma-render admin/src \
        tests/Integration/Render/FacetsTwigTest.php tests/Integration/PreviewFlowTest.php \
        CHANGELOG.md docs/V2_DESIGN.md docs/NEXT.md
```

Expected: all green (same pre-existing single Integration skip). STOP — when authorized:

```bash
git commit -m "feat(render): theme_url + Preview-in-theme SPA action, facets() in Twig, OpenAPI HTML-route exclusion

FacetCountsReader contract returns {items, cache_tags} (valid-empty facets still
tag); render-scoped tag collector merges into Cache-Tag (reset-before-render,
drain-on-success); mint response carries server-decided theme_url; Default tag
joined the OpenAPI deny-list and the spec regenerated."
```

---

## Self-Review Notes (already applied)

- **Spec coverage:** §1 route/no-middleware/token-authorization → Task 2 (+ non-public-type test); §2 kind-content+flag / no-seo / fail-closed+logged → Task 1 (tests assert the flag, the missing seo key, all three failure classes, AND the single-projection guarantee — `testOldSchemaPreviewProjectsExactlyOnce` seeds a real rename chain that re-uses a name, the case double projection clobbers); §3 headers/banner/no-RenderErrorCache → Task 2 (fixed-404-body-not-filled assertion); §4 theme_url pinned nulls + always-shown-mint-on-click SPA → Task 3; §5 `{items, cache_tags}` incl. valid-empty + collector reset/drain-finally semantics → Task 4 (the leak test models the controller's reset-before-every-render discipline; the drain sits on the success path and the reset guards the exception path — functionally the finally the spec asks for); §6 exclusion via the verified `Default`-tag mechanism → Task 3; §7 tests all mapped except a kernel-level facets Cache-Tag merge test — the merge is exercised by every existing render kernel test path (tagResponse/tagCollection now flow through `mergeCacheTags`) and the collector by FacetsTwigTest; a themed facets kernel test needs an app-override theme (basePath `themes/` dir + override boot, controller-direct) — add during execution if the reviewer wants the end-to-end belt-and-braces.
- **Type consistency:** `preview` key present on every resolver return (Task 1 instructs all sites incl. listing/archive); `resolvePreview` synthesized-row keys match what `shape()`/`item()` read (`entry_uuid`, `locale`, `schema_version`, `fields`, `version`, `version_uuid`, `published_at`); extension ctor param named `$facetReader` for clarity beside the `facets()` method (code blocks consistent); `mergeCacheTags` replaces BOTH existing `set('Cache-Tag')` sites so drained tags survive caller-side tagging.
- **Judgement calls, stated:** preview strips `Cache-Tag` after render (no-store — the drain still ran, tags discarded); the PreviewFlowTest theme_url test sketch names the file's own helper rather than inventing one (implementer follows the existing mint invocation); `pnpm gen:api` regeneration ordering (config exclusion BEFORE regenerate, once).
