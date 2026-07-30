# SEO Head Partial Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps
> use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entry pages render a complete SEO head (description, canonical, hreflang, OG,
conditional twitter/robots) composed from existing thallo-seo data, per
`docs/superpowers/specs/2026-07-26-seo-head-partial-design.md` (approved).

**Architecture:** One new contract (`SeoHeadResolver`) + one new contracts event
(`SeoMetaChanged`); the app composes pack `SeoMetaResolver` + app `CanonicalProjector` +
`CanonicalPublicOriginResolver` behind the contract (`EngineSeoHeadProvider`); render
soft-binds the contract, threads a `seo` context variable, and a `needs_context`
`seo_head()` Twig function emits the tags. A dedicated listener purges local + edge
caches on every SEO override update.

**Tech Stack:** PHP 8.4, Twig, PHPUnit integration tests (AppTestCase), no new deps, no
migrations, no SPA changes.

## Global Constraints

- **No new SEO storage** — everything composes existing data.
- **Pack boundaries**: render imports ONLY `Thallo\Contracts\*`; thallo-seo imports no
  `App\*`; the engine provider lives in the app.
- Contract: `SeoHeadResolver::headFor(string $entryUuid, string $locale): ?array` — the
  wire shape in spec §2 (title always the FINAL effective value; `og.title` = explicit
  `og_title` override else the effective title).
- Effective-title precedence: override → mapped `title_field` → entry `title` field →
  site name, via the PACK resolver's `$map['title_field'] ?? 'title'` default.
- Absolute URLs from `CanonicalPublicOriginResolver::currentOrigin()` ONLY; blank/failed
  origin ⇒ omit canonical/hreflang/og:url (never relative). Homepage entry (per
  `HomepageEntryProvider`) ⇒ canonical/og:url `= origin + '/'`, alternates + x_default
  empty, `og.type = 'website'`; all other entries `og.type = 'article'`.
- Emission rules (spec §3): `twitter:card` ONLY when explicitly overridden; `robots`
  meta ONLY when not plain `'index'`; each tag only when its value exists; everything
  ENT_QUOTES-escaped.
- Preview: `seo_head()` emits exactly `<meta name="robots" content="noindex, nofollow">`
  and nothing else.
- `TemplatePolicy`: `seo_head` joins FUNCTIONS, `CACHE_VERSION` 12 → 13, same change.
- Cache purge: `SeoMetaChanged` after EVERY successful `update` (clears included);
  `SeoMetaChangedListener` drops internal tag `thallo:entry:{uuid}` AND edge-purges the
  same tag with the NullEdgeCache disabled-skip; NO type-level tags.
- Tests: `set -o pipefail && vendor/bin/phpunit <paths> 2>&1 | tail -5` (never grep);
  phpcs PSR12 on touched PHP; commit per task on `dev`; never push; no AI attribution;
  nothing under docs/ or CLAUDE.md staged.

---

### Task 1: Pack resolver title default

**Files:**
- Modify: `packages/thallo-seo/src/Meta/SeoMetaResolver.php` (~line 53 in `resolve()`)
- Test: `tests/Integration/Seo/SeoMetaEndpointTest.php` (extend)

**Interfaces:**
- Produces: unmapped types now derive title from the entry's `title` field (template
  applied) before falling to the site name — Task 4's provider relies on this.

- [ ] **Step 1: Write the failing tests.** In `SeoMetaEndpointTest` (read its fixture
  helpers first and reuse them — it already creates types/entries and hits the meta
  endpoint):

```php
public function testUnmappedTypeDerivesTitleFromTheTitleFieldNotBareSiteName(): void
{
    // A type with NO seo.fallbacks entry whose entry has fields.title = 'Hello Post'
    // (build with the class's existing type/entry fixtures; publish it).
    // GET the meta endpoint for it and assert:
    //   title === strtr(title_template, ['{title}' => 'Hello Post', '{site_name}' => <site>])
    // — NOT the bare site name.
}

public function testUnmappedTypeWithoutTitleFieldStillFallsToSiteName(): void
{
    // Same, but the entry has no 'title' field at all → title === site name (unchanged).
}

public function testMappedTitleFieldStillWinsOverTheTitleConvention(): void
{
    // A type WITH fallbacks.title_field = 'headline' and fields carrying BOTH
    // headline + title → headline (templated) wins.
}
```

- [ ] **Step 2: Run to verify the first fails** (bare site name today):
`set -o pipefail && vendor/bin/phpunit tests/Integration/Seo/SeoMetaEndpointTest.php 2>&1 | tail -5`

- [ ] **Step 3: The one-line change.** In `SeoMetaResolver::resolve()`:

```php
        $fieldTitle = $this->fieldString($fields, $map['title_field'] ?? 'title');
```

(was `?? null`). Update the class docblock's precedence sentence: "per-entry override →
per-type fallback field → the conventional `title` field → site default (theme-runtime
SEO head spec §2: this default also fixes the unmapped-type regression on this
endpoint)."

- [ ] **Step 4: Run the Seo suite:**
`set -o pipefail && vendor/bin/phpunit tests/Integration/Seo 2>&1 | tail -5` — green
(if an existing test pinned the OLD bare-site-name behavior for unmapped types, update
it to the corrected expectation with a comment citing spec §2).

- [ ] **Step 5: phpcs + commit**

```bash
vendor/bin/phpcs --standard=PSR12 packages/thallo-seo/src/Meta/SeoMetaResolver.php tests/Integration/Seo/SeoMetaEndpointTest.php
git add packages/thallo-seo/src/Meta/SeoMetaResolver.php tests/Integration/Seo/SeoMetaEndpointTest.php
git commit -m "fix(seo): unmapped types derive meta title from the conventional title field"
```

---

### Task 2: Contracts — `SeoHeadResolver` + `SeoMetaChanged`

**Files:**
- Create: `packages/thallo-contracts/src/Delivery/SeoHeadResolver.php`
- Create: `packages/thallo-contracts/src/Seo/SeoMetaChanged.php`
- Check: `packages/thallo-contracts/src/ContractsManifest.php` — read it; if it
  enumerates contract classes, add both entries in its existing style.

**Interfaces:**
- Produces: `SeoHeadResolver::headFor(string $entryUuid, string $locale): ?array` (wire
  shape below, consumed by Tasks 4–5); `SeoMetaChanged` (`public readonly string
  $entryUuid`, `public readonly string $locale`), extends `BaseEvent` (consumed by
  Task 3).

- [ ] **Step 1: Write both files.**

```php
<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Composed SEO head data for one published entry variant (seo-head spec §2). The
 * engine implementation derives type/slug itself — callers supply only the identity
 * every render site holds (the same pair the page cache tags with).
 */
interface SeoHeadResolver
{
    /**
     * @return array{
     *   title: string,
     *   description: ?string,
     *   canonical: ?string,
     *   alternates: list<array{locale: string, href: string}>,
     *   x_default: ?string,
     *   og: array{title: string, description: ?string, image: ?string, url: ?string, type: string},
     *   twitter_card: ?string,
     *   robots: string,
     * }|null null when the entry is not published (or not routed) in this locale.
     */
    public function headFor(string $entryUuid, string $locale): ?array;
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Contracts\Seo;

use Glueful\Events\Contracts\BaseEvent;

/**
 * An entry's seo_meta override changed (upsert, including an empty-values clear).
 * Cross-pack seam (the MenuUpdated precedent): thallo-seo dispatches it; the app's
 * SeoMetaChangedListener purges the entry's cached rendered pages locally and at the
 * edge (seo-head spec §5).
 */
final class SeoMetaChanged extends BaseEvent
{
    public function __construct(
        public readonly string $entryUuid,
        public readonly string $locale,
    ) {
        parent::__construct();
    }
}
```

- [ ] **Step 2: Commit** (contracts are exercised by Tasks 3–5's tests; no standalone
  test — they are pure declarations):

```bash
vendor/bin/phpcs --standard=PSR12 packages/thallo-contracts/src/Delivery/SeoHeadResolver.php packages/thallo-contracts/src/Seo/SeoMetaChanged.php
git add packages/thallo-contracts
git commit -m "feat(contracts): SeoHeadResolver seam + SeoMetaChanged event"
```

---

### Task 3: Dispatch + `SeoMetaChangedListener` (local + edge purge)

**Files:**
- Modify: `packages/thallo-seo/src/Http/Controllers/AdminSeoMetaController.php`
- Create: `app/Content/Pipeline/Listeners/SeoMetaChangedListener.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (`registerEventListeners()` map +
  the listener's service registration — mirror how `PurgeCdnListener` is registered)
- Test: extend the test class covering seo admin meta (find it:
  `grep -rln "AdminSeoMetaController\|seo_meta" tests/Integration/Seo`) + a purge test
  in the same class

**Interfaces:**
- Consumes: `SeoMetaChanged` (Task 2).
- Produces: on every successful admin upsert, internal tag `thallo:entry:{uuid}` is
  invalidated and the edge cache purges the same tag.

- [ ] **Step 1: Failing tests.**

```php
public function testUpsertDispatchesSeoMetaChangedAndPurgesEntryCaches(): void
{
    // Substitute a RecordingEdgeCache (the CapabilityGatingTest singleton-substitution
    // idiom) and prime the page-cache tag store with a probe entry under
    // 'thallo:entry:{uuid}' (write any value via CacheStore::tags/put per
    // InvalidateCacheTagsListener's API — read that listener first).
    // POST the admin upsert endpoint (or call the controller with a request the way the
    // class's existing update tests do) with {title: 'New'} —
    //   assert the probe cache entry is GONE (invalidateTags ran for the entry tag),
    //   assert the recording edge cache saw purgeByTag('thallo:entry:{uuid}') exactly once,
    //   and no type-level tag was purged.
}

public function testEmptyValuesClearStillDispatches(): void
{
    // Upsert with all-empty fields (the clear path) → same two purge assertions.
}

public function testLeanInstallSkipsEdgePurgeCleanly(): void
{
    // No substitution: NullEdgeCache bound. Upsert must succeed with no exception
    // (disabled-skip), and the internal tag invalidation still happens.
}
```

- [ ] **Step 2: Verify failing**, then implement.

`AdminSeoMetaController` — constructor gains
`private readonly ?\Glueful\Events\EventService $events = null` (nullable keeps the
pack constructible in isolation); `update()` after the upsert:

```php
        $this->repo->upsert($entryUuid, $dto->locale, $dto->fields);
        // Every successful upsert (a clear included) invalidates the entry's rendered
        // pages — local and edge — via the app's SeoMetaChangedListener (seo-head spec §5).
        $this->events?->dispatch(new SeoMetaChanged($entryUuid, $dto->locale));
        return Response::success($this->repo->find($entryUuid, $dto->locale));
```

with `use Thallo\Contracts\Seo\SeoMetaChanged;`.

`SeoMetaChangedListener` (mirror the two content listeners' service access — container
+ `CacheStore`, `EdgeCacheInterface`):

```php
<?php

declare(strict_types=1);

namespace App\Content\Pipeline\Listeners;

use Glueful\Cache\Contracts\EdgeCacheInterface;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Seo\SeoMetaChanged;

/**
 * SEO override changed → the entry's cached rendered pages are stale in BOTH cache
 * layers (seo-head spec §5). The existing content listeners read content-event shapes
 * and would ignore SeoMetaChanged, so this dedicated listener does both halves itself:
 * drops the internal page-cache tag and edge-purges the same surrogate tag with
 * PurgeCdnListener's exact disabled-skip discipline. Deliberately NO type-level tags —
 * a meta edit changes one entry's pages only.
 */
final class SeoMetaChangedListener
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof SeoMetaChanged) {
            return;
        }
        $tag = 'thallo:entry:' . $event->entryUuid;

        $this->cache()->invalidateTags([$tag]);

        /** @var EdgeCacheInterface $edge */
        $edge = $this->container->get(EdgeCacheInterface::class);
        if ($edge->isEnabled()) {
            $edge->purgeByTag($tag);
        }
    }

    private function cache(): \Glueful\Cache\CacheStore
    {
        /** @var \Glueful\Cache\CacheStore $store */
        return $this->container->get(\Glueful\Cache\CacheStore::class);
    }
}
```

(Read `InvalidateCacheTagsListener` first: reuse ITS exact `CacheStore` import/FQCN and
`invalidateTags()` call form; the sketch's FQCNs follow it but the file's imports win.)

Wiring in `registerEventListeners()`'s `$listeners` map:

```php
            \Thallo\Contracts\Seo\SeoMetaChanged::class => [
                SeoMetaChangedListener::class,
            ],
```

plus the listener's service registration and `use` import in the provider's style.

- [ ] **Step 3: Run + commit**

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Seo tests/Integration/Pipeline 2>&1 | tail -4`

```bash
git add packages/thallo-seo app/Content/Pipeline/Listeners/SeoMetaChangedListener.php app/Providers/ThalloServiceProvider.php tests/Integration/Seo
git commit -m "feat(seo): SeoMetaChanged dispatch + local/edge purge listener"
```

---

### Task 4: `EngineSeoHeadProvider`

**Files:**
- Create: `app/Content/Seo/EngineSeoHeadProvider.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (bind
  `SeoHeadResolver::class => ['factory' => [self::class, 'makeSeoHeadProvider'], 'shared' => true]`
  + the factory method, matching the provider's factory idiom)
- Test: `tests/Integration/Seo/SeoHeadProviderTest.php` (new)

**Interfaces:**
- Consumes: `SeoMetaResolver` (pack, Task 1 semantics), `CanonicalProjector`,
  `CanonicalPublicOriginResolver`, `HomepageEntryProvider`, `RouteRepository`
  (`forEntry(entryUuid)` rows carry `content_type_uuid`/`locale`/`slug`),
  `ContentTypeRepository::findByUuid`.
- Produces: the bound `SeoHeadResolver` returning the spec-§2 wire shape (Task 5
  consumes it).

- [ ] **Step 1: Failing tests** — build published entries with the class fixtures other
  Seo tests use (read `SeoMetaEndpointTest` + `CanonicalProjectorTest` first, reuse
  their type/entry/route helpers):

```php
public function testComposesFullHeadWithAbsoluteUrls(): void
{
    // Published, routed entry with a seo_meta override description; configured origin
    // (set the origin the CanonicalPublicOriginResolver reads — read its engine impl
    // to learn the config/tenant source and use the same fixture the media-url tests
    // use). Assert: title per Task-1 precedence; canonical === origin . <projector
    // canonical href>; every alternates[].href absolute; og.url === canonical;
    // og.type === 'article'; twitter_card null (no override); robots 'index'.
}

public function testBlankOriginOmitsUrlBearingKeysButKeepsText(): void
{
    // Origin fixture blank → canonical/x_default/og.url null, alternates [],
    // description + title intact.
}

public function testRelativeDefaultOgImageIsAbsolutized(): void
{
    // seo.defaults.default_og_image '/media/x.png' + origin set → og.image absolute;
    // an absolute (http…) og_image override passes through untouched.
}

public function testHomepageEntryGetsRootCanonicalAtBothIdentities(): void
{
    // Make the fixture entry the homepage (write the homepage_entry setting the way
    // GeneralSettings homepage tests do). headFor(entryUuid, locale) →
    // canonical === origin . '/', og.url same, alternates [], x_default null,
    // og.type === 'website'.
}

public function testUnroutedOrUnpublishedReturnsNull(): void
{
    // (a) entry with no route row for the locale → null;
    // (b) routed but unpublished variant → null.
}

public function testExplicitOgTitleOverrideWins(): void
{
    // Override og_title 'Social!' → og.title 'Social!' while title stays the
    // effective page title.
}
```

- [ ] **Step 2: Verify failing, implement.**

```php
<?php

declare(strict_types=1);

namespace App\Content\Seo;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\RouteRepository;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Contracts\Delivery\HomepageEntryProvider;
use Thallo\Contracts\Delivery\SeoHeadResolver;
use Thallo\Seo\Meta\SeoMetaResolver;

/**
 * The SeoHeadResolver engine implementation (seo-head spec §2): composes the pack's
 * SeoMetaResolver (title/description/og/robots) + CanonicalProjector (canonical/
 * hreflang) + the trusted-origin resolver into the head wire shape. Derives type and
 * slug itself from the entry's route rows — callers supply entryUuid + locale only.
 */
final class EngineSeoHeadProvider implements SeoHeadResolver
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SeoMetaResolver $meta,
        private readonly CanonicalProjector $canonical,
        private readonly CanonicalPublicOriginResolver $origins,
        private readonly HomepageEntryProvider $homepage,
        private readonly RouteRepository $routes,
        private readonly ContentTypeRepository $types,
    ) {
    }

    public function headFor(string $entryUuid, string $locale): ?array
    {
        // Identity derivation: the route row for THIS locale carries type + slug.
        $route = null;
        foreach ($this->routes->forEntry($entryUuid) as $row) {
            if ((string) $row['locale'] === $locale) {
                $route = $row;
                break;
            }
        }
        if ($route === null) {
            return null; // unrouted variants are never independently rendered
        }
        $typeUuid = (string) $route['content_type_uuid'];
        $slug = (string) $route['slug'];
        $type = $this->types->findByUuid($typeUuid);
        if ($type === null) {
            return null;
        }
        $typeSlug = (string) $type['slug'];

        $meta = $this->meta->resolve($typeUuid, $typeSlug, $slug, $locale);
        if ($meta === null) {
            return null; // not published in this locale
        }

        $origin = $this->safeOrigin();
        $isHomepage = $this->homepage->homepageEntry() === $entryUuid;

        if ($isHomepage) {
            $canonical = $origin !== null ? $origin . '/' : null;
            $alternates = [];
            $xDefault = null;
        } else {
            $projected = $this->canonical->project($entryUuid, $typeUuid, $typeSlug, $locale);
            $canonical = $this->absolute($origin, $projected['canonical']['href'] ?? null);
            $alternates = [];
            foreach ($projected['alternates'] as $alt) {
                $href = $this->absolute($origin, (string) $alt['href']);
                if ($href !== null) {
                    $alternates[] = ['locale' => (string) $alt['locale'], 'href' => $href];
                }
            }
            $xDefault = $this->absolute($origin, $projected['x_default']['href'] ?? null);
        }

        $image = is_string($meta['og']['image'] ?? null) ? $meta['og']['image'] : null;
        if ($image !== null && str_starts_with($image, '/')) {
            $image = $origin !== null ? $origin . $image : null;
        }

        return [
            'title' => (string) $meta['title'],
            'description' => $meta['description'],
            'canonical' => $canonical,
            'alternates' => $alternates,
            'x_default' => $xDefault,
            'og' => [
                'title' => (string) $meta['og']['title'],
                'description' => $meta['og']['description'],
                'image' => $image,
                'url' => $canonical,
                'type' => $isHomepage ? 'website' : 'article',
            ],
            'twitter_card' => is_string($meta['twitter']['card'] ?? null) ? $meta['twitter']['card'] : null,
            'robots' => (string) $meta['robots'],
        ];
    }

    private function safeOrigin(): ?string
    {
        try {
            $origin = rtrim($this->origins->currentOrigin($this->context), '/');
        } catch (\Throwable) {
            return null;
        }
        return $origin === '' ? null : $origin;
    }

    private function absolute(?string $origin, ?string $path): ?string
    {
        if ($origin === null || $path === null || $path === '') {
            return null;
        }
        return $origin . $path;
    }
}
```

(Verify each repo method's exact return shape while implementing — `forEntry` row keys
per `CanonicalProjector::slugFor`, `findByUuid` array per its other callers; adjust
casts, never the algorithm. The `CanonicalProjector` import is `App\Content\Seo\` —
same namespace, no `use` needed.)

Factory in `ThalloServiceProvider` (match its factory idiom):

```php
    public static function makeSeoHeadProvider(ContainerInterface $container): \App\Content\Seo\EngineSeoHeadProvider
    {
        return new \App\Content\Seo\EngineSeoHeadProvider(
            $container->get(ApplicationContext::class),
            $container->get(\Thallo\Seo\Meta\SeoMetaResolver::class),
            $container->get(\App\Content\Seo\CanonicalProjector::class),
            $container->get(\Thallo\Contracts\Delivery\CanonicalPublicOriginResolver::class),
            $container->get(\Thallo\Contracts\Delivery\HomepageEntryProvider::class),
            $container->get(\App\Content\Repositories\RouteRepository::class),
            $container->get(\App\Content\Repositories\ContentTypeRepository::class),
        );
    }
```

(with `use` imports per the provider's convention — short names, per the house rule.)

- [ ] **Step 3: Run + commit**

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Seo/SeoHeadProviderTest.php tests/Integration/Seo 2>&1 | tail -4`

```bash
git add app/Content/Seo/EngineSeoHeadProvider.php app/Providers/ThalloServiceProvider.php tests/Integration/Seo/SeoHeadProviderTest.php
git commit -m "feat(seo): EngineSeoHeadProvider - composed head behind the contract"
```

---

### Task 5: Render integration — `seo` context + `seo_head()` + templates

**Files:**
- Modify: `packages/thallo-render/src/Http/Controllers/RenderController.php` — ONE
  insertion point: every entry page funnels through `renderEntry(array $result, …)`
  (line ~589; `$entry = $result['content']`, `$locale = $result['locale']`, and the
  method already computes the entry uuid for its `thallo:entry:{uuid}` cache tags).
  Add `'seo' => $this->seoHead(<the method's own uuid expression>, $locale)` to the
  `$extra` it forwards into the shared `render()` (line ~761) — use the SAME uuid
  expression the cache-tag lines in that method already use, never a new derivation.
  Homepage renders flow through `renderEntry` too — no second site. Also modify the
  constructor (nullable soft-bind param) and
  `RenderServiceProvider::makeRenderController` (see Step 2).
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (`getFunctions()` +
  new `seoHead()` method)
- Modify: `packages/thallo-render/src/Templates/TemplatePolicy.php` (FUNCTIONS +
  CACHE_VERSION 12 → 13, bump comment)
- Modify: `packages/thallo-render/themes/default/templates/layout.twig` (title line +
  `{{ seo_head() }}`)
- Modify: `packages/thallo-render/themes/default/templates/entry.twig` (title block)
- Test: `tests/Integration/Render/SeoHeadRenderTest.php` (new)

**Interfaces:**
- Consumes: the bound `SeoHeadResolver` (Task 4), soft-bound:
  `$this->container->has(SeoHeadResolver::class)` — absent ⇒ `seo` is null.
- Produces: template variable `seo` (the wire array or null); Twig `seo_head()`.

- [ ] **Step 1: Failing tests.** Use the render pipeline harness other Render tests use
  (`RenderPipelineTest` idiom — publish an entry, GET its page through the real kernel):

```php
public function testEntryPageRendersTheFullHead(): void
{
    // Entry with description override + configured origin. Page HTML contains:
    //   <meta name="description" content="...">, <link rel="canonical" href="http...">,
    //   hreflang alternates, og:title/og:description/og:url/og:type=article,
    //   og:site_name. And does NOT contain name="twitter:card" or name="robots".
}

public function testTitleValuesAreEscaped(): void
{
    // Override title: A "quoted" <title> & Co →
    // rendered og:title content attribute carries &quot; and &lt; entities, never raw.
}

public function testTitlePrecedenceOnThePage(): void
{
    // <title> equals the resolver title (template applied); with an override title,
    // the override verbatim.
}

public function testNonEntryPagesEmitNoSeoTags(): void
{
    // The listing page (/{type}) contains none of: rel="canonical", property="og:,
    // name="description" — and its <title> is unchanged ("{type} — {site}").
}

public function testPreviewEmitsOnlyNoindex(): void
{
    // A preview render (reuse PreviewSessionTest's preview boot idiom) contains
    // <meta name="robots" content="noindex, nofollow"> and does NOT contain
    // rel="canonical" or property="og:.
}

public function testUnsafeUrlsAreOmittedNeverEmitted(): void
{
    // Call the extension's seoHead() directly with a context whose seo array carries
    // canonical: 'javascript:alert(1)' and og.image: 'data:text/html,x' —
    // the output contains NO rel="canonical" and NO og:image line at all
    // (safe_url discipline: omit, never emit raw), while og:title still renders.
}

public function testPolicyAllowsSeoHeadAndBumpedVersion(): void
{
    self::assertContains('seo_head', TemplatePolicy::FUNCTIONS);
    self::assertGreaterThanOrEqual(13, TemplatePolicy::CACHE_VERSION);
}
```

- [ ] **Step 2: Verify failing, implement.**

`RenderController` has NO container property — it is factory-built
(`RenderServiceProvider::makeRenderController`) with a long list of nullable
soft-bound constructor params (`?HomepageEntryProvider $homepage = null` is the exact
precedent). Follow it:

- Constructor gains `private readonly ?SeoHeadResolver $seoHeadResolver = null,`
  (placed with the other nullable soft-binds; import
  `Thallo\Contracts\Delivery\SeoHeadResolver`).
- `makeRenderController` passes
  `$container->has(SeoHeadResolver::class) ? $container->get(SeoHeadResolver::class) : null`
  in the same style the factory passes its other optional services (read the factory
  and match its exact form).
- Private helper:

```php
    /** @return array<string,mixed>|null */
    private function seoHead(string $entryUuid, string $locale): ?array
    {
        // Soft-bound (seo-head spec §3): absent wiring degrades to no head tags.
        return $this->seoHeadResolver?->headFor($entryUuid, $locale);
    }
```

Thread `'seo' => …` in `renderEntry()` ONLY (per Files above). Non-entry contexts
(listing/terms/archive/404/error) never gain the key — templates see `seo` undefined ⇒
the `default()` filters cover it.

`RenderContextExtension::getFunctions()` gains:

```php
            new TwigFunction('seo_head', $this->seoHead(...), [
                'is_safe' => ['html'],
                'needs_context' => true,
            ]),
```

and the method (mirror `colorModeScript()`'s Markup-returning style):

```php
    /**
     * The SEO head tag block (seo-head spec §3): consumes the `seo` CONTEXT variable
     * (one source of truth) + the preview state. Preview emits ONLY noindex —
     * draft titles must never be canonicalized or socially scrapeable (spec §4).
     *
     * @param array<string,mixed> $context
     */
    public function seoHead(array $context): string
    {
        if ($this->isPreview()) {
            return '<meta name="robots" content="noindex, nofollow">';
        }
        $seo = $context['seo'] ?? null;
        if (!is_array($seo)) {
            return '';
        }
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        // Spec §3 URL safety: every URL attribute passes the SAME discipline the
        // safe_url template filter enforces — this class's own safeUrl(). A URL that
        // fails it is OMITTED, never emitted raw.
        $u = fn (mixed $v): ?string => $this->safeUrl($v);
        $lines = [];
        if (is_string($seo['description'] ?? null) && $seo['description'] !== '') {
            $lines[] = '<meta name="description" content="' . $e($seo['description']) . '">';
        }
        if (($canonical = $u($seo['canonical'] ?? null)) !== null) {
            $lines[] = '<link rel="canonical" href="' . $e($canonical) . '">';
        }
        foreach ((array) ($seo['alternates'] ?? []) as $alt) {
            $href = $u($alt['href'] ?? null);
            if ($href !== null && is_string($alt['locale'] ?? null)) {
                $lines[] = '<link rel="alternate" hreflang="' . $e($alt['locale']) . '" href="' . $e($href) . '">';
            }
        }
        if (($xDefault = $u($seo['x_default'] ?? null)) !== null) {
            $lines[] = '<link rel="alternate" hreflang="x-default" href="' . $e($xDefault) . '">';
        }
        $og = (array) ($seo['og'] ?? []);
        $lines[] = '<meta property="og:type" content="' . $e($og['type'] ?? 'article') . '">';
        $lines[] = '<meta property="og:title" content="' . $e($og['title'] ?? ($seo['title'] ?? '')) . '">';
        if (is_string($og['description'] ?? null) && $og['description'] !== '') {
            $lines[] = '<meta property="og:description" content="' . $e($og['description']) . '">';
        }
        if (($image = $u($og['image'] ?? null)) !== null) {
            $lines[] = '<meta property="og:image" content="' . $e($image) . '">';
        }
        if (($ogUrl = $u($og['url'] ?? null)) !== null) {
            $lines[] = '<meta property="og:url" content="' . $e($ogUrl) . '">';
        }
        // og:site_name from the SAME source the templates use — the render context's
        // site.name (needs_context makes it available; no parallel state).
        $siteName = is_array($context['site'] ?? null) ? (string) ($context['site']['name'] ?? '') : '';
        if ($siteName !== '') {
            $lines[] = '<meta property="og:site_name" content="' . $e($siteName) . '">';
        }
        if (is_string($seo['twitter_card'] ?? null)) {
            $lines[] = '<meta name="twitter:card" content="' . $e($seo['twitter_card']) . '">';
        }
        if (($seo['robots'] ?? 'index') !== 'index') {
            $lines[] = '<meta name="robots" content="' . $e($seo['robots']) . '">';
        }
        return implode("\n  ", $lines);
    }
```

(`safeUrl()` is this class's OWN public method at ~line 544 — the `safe_url` filter's
implementation, scheme-allowlisted http/https/mailto + root-relative; reuse it
directly. `isPreview()` stands for the class's existing preview-state accessor — the
same one the `is_preview` Twig function uses at its `getFunctions()` registration; do
not invent new state.)

`TemplatePolicy`: add `'seo_head'` to FUNCTIONS; `CACHE_VERSION = 13` with
`// bumped: seo_head joined the function allowlist (seo-head spec §3)`.

`layout.twig`:

```twig
  <title>{% block title %}{{ seo.title|default(site.name) }}{% endblock %}</title>
  {# SEO head (seo-head spec §3): composed entry metadata; empty on non-entry pages;
     noindex-only in preview (spec §4). #}
  {{ seo_head() }}
```

`entry.twig` title block:

```twig
{% block title %}{{ seo.title|default(entry.fields.title|default(site.name)) }}{% endblock %}
```

- [ ] **Step 3: Run Render + Content + fix title-assertion fallout.** Existing tests
  asserting `<title>` values (grep `-rn "<title>" tests/Integration/Render`) may pin the
  pre-template form — update them to the templated title with a comment citing spec §2.

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Render tests/Integration/Content 2>&1 | tail -4`

- [ ] **Step 4: phpcs + commit**

```bash
vendor/bin/phpcs --standard=PSR12 packages/thallo-render/src tests/Integration/Render/SeoHeadRenderTest.php
git add packages/thallo-render tests/Integration/Render
git commit -m "feat(render): SEO head partial - seo context, seo_head(), noindex preview"
```

---

### Task 6: Full gates + CHANGELOG

**Files:**
- Modify: `CHANGELOG.md` (`[Unreleased]` → `### Added`)

- [ ] **Step 1: Full gates.**

Run: `set -o pipefail && vendor/bin/phpunit 2>&1 | tail -4` — full suite green.
Run: `set -o pipefail && composer phpcs 2>&1 | tail -3` — clean.
(No SPA changes — admin gates unaffected; run them only if something touched admin/.)

- [ ] **Step 2: CHANGELOG bullet** (top of the existing `### Added`): SEO head partial —
  rendered entry pages gain description/canonical/hreflang/OG (+conditional
  twitter/robots) composed from thallo-seo data behind the new `SeoHeadResolver`
  contract; homepage canonicalizes to `/`; previews emit noindex only; SEO override
  edits purge rendered pages locally and at the edge via `SeoMetaChanged`; the seo meta
  endpoint's unmapped-type titles now derive from the conventional `title` field.

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: changelog for the SEO head partial"
```

---

## Execution notes

- Task order: 1 and 2 are independent; 3 needs 2; 4 needs 1+2; 5 needs 4; 6 last.
- Every "read X first and reuse its form" instruction is binding — fixture helpers,
  container access, preview state, and repo row shapes must come from the real files,
  never from this plan's sketches, wherever the two disagree on FORM (the ALGORITHM and
  the pinned outcomes are the spec's and may not be weakened).
- No migrations, no OpenAPI regeneration (no route or wire-shape changes to admin APIs),
  no dev-DB steps.
