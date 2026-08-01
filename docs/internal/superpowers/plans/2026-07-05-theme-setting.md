# Live Theme Setting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The live site's theme becomes a Settings → General picker (DB row → `RENDER_THEME` env → `default`), applied on the next request with correct asset/browser-cache behavior.

**Architecture:** A lemma-contracts `ThemeSettingProvider` seam (app binds it over GeneralSettings, raw override only) feeds a render-pack `ActiveThemeSource` (per-request memo, revalidating ladder). The boot-frozen `/theme-assets` static mount becomes a dynamic route; `asset()` gains a `?t={theme}` buster; `ThemeChanged` purges via `invalidateTags(['lemma:render:page'])`.

**Tech Stack:** PHP 8.3/Twig/PHPUnit; Vue 3 + Nuxt UI, vitest.

**Spec:** `docs/superpowers/specs/2026-07-05-theme-setting-design.md`

## Global Constraints

- Env ladder UNCHANGED: missing env theme dir → silent default fallback; present dir + broken theme.json → loud `ThemeConfigError`. A stale DB row NEVER 500s (revalidate → log → fall back).
- The app provider reads the RAW override (`GeneralSettings::themeOverride(): ?string`, the `homepageEntryOverride()` mirror) — never the resolved effective value.
- Purge = `invalidateTags(['lemma:render:page'])` (the region/menu/template listener mechanism) — never `deletePattern('render:*')`.
- `asset()` buster applies ONLY to the live base (`assetBase === null`); preview's `setAssetBase` path is untouched.
- Provider `use`-imports; stage only; commit on "commit all"; CHANGELOG; no attribution.

---

### Task 1: Contracts + settings backend

**Files:**
- Create: `packages/lemma-contracts/src/Settings/ThemeSettingProvider.php`, `packages/lemma-contracts/src/Settings/ThemeChanged.php`
- Modify: `app/Settings/GeneralSettings.php` (+`theme` DEF, `theme()` accessor, `themeOverride()`, `theme` joins the clear-on-empty special case beside `homepage_entry`)
- Create: `app/Settings/EngineThemeSettingProvider.php`
- Modify: `app/Http/DTOs/UpdateGeneralSettingsData.php` (+`?string $theme`), `app/Http/Controllers/GeneralSettingsController.php` (save map + write-time validation + `ThemeChanged` dispatch), `app/Providers/LemmaServiceProvider.php` (bind provider, WITH `use` import)
- Test: `tests/Integration/Render/RenderPipelineTest.php` (settings round-trip + validation cases, next to `testIdentitySettingsRoundTrip`)

**Interfaces:**
- `ThemeSettingProvider::themeOverride(): ?string` — null = no stored override (docblock: raw row, never the resolved fallback).
- `ThemeChanged` — `final class ThemeChanged extends BaseEvent { public function __construct(public readonly string $theme) { parent::__construct(); } }` — lives in lemma-contracts so the render pack can listen without depending on the app.
- `GeneralSettings::DEFS` += `'theme' => ['lemma_render.theme', 'string', 'default']`; `theme(): string` (effective); `themeOverride(): ?string` = `$this->store->get('theme')`.
- `GeneralSettings::save()`: `theme` joins the `=== ''` → `forget()` branch (explicit empty clears the override, env shows through — the homepage_entry model).
- Controller `update()`: when `$input->theme` is a non-empty string — soft-probe `PreviewThemeValidator` via `container($context)->has(...)`; bound and `!isValidTheme($input->theme)` → 422 `'Unknown theme.'`; unbound → skip validation (the setting is inert without the render pack). Capture `$before = $settings->themeOverride()`; after save, if the stored override changed, `app($context, EventService::class)->dispatch(new ThemeChanged($settings->theme()))`.
- `EngineThemeSettingProvider implements ThemeSettingProvider` over `GeneralSettings::themeOverride()`; bound in `LemmaServiceProvider::services()` (shared, autowire; `use` imports for BOTH class and contract — the MediaUrlResolver lesson).

- [ ] **Step 1: Failing tests** (RenderPipelineTest):

```php
public function testThemeSettingRoundTripAndValidation(): void
{
    $controller = $this->container()->get(\App\Http\Controllers\GeneralSettingsController::class);
    $hydrate = fn(array $body) => (new \Glueful\Validation\RequestDataHydrator())
        ->hydrate(\App\Http\DTOs\UpdateGeneralSettingsData::class, $body, [], []);

    // Unknown theme -> 422 (validator bound in this env; only 'default' exists).
    self::assertSame(422, $controller->update($hydrate(['theme' => 'nope']))->getStatusCode());

    // 'default' is always valid; round-trips as the stored override.
    $ok = $controller->update($hydrate(['theme' => 'default']));
    self::assertSame(200, $ok->getStatusCode());
    $general = $this->container()->get(\App\Settings\GeneralSettings::class);
    self::assertSame('default', $general->themeOverride());

    // Explicit '' clears the row -> env fallback; raw override reads null.
    $controller->update($hydrate(['theme' => '']));
    self::assertNull($general->themeOverride());
    self::assertSame('default', $general->theme()); // effective falls back
}
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit --filter=testThemeSettingRoundTripAndValidation`
- [ ] **Step 3: Implement** per the Interfaces block.
- [ ] **Step 4: Run to verify pass** + `composer run phpcs`.

---

### Task 2: ActiveThemeSource + locator wiring + purge listener

**Files:**
- Create: `packages/lemma-render/src/ActiveThemeSource.php`, `packages/lemma-render/src/Listeners/PurgeRenderCacheOnThemeChange.php`
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php` (`makeThemeLocator` consults the source; register the source + listener; `addListener(ThemeChanged::class, …)` in `boot()` beside the RegionUpdated listener)
- Test: `tests/Integration/Render/ActiveThemeSourceTest.php` (new)

**Interfaces:**
- `ActiveThemeSource`:

```php
final class ActiveThemeSource
{
    private ?string $memo = null;

    public function __construct(
        private readonly ?ThemeSettingProvider $settings,   // soft-bound; null = env only
        private readonly PreviewThemeValidator $validator,
        private readonly string $envTheme,                   // config('lemma_render.theme')
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function name(): string
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $override = $this->settings?->themeOverride();
        if ($override !== null && $override !== '') {
            if ($this->validator->isValidTheme($override)) {
                return $this->memo = $override;
            }
            // Stale row (deleted dir / broken theme.json): log + fall back — never 500.
            $this->logger?->warning("[Lemma] Stored theme '{$override}' is no longer valid; falling back.");
        }
        return $this->memo = $this->envTheme;
    }
}
```

- `makeThemeLocator`: theme name comes from `ActiveThemeSource::name()` instead of raw config (ThemeLocator's own ladder — missing dir silent fallback, broken PRESENT env dir loud — is untouched).
- `PurgeRenderCacheOnThemeChange`: mirror `PurgeRenderCacheOnTemplateUpdate` — `onThemeChanged(ThemeChanged $event): void` → `invalidateTags(['lemma:render:page'])` (copy that listener's container/cache access verbatim).
- Registration: `ThemeSettingProvider` consumed via `$container->has(...)` in the source's factory (soft-bound); listener wired in `boot()` behind the same capability gate as the other purge listeners.

- [ ] **Step 1: Failing tests:**

```php
public function testRowWinsEnvAndStaleRowFallsBack(): void
{
    // Bound provider returning a valid theme -> that theme.
    // Provider returning a theme whose dir is gone -> env fallback + a log line, no throw.
    // No provider bound -> env value verbatim.
}
public function testMemoizesWithinAnInstance(): void
```

(Construct `ActiveThemeSource` directly with stub providers/validators — the CommerceTestCase-style anonymous classes; assert with a `CapturingLogger` if the harness has one, else a tiny inline PSR logger.)

- [ ] **Step 2–4: fail → implement → pass** + full `tests/Integration/Render/`.

---

### Task 3: Dynamic theme assets + `asset()` buster

**Files:**
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php` (+`themeAsset()`), `packages/lemma-render/routes/public-routes.php` (+route), `packages/lemma-render/src/LemmaRenderServiceProvider.php` (DELETE the boot-time `serveFrontend('/theme-assets', …)` block), `packages/lemma-render/src/RenderContextExtension.php` (`asset()` buster; ctor gains `?ActiveThemeSource`)
- Test: `tests/Integration/Render/ThemeAssetServingTest.php` (new), `RenderPipelineTest` (buster regex)

**Interfaces:**
- Route, registered with the literal statics: `$router->get('/theme-assets/{path}', [RenderController::class, 'themeAsset'])->where('path', '.+');` — `theme-assets` is already in `reserved_prefixes`, so the page catch-all never sees it.
- `RenderController::themeAsset(Request $request, string $path): Response` — MIRROR `previewAsset()`'s segment validation and MIME map (read it first; same traversal guards), rooted at `ThemeLocator::activePaths()['assets']`; `Cache-Control: public, max-age=86400`; 404 on miss/invalid.
- `asset()` (extension): after the existing safety checks —

```php
$base = $this->assetBase ?? '/theme-assets';
$url = $base . '/' . $rel;
// Theme cache-buster (theme-setting spec §3 P1): live base only — preview's
// token-scoped base is already theme-pinned and must not be rewritten.
if ($this->assetBase === null && $this->themeSource !== null) {
    $url .= '?t=' . rawurlencode($this->themeSource->name());
}
return $url;
```

- [ ] **Step 1: Failing tests** — serving: active theme's `site.css` body via kernel `GET /theme-assets/site.css?t=x`; traversal (`/theme-assets/../theme.json`) 404; buster: rendered HTML matches `#/theme-assets/site\.css\?t=default#`; preview assets unaffected (existing preview tests keep passing).
- [ ] **Step 2–4: fail → implement → pass.** Delete the `serveFrontend` block LAST, after the dynamic route proves itself.

---

### Task 4: Themes endpoint + Settings card + vitest

**Files:**
- Modify: `packages/lemma-render/src/Http/Controllers/TemplatesAdminController.php` (+`themes()` action reusing `availableThemes()`), `packages/lemma-render/routes/admin-routes.php` (`GET /themes`)
- Modify: `admin/src/queries/templates.ts` (+`fetchRenderThemes()`), `admin/src/queries/generalSettings.ts` (+`theme: string` on the interface), `admin/src/pages/settings/general/index.vue` (Theme card)
- Test: `admin/src/__tests__/generalSettingsPage.spec.ts`

**Interfaces:**
- `GET /v1/admin/render/themes` → `{themes: list<string>, active: string}`. **Permission:** the settings page consumes this, so gate it `lemma_permission:settings.manage` (verify the exact permission string on the settings/general routes in `routes/lemma_admin.php` and mirror it — do NOT reuse `templates.manage` here).
- Settings page: a small "Theme" card in the right column above Homepage — `USelect` fed by `fetchRenderThemes()` (graceful: fetch failure hides the card), bound to `form.theme`; help copy: "Applies on the next page view. Preview a theme first via a preview session." Form state `theme: ''` joins the reactive form + save payload.
- vitest: card renders options from a mocked themes query; save payload carries `theme`; fetch-failure hides the card without an error toast.

- [ ] **Step 1–4: fail → implement → pass** (`pnpm vitest run`, `pnpm type-check`, `pnpm lint`).

---

### Task 5: Gates + OpenAPI + CHANGELOG + stage

- [ ] `composer run docs:openapi && cd admin && pnpm gen:api`
- [ ] `vendor/bin/phpunit && composer run phpcs`; `pnpm vitest run && pnpm type-check && pnpm lint`
- [ ] CHANGELOG `[Unreleased]`: live theme setting (DB override → env → default; write-time validation; stale-row fallback never 500s; dynamic `/theme-assets` serving replacing the boot mount; `?t=` asset buster; tag-based purge on `ThemeChanged`; Settings → General Theme card; `GET /admin/render/themes`).
- [ ] Stage everything. NO commit — wait for "commit all".

---

## Self-Review Notes (completed)

- Spec §1 (setting + both P1 postures) → Task 1 (raw-override pin implemented as `themeOverride()`; clear-on-empty; write-time validation; env ladder untouched — Task 2 only feeds the NAME differently); §2 (contract seam + memoized source + tag purge) → Tasks 1/2; §3 (dynamic assets + buster P1) → Task 3 (mount deleted only after the route passes); §4 (endpoint + card, settings-permission note) → Task 4; §5 (tag mechanism + raw-override) → Tasks 1/2. Out-of-scope respected (no cloning changes — already shipped separately; no per-page themes).
- Verify-points: `previewAsset()` validation/MIME internals (Task 3), the settings routes' exact permission string (Task 4), `PurgeRenderCacheOnTemplateUpdate` container/cache access shape (Task 2), whether `TemplateEditor`/templates-page `fetchTemplates` themes list should also feed from the new endpoint (leave as-is — the embedded list already works).
- Type consistency: `ActiveThemeSource::name(): string` used by locator factory + `asset()`; `ThemeChanged(string $theme)` constructed in the app controller, consumed by the pack listener.
