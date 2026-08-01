# Modern Default Theme Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the default theme + ten starter blocks into a polished modern-SaaS site (fluid type, tokens, sticky header, full-bleed bands, dark mode) with the `show_title`/`layout` editor conventions — all contracts (annotation, standalone blocks.css, enum classes, edit regions, no build step, DB-overridability) intact.

**Architecture:** Task 1 rebuilds the shell (layout/entry/index templates + `site.css`) and lands the conventions with PHP tests; Task 2 rebuilds `blocks.css` and adds inner containers to the three band blocks; Task 3 documents, gates, stages. CSS visual fine-tuning is explicitly finished against the browser (the user's pass is the real gate); structure/selectors/behaviors below are the contract.

**Tech Stack:** Twig templates, plain CSS (no build), PHPUnit integration tests.

**Spec:** `docs/superpowers/specs/2026-07-04-modern-default-theme-design.md`

## Global Constraints

- NO commits; stage at the end, STOP for "commit all".
- Shell selectors ONLY via `.site-header` / `.site-nav` / `.site-footer` (review pin) — zero bare `header`/`nav`/`footer` rules in `site.css`.
- `layout--centered` constrains `.entry-content` only (review P2) — never `main > *`; band blocks respond to `.layout--centered` themselves in `blocks.css` (dormant rules; classless default = full-bleed).
- Block class contract unchanged: `lemma-block-{slug}`, `--{value}` modifiers, `__element` children; new wrappers only INSIDE block roots; `editable_text`/`safe_html`/conditional guards byte-preserved.
- Homepage body branch mirrors `entry.twig` exactly (review pin).
- No webfonts, no JS; dark mode via `prefers-color-scheme` variable re-mapping only.

---

### Task 1: Shell + conventions (`layout.twig`, `entry.twig`, `index.twig`, `site.css`) + PHP tests

**Files:**
- Modify: `packages/lemma-render/themes/default/templates/layout.twig`
- Modify: `packages/lemma-render/themes/default/templates/entry.twig`
- Modify: `packages/lemma-render/themes/default/templates/index.twig`
- Rewrite: `packages/lemma-render/themes/default/assets/site.css`
- Test: `tests/Integration/Render/RenderPipelineTest.php` (or the suite that renders `/blog/hello` — extend in place)

- [ ] **Step 1: Write the failing PHP tests**

Add to the render pipeline suite (adapt seeding to its existing helpers — `seedBilingualPublishedEntry` seeds a title-only blog type; for `show_title`/`layout` seed a type whose schema includes them):

```php
    public function testShowTitleFalseOmitsTheHeadingOnEntryAndHomepage(): void
    {
        $entry = $this->seedPublishedEntryWithFields([
            'title' => 'Hidden Title Page',
            'show_title' => false,
        ]);
        $res = $this->handle(Request::create('/pages/hidden', 'GET'));
        self::assertStringNotContainsString('<h1>Hidden Title Page</h1>', (string) $res->getContent());

        // Homepage (override-app controller pattern, mirroring RenderPipelineTest).
        $app = self::bootAppWithConfigOverride('lemma_render', ['homepage_entry' => $entry]);
        $controller = $app->getContainer()
            ->get(\Glueful\Lemma\Render\Http\Controllers\RenderController::class);
        $home = $controller->home(Request::create('/', 'GET'));
        self::assertStringNotContainsString('<h1>Hidden Title Page</h1>', (string) $home->getContent());
    }

    public function testLayoutFieldMapsToTheMainClass(): void
    {
        $this->seedPublishedEntryWithFields(['title' => 'Wide', 'layout' => 'full'], slug: 'wide');
        $res = $this->handle(Request::create('/pages/wide', 'GET'));
        self::assertStringContainsString('layout--full', (string) $res->getContent());

        // Absent field -> centered default (and the title sits in .entry-content).
        $this->seedBilingualPublishedEntry();
        $plain = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertStringContainsString('layout--centered', (string) $plain->getContent());
        self::assertStringContainsString('entry-content', (string) $plain->getContent());
    }

    public function testHomepageWithABlocksBodyRendersBlocks(): void
    {
        // Review pin: index.twig used to print a blocks array through
        // <div class="body"> — the homepage must render blocks() like entry.twig.
        $entry = $this->seedPublishedBlockPageEntry(); // quote block body, mirrors PreviewWorkingCopyTest::seedBlockPage
        $app = self::bootAppWithConfigOverride('lemma_render', ['homepage_entry' => $entry]);
        $controller = $app->getContainer()
            ->get(\Glueful\Lemma\Render\Http\Controllers\RenderController::class);
        $home = $controller->home(Request::create('/', 'GET'));
        $html = (string) $home->getContent();
        self::assertStringContainsString('lemma-block-quote', $html);   // rendered block markup
        self::assertStringNotContainsString('class="body"', $html);     // not the scalar fallback
    }
```

Write `seedPublishedEntryWithFields`/`seedPublishedBlockPageEntry` helpers against the suite's existing repo stack (`ContentTypeRepository`/`EntryRepository`/`RouteRepository`/`PublishService`, same shapes as `seedPublishedEntryInType` and `PreviewWorkingCopyTest::seedBlockPage`). The schema for the first helper includes `show_title` (boolean) and `layout` (string) fields so the values validate.

- [ ] **Step 2: Run to verify the right failures** — all three FAIL (`<h1>` always renders, no layout classes, homepage prints the array through `.body`).

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Integration/Render/RenderPipelineTest.php`

- [ ] **Step 3: Rewrite the templates**

`layout.twig` — namespaced shell + layout class on `<main>` (Twig `??` tolerates the undefined-entry pages: listings/terms/errors get `centered`):

```twig
<!DOCTYPE html>
<html lang="{{ site.locale }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{% block title %}{{ site.name }}{% endblock %}</title>
  <link rel="stylesheet" href="{{ asset('site.css') }}">
  <link rel="stylesheet" href="{{ asset('blocks.css') }}">
</head>
<body>
  {% if preview|default(false) %}
    <div class="preview-banner">
      Preview — unpublished content
      {% if preview_exit|default(null) %}<a href="{{ preview_exit }}">Exit preview</a>{% endif %}
    </div>
  {% endif %}
  <header class="site-header">
    <div class="site-header__inner">
      <a href="/" class="site-name">{{ site.name }}</a>
      <nav class="site-nav">
        {% for item in menu('main') %}
          <a href="{{ item.url }}">{{ item.label }}</a>
        {% endfor %}
      </nav>
    </div>
  </header>
  <main class="layout--{{ (entry.fields.layout ?? 'centered') == 'full' ? 'full' : 'centered' }}">
    {% block content %}{% endblock %}
  </main>
  <footer class="site-footer">
    <div class="site-footer__inner"><small>{{ site.name }}</small></div>
  </footer>
</body>
</html>
```

(The preview banner moves ABOVE the sticky header so blur/stickiness never
hide it; it keeps its `.preview-banner` class — the session-chrome tests
assert the class and Exit link, both preserved.)

`entry.twig`:

```twig
{% extends 'layout.twig' %}
{% block title %}{{ entry.fields.title ?? site.name }}{% endblock %}
{% block content %}
  <article>
    {% if entry.fields.show_title ?? true %}
      <div class="entry-content"><h1>{{ entry.fields.title }}</h1></div>
    {% endif %}
    {# A blocks-typed body renders through blocks() as DIRECT flow (review P2 —
       block roots own their width); scalar text stays escaped in the
       constrained .entry-content, as before. #}
    {% if entry.fields.body is defined %}
      {% if entry.fields.body is iterable %}{{ blocks(entry.fields.body) }}
      {% else %}<div class="entry-content"><div class="body">{{ entry.fields.body }}</div></div>{% endif %}
    {% endif %}
  </article>
{% endblock %}
```

`index.twig` — the exact same branch (review pin):

```twig
{% extends 'layout.twig' %}
{% block content %}
  {% if entry is defined and entry %}
    <article>
      {% if entry.fields.show_title ?? true %}
        <div class="entry-content"><h1>{{ entry.fields.title }}</h1></div>
      {% endif %}
      {% if entry.fields.body is defined %}
        {% if entry.fields.body is iterable %}{{ blocks(entry.fields.body) }}
        {% else %}<div class="entry-content"><div class="body">{{ entry.fields.body }}</div></div>{% endif %}
      {% endif %}
    </article>
  {% else %}
    <div class="entry-content">
      <h1>{{ site.name }}</h1>
      <p>This site is powered by Lemma. Create a theme in themes/ to make it yours.</p>
    </div>
  {% endif %}
{% endblock %}
```

Note: `<article>` is a styling-neutral flow element (no shell rules target
it), so block roots remain effectively main-direct for CSS purposes; the
`.layout--centered` rules in blocks.css use descendant selectors
(`.layout--centered .lemma-block-hero`), not child combinators.

- [ ] **Step 4: Rewrite `site.css`** — the full foundation:

```css
/* lemma-render default theme — modern-SaaS reference. Copy this theme into
   your app's themes/ directory as a starting point. Shell selectors are
   NAMESPACED (.site-header/.site-nav/.site-footer): block templates render
   semantic header/nav/footer elements of their own and must never inherit
   shell styling. */
:root {
  --bg: #ffffff;
  --surface: #f6f7f9;
  --surface-2: #eef0f4;
  --ink: #0f172a;
  --muted: #64748b;
  --line: #e2e8f0;
  --accent: #2563eb;
  --accent-ink: #ffffff;
  --shadow: 0 1px 2px rgb(15 23 42 / 0.06), 0 8px 24px rgb(15 23 42 / 0.08);
  --radius: 12px;
  --radius-lg: 20px;
  --container: 72rem;
  --content: 46rem;
  --space-1: 0.25rem; --space-2: 0.5rem; --space-3: 1rem;
  --space-4: 1.5rem; --space-5: 2.5rem; --space-6: 4rem; --space-7: 6rem;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #0b1120;
    --surface: #111a2e;
    --surface-2: #16213a;
    --ink: #e2e8f0;
    --muted: #94a3b8;
    --line: #1e293b;
    --accent: #3b82f6;
    --accent-ink: #ffffff;
    --shadow: 0 1px 2px rgb(0 0 0 / 0.4), 0 8px 24px rgb(0 0 0 / 0.5);
  }
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
  margin: 0;
  background: var(--bg);
  color: var(--ink);
  font: 17px/1.65 system-ui, -apple-system, "Segoe UI", sans-serif;
  -webkit-font-smoothing: antialiased;
}
h1, h2, h3, h4 { line-height: 1.15; letter-spacing: -0.02em; }
h1 { font-size: clamp(1.9rem, 1.4rem + 2vw, 2.75rem); }
h2 { font-size: clamp(1.5rem, 1.2rem + 1.2vw, 2rem); }
a { color: var(--accent); }

.site-header {
  position: sticky;
  top: 0;
  z-index: 10;
  background: color-mix(in srgb, var(--bg) 82%, transparent);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--line);
}
.site-header__inner {
  max-width: var(--container);
  margin-inline: auto;
  padding: var(--space-3) var(--space-4);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
}
.site-name { font-weight: 700; font-size: 1.05rem; color: var(--ink); text-decoration: none; }
.site-nav { display: flex; gap: var(--space-4); flex-wrap: wrap; }
.site-nav a {
  color: var(--muted);
  text-decoration: none;
  font-size: 0.95rem;
  transition: color 0.15s ease;
}
.site-nav a:hover { color: var(--ink); }

.preview-banner {
  display: flex;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-2) var(--space-4);
  background: var(--surface);
  border-bottom: 1px solid var(--line);
  font-size: 0.9rem;
  color: var(--muted);
}

main { min-height: 60vh; padding-block: var(--space-5) var(--space-7); }

/* Centered layout constrains ONLY the entry-content wrapper (spec P2):
   block roots are main-direct (display:contents wrappers) and own their
   width; blocks.css carries their .layout--centered presentation. */
.entry-content {
  max-width: var(--content);
  margin-inline: auto;
  padding-inline: var(--space-4);
}
.body { margin-top: var(--space-3); white-space: pre-wrap; }

.site-footer {
  border-top: 1px solid var(--line);
  color: var(--muted);
  background: var(--surface);
}
.site-footer__inner {
  max-width: var(--container);
  margin-inline: auto;
  padding: var(--space-5) var(--space-4);
}
```

(Executor may tune values against the browser; selectors and the
token/dark-mode structure are the contract. `color-mix` has full modern
support; the fallback is the solid `--bg` via a plain `background`
declaration first if the pass shows problems.)

- [ ] **Step 5: Run the PHP tests to green**, plus the neighboring render suites:

`vendor/bin/phpunit tests/Integration/Render/ tests/Integration/Content/PreviewApplyTest.php` — session-chrome assertions (preview banner class/Exit link) must stay green.

---

### Task 2: Starter blocks (`blocks.css` rewrite + three inner containers)

**Files:**
- Modify: `packages/lemma-render/themes/default/templates/blocks/hero.twig`
- Modify: `packages/lemma-render/themes/default/templates/blocks/section.twig`
- Modify: `packages/lemma-render/themes/default/templates/blocks/cta.twig`
- Rewrite: `packages/lemma-render/themes/default/assets/blocks.css`

- [ ] **Step 1: Template touches** — inner containers INSIDE the roots, everything else byte-identical (filters, guards, conditionals):

`hero.twig`: wrap the existing children (img/h1/p/cta) in `<div class="lemma-block-hero__inner">…</div>` directly inside the `<header>` root.
`section.twig`: wrap the title + `{{ blocks(data.content) }}` in `<div class="lemma-block-section__inner">…</div>` inside the `<section>` root.
`cta.twig`: wrap the children in `<div class="lemma-block-cta__inner">…</div>` inside the `<aside>` root.

- [ ] **Step 2: Rewrite `blocks.css`** to the spec's per-block system. Contract per block (selectors exact; declarations tuned in-browser):

- Keep the header comment (standalone-adoption note) and extend it: uses site.css variables with local fallbacks is NOT required — same variable dependency as today, plus the dormant `.layout--centered` rules.
- `.lemma-block { margin-block: var(--space-5); }`
- **hero**: root = full-bleed band (`padding-block: var(--space-7)`), gradient tint `background: linear-gradient(180deg, color-mix(in srgb, var(--accent) 8%, var(--bg)), var(--bg))`; `__inner { max-width: var(--container); margin-inline: auto; padding-inline: var(--space-4); }`; `__heading` display size `clamp(2.25rem, 1.6rem + 3vw, 3.5rem)`; `__subheading` at `--content` width, muted, larger line-height; `__cta` = pill (`border-radius: 999px`, accent bg, `--accent-ink`, padding, hover transform/shadow); `--center` centers text + inner; `__image` rounded-lg + shadow + max-height cap.
- **cta**: root full-bleed-neutral (no band bg by default); `__inner` container at `--container`, `--surface` panel, `--radius-lg`, `--shadow`, generous padding; `--primary` panel = accent gradient + `--accent-ink` (links included); `__button` pill (accent-on-surface for default, inverted on `--primary`).
- **section**: root = band (`padding-block: var(--space-6)`); `--none` = transparent + reduced padding; `--subtle` = `--surface`; `--emphasis` = accent-tinted (`color-mix` accent 10% into bg) with accent-colored `__title`; `__inner` at `--container` with inline padding; `__title` styled h2 with bottom margin.
- **columns**: grid as today; `__col` = card (`--surface` in bands stays legible: use `--bg` card + `--line` border + `--shadow`, `--radius`, padding, `transition: transform/box-shadow`, hover lift `translateY(-2px)`); container at `--container` centered.
- **quote**: centered pull quote at `--content`; text `clamp(1.25rem, 1.1rem + 0.8vw, 1.6rem)`, ink (not muted); oversized decorative `::before` quotation mark in accent at low opacity; `cite` muted small with an em-dash prefix.
- **image**: container at `--container`; img rounded + `--shadow`; `--wide` = 100% of container; `--full` = true full-bleed (natural now — plain `width: 100%`, no negative-margin hack, radius 0); figcaption muted small centered.
- **gallery**: container at `--container`; items wrapped visually via `overflow: hidden`-safe styling on the imgs themselves (`border-radius: var(--radius)`, `aspect-ratio: 4/3`, `object-fit: cover`, hover `transform: scale(1.02)` + transition); grid gaps `--space-3`.
- **divider**: `--line` hairline at `--content` width centered; `--space` unchanged.
- **spacer**: unchanged sizes, tokens only.
- **`.layout--centered` dormancy block (review P2)** at the end:

```css
/* Centered page layout (opt-in via main.layout--centered): band blocks
   present as CONTAINED cards instead of edge-to-edge bands. Dormant in
   themes that never emit layout classes — full-bleed stays the default. */
.layout--centered .lemma-block-hero,
.layout--centered .lemma-block-section,
.layout--centered .lemma-block-cta {
  max-width: var(--container);
  margin-inline: auto;
  border-radius: var(--radius-lg);
}
.layout--centered .lemma-block-image--full img { border-radius: var(--radius); }
```

- [ ] **Step 3: Full render + annotation suites green**

`vendor/bin/phpunit tests/Integration/Render/` — `PreviewAnnotationTest` (wrapper injection), seed tests, session tests. The admin bridge suite is CSS-independent but run `pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts` as insurance (templates kept edit regions byte-identical).

- [ ] **Step 4: Browser pass (the real gate)** — user reviews the seeded Test page: light + dark, `layout: full` + centered, desktop + mobile, canvas still selects/edits/drag/patches normally.

---

### Task 3: Docs, gates, STAGE

- [ ] **Step 1: README** (`packages/lemma-render/README.md`): update the theme description (modern-SaaS reference, namespaced shell classes) and document the two conventions:

```
The default templates honor two optional schema fields on any content
type: `show_title` (boolean — set false on block-built pages whose hero
owns the heading) and `layout` (`full` | `centered`, default `centered`)
which maps to a `layout--*` class on `<main>`; band blocks render
edge-to-edge under `full` and as contained cards under `centered`.
```

Also note in the blocks paragraph: the starter look is modern-SaaS; existing sites' seeded DB templates are never overwritten (idempotent seeder) — re-seed on a fresh site or delete overrides to adopt the new templates.

- [ ] **Step 2: CHANGELOG** `[Unreleased]`:

```
- Modern default theme: the reference theme is now a polished modern-SaaS
  site — fluid type scale, spacing/radius/color tokens with automatic dark
  mode, sticky translucent header, namespaced shell classes
  (.site-header/.site-nav/.site-footer — block templates' own
  header/nav/footer elements no longer inherit shell styling), full-width
  flow with per-block containers, and a restyled starter block library
  (full-bleed gradient hero, panel CTA, section bands, card columns, pull
  quote, cover-fit gallery). Two new template conventions: `show_title`
  (boolean) hides the automatic page H1, and `layout` (`full`|`centered`)
  picks edge-to-edge bands vs contained cards per page. The homepage
  template now renders a blocks body through blocks() exactly like
  entry.twig (it previously printed the array through the scalar
  fallback). Existing sites' seeded DB templates are untouched.
```

- [ ] **Step 3: Full gates** — `vendor/bin/phpunit` (full), `composer run phpcs`, admin `pnpm vitest run` (insurance).

- [ ] **Step 4: STAGE (no commit)** — theme templates + both CSS files + PHP test file + README + CHANGELOG + spec + plan. STOP for the user's browser pass and "commit all".

---

## AMENDMENT: `_presentation` + theme settings (spec §5a — replaces the schema-field convention)

### Task 4: Server presentation layer

**Files:**
- Modify: `app/Content/Validation/FieldValidator.php` (extract/validate/reattach `_presentation`; the vocabulary: `show_title` bool, `layout` in {full, centered})
- Modify: the content-type schema validation path (reject field names starting `_` on create/update — locate via ContentTypeRepository/schema validator)
- Create: `packages/lemma-render/src/PresentationResolver.php` (chain: entry `_presentation` → theme.json `settings.types.{slug}` → `settings` → built-ins; theme.json read through the active/session theme's dir)
- Modify: `packages/lemma-render/src/ThemeLocator.php` or the controller wiring to expose the active theme dir/settings
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php` (compose `presentation` into template context on every render; entry-less pages compose with no override)
- Modify: theme.json validation (strict `settings` block: only `show_title`/`layout`/`types` map of the same; unknown keys → ThemeConfigError)
- Modify: the delivery/public shapers (strip keys starting `_` from public fields payloads)
- Modify: `layout.twig`/`entry.twig`/`index.twig` (read `presentation.*`, drop `entry.fields.show_title|layout` reads)
- Test: `tests/Integration/Render/RenderPipelineTest.php` (REWRITE the three convention tests to `_presentation` + chain precedence + delivery strip + reserved-name rejection + theme.json settings validation)

### Task 5: Canvas "Page" tab

**Files:**
- Modify: `admin/src/pages/content/[type]/[uuid]/design/[locale].vue` (left panel → tabs: Content = FieldEditor as-is; Page = tri-state "Show page title" + "Layout" selects writing `fields._presentation`; "Theme default" DELETES the key)
- Test: `admin/src/__tests__/canvas-page.spec.ts` (tab renders; edits set/clear `_presentation`; changes mark stale → auto-apply fires)

### Task 6: Docs + gates + stage refresh

- README: replace the schema-field convention paragraph with the `_presentation`/theme-settings contract (incl. the reserved `_` key policy and the delivery-strip guarantee).
- CHANGELOG: rework the conventions sentence in the modern-theme bullet.
- Full gates; re-stage everything.
