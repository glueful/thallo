# lemma-render Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `glueful/lemma-render` capability pack — Twig SSR of published content at clean public URLs through a lowest-priority catch-all into the new `PublicRouteResolver` contract — per `docs/superpowers/specs/2026-07-02-lemma-render-core-design.md` (parent: `docs/V2_DESIGN.md`).

**Architecture:** One ordinary `*`-bucket route (`GET /{path}` where `.+`) plus `GET /` feeds raw paths to `PublicRouteResolver` (core wraps path parsing + `RouteResolver` + delivery shaping + canonicals); the pack owns themes (pack-embedded default + app `themes/`), the Twig environment, context functions (`menu`/`path`/`asset`), and the reserved-prefix JSON-404 guard. Uncached SSR; page caching is sub-project 3.

**Tech Stack:** PHP 8.3 / Glueful, `twig/twig ^3` (pack dependency), Postgres test harness, PHPUnit 10. No SPA work in this sub-project.

## Global Constraints

- The spec is the contract: `docs/superpowers/specs/2026-07-02-lemma-render-core-design.md`. Re-read it (and V2_DESIGN §2–§4) before starting. Every pinned behavior there is a requirement.
- Pack namespace `Glueful\Lemma\Render\`; deps `glueful/lemma-contracts` + `glueful/framework` + `twig/twig ^3` ONLY. Never the app engine namespace (the literal sequence `App\`) anywhere in `packages/lemma-render/src/` — including comments (boundary regex catches doc text).
- NO `enabled` config key; capability `lemma.render` via the switchboard. NO migrations, NO permissions in this pack (no admin surface, no tables).
- PHP: `use` imports, phpcs 120 cols, `declare(strict_types=1)`.
- Harness facts: no migration-path registration needed (no migrations); rebuild the extension cache after provider changes (`php glueful extensions:cache`); the dev app's compiled route cache may need clearing when routes change (`rm -f storage/cache/routes_*.php` — tests boot fresh).
- Commits: on `dev`, batched at the 3 marked commit points. No AI attribution trailers.
- Responses from render are raw `Symfony\Component\HttpFoundation\Response` HTML (SitemapController precedent) — never the JSON envelope, EXCEPT the reserved-path guard which returns the framework's standard JSON 404.

## File Map

| Area | Files |
|---|---|
| Contract | `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php` |
| Core | `app/Content/Delivery/DeliveryItemShaper.php` (extracted shaping), `app/Content/Delivery/EnginePublicRouteResolver.php`, `app/Content/Http/Controllers/DeliveryController.php` (delegate to shaper), `app/Providers/LemmaServiceProvider.php` (bindings) |
| Pack | `packages/lemma-render/{composer.json,README.md,routes/public-routes.php,src/*,themes/default/*}` |
| Pack src | `LemmaRenderServiceProvider.php`, `ThemeLocator.php`, `TwigFactory.php`, `RenderContextExtension.php`, `ReservedPaths.php`, `HomepageConfigError.php`, `Http/Controllers/RenderController.php`, `config/lemma-render.php` |
| Default theme | `packages/lemma-render/themes/default/{theme.json,templates/{layout,index,entry,404,error}.twig,assets/site.css}` |
| App wiring | root `composer.json` (path repo + require), `config/extensions.php` |
| Tests | `tests/Integration/Render/{PublicRouteResolverTest,RenderCapabilityTest,ThemeLadderTest,RenderContextTest,RenderPipelineTest,RenderRemovabilityTest}.php` |

---

### Task 1: `PublicRouteResolver` contract + core implementation (shaping extraction)

**Files:**
- Create: `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php`
- Create: `app/Content/Delivery/DeliveryItemShaper.php`
- Create: `app/Content/Delivery/EnginePublicRouteResolver.php`
- Modify: `app/Content/Http/Controllers/DeliveryController.php` (its private `shape()`/`item()` + the `$item['seo']` stamping move to the shaper; controller delegates — read those privates FIRST, they are the authoritative shaping semantics)
- Modify: `app/Providers/LemmaServiceProvider.php` (bind contract + shaper)
- Test: `tests/Integration/Render/PublicRouteResolverTest.php`

**Interfaces:**
- Produces: `PublicRouteResolver::resolvePath(string $path): array{kind: 'content'|'redirect'|'gone'|'not_found', locale: ?string, content: ?array, redirect: ?array{location: string, status: int}}` and `resolveEntry(string $entryUuid, ?string $locale = null): array` (same shape); `DeliveryItemShaper::shapePublic(array $row, string $typeUuid, string $typeSlug): array` — the FULL public item (no field selection, anonymous scopes) with `seo` stamped via `CanonicalProjector`, byte-identical to the delivery API's unselected item shape.

- [ ] **Step 1: The contract** — `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Core answers "what does this public path serve?" for the render pack. `content` is the
 * PUBLIC DELIVERY SHAPE for one published entry — `seo` included — already
 * visibility-filtered and route-resolved by core; consumers treat it as READ-ONLY
 * template context (no mutation, no re-normalization). Normalization differences
 * (trailing slash, duplicate slashes) are returned as 301 redirects BEFORE any lookup,
 * so content resolution only ever sees canonical paths.
 */
interface PublicRouteResolver
{
    /**
     * @return array{kind: 'content'|'redirect'|'gone'|'not_found', locale: ?string,
     *   content: ?array, redirect: ?array{location: string, status: int}}
     */
    public function resolvePath(string $path): array;

    /** Same result shape, for a known entry (homepage; previews later). */
    public function resolveEntry(string $entryUuid, ?string $locale = null): array;
}
```

- [ ] **Step 2: Failing tests** — `tests/Integration/Render/PublicRouteResolverTest.php`. Full test methods (harness idioms as in `EntryTargetResolverTest`; `SeedsPublishedContent` gives blog/hello en + blog/bonjour fr):

```php
public function testPublishedPathResolvesToDeliveryShapedContent(): void
{
    $entry = $this->seedBilingualPublishedEntry();
    $r = $this->resolver()->resolvePath('/blog/hello');       // default-locale variant
    self::assertSame('content', $r['kind']);
    self::assertSame('en', $r['locale']);
    self::assertSame($entry, $r['content']['uuid'] ?? $r['content']['entry_uuid']);
    self::assertArrayHasKey('seo', $r['content']);            // stamped like the API
    self::assertSame('Hello', $r['content']['fields']['title']);
}

public function testLocaleVariantPath(): void
{
    $this->seedBilingualPublishedEntry();
    $r = $this->resolver()->resolvePath('/fr/blog/bonjour');
    self::assertSame('content', $r['kind']);
    self::assertSame('fr', $r['locale']);
}

public function testNormalizationRedirectsComeBeforeLookup(): void
{
    $this->seedBilingualPublishedEntry();
    foreach (['/blog//hello' => '/blog/hello', '/blog/hello/' => '/blog/hello'] as $raw => $canonical) {
        $r = $this->resolver()->resolvePath($raw);
        self::assertSame('redirect', $r['kind'], $raw);
        self::assertSame(['location' => $canonical, 'status' => 301], $r['redirect']);
    }
}

public function testArityAndLocaleEdges(): void
{
    $this->seedBilingualPublishedEntry();
    self::assertSame('not_found', $this->resolver()->resolvePath('/en/blog')['kind']);
    self::assertSame('not_found', $this->resolver()->resolvePath('/only-one')['kind']);
    self::assertSame('not_found', $this->resolver()->resolvePath('/a/b/c/d')['kind']);
    // "/blog/hello" is type blog + default locale — NEVER locale "blog" (spec §3).
}

public function testResolveEntryForHomepage(): void
{
    $entry = $this->seedBilingualPublishedEntry();
    $r = $this->resolver()->resolveEntry($entry);
    self::assertSame('content', $r['kind']);
    self::assertArrayHasKey('seo', $r['content']);
    self::assertSame('not_found', $this->resolver()->resolveEntry('nope00000000')['kind']);
}

public function testNonPublicTypeIsNotFoundEvenWithARoute(): void
{
    // seedPublishedEntryInType('secret-doc', false, 'en', 'classified', 'Classified')
    // (the Seo concern) creates a NON-public type with a live route. The resolver must
    // return not_found — render is anonymous; a route existing is not enough.
    $this->seedPublishedEntryInType('secret-doc', false, 'en', 'classified', 'Classified');
    self::assertSame('not_found', $this->resolver()->resolvePath('/secret-doc/classified')['kind']);
}

// testDeliveryRedirectAndGoneFlowThrough: DO NOT write this test from this skeleton.
// HARD ORDER: first read App\Content\Seo\RedirectRepository +
// tests/Integration/Seo/RedirectRepositoryTest.php (seeding shapes) +
// DeliveryController::redirectResponse() (descriptor keys) — THEN author the test using
// the real keys: seed blog/old → blog/hello, assert kind 'redirect' with the
// repository's real status field; seed a gone row, assert kind 'gone'. The guessed keys
// ('location'/'target'/'status') in the Step 4 skeleton must be corrected to the real
// ones at the same time.
```

(`resolver()` = `$this->container()->get(\Glueful\Lemma\Contracts\Delivery\PublicRouteResolver::class)`. The last test's seeding calls follow `tests/Integration/Seo/RedirectRepositoryTest.php` — read it first; keep the assertions as written.)

- [ ] **Step 3: Extract `DeliveryItemShaper`** — move `DeliveryController`'s private `shape()` + `item()` bodies (verbatim semantics) into `app/Content/Delivery/DeliveryItemShaper.php` with two entry points: the controller's request-aware path keeps working through it, and:

```php
    /**
     * The FULL public item for one published row — no field selection, anonymous scopes —
     * with `seo` stamped exactly as the delivery API stamps it. This is the render-facing
     * shape; it MUST stay byte-identical to an unselected delivery API item.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function shapePublic(array $row, string $typeUuid, string $typeSlug): array
```

`shapePublic` builds the schema via `ContentTypeSchema::fromArray` (type row lookup inside, or take the schema as a param — mirror whichever the moved code needs), shapes with an all-fields selector and empty scopes, then stamps `$item['seo'] = $this->canonical->project($row['entry_uuid'], $typeUuid, $typeSlug, $row['locale'])`. `DeliveryController` is updated to call the shaper (its response bytes must not change — the full delivery test suite is the regression harness).

- [ ] **Step 4: `EnginePublicRouteResolver`** — `app/Content/Delivery/EnginePublicRouteResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Localization\ContentLocaleService;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Seo\RouteResolver;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Lemma\Contracts\Delivery\PublicRouteResolver;

use function config;

/**
 * Path → published content, wrapping the existing RouteResolver. Owns raw-path parsing
 * (the inverse of PathRenderer's /{locale}/{type}/{slug} template) and NORMALIZATION-
 * FIRST canonical redirects (spec §3): /blog//hello 301s before any parsing or lookup.
 */
final class EnginePublicRouteResolver implements PublicRouteResolver
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ContentTypeRepository $types,
        private readonly RouteResolver $routes,
        private readonly ContentLocaleService $locales,
        private readonly DeliveryItemShaper $shaper,
    ) {
    }

    public function resolvePath(string $path): array
    {
        $normalized = $this->normalize($path);
        if ($normalized !== ($path === '' ? '/' : $path)) {
            return $this->redirect($normalized, 301);
        }

        $segments = array_map(rawurldecode(...), array_values(array_filter(
            explode('/', trim($normalized, '/')),
            static fn(string $s): bool => $s !== '',
        )));

        // 3 segments with an ACTIVE locale first → locale variant; 2 → default locale;
        // anything else → not_found. "/blog/hello" is type blog, never locale blog.
        $default = (string) config($this->context, 'i18n.default_locale', 'en');
        if (count($segments) === 3 && $this->isActiveLocale($segments[0])) {
            [$locale, $typeSlug, $slug] = $segments;
        } elseif (count($segments) === 2) {
            [$typeSlug, $slug] = $segments;
            $locale = $default;
        } else {
            return $this->notFound();
        }

        $typeRow = $this->types->findBySlug($typeSlug);
        if ($typeRow === null) {
            return $this->notFound();
        }
        // VISIBILITY (spec §3): render is an ANONYMOUS surface — enforce the same
        // public-only rule as anonymous delivery. A route existing is not enough; a
        // non-public-delivery type resolves not_found here exactly as the delivery API
        // would refuse it. Read how DeliveryController/DeliveryVisibility gates
        // anonymous requests and apply the SAME check (do not invent a parallel rule).
        if (!$this->isPubliclyDeliverable($typeRow)) {
            return $this->notFound();
        }
        $typeUuid = (string) $typeRow['uuid'];

        $chain = $this->localeChain($locale);
        $result = $this->routes->resolve($typeUuid, $typeSlug, $chain, $slug);
        if ($result === null) {
            return $this->notFound();
        }
        if ($result->isGone()) {
            return ['kind' => 'gone', 'locale' => $locale, 'content' => null, 'redirect' => null];
        }
        if ($result->isRedirect()) {
            $descriptor = $result->redirect();
            return $this->redirect(
                (string) ($descriptor['location'] ?? $descriptor['target'] ?? '/'),
                (int) ($descriptor['status'] ?? 301),
            );
        }
        $row = $result->content();
        return [
            'kind' => 'content',
            'locale' => (string) $row['locale'],
            'content' => $this->shaper->shapePublic($row, $typeUuid, $typeSlug),
            'redirect' => null,
        ];
    }

    /**
     * PINNED behavior (spec §3/§4 — the homepage depends on this):
     *   - $locale null → the DEFAULT locale chain (config i18n.default_locale)
     *   - missing / deleted / unpublished / ROUTELESS entry → not_found (a published
     *     entry with no route is NOT content here — same rule as EntryTargetResolver)
     *   - non-public-delivery content type → not_found (visibility, see resolvePath)
     *   - otherwise → kind content with the shaped public delivery row
     */
    public function resolveEntry(string $entryUuid, ?string $locale = null): array
    {
        // Implementation: entries row (missing/deleted checks exactly like
        // EngineEntryTargetResolver), type row by content_type_uuid + public-delivery
        // check, route row for the locale (absent → not_found), then
        // RouteResolver::resolve($typeUuid, $typeSlug, $chain, $entryUuid) — it accepts
        // uuids — and shape the content result. Redirect/gone from a uuid resolution
        // are treated as not_found (a homepage must point at live content directly).
    }

    private function normalize(string $path): string
    {
        $collapsed = preg_replace('#/{2,}#', '/', '/' . trim($path)) ?? $path;
        $trimmed = rtrim($collapsed, '/');
        return $trimmed === '' ? '/' : $trimmed;
    }
    // redirect()/notFound()/isActiveLocale()/localeChain() helpers — localeChain mirrors
    // DeliveryController::localeChain (requested first + ContentLocaleService::fallbackChain,
    // deduped); isActiveLocale asks ContentLocaleService for active codes (read its API).
}
```

(The `resolveEntry` body and the four small helpers are written at implementation time against the authoritative neighbors named in the comments; the shapes above are the contract. Redirect descriptor keys: read `DeliveryController::redirectResponse()` to use the real key names.)

- [ ] **Step 5: Bind** — `LemmaServiceProvider`: `DeliveryItemShaper` (autowire shared) and `PublicRouteResolver::class => EnginePublicRouteResolver` (autowire shared), next to the `EntryTargetResolver` binding.

- [ ] **Step 6: Verify** — new tests PASS; **full suite green** (the DeliveryController refactor must not change any delivery test); phpcs clean. (Commit lands with Task 2.)

---

### Task 2: Pack skeleton + wiring (COMMIT 1)

**Files:**
- Create: `packages/lemma-render/composer.json`, `packages/lemma-render/config/lemma-render.php`, `packages/lemma-render/src/LemmaRenderServiceProvider.php`
- Modify: root `composer.json` (path repo + require, alphabetical: between navigation and search), `config/extensions.php` (append provider)
- Test: `tests/Integration/Render/RenderCapabilityTest.php`

**Interfaces:**
- Produces: capability `lemma.render`; config tree `lemma_render.*` per spec §1 (theme/homepage_entry/site_name/reserved_prefixes/reserved_exact — copy the spec table verbatim into the config file with the env keys `RENDER_THEME`, `RENDER_HOMEPAGE_ENTRY`, `RENDER_SITE_NAME`).

- [ ] Steps mirror the lemma-navigation Task 2 exactly (capability test asserting `lemma.render` enabled; composer.json copied from lemma-navigation's with name/description/psr-4/provider swapped AND `"twig/twig": "^3.0"` added to require; provider skeleton with `register()` merging `lemma_render` config and `boot()` registering the capability — description: `'Server-rendered pages from published content via filesystem Twig themes.'`; NO `loadMigrationsFrom`). Wire, `composer update glueful/lemma-render` (verify twig lands in vendor), `extensions:cache` (expect 16 providers), test green.
- [ ] **COMMIT 1:**

```bash
git add packages/lemma-contracts/src/Delivery/PublicRouteResolver.php \
  app/Content/Delivery/ app/Content/Http/Controllers/DeliveryController.php \
  app/Providers/LemmaServiceProvider.php packages/lemma-render/ composer.json composer.lock \
  config/extensions.php tests/Integration/Render/ \
  docs/superpowers/specs/2026-07-02-lemma-render-core-design.md \
  docs/superpowers/plans/2026-07-02-lemma-render-core.md
git commit -m "lemma-render: PublicRouteResolver contract, core resolver, pack skeleton

- lemma-contracts: PublicRouteResolver (path + entry resolution returning the
  read-only public delivery shape; normalization 301s BEFORE any lookup).
- Core: DeliveryItemShaper extracted from DeliveryController (byte-identical
  delivery responses, regression-covered by the existing suite) and
  EnginePublicRouteResolver (route-template inverse parsing, locale chain,
  redirect/gone passthrough, canonical normalization).
- glueful/lemma-render pack skeleton: capability lemma.render, lemma_render
  config (theme/homepage/site_name/reserved paths), twig/twig ^3 pack dep."
```

---

### Task 3: Themes — locator, Twig factory, default reference theme

**Files:**
- Create: `packages/lemma-render/src/ThemeLocator.php`, `src/TwigFactory.php`
- Create: `packages/lemma-render/themes/default/theme.json`, `templates/layout.twig`, `templates/index.twig`, `templates/entry.twig`, `templates/404.twig`, `templates/error.twig`, `assets/site.css`
- Modify: provider (register both services)
- Test: `tests/Integration/Render/ThemeLadderTest.php`

**Interfaces:**
- Produces: `ThemeLocator::activePaths(): array{templates: list<string>, assets: string, name: string}` — implements the spec §4 ladder: app theme dir `base_path('themes/{name}')` when present (invalid `theme.json` → throw `ThemeConfigError extends \RuntimeException`); pack default dir appended as fallback loader path; missing/invalid pack default → `\RuntimeException` (hard 500). `TwigFactory::environment(): \Twig\Environment` — `FilesystemLoader($paths['templates'])`, `autoescape: 'html'`, `cache: base_path('storage/cache/twig/' . $name)`, `auto_reload: true`, plus the Task 4 extension.

- [ ] **Step 1: Failing ladder tests** — app-theme-missing → default paths + (assert a log or just the fallback path order); invalid `theme.json` in a temp app theme dir (write a temp `themes/broken/theme.json` with junk inside the test, config-override the theme name via the `bootAppWithConfigOverride` helper or direct `ThemeLocator` construction with an explicit base path — direct construction is simpler and precedented by the seo empty-origin test) → `ThemeConfigError`; per-template fallback proven in Task 5's pipeline tests.
- [ ] **Step 2: Default theme files.** `theme.json`: `{"name": "default", "version": "1.0.0", "menus": ["main"]}`. Templates (complete, minimal, real):

`layout.twig`:

```twig
<!DOCTYPE html>
<html lang="{{ site.locale }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{% block title %}{{ site.name }}{% endblock %}</title>
  <link rel="stylesheet" href="{{ asset('site.css') }}">
</head>
<body>
  <header>
    <a href="/" class="site-name">{{ site.name }}</a>
    <nav>
      {% for item in menu('main') %}
        <a href="{{ item.url }}">{{ item.label }}</a>
      {% endfor %}
    </nav>
  </header>
  <main>{% block content %}{% endblock %}</main>
  <footer><small>{{ site.name }}</small></footer>
</body>
</html>
```

`entry.twig`:

```twig
{% extends 'layout.twig' %}
{% block title %}{{ entry.fields.title ?? site.name }}{% endblock %}
{% block content %}
  <article>
    <h1>{{ entry.fields.title }}</h1>
    {# ESCAPED on purpose: the reference theme cannot know that a field named "body"
       is sanitized rich text rather than arbitrary text. Theme authors who know their
       schema opt into |raw knowingly for their rich-text fields. #}
    {% if entry.fields.body is defined %}<div class="body">{{ entry.fields.body }}</div>{% endif %}
  </article>
{% endblock %}
```

`index.twig`:

```twig
{% extends 'layout.twig' %}
{% block content %}
  {% if entry is defined and entry %}
    <article>
      <h1>{{ entry.fields.title }}</h1>
      {% if entry.fields.body is defined %}<div class="body">{{ entry.fields.body }}</div>{% endif %}
    </article>
  {% else %}
    <h1>{{ site.name }}</h1>
    <p>This site is powered by Lemma. Create a theme in themes/ to make it yours.</p>
  {% endif %}
{% endblock %}
```

`404.twig`:

```twig
{% extends 'layout.twig' %}
{% block title %}Not found — {{ site.name }}{% endblock %}
{% block content %}<h1>Page not found</h1><p>The page you requested does not exist.</p>{% endblock %}
```

`error.twig`:

```twig
{% extends 'layout.twig' %}
{% block title %}Error — {{ site.name }}{% endblock %}
{% block content %}<h1>Something went wrong</h1><p>Please try again later.</p>{% endblock %}
```

`assets/site.css`: a small readable reset + typographic defaults (~30 lines; body max-width, font stack, header/footer spacing — write real CSS, not a placeholder comment).

(NOTE: the reference theme escapes EVERYTHING — it cannot know a field named `body` is
sanitized rich text. The theme README documents `|raw` as an explicit opt-in for theme
authors who know their schema's rich-text fields.)

- [ ] **Step 3: Implement locator + factory, register, tests green, phpcs.**

---

### Task 4: Context functions — `menu()`, `path()`, `asset()`

**Files:**
- Create: `packages/lemma-render/src/RenderContextExtension.php`
- Test: `tests/Integration/Render/RenderContextTest.php`

**Interfaces:**
- Consumes: `MenuReader` (nullable — resolved via `$container->has()`), `EntryTargetResolver`.
- Produces: a `\Twig\Extension\AbstractExtension` with three `TwigFunction`s. `menu(slug)` → `MenuReader::menu(slug, $currentLocale) ?? []`, and `[]` when no reader bound (render must not hard-depend on navigation); the current locale is provided per-render (the extension holds a locale that `RenderController` sets per request — or the function reads it from a runtime service; pick a per-render context object, NOT global state). `path(entryUuid)` → `EntryTargetResolver` path (null unless published). `asset(rel)` → reject absolute URLs (`preg_match('#^[a-z][a-z0-9+.-]*://#i')`), `..` segments, leading `/`, and backslashes with a `\Twig\Error\RuntimeError` naming the offending value; return `/theme-assets/{rel}`.

- [ ] Failing tests (unit-style over the extension with a real Twig env from Task 3's factory + fake/real readers): menu returns navigation data when the pack has a menu; `[]` with no reader; `[]` when navigation capability disabled (real container); path null for draft entries and a live path for published; asset accepts `css/site.css` → `/theme-assets/css/site.css` and throws on `../x`, `/x`, `https://x`, `a\\b`. Then implement, register in TwigFactory, green, phpcs.

---

### Task 5: RenderController + routes + pipeline (COMMIT 2)

**Files:**
- Create: `packages/lemma-render/src/ReservedPaths.php`, `src/HomepageConfigError.php`, `src/Http/Controllers/RenderController.php`, `packages/lemma-render/routes/public-routes.php`
- Modify: provider (`loadRoutesFrom` inside `isEnabled`, `serveFrontend('/theme-assets', $assetsDir)` when enabled, controller/service registrations)
- Test: `tests/Integration/Render/RenderPipelineTest.php`

**Interfaces:**
- Consumes: everything above by the exact signatures produced.
- Produces routes `GET /` and `GET /{path}` (`where('path', '.+')`), both only when `lemma.render` is enabled.

- [ ] **Step 1: Failing pipeline tests** (drive via `$this->handle(Request::create(...))` — the real kernel, since bucket order IS the subject):

```php
public function testPublishedEntryRendersHtml(): void        // 200, text/html, contains 'Hello', <nav> from a real lemma-navigation menu
public function testTypeTemplateBeatsGenericEntry(): void    // app theme override dir via config: entry/blog.twig wins
public function testNormalizationRedirect(): void            // GET /blog//hello → 301 Location /blog/hello
public function testThemed404(): void                        // GET /no/such-page → 404, text/html, 'Page not found'
public function testReservedPathsReturnStandardJson404(): void
// GET /v1/nonexistent and GET /sitemap.xml-ish reserved-exact: assert status 404,
// content-type application/json, and body keys/message equal to a disabled-render boot's
// response for the same path (compare success/message/error.code — request_id/timestamp
// naturally differ). /sitemap-history (NOT reserved) must render the themed 404.
public function testHomepageStandaloneAndEntryModes(): void  // config override boots (bootAppWithConfigOverride('lemma_render', [...]))
public function testBadHomepageEntryIs500(): void            // homepage_entry = missing uuid → 500; body generic (debug off in tests? assert 500 + not themed 404)
public function testHeadRequestServesGetHeaders(): void
```

- [ ] **Step 2: `ReservedPaths`** — small value class: `isReserved(string $path): bool` implementing path-SEGMENT prefix semantics (`v1` reserves `/v1` and `/v1/...`, not `/v1abc`) + exact matches, from the two config lists.
- [ ] **Step 3: `RenderController`** — `home(Request)`: homepage per spec §4 (`index.twig` always; `homepage_entry` set → `resolveEntry`, non-content → throw `HomepageConfigError("lemma_render.homepage_entry resolves to {status}")` caught in the controller → log via `LoggerInterface`, 500 with the message included ONLY when the framework debug flag is on, generic plain body otherwise). `page(Request, string $path)`: reserved check → framework `Response::error('Not Found', 404)` (the standard JSON shape); else `resolvePath` → 30x / themed 404 / 410 via `error.twig` / render through the hierarchy (`entry/{type-slug}.twig` existence probed via the Twig loader, falling back to `entry.twig`). Render exceptions → try `error.twig` (500) → plain-text 500. All HTML responses `new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8'])`.
- [ ] **Step 4: Routes + provider wiring** — routes file registers `GET /` → `home` and `GET /{path}`->where('path', '.+') → `page` (no auth middleware; rate limiting deliberately NOT applied to the whole site surface — the page cache sub-project owns abuse posture; note this in the routes comment). Provider: `serveFrontend('/theme-assets', ThemeLocator assets dir)` inside `isEnabled`.
- [ ] **Step 5: All green** — pipeline tests, then FULL suite (nothing else may regress — especially delivery + seo suites), phpcs.
- [ ] **COMMIT 2** (message summarizing Tasks 3–5: themes + context functions + pipeline; follow the established style).

---

### Task 6: Removability + docs (COMMIT 3)

**Files:**
- Test: `tests/Integration/Render/RenderRemovabilityTest.php`
- Create: `packages/lemma-render/README.md`
- Modify: `CHANGELOG.md` ([Unreleased] Added), `docs/NEXT.md` (render core shipped; caching is next)

- [ ] Removability test (the established `bootAppWithConfigOverride` pattern): capability disabled → `GET /blog/hello` and `GET /anything` return the standard JSON 404 exactly as pre-render (this doubles as the byte-compat source for Task 5's comparison), `/theme-assets/site.css` 404s, boundary sweep over `packages/lemma-render/src` (>5 files checked), `composer boundaries` → **9 packages**.
- [ ] README modeled on lemma-navigation's: the catch-all + resolver architecture, theme anatomy + ladder, context functions incl. the `menu()`/`path()` soft-dependency guarantees AND the escape-by-default/`|raw`-is-an-opt-in guidance for theme authors, config table, **the v1 restart caveat (theme selection is resolved at boot — changing `lemma_render.theme` requires an app restart/cache rebuild; runtime switching needs a dynamic asset controller, deferred)**, install/remove, out-of-scope (spec §6).
- [ ] CHANGELOG Added entry + NEXT.md: sub-project 2 shipped, sub-project 3 (render caching) next.
- [ ] Final verification: full backend suite, phpcs, boundaries. **COMMIT 3.**

---

## Self-Review Checklist

- Spec §1 config/boundary → Task 2. §2 catch-all + reserved semantics + JSON-404 byte-compat → Task 5 (+ removability as the comparison source). §3 contract + normalization-first + parsing rules → Task 1. §4 theme ladder → Task 3; context functions + asset safety → Task 4; homepage + error ladder → Task 5. §5 tests → distributed per task. §6 deferrals respected (no rate limit on pages is called out explicitly as a caching-sub-project concern).
- Deliberate deviations to flag at execution: none — but two READ-FIRST duties are mandatory, not optional: `DeliveryController::shape()/item()/redirectResponse()` before Task 1 Steps 3–4, and `RedirectRepositoryTest` before Task 1's redirect seeding.
- Type consistency: the resolver result array shape is identical across contract, Task 1 tests, and Task 5's controller consumption; `ThemeLocator::activePaths()` feeds `TwigFactory` and `serveFrontend` with the same array.
