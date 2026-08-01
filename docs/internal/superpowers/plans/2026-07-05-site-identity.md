# Site Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Favicon upload with a browser-tab preview, a dark-mode logo variant, and uuid-free asset previews.

**Architecture:** Two new GeneralSettings keys (`site_favicon`, `site_logo_dark`). `SiteLogoProvider::siteLogoUuid()` gains a strict variant param; a new one-method `SiteFaviconProvider` mirrors it. Twig: `site_logo(variant)` validates `null|'light'|'dark'` at the extension boundary; new `site_favicon()` resolves through the SAME `media()` predicate (null when not anonymously servable) → FUNCTIONS + CACHE_VERSION 8. Layout/logo block render a light/dark img pair only when the dark variant exists; `<head>` gains the favicon link when resolvable. Admin: AssetField single-mode shows a larger uuid-free preview (identity = picker; tooltip keeps the uuid), the identity card gains dark-logo + favicon fields and a `FaviconPreview` tab mock.

**Tech Stack:** PHP 8.3, Twig 3, PHPUnit; Vue 3 + Nuxt UI, vitest.

**Spec:** `docs/superpowers/specs/2026-07-05-site-identity-design.md`

## Global Constraints

- Favicon/logo URLs resolve ONLY through `MediaUrlResolver` (P1 pin) — unresolvable → null → no markup.
- `site_logo(variant)`: `null|'light'|'dark'` only; anything else → null (P2 pin).
- No dark upload → byte-identical light-only markup (regression-tested).
- Identity ownership (P2 pin): picker = rich identity; field preview = minimal + uuid tooltip.
- `CACHE_VERSION = 8` (`site_favicon` joined FUNCTIONS).
- Session conventions: stage only; commit on "commit all"; CHANGELOG; no attribution.

---

### Task 1: Settings + providers + Twig + policy (PHP)

**Files:**
- Modify: `app/Settings/GeneralSettings.php` (+2 DEFS + accessors)
- Modify: `app/Http/DTOs/UpdateGeneralSettingsData.php`, `app/Http/Controllers/GeneralSettingsController.php` (save map)
- Modify: `packages/lemma-contracts/src/Settings/SiteLogoProvider.php` (variant param)
- Create: `packages/lemma-contracts/src/Settings/SiteFaviconProvider.php`
- Modify: `app/Settings/EngineSiteLogoProvider.php`; Create: `app/Settings/EngineSiteFaviconProvider.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (favicon provider registration WITH import)
- Modify: `packages/lemma-render/src/RenderContextExtension.php` (+variant validation, +site_favicon), `packages/lemma-render/src/LemmaRenderServiceProvider.php` (soft-bind), `packages/lemma-render/src/Templates/TemplatePolicy.php` (FUNCTIONS + v8)
- Tests: `BlocksRenderingTest` policy pin 7→8 + `site_favicon` lint; settings round-trip in the general-settings API test; extension unit cases in `BlockLibraryRenderTest`/`RenderContextTest`-style

**Key code — contracts:**

```php
// SiteLogoProvider (pre-launch interface change; one impl):
public function siteLogoUuid(string $variant = 'light'): ?string;

// SiteFaviconProvider (new, the SiteLogoProvider shape):
interface SiteFaviconProvider
{
    public function faviconUuid(): ?string;
}
```

**Engine impls:** `EngineSiteLogoProvider::siteLogoUuid($variant)` — `'dark'` → `site_logo_dark`, `'light'` → `site_logo`, anything else → null (defense in depth under the extension gate); empty string → null. `EngineSiteFaviconProvider::faviconUuid()` — `site_favicon`, empty → null.

**Extension:**

```php
    public function siteLogo(?string $variant = null): ?string
    {
        // P2 pin: closed variant vocabulary at the template boundary.
        $variant ??= 'light';
        if (!in_array($variant, ['light', 'dark'], true)) {
            return null;
        }
        $uuid = $this->siteLogo?->siteLogoUuid($variant);
        return $uuid === null ? null : $this->media($uuid);
    }

    /** P1 pin: the media() predicate — null when not anonymously servable. */
    public function siteFavicon(): ?string
    {
        $uuid = $this->favicon?->faviconUuid();
        return $uuid === null ? null : $this->media($uuid);
    }
```

(+ `?SiteFaviconProvider $favicon = null` ctor param after `$regions`; soft-bind in `makeRenderContextExtension`; register `new TwigFunction('site_favicon', …)`; FUNCTIONS += `'site_favicon'`; `CACHE_VERSION = 8` with comment.)

**Tests:** policy pin 6→…→8 update + lint `{{ site_favicon() }}`; `site_logo('weird')` renders empty (template-level case); settings PUT round-trips `site_favicon`/`site_logo_dark`; dev DB needs nothing (settings rows appear on save).

---

### Task 2: Theme (layout + logo block + CSS) + render tests

**Files:**
- Modify: `packages/lemma-render/themes/default/templates/layout.twig` (head link + header pair), `blocks/logo.twig` (pair), `assets/site.css`, `assets/blocks.css`
- Test: `RegionRenderingTest`/`RenderPipelineTest`-style cases (favicon link set/unset; dark pair only when set; light-only unchanged; private favicon blob → no link)

**Layout head:**

```twig
  {% set favicon = site_favicon() %}
  {% if favicon %}<link rel="icon" href="{{ favicon }}">{% endif %}
```

**Header logo (and the same shape in `blocks/logo.twig`):**

```twig
      {% set logo = site_logo() %}
      {% set logoDark = site_logo('dark') %}
      <a href="/" class="site-name">
        {%- if logo -%}
          <img class="site-logo site-logo--light" src="{{ logo }}" alt="{{ site.name }}">
          {%- if logoDark -%}<img class="site-logo site-logo--dark" src="{{ logoDark }}" alt="{{ site.name }}">{%- endif -%}
        {%- else -%}
          {{ site.name }}
        {%- endif -%}
      </a>
```

**CSS (site.css; mirror in blocks.css for the logo block):** `--dark` hidden by default; under `prefers-color-scheme: dark`, hide `--light` and show `--dark` ONLY via a sibling-presence-free rule — the template already omits `--dark` when unset, so:

```css
.site-logo--dark { display: none; }
@media (prefers-color-scheme: dark) {
  .site-logo--light:has(+ .site-logo--dark), /* hide light only when a dark twin exists */
  .site-logo--dark { }
}
```

(Implementation note: `:has` sibling check keeps the LIGHT logo visible in dark
mode when no dark image exists. If `:has` support is a concern for the target
baseline, invert: wrap the pair in a `--has-dark` modifier class emitted by the
template — prefer the template-emitted modifier, it's testable in PHPUnit:
`<a class="site-name{% if logoDark %} site-name--has-dark{% endif %}">` with
plain descendant rules. USE THE MODIFIER APPROACH.)

**Render tests:** favicon link present when `site_favicon` set to a PUBLIC blob; absent when unset; absent when the blob is PRIVATE (P1 proof); dark pair + modifier class only when `site_logo_dark` set; light-only markup byte-unchanged otherwise.

---

### Task 3: Admin (AssetField preview, identity card, FaviconPreview) + vitest

**Files:**
- Modify: `admin/src/fields/components/AssetField.vue` (single-mode preview)
- Modify: `admin/src/pages/settings/general/index.vue` (dark logo + favicon fields, preview)
- Create: `admin/src/pages/settings/general/components/FaviconPreview.vue`
- Tests: adjust `assetFieldLibrary.spec.ts` if it asserts the uuid text; add `FaviconPreview` case to `generalSettings`-related spec or a small new spec

**AssetField single-mode** (replace the `h-10 img + uuid span` row):

```html
<div v-else-if="singleUuid" class="flex min-w-0 items-center gap-2">
  <img
    :src="blobDisplayUrl(singleUuid)"
    :alt="singleUuid"
    :title="singleUuid"
    class="max-h-20 max-w-full rounded object-contain"
    data-test="asset-single-preview"
  />
</div>
```

(Identity pin: `title`/`alt` carry the uuid — tooltip + AT affordance; the
picker's tiles keep filename identity. Keep the Uploading… indicator.)

**Identity card:** after "Site logo": `Site logo (dark)` AssetField
(`site_logo_dark`, help per spec) and `Favicon` AssetField (`site_favicon`,
help "PNG or SVG, square, ≥ 512×512 recommended.") + the preview:

```html
<FaviconPreview v-if="form.site_favicon" :src="blobDisplayUrl(form.site_favicon)" :site-name="form.site_name" />
```

**FaviconPreview.vue** — pure presentation, the WP site-icon mock: a rounded
app-icon tile (`size-12 rounded-xl border` img) next to a browser-tab mock
(rounded-t bar with three dots, then a tab chip: favicon `size-4` + truncated
site name + a muted ×). `data-test="favicon-preview"`. No logic beyond props.

**Form state:** `site_favicon: ''`, `site_logo_dark: ''` join the reactive
form + save payload (the GeneralSettings API already round-trips them after
Task 1). Regenerate nothing client-side beyond `pnpm gen:api` (Task 4).

**Vitest:** general settings page renders the favicon preview when the form
value is set (mock queries per house rules); AssetField spec: single-mode
preview shows `[data-test="asset-single-preview"]` with `title` = uuid and NO
visible uuid text node.

---

### Task 4: Gates + OpenAPI + CHANGELOG + stage

- [ ] `composer run docs:openapi && cd admin && pnpm gen:api`
- [ ] `vendor/bin/phpunit && composer run phpcs`; `pnpm vitest run && pnpm type-check && pnpm lint`
- [ ] CHANGELOG `[Unreleased]`: site identity (favicon setting + `site_favicon()` behind the media() predicate, CACHE_VERSION 8; dark-mode logo variant via `site_logo('dark')` with strict variant vocabulary and light fallback; browser-tab favicon preview; uuid-free asset previews with picker-owned identity).
- [ ] Stage everything. NO commit — wait for "commit all".
