# Site Custom CSS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A DB-backed, per-theme `custom.css` editable from the templates admin, served versioned at `GET /custom.css`, linked after the theme stylesheets.

**Architecture:** One well-known DB-only path (`custom.css`) rides the EXISTING template store — versioning, history, restore, and the `TemplateUpdated` purge come free. Save gets a type-aware branch (skip Twig lint; UTF-8 + size cap). A new static public route serves the active theme's row immutably cache-busted by version uuid; a policy-gated `custom_css()` Twig function emits the link in `layout.twig`.

**Tech Stack:** PHP 8.3/Twig 3/PHPUnit; Vue 3 + Nuxt UI + CodeMirror (legacy-modes `css`), vitest.

**Spec:** `docs/superpowers/specs/2026-07-05-custom-css-design.md`

## Global Constraints

- The path special case is EXACT: `custom.css` only; every other non-`.twig` path still 422s.
- CSS is never syntax-validated (it cannot 500 the site); UTF-8 + `lemma_render.custom_css.max_bytes` (default 262144) are the only save gates.
- `TemplatePolicy::CACHE_VERSION` 8 → 9 (`custom_css` joins FUNCTIONS); `BlocksRenderingTest` pin updated.
- Serving: `Content-Type: text/css`, `Cache-Control: public, max-age=31536000, immutable`; 404 when no row or trim-empty source; URL always `/custom.css?v={version_uuid}`.
- Trust model in copy: trusted-site styling under `templates.manage` — never a content-editor capability (helper note + docs say so).
- Session conventions: stage only, commit on "commit all", CHANGELOG `[Unreleased]`, no attribution.

---

### Task 1: Backend — type-aware save + versioned serving

**Files:**
- Modify: `packages/lemma-render/src/Http/Controllers/TemplatesAdminController.php` (path special-case + CSS validation branch)
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php` (+`customCss()` action)
- Modify: `packages/lemma-render/routes/public-routes.php` (static route — the `/_preview.css` precedent)
- Modify: `packages/lemma-render/config/lemma-render.php` (+`custom_css.max_bytes`)
- Test: `tests/Integration/Render/TemplatesAdminApiTest.php` (save matrix), `tests/Integration/Render/CustomCssServingTest.php` (new)

**Interfaces:**
- Consumes: `TemplateRepository::findCurrentSource(string $theme, string $path): ?array{source, version_uuid}` and the existing save/delete/versions methods; `ThemeLocator::activePaths()['name']`.
- Produces:
  - `TemplatesAdminController` (AMENDED per review):
    - `invalidPath()` stays UNTOUCHED and Twig-only — its "ending .twig" error
      copy remains true for every twig path. Add
      `private const CUSTOM_CSS = 'custom.css';`,
      `private function isCustomCss(string $path): bool`, and
      `private function pathAllowed(string $path): bool { return $this->isCustomCss($path) || !$this->invalidPath($path); }`;
      `show`/`save`/`delete`/`versions`/`restore` switch to `pathAllowed()`
      (negated) so the "every other non-twig path still 422s" invariant stays
      obvious at each call site.
    - Extract the CSS branch once:
      `private function cssViolation(ApplicationContext|Request-context, string $source): ?string`
      — `!mb_check_encoding($source, 'UTF-8')` → `'custom.css must be valid UTF-8.'`;
      `strlen($source) > (int) config(..., 'lemma_render.custom_css.max_bytes', 262144)`
      → `'custom.css exceeds the size limit.'`; else null.
    - `save()`: `isCustomCss($path)` → use `cssViolation()` (422 on non-null)
      INSTEAD of `$this->linter->lint(...)`. Everything else (transactional
      save, `TemplateUpdated`, history) unchanged.
    - **`restore()` (review pin):** the current restore re-lints the restored
      source with the Twig linter — for `custom.css` that would 422 valid CSS
      like `.x { color: red; }`. Restore branches identically to save:
      `isCustomCss($path)` → `cssViolation()` instead of the Twig re-lint
      (the size cap re-applies in case the cap shrank since the version was
      written).
    - **`show()` (review pin):** for `custom.css`, DB row or 404 — NEVER fall
      through to `TemplateCatalog::readFile()`. The spec pins "no filesystem
      counterpart"; an accidental `custom.css` file in a theme directory must
      not become the editor's source.
  - `RenderController::customCss(Request $request): Response` — active theme via `ThemeLocator`, `findCurrentSource($theme, 'custom.css')`; null row or `trim($source) === ''` → themed-404-free plain 404; else 200 body = source, headers `Content-Type: text/css; charset=utf-8`, `Cache-Control: public, max-age=31536000, immutable`.
  - Route (in `public-routes.php`, with the other literal statics ABOVE the `/` + catch-all registrations):

```php
// Site custom CSS (custom-css spec §3): DB-backed stylesheet, immutable-cached —
// the layout links it with ?v={version_uuid}, so every save changes the URL.
$router->get('/custom.css', [RenderController::class, 'customCss']);
```

  - Config addition:

```php
'custom_css' => [
    // Save-time size cap for the DB-backed custom.css (bytes).
    'max_bytes' => (int) env('LEMMA_CUSTOM_CSS_MAX_BYTES', 262144),
],
```

- [ ] **Step 1: Write the failing tests**

In `TemplatesAdminApiTest`:

```php
public function testCustomCssSavesWithoutTwigLintAndCapsSize(): void
{
    // Braces would be Twig-linted noise on a .twig path; custom.css skips the linter.
    $res = $this->api()->save($this->putReq('.lemma-block-hero { padding: 2rem; }'), 'custom.css');
    self::assertSame(200, $res->getStatusCode());

    // Over the cap → 422. (Shrink the cap via config override per this suite's pattern.)
    // Non-custom non-twig paths keep the exact grammar:
    self::assertSame(422, $this->api()->save($this->putReq('x'), 'assets/site.css')->getStatusCode());

    // Show round-trips as a DB row:
    $show = $this->json($this->api()->show(Request::create('/x', 'GET'), 'custom.css'));
    self::assertSame('db', $show['data']['origin']);
}

public function testEmptyCustomCssSaveKeepsTheRow(): void
{
    $this->api()->save($this->putReq('body{}'), 'custom.css');
    $res = $this->api()->save($this->putReq(''), 'custom.css');
    self::assertSame(200, $res->getStatusCode()); // disabled, history kept
}

public function testCustomCssRestoreSkipsTheTwigLint(): void
{
    // Review pin: restore re-validates with the CSS branch, not the Twig linter —
    // otherwise restoring '.x { color: red; }' 422s under Twig.
    $this->api()->save($this->putReq('.x { color: red; }'), 'custom.css');
    $this->api()->save($this->putReq('.y { color: blue; }'), 'custom.css');
    $versions = $this->json($this->api()->versions(Request::create('/x', 'GET'), 'custom.css'))['data']['versions'];
    $first = $versions[array_key_last($versions)]['uuid'];
    $res = $this->api()->restore(Request::create('/x', 'POST'), 'custom.css', $first);
    self::assertSame(200, $res->getStatusCode());
}

public function testCustomCssShowNeverReadsTheFilesystem(): void
{
    // Review pin: no DB row → 404 even if a theme dir happens to contain a
    // custom.css file — the path is DB-only by contract.
    $res = $this->api()->show(Request::create('/x', 'GET'), 'custom.css');
    self::assertSame(404, $res->getStatusCode());
}
```

New `CustomCssServingTest` (kernel-driven, the RenderPipelineTest pattern):

```php
public function testServesTheActiveThemesRowWithImmutableHeaders(): void
{
    /* save a custom.css row via the admin controller */
    $res = $this->handle(Request::create('/custom.css?v=abc', 'GET'));
    self::assertSame(200, $res->getStatusCode());
    self::assertStringContainsString('text/css', (string) $res->headers->get('Content-Type'));
    self::assertStringContainsString('immutable', (string) $res->headers->get('Cache-Control'));
    self::assertStringContainsString('.lemma-block-hero', (string) $res->getContent());
}

public function testMissingOrEmptyCustomCssIs404(): void
{
    self::assertSame(404, $this->handle(Request::create('/custom.css', 'GET'))->getStatusCode());
    /* save '' → still 404 */
}
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/Integration/Render/TemplatesAdminApiTest.php tests/Integration/Render/CustomCssServingTest.php`
- [ ] **Step 3: Implement** per the Interfaces block.
- [ ] **Step 4: Run to verify pass.**
- [ ] **Step 5: No commit** — stage at the end (session convention).

---

### Task 2: Render seam — `custom_css()` + policy v9 + layout link

**Files:**
- Create: `packages/lemma-render/src/Templates/CustomCssUrl.php`
- Modify: `packages/lemma-render/src/RenderContextExtension.php` (ctor param + function), `packages/lemma-render/src/LemmaRenderServiceProvider.php` (factory wiring), `packages/lemma-render/src/Templates/TemplatePolicy.php` (FUNCTIONS + v9), `packages/lemma-render/themes/default/templates/layout.twig`
- Test: `tests/Integration/Render/BlocksRenderingTest.php` (pin 8→9 + lint), `tests/Integration/Render/RenderPipelineTest.php` (link emission)

**Interfaces:**
- Produces:
  - `CustomCssUrl` (pack-internal, the IconSet posture): ctor `(TemplateRepository $repo, ThemeLocator $theme)`, method `url(): ?string` — `findCurrentSource(activeTheme, 'custom.css')`; null or trim-empty → null; else `'/custom.css?v=' . $row['version_uuid']`.
  - `RenderContextExtension`: ctor gains `private readonly ?CustomCssUrl $customCss = null` (after `$favicon`); `public function customCss(): ?string { return $this->customCss?->url(); }`; `new TwigFunction('custom_css', $this->customCss(...))` in `getFunctions()`.
  - `TemplatePolicy`: FUNCTIONS += `'custom_css'`; `CACHE_VERSION = 9` with comment `// bumped: 'custom_css' joined FUNCTIONS (custom-css spec)`.
  - `layout.twig`, directly after the blocks.css link (cascade order is the contract):

```twig
  {# Site custom CSS (custom-css spec §4): DB-backed, loaded LAST so operator
     rules win the cascade; null (absent/empty) emits nothing. #}
  {% set customCss = custom_css() %}
  {% if customCss %}<link rel="stylesheet" href="{{ customCss }}">{% endif %}
```

- [ ] **Step 1: Failing tests** — `BlocksRenderingTest`: `assertContains('custom_css', ...)`, `assertSame(9, TemplatePolicy::CACHE_VERSION)`, lint `{{ custom_css() }}`. `RenderPipelineTest`:

```php
public function testCustomCssLinkRendersOnlyWhenARowExists(): void
{
    $this->seedBilingualPublishedEntry();
    self::assertStringNotContainsString('/custom.css', $this->renderHello()); // fresh install: no link

    /* save custom.css via the admin controller */
    $html = $this->renderHello();
    self::assertMatchesRegularExpression('#/custom\.css\?v=[A-Za-z0-9_-]+#', $html);

    /* save AGAIN with different source → the v= changes (cache-buster) */
}
```

- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** (wire `CustomCssUrl` in `makeRenderContextExtension` — pack-internal `new CustomCssUrl($container->get(TemplateRepository::class), ...)`; follow the IconSet line).
- [ ] **Step 4: Run to verify pass** — `vendor/bin/phpunit tests/Integration/Render/`.

---

### Task 3: Admin — pinned Site entry + CSS editor mode

**Files:**
- Modify: `admin/src/pages/templates/components/TemplateEditor.vue` (language prop)
- Modify: `admin/src/pages/templates/index.vue` (pinned Site group; exclude `custom.css` from folder groups; empty-state open)
- Test: `admin/src/__tests__/templatesPage.spec.ts`

**Interfaces:**
- `TemplateEditor` props: `language?: 'twig' | 'css'` (default `'twig'`), via `import { css } from '@codemirror/legacy-modes/mode/css'` — `StreamLanguage.define(props.language === 'css' ? css : jinja2)` (already-installed `@codemirror/legacy-modes`; no new dependency).
- Page:
  - `groups` computed EXCLUDES `custom.css` (the pinned entry owns it).
  - Pinned block above the folders (inside the scrollable list, not collapsible):

```vue
<button
  class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-elevated"
  :class="{ 'bg-elevated': selectedPath === 'custom.css' }"
  data-test="template-item-custom.css"
  @click="openCustomCss()"
>
  <UIcon name="i-lucide-paintbrush" class="size-4 shrink-0 text-muted" />
  <span class="min-w-0 flex-1 truncate">custom.css</span>
  <UBadge size="xs" :color="customCssRow ? 'primary' : 'neutral'" variant="subtle">
    {{ customCssRow ? 'db' : 'empty' }}
  </UBadge>
</button>
```

  - `customCssRow` computed: `templates.value.find((t) => t.path === 'custom.css')`.
  - `openCustomCss()`: try `open('custom.css')`; a 404 (no row yet) is NOT an error — set `selectedPath = 'custom.css'`, `source = ''`, `origin = 'empty'`, no toast.
  - Detail area, when `selectedPath === 'custom.css'`: helper note replacing the fs-origin note — "Loaded after the theme stylesheets on every page — target blocks via their `lemma-block-*` classes. Site styling for trusted operators; this is not a content-editing surface." `TemplateEditor :language="selectedPath === 'custom.css' ? 'css' : 'twig'"`.
- [ ] **Step 1: Failing vitest cases** — pinned entry always visible with `empty` badge; opening it with a 404 mock shows the empty editor + helper note (no error toast); badge flips to `db` when the listing carries the row; `custom.css` never appears inside folder groups.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run to verify pass** — `pnpm vitest run src/__tests__/templatesPage.spec.ts`.

---

### Task 4: Gates + docs + stage

- [ ] `composer run docs:openapi && cd admin && pnpm gen:api` (docblocks touched).
- [ ] Full gates: `vendor/bin/phpunit && composer run phpcs`; `pnpm vitest run && pnpm type-check && pnpm lint`.
- [ ] CHANGELOG `[Unreleased]`: site custom CSS (DB-backed per-theme `custom.css`, versioned immutable serving, `custom_css()` + CACHE_VERSION 9, pinned admin entry with CSS editing, trust-model framing).
- [ ] Stage everything. NO commit — wait for "commit all".

---

## Self-Review Notes (completed)

- Spec §1 storage-reuse → Task 1 (no new tables); §2 validation matrix → Task 1 tests (skip-lint, cap, exact-path, empty-save); §3 serving → Task 1 (route precedent `/_preview.css`, headers pinned); §4 seam/policy/layout/purge → Task 2 (purge needs no work — existing listener; the dispatch is already in the save path Task 1 leaves untouched); §5 admin incl. trust-model copy → Task 3; §6 preview — no work (same layout). Out-of-scope respected.
- Type consistency: `CustomCssUrl::url(): ?string` matches `customCss()` passthrough; `'custom.css'` literal centralized in the controller const, client uses the string (path IS the API).
