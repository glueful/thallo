# Style block (Feature C) — Design

> Third and final spec of the theming trilogy (A = color mode, B = theme color
> config, C = this). A completes the global light/dark axis; B the global
> accent/neutral appearance; C brings the *same* accent/neutral re-skin down to a
> **scoped subtree** as an authorable block, plus a custom-CSS class hook.

**Status:** approved (brainstorm), pending spec review.
**Date:** 2026-07-08.

---

## 1. Summary

A new server-seeded block type — **slug `style`, label "Style", category Layout** —
that wraps a child block list and re-skins that subtree's design tokens. The
operator picks an **accent** family and/or a **neutral** family (the same closed
Tailwind enums as Spec B, sourced from `ThemeColors`), and may attach a **class
hook** for `custom.css`. The re-skin **follows the global color mode**: it emits
both light and dark scoped token values, switched by the existing `data-theme`.

The block's job is exactly one thing: *scoped palette re-skin + class hook + wrap
children*. Backgrounds/overlay/width/padding remain the `container` block's job.

**Out of scope (deliberately):**
- Freeform per-token color inputs (raw `--bg`/`--ink`/… editing) — closed families only.
- A scoped force-light/force-dark override — the wrapper always follows the global mode.
- Swatch-select admin polish — v1 dogfoods the generic schema-driven block editor
  (plain selects); swatches are a later, optional nicety.
- Template/theme presets/variants — a separate future feature.

---

## 2. Pins (authoritative constraints)

Every task inherits these:

1. **Naming (fixed vocabulary):**
   - Block slug `style`, label "Style", template `packages/thallo-render/themes/default/templates/blocks/style.twig`.
   - Root CSS class `thallo-block-style`, inner `thallo-block-style__inner`.
   - Stored hook field name **`class_hook`** (not `class`).
   - Rendered hook class(es): **`thallo-style-{hook}`** — one per sanitized token.
   - Generated scope class (implementation-facing): **`thallo-skin-{accent}-{neutral}`**,
     where an unset dimension is the literal `none` (e.g. `thallo-skin-rose-none`,
     `thallo-skin-none-slate`, `thallo-skin-rose-slate`).
   - Public Twig helper **`theme_style_scope(accent, neutral)`** → `{ class, style }`.
     No `skin` vocabulary is exposed to template authors.
   - Twig filter **`style_hook`** (sanitize + namespace-prefix).
2. **Enum single source of truth + explicit inherit.** Accent options are
   `['inherit', ...ThemeColors::ACCENTS]` (17 + sentinel), neutral options
   `['inherit', ...ThemeColors::NEUTRALS]` (5 + sentinel). `StarterBlockTypes`
   references those constants; B and C never drift. The leading **`inherit`**
   sentinel is how an editor returns a dimension to unset — the current generic
   `EnumField` (`<USelect>` over `field.enum`) has **no clear/placeholder path**, so
   without an explicit option a picked `rose` could never go back to inherit.
   `inherit` is not a Tailwind family, so `normalizeAccent/normalizeNeutral` already
   fold it (and any unknown) to unset — no special-casing in the template. (The
   cleaner long-term fix — making optional enum fields generically clearable — is a
   broader admin change deferred out of C; see §9.)
3. **Scoped, partial emission:** accent and neutral are independent and each
   optional (`inherit`/absent/unknown = unset). Emit only the set dimension's vars.
   Neither set → emit nothing.
4. **No blue/slate fallback (scoped).** Unknown/invalid stored accent or neutral is
   treated as **inherit/unset** ("don't apply that override"), *not* coerced to the
   global default. (Contrast Spec B, where invalid → blue/slate because a page must
   have a global appearance. A wrapper has a safe do-nothing state.)
5. **Follows the global color mode.** The scoped CSS emits both a light rule
   (`.scope{…}`) and a dark rule (`html[data-theme="dark"] .scope{…}`); the shipped
   color-mode toggle switches between them. No new mode concept.
6. **Inline delivery.** Each `style` block emits its own `<style>` adjacent to its
   wrapper (not hoisted to `<head>`), so the block fragment stays self-contained and
   survives the visual canvas's partial-DOM patching. Identical accent/neutral pairs
   share one deterministic scope class, so repeated pairs dedupe by class even though
   the `<style>` text repeats.
7. **Class hook is never trusted raw.** Operator hook input is sanitized at **render
   time** by the `style_hook` filter regardless of any admin-side `pattern`; only
   safe CSS class tokens survive, each namespaced under `thallo-style-`.
8. **CSP unchanged.** The inline `<style>` reuses the `style-src 'unsafe-inline'`
   allowance already accepted for Spec B — no new CSP tradeoff.

---

## 3. Block type definition

Added to `app/Content/Blocks/StarterBlockTypes.php` (and seeded via
`SeedBlockTypesCommand` / `php glueful seed:block-types`):

```php
['slug' => 'style', 'label' => 'Style', 'icon' => 'i-lucide-palette',
    'category' => 'Layout',
    'description' => 'Re-skin a group of blocks with a chosen accent/neutral, '
        . 'plus an optional custom-CSS class hook.',
    'schema' => [
        ['name' => 'accent',  'type' => 'enum',
            'enum' => array_merge(['inherit'], ThemeColors::ACCENTS)],
        ['name' => 'neutral', 'type' => 'enum',
            'enum' => array_merge(['inherit'], ThemeColors::NEUTRALS)],
        ['name' => 'class_hook', 'type' => 'string',
            'pattern' => '[A-Za-z_][A-Za-z0-9_-]*( [A-Za-z_][A-Za-z0-9_-]*)*'],
        ['name' => 'content', 'type' => 'blocks'],
    ]],
```

Notes:
- `accent`/`neutral` are **optional** (not `required`). The leading `inherit` option
  is the editor's explicit "unset" (pin 2 — the generic `EnumField` has no clear
  path). `inherit`, absent, empty, and any unknown value all normalize to unset via
  `ThemeColors::normalizeAccent/normalizeNeutral` (pin 4), so stale content can't
  emit a bogus var and a re-selected `inherit` cleanly drops the override.
- `class_hook`'s `pattern` is an admin-side nicety only; `style_hook` is the guard.
- `content` is a child block list — counts toward `BlockDepth::MAX` like any wrapper.
- Icon `i-lucide-palette` matches the Lucide set used by other block types.

`StarterBlockTypes` must `use Thallo\Render\Theme\ThemeColors;` (app already depends
on the render pack). Existing installs get the new type by reseeding; pre-launch, no
data migration.

---

## 4. Rendering

### 4.1 `ThemeColors` additions (pure, testable)

Two new public methods on `ThemeColors` (partial/scoped emission — pin 3):

```php
/** Deterministic scope class, or '' when neither dimension is set/valid. */
public static function skinClass(?string $accent, ?string $neutral): string;

/**
 * Scoped CSS re-skinning ONLY the set dimensions, following the global mode:
 *   .scope{ <light vars> } html[data-theme="dark"] .scope{ <dark vars> }
 * Returns '' when neither accent nor neutral resolves.
 */
public static function scopedCss(?string $accent, ?string $neutral, string $scopeClass): string;
```

- Both normalize inputs via `normalizeAccent`/`normalizeNeutral` first; unknown →
  treated as unset (pin 4).
- `skinClass('rose', null)` → `thallo-skin-rose-none`; `skinClass(null, 'slate')` →
  `thallo-skin-none-slate`; `skinClass(null, null)` → `''`.
- Accent contributes `--accent`, `--accent-ink`; neutral contributes the six neutral
  vars (`--bg`, `--surface`, `--surface-2`, `--ink`, `--muted`, `--line`). These
  already sit in separate tables, so partial emission is a straight split — factor the
  per-dimension var maps into small private helpers (`accentVars`, `neutralVars`)
  reused by both `scopedCss` and B's existing `tokens()`/`css()` (no behavior change to B).
- `scopedCss` omits the dark rule entirely if the dark var set would be empty (it
  never is when a dimension is set, but keep the emission minimal).

### 4.2 `theme_style_scope()` Twig function

New function on `RenderContextExtension` (sibling of B's `theme_colors_style`), registered
WITHOUT `is_safe` — it returns an array whose members carry their own safety. BOTH members
are `Twig\Markup`: `class` is enum-derived (closed families → safe by construction), so it
is emitted directly rather than relying on autoescape happening to be a no-op (review P2a).

```php
public function themeStyleScope(?string $accent, ?string $neutral): array
{
    $class = ThemeColors::skinClass($accent, $neutral);   // '' or thallo-skin-…
    $css   = $class === '' ? '' : ThemeColors::scopedCss($accent, $neutral, $class);
    return [
        'class' => new \Twig\Markup($class === '' ? '' : ' ' . $class, 'UTF-8'),
        'style' => new \Twig\Markup($css === '' ? '' : "<style>{$css}</style>", 'UTF-8'),
    ];
}
```

### 4.3 `style_hook` Twig filter

New filter on `RenderContextExtension`, backed by a pure static helper (testable in
isolation). Sanitizes and namespaces operator hook input:

- Split on whitespace; keep only tokens matching `^[A-Za-z_-][A-Za-z0-9_-]*$`.
- Strip an already-present `thallo-style-` prefix before re-prefixing (idempotent —
  no `thallo-style-thallo-style-x`).
- Prefix each surviving token with `thallo-style-`.
- Return `' ' . implode(' ', $tokens)` (leading space) or `''` if nothing survives.

This is the security net: an operator value like `"><script>` yields `''`; it can
never break out of the class attribute.

### 4.4 `blocks/style.twig`

```twig
{# style — re-skins its subtree's design tokens (accent/neutral) and wraps a child
   block list, plus an optional custom-CSS class hook. Follows the global color mode:
   the scoped <style> carries both light and dark var sets, switched by data-theme.
   Fields (all optional except content): accent, neutral, class_hook, content.
   The <style> is rendered LAST (after __inner) — see the canvas-host pin below. #}
{% set scope = theme_style_scope(data.accent|default(''), data.neutral|default('')) %}
<div class="thallo-block thallo-block-style{{ scope.class }}{{ data.class_hook|default('')|style_hook }}">
  <div class="thallo-block-style__inner">{{ blocks(data.content) }}</div>
  {{ scope.style }}
</div>
```

- `scope.class` is `''` (no re-skin) or ` thallo-skin-…`; `data.class_hook|style_hook`
  is `''` or ` thallo-style-…`. Both leading-space, so the attribute stays clean even
  when both are empty (`class="thallo-block thallo-block-style"`).
- The `<style>` sits *inside* the wrapper (self-contained for canvas partial-patching)
  but **after `__inner`**; the scope class is on the wrapper, so the vars cascade to all
  descendants regardless of `<style>` position. Nested `style` blocks cascade naturally
  (deeper/inner class wins).
- **Canvas-host pin (review P1):** the canvas bridge anchors the selection toolbar to a
  block's *first element child* (`w.firstElementChild`). A leading `<style>` would make
  the toolbar attach to a non-rendering element. Two defenses: (a) the template renders
  `<style>` LAST so `__inner` is the first element child; (b) the bridge's host
  resolution skips non-visual elements (`STYLE`/`SCRIPT`/`LINK`/`TEMPLATE`) via a
  `firstVisualChild()` helper, so the invariant holds independent of template order.

### 4.5 `blocks.css`

Minimal base rule for the wrapper (layout-neutral — it must not fight the container it
may sit in):

```css
.thallo-block-style { /* no imposed spacing/paint; it only re-scopes tokens */ }
.thallo-block-style__inner { }
```

The block deliberately paints nothing itself; it only redefines CSS custom properties
that descendant blocks already consume. (Any base rule is presentational glue only.)

---

## 5. What comes for free

Because the accent/neutral/`class_hook` live in the **page's block tree**, not in a
global setting, three subsystems need *no* new work — this is the payoff of the
block-content model over Spec B's global-setting model:

- **Preview.** The existing content preview (session working-copy overlay) already
  renders the draft block tree, so `style`-block edits preview with zero new
  machinery — no preview token, no signed payload, no request-local override
  (contrast B, which needed all three because its values were global settings).
- **Caching.** The render page cache is **path-based, not content-hashed** — it does
  *not* automatically vary when a page's skin changes. What makes C free is that
  `style` values are ordinary *published block content*: publishing a page runs the
  **existing** content/publish invalidation, which purges that page's cached render
  (by path/tag) exactly like any other block edit. So C adds **no** appearance
  fingerprint, purge listener, or `ThemeAppearanceChanged`-style event — it inherits
  page invalidation for free. (Plan requirement: a test asserting that publishing a
  page with changed `style` content purges its render cache via the existing path.)
- **Admin editor.** The block editor is schema-driven: `accent`/`neutral` render as
  selects (options from the seeded enum), `class_hook` as a validated text field
  labeled "Class hook". No bespoke Vue.

The genuinely new surface is the render pack: `ThemeColors` gains two methods,
`RenderContextExtension` gains a function + a filter, the theme gains one template, and
the canvas `preview-bridge.js` gains a one-line host guard (review P1).

---

## 6. Error handling & edge cases

| Case | Behavior |
|---|---|
| accent + neutral both empty/invalid | `theme_style_scope` returns empty class + empty style; block still wraps children and still applies the class hook. |
| Only accent set | Emit `--accent`, `--accent-ink` (light + dark); scope class `thallo-skin-{accent}-none`. |
| Only neutral set | Emit the six neutral vars (light + dark); scope class `thallo-skin-none-{neutral}`. |
| `inherit` selected, or unknown stored enum (e.g. `banana`) | Both normalize to unset — that dimension inherits; no bogus var, no log (scoped = silent no-op, unlike B's logged global fallback). |
| Malicious/invalid `class_hook` | `style_hook` drops non-matching tokens; `"><script>` → `''`. No attribute breakout. |
| Operator pre-typed `thallo-style-promo` | Idempotent prefix strip → renders `thallo-style-promo`, not doubled. |
| Nested `style` blocks | Inner scope class cascades over outer; both `<style>` blocks emit; correct by CSS document order/specificity. |
| Same accent/neutral pair used N times on a page | One shared scope class; the `<style>` text repeats but is byte-identical and idempotent (acceptable v1; a page-level dedupe is possible later). |
| Canvas selection of a `style` block (review P1) | Toolbar anchors to `__inner` (first element child), never the block's `<style>`; the bridge's `firstVisualChild()` skips `STYLE`/`SCRIPT`/`LINK`/`TEMPLATE` so the invariant holds regardless of child order. |

---

## 7. Testing strategy

- **Unit — `ThemeColors`:**
  `skinClass` for accent-only / neutral-only / both / neither (determinism + `none`
  segments). `scopedCss` for each of those: contains `.thallo-skin-…{` light block and
  `html[data-theme="dark"] .thallo-skin-…{` dark block; emits only the set dimension's
  vars; `''` when neither set; unknown enum → treated as unset.
- **Unit — `style_hook` helper:** strips `"><script>`, quotes, and invalid tokens;
  keeps valid multi-token input; namespaces each with `thallo-style-`; idempotent on a
  pre-prefixed token; empty/whitespace → `''`.
- **Integration — render:** a page with a `style` block (accent + neutral) renders the
  wrapper carrying `thallo-block-style thallo-skin-…`, exactly one `<style>` with light
  + dark rules, children inside `__inner`; a `class_hook` value lands as
  `thallo-style-…`; a nested `style` block emits its own scoped `<style>`.
- **Integration — seed:** the `style` block type is present after seeding with the
  `accent`/`neutral`/`class_hook`/`content` schema; the accent/neutral enum options
  equal `['inherit', ...ThemeColors::ACCENTS]` / `['inherit', ...::NEUTRALS]`.
- **Integration — cache/publish (P2):** publishing a page whose `style` block content
  changed purges that page's render cache through the **existing** content/publish
  invalidation path — asserting C needs no new cache code and that a stale skinned
  render is not served after republish.
- **Canvas host (P1):** a bridge test — a block whose first element child is a `<style>`
  anchors the selection toolbar to the next visual element (`__inner`), not the `<style>`.
- **Admin:** the generic block editor renders the three fields for the `style` type
  (schema-driven; no new component test beyond confirming the type appears with its
  fields, if an existing block-type editor test already covers the mechanism). The
  `inherit` option is selectable and round-trips a picked family back to unset.

---

## 8. File map

**Modify:**
- `packages/thallo-render/src/Theme/ThemeColors.php` — add `skinClass`, `scopedCss`
  (+ private `accentVars`/`neutralVars` refactor; no behavior change to `tokens`/`css`).
- `packages/thallo-render/src/RenderContextExtension.php` — register `theme_style_scope`
  function and `style_hook` filter; add the backing methods + pure hook-sanitizer helper.
- `app/Content/Blocks/StarterBlockTypes.php` — add the `style` block type (uses
  `ThemeColors::ACCENTS`/`::NEUTRALS`).
- `packages/thallo-render/themes/default/assets/blocks.css` — minimal base rules for
  `.thallo-block-style` / `__inner`.
- `packages/thallo-render/assets/preview/preview-bridge.js` — add `firstVisualChild()`
  and route the four host lookups through it, so a block-owned `<style>` is never the
  canvas host (review P1).
- `packages/thallo-render/docs/THEMING.md` — document the `style` block (§10).

**Create:**
- `packages/thallo-render/themes/default/templates/blocks/style.twig`.
- Tests: a `ThemeColors` scoped-emission test, a `style_hook` filter test, a render
  integration test for the `style` block, and a seed assertion (extend the existing
  block-type seed test if present).

**Free (no change):** preview pipeline, render page/error cache, admin block editor.

---

## 9. Decisions deferred to the implementation plan

- Exact factoring of `accentVars`/`neutralVars` out of B's existing `tokens()`/`css()`
  (keep B's output byte-identical; assert via B's frozen-default test).
- Whether `theme_style_scope` returns a plain array or a tiny readonly VO (array is
  fine; VO only if the template ergonomics want typed access).
- Whether to add a page-level `<style>` dedupe for repeated pairs (v1: no; the repeat
  is idempotent and small).

**Deferred out of C (not this plan):** making the generic `EnumField` optionally
clearable (placeholder + a clear affordance) so *any* optional enum block/content
field can return to unset without a sentinel. C uses the explicit `inherit` option
(pin 2) instead — self-contained and no cross-cutting admin change. If the generic
clearable path lands later, the `inherit` sentinel can stay (it still normalizes to
unset) or be dropped in a follow-up.
