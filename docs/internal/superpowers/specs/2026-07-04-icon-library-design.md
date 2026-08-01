# Icon Library (`icon()` Twig function) — Design

**Date:** 2026-07-04
**Status:** Draft for review

## Goal

Give templates a first-class icon vocabulary: one sandbox-safe Twig function that
inlines vendored SVG icons server-side — Lucide as the default design-system set,
a curated Simple Icons subset as an explicit `brand:` namespace — with the
features block as the first schema-integrated consumer.

## Pinned contract

1. **Single `icon(name)` Twig function.** No `brand_icon()`; one entry in the
   sandbox allowlist, one mental model.
2. **Default namespace = Lucide; explicit `brand:` namespace = curated Simple
   Icons.** `icon('star')` → Lucide; `icon('brand:github')` → Simple Icons.
3. **Strict name grammar:** `^(brand:)?[a-z0-9-]+$`. Anything else → `null`.
4. **Fixed render-pack asset roots only:**
   `packages/lemma-render/resources/icons/lucide/` and
   `packages/lemma-render/resources/icons/brands/`. The name maps to
   `{root}/{set}/{name}.svg` — the grammar admits no dots or slashes, so
   traversal is impossible by construction.
5. **Unknown or invalid name returns `null`** so templates degrade to text:
   `{{ icon(item.icon) ?? item.icon }}`.
6. **Injection only, no rewriting:** the opening `<svg` tag gains
   `class="lemma-icon"` (appended if a class attribute already exists — Lucide
   ships `class="lucide lucide-{name}"`) and `aria-hidden="true"`. Nothing else
   in the vendored markup is touched.
7. **No runtime sanitizer.** Vendored SVGs are reviewed once at vendoring time
   (no `<script>`, no event handlers, no external refs) and version-pinned in
   `packages/lemma-render/resources/icons/VENDORED.md`.
8. **`TemplatePolicy::FUNCTIONS` += `icon`; `CACHE_VERSION` 5 → 6.** The bump
   exists because DB templates may start calling `icon()` — filesystem
   templates don't go through TemplatePolicy; the compile-cache key
   (`db:{theme}:{path}:{version_uuid}:policy:{CACHE_VERSION}`) is what forces
   already-compiled DB templates to recompile and re-lint under the new
   allowlist.
9. **Feature block `icon` field is Lucide-only** (no `brand:` prefix in its
   pattern).
10. **A future social-links block uses only `brand:*`.** Out of scope here;
    the namespace exists so it needs no new machinery.
11. **`icon()` returns `?\Twig\Markup` (no `is_safe` flag)** so mixed
    expressions like `icon(x) ?? x` render the SVG raw and the fallback
    escaped — see Twig wiring.
12. **Brand SVGs are normalized to `fill="currentColor"` at vendoring time**;
    exact brand color is theme CSS, not the asset — see Vendored assets.

## Architecture

### `IconSet` (new, render pack)

`packages/lemma-render/src/Templates/IconSet.php` — a small `final` class that
owns resolution, loading, injection, and memoization:

```php
final class IconSet
{
    /** @var array<string, string|null> per-process memo: name → decorated SVG (null = known miss) */
    private array $memo = [];

    public function __construct(private readonly string $root) {} // .../resources/icons

    /** Decorated inline SVG for a valid, existing icon; null otherwise. */
    public function svg(string $name): ?string;
}
```

Resolution inside `svg()`:

1. Validate against `^(brand:)?[a-z0-9-]+$` (`preg_match`); fail → `null`
   (memoized, so repeated bad names don't re-regex).
2. Split namespace: `brand:` → `brands/` subdirectory, default → `lucide/`.
3. `is_file($root/$dir/$name.svg)`; miss → `null`.
4. Read, inject `class` + `aria-hidden` into the opening `<svg …>` tag
   (single regex replace on the first tag; append to an existing `class`
   attribute rather than adding a second one), memoize, return.

The memo is per-process (per-request under Apache/FPM) — a page renders a
handful of icons, repeated across items; no shared cache, no invalidation
surface. This mirrors the existing shared-singleton discipline: state that
would go stale across requests is not held.

### Twig wiring (render pack)

`RenderContextExtension` gains a constructor dependency and a function:

```php
private readonly ?IconSet $icons = null,   // soft-bound like the others
// …
new TwigFunction('icon', $this->icon(...)),   // NO is_safe — safety travels in the Markup value
// …
public function icon(?string $name): ?\Twig\Markup
{
    $svg = $name === null ? null : $this->icons?->svg($name);
    return $svg === null ? null : new \Twig\Markup($svg, 'UTF-8');
}
```

**Escaping discipline (P1, pinned): `icon()` returns `?\Twig\Markup`, not an
`is_safe`-flagged string.** Rationale — the canonical call site mixes trusted
and untrusted branches:

```twig
{{ icon(data.icon) ?? data.icon }}
```

With `is_safe: html`, Twig's *compile-time* safety analysis intersects the
branches, marks the whole expression unsafe, and double-escapes the SVG — and
any pattern that instead raw-marks the expression (`|raw`, or piping the
fallback through it) lets a legacy icon value like `<img onerror=…>` become
markup. `Markup` moves the decision to *runtime, per value*: the auto-escaper
passes `Markup` instances through untouched and escapes plain strings, so in
the same expression the vendored SVG renders raw while the legacy-text
fallback is always escaped. This also survives `{% set %}` assignment and
being passed through includes — safety travels with the value, not the
expression. Trusting the SVG at all is justified by contract items 6–7: the
markup is vendored and reviewed; the untrusted name only selects a file
through the strict grammar.

Construction happens where the extension is built in
`LemmaRenderServiceProvider` (`new IconSet(dirname(__DIR__, 2) . '/resources/icons')`
resolved from the pack root — this is pack-internal furniture, NOT an app
soft-binding: no contract interface, no `$container->has()`, because there is
no app-side implementation to swap in. The nullable parameter simply keeps
existing direct constructions in tests compiling.

### Vendored assets

```
packages/lemma-render/resources/icons/
├── VENDORED.md          # upstream versions, refresh procedure, review checklist
├── lucide/              # FULL set (~1,600 files, ~1.5 MB) from the pinned lucide release
│   ├── arrow-right.svg
│   └── …
└── brands/              # curated subset (~30 files) from the pinned simple-icons release
    ├── github.svg
    └── …
```

- **Lucide: the full set.** Disk is cheap; "my icon is missing" support noise
  is not. Files are kept byte-identical to upstream (`icons/*.svg` from the
  release tarball) so refreshes are clean diffs. Lucide already uses
  `stroke="currentColor"` natively — no normalization.
- **Brands: curated 27.** github, gitlab, bitbucket, google, apple, x,
  facebook, instagram, youtube, tiktok, discord, whatsapp, telegram, reddit,
  pinterest, twitch, spotify, snapchat, threads, bluesky, mastodon, vimeo,
  medium, dribbble, behance, figma, stackoverflow. (`linkedin`, `slack` and
  `microsoft` were on the draft list but are removed from Simple Icons
  upstream at the brand owners' request — deliberately absent, recorded in
  `VENDORED.md`.) Simple Icons files ship
  `role="img"` + a `<title>Brand</title>`; both are kept — `aria-hidden` is
  injected at render, and a hidden title is harmless.
- **Brand color normalization (P2, pinned): brand SVGs get
  `fill="currentColor"` on the root `<svg>` at vendoring time.** Simple Icons
  ships single-path SVGs with **no** fill attribute (rendering as fixed black
  by SVG default) and brand colors only as package metadata — not
  `currentColor`. Normalizing at import makes brand icons behave exactly like
  Lucide ones in buttons and social rows (inherit text color, invert in dark
  mode). Any fixed `fill`/`stroke` values found on a curated file are removed
  in the same step. **Exact brand color is theme CSS, not the SVG asset** —
  a theme that wants GitHub-black or LinkedIn-blue sets `color` on the
  element (documented in `VENDORED.md`). This is the one deliberate deviation
  from byte-identical vendoring; `VENDORED.md` records the normalization rule
  so refreshes reapply it, and the regression scan enforces it.
- **`VENDORED.md`** records: exact upstream versions (pinned at implementation
  time from the latest releases), source URLs, the curation list, the
  vendoring-time review checklist (no scripts / handlers / external hrefs /
  foreignObject), and the refresh procedure. Licenses: Lucide ISC,
  Simple Icons CC0 (brand marks remain trademarks of their owners — usage
  responsibility sits with the site operator, noted in the file).
- Vendoring is a one-time copy from pinned GitHub release tarballs during
  implementation — no build step, no npm, nothing at runtime.
- **Strict curation (review pin): the import fails on any requested curated
  brand missing upstream.** A slug typo must break vendoring loudly, never
  silently ship a smaller social set. Removing a brand is a deliberate edit to
  the curated list (spec + `VENDORED.md` + import list together, same patch).
  A regression test asserts the shipped `brands/` set equals the `VENDORED.md`
  curated list exactly. Runtime stays lenient (`null` for unknown names);
  vendoring is strict.

### Sizing CSS

`blocks.css` (the standalone, copy-one-file starter sheet) gains:

```css
.lemma-icon { width: 1em; height: 1em; display: inline-block; vertical-align: -0.125em; }
```

`width`/`height="24"` presentation attributes on the vendored SVGs lose to CSS
rules, so icons scale with the surrounding font size and inherit `currentColor`
(both sets use it natively — Lucide via `stroke`, Simple Icons via `fill`
defaulting to the injected context). The feature block adds its own
`.lemma-block-feature__icon svg { … }` sizing where the design wants icons
larger than text.

## Features block integration

The `feature` child block **already has** an `icon` string field (free text,
rendered today as raw text in a `<span>` — which is how emoji "icons" work).
Changes:

1. **Schema** (`StarterBlockTypes`, `feature` definition): the `icon` field
   gains `'pattern' => '[a-z0-9]+(-[a-z0-9]+)*'` — Lucide-only (no `brand:`),
   and structurally excludes emoji/free text **for newly seeded installs**.
2. **Template** (`themes/default/templates/blocks/feature.twig`): render
   `icon(data.icon)` when it resolves, else fall back to the raw text exactly
   as today:

   ```twig
   {% if data.icon %}<span class="lemma-block-feature__icon" aria-hidden="true">{{ icon(data.icon) ?? data.icon }}</span>{% endif %}
   ```

   The null fallback keeps every existing install working unchanged: emoji and
   free-text icons keep rendering as text, valid Lucide names upgrade to SVG.
3. **Seeder reach:** `lemma:blocks:seed` is skip-if-exists by design, so the
   pattern reaches new installs only. Existing installs keep their
   unconstrained field — acceptable because the template's fallback makes any
   value render sensibly. (Dev DBs can be hand-updated; not part of this
   change.)

No other starter block changes. Blocks that later want icons (steps, buttons)
adopt the same field shape in their own passes.

## TemplatePolicy & cache

- `FUNCTIONS` gains `'icon'` (alphabetical position next to existing entries).
- `CACHE_VERSION = 6`, comment updated:
  `// bumped: 'icon' joined FUNCTIONS (icon-library spec)`.
- Rationale (pinned correction): the version is the DB-template compile-cache
  invalidator. Filesystem theme templates never pass through the policy;
  sandboxed **DB** templates do, and ones compiled under version 5 would
  reject `icon()` until recompiled. The bump re-keys every DB template's
  compile cache so the next render recompiles — and re-lints — against the
  new allowlist.

## Error handling summary

| Input | Result |
| --- | --- |
| `icon('star')`, file exists | `\Twig\Markup` (decorated SVG) |
| `icon('brand:github')`, file exists | `\Twig\Markup` (decorated SVG) |
| `icon('no-such-icon')` | `null` |
| `icon('brand:no-such')` | `null` |
| `icon('Star')`, `icon('a/b')`, `icon('brand:../x')`, `icon('')` | `null` (grammar) |
| `icon(null)` / non-string | `null` (nullable string param; Twig passes null through) |
| IconSet not wired (direct extension construction in old tests) | `null` (soft null default) |
| `{{ icon('<img onerror=…>') ?? '<img onerror=…>' }}` | grammar rejects the name → fallback string is auto-escaped (Markup discipline) |

Never throws; never logs per-render (a missing icon is a content choice, not
an error).

## Testing

Unit — `IconSet` (new test, constructs directly against the real resources dir):
- valid Lucide name → SVG string containing `class="…lemma-icon"` and
  `aria-hidden="true"`, starts with `<svg`.
- valid brand name → same, from `brands/`.
- unknown name, bad grammar (uppercase, slash, dot, `brand:` alone, empty,
  traversal attempts) → `null`.
- memo behavior: second call for a known miss returns `null` without touching
  disk (observable via a nonexistent-root construction after priming — or
  simply asserted by contract, not instrumented).

Integration — render:
- a DB template calling `icon('check')` compiles under the new policy and
  renders the SVG (extends the existing representative-template policy test).
- `feature.twig`: item with `icon: 'zap'` renders inline SVG; item with
  `icon: '✓'` renders the raw text fallback; item without icon renders no span.
- **escaping discipline:** an item with `icon: '<img src=x onerror=alert(1)>'`
  renders the value HTML-escaped (grammar rejects the name; the Markup
  discipline leaves the string fallback to the auto-escaper) — the rendered
  page contains no `<img` tag.

Pins:
- `TemplatePolicy::CACHE_VERSION === 6` wherever the existing suite pins it.
- Vendored-tree sanity test: every shipped SVG file passes the review
  predicate (no `<script`, no `on*=` attributes, no `href="http`,
  no `<foreignObject`) — turns the vendoring-time review into a regression
  gate that also covers future refreshes.
- Brand normalization: every file under `brands/` carries
  `fill="currentColor"` on the root `<svg>` and no other fixed
  `fill`/`stroke` values (same regression test).

## Out of scope (explicit)

- **Social-links block** — next pass; uses `brand:*` only, per contract.
- **Admin icon picker / live preview** in the block editor (the admin already
  has Lucide via `i-lucide-*`, so a preview chip is cheap later — but it is
  its own UX pass).
- **Icons in other starter blocks** (steps, button, cta).
- **Per-site custom icon uploads** — different trust model entirely (would
  need the runtime sanitizer this design deliberately avoids).
- **Backfilling the pattern constraint into existing installs' `feature` type.**

## Files touched

| File | Change |
| --- | --- |
| `packages/lemma-render/src/Templates/IconSet.php` | new |
| `packages/lemma-render/src/RenderContextExtension.php` | `?IconSet` dep + `icon()` function |
| `packages/lemma-render/src/LemmaRenderServiceProvider.php` | construct `IconSet` where the extension is built |
| `packages/lemma-render/src/Templates/TemplatePolicy.php` | `FUNCTIONS` += `icon`; `CACHE_VERSION = 6` |
| `packages/lemma-render/resources/icons/**` | vendored Lucide (full) + brands (curated) + `VENDORED.md` |
| `packages/lemma-render/themes/default/assets/blocks.css` | `.lemma-icon` sizing |
| `app/Content/Blocks/StarterBlockTypes.php` | `feature.icon` gains Lucide pattern |
| `packages/lemma-render/themes/default/templates/blocks/feature.twig` | `icon(data.icon) ?? data.icon` |
| tests | `IconSetTest` (new), policy/representative-template extension, feature.twig render cases, vendored-tree sanity |
