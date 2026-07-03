# Canvas v4: Editable String Fields (`|editable_text`) — Design

**Date:** 2026-07-03
**Status:** Approved design, pending implementation
**Builds on:** canvas v3 edit-in-place (`2026-07-03-canvas-edit-in-place-design.md`)

## 0. Summary

Themes opt string and plain-text fields into edit-in-place with a Twig filter:
`{{ data.heading|editable_text('heading') }}`. In annotated renders the filter
emits a marked `<span>` region; in live renders it emits exactly the escaped
value. The v3 edit-session machinery (grant protocol, debounce, flush,
patch-through-`BlocksField`) is reused wholesale; the protocol unifies so
`edit-request` carries `{id, field}` and `edit-grant` carries a parent-decided
`kind`. The parent's grant matrix is the sole authority — templates control
WHERE, the schema controls WHAT.

Decision pins from brainstorm review:

- **Field kinds:** `string` (single-line; Enter commits-and-exits) and plain
  `text` (multiline; Enter inserts a newline, `innerText` preserves `\n`).
  Rich text stays prose-convention-only via `safe_html` — `editable_text` is
  never the right tool for a rich field. A theme that misuses it there is
  caught structurally, not silently: alongside the `safe_html` region it
  creates a SECOND region for the same (block, field), so the one-region rule
  refuses to edit; without `safe_html` the filter renders the escaped raw
  HTML source — visibly wrong in every render. Documented as a theme-author
  contract, like attribute misuse.
- **Interactive elements:** regions inside `<a>`/`<button>` (CTA labels) are
  included — canvas clicks are already inert, and `<span>` is valid phrasing
  content there.
- **Filter name (review pin):** `editable_text`, not `editable` — the name
  scopes it to text and avoids implying enum/media/number support.
- **Self-escaping (review pin):** the filter ALWAYS HTML-escapes the value
  itself, in both modes — it is registered `is_safe: ['html']` (it emits
  markup in annotated mode), so Twig autoescape is off and must never be
  relied on.
- **No kind in the DOM (review pin):** regions carry only
  `data-lemma-edit-block` and `data-lemma-edit-field`. The bridge behaves per
  the parent's GRANT kind, never per DOM guesswork.
- **Empty values (review pin):** annotated mode emits an EMPTY span (editors
  can click into blank CTA labels/headings wherever the template rendered the
  field location); live mode emits the empty string. Conditional template
  blocks (`{% if data.caption %}`) still suppress absent regions — correct:
  first-fill happens in the inspector.
- **`plaintext-only` best-effort (review pin):** commits read `innerText`, so
  pasted markup can never persist regardless of browser support.

## 1. The `|editable_text` filter (renderer)

Registered in `RenderContextExtension` with `is_safe: ['html']`:

- **Annotated mode** (same triple gate as v3 marking: `$annotateBlocks` on, a
  block frame current, frame id a string):
  `<span class="lemma-edit-region" data-lemma-edit-block="{id}"
  data-lemma-edit-field="{name}">{htmlspecialchars(value)}</span>`
  — both attributes and the value escaped by the filter itself.
- **Live mode / no frame:** `htmlspecialchars(value)` alone — byte-identical
  to what `{{ data.heading }}` renders today.
- Non-string values (null, arrays) render as `''` (empty span when
  annotated). The field NAME is escaped too but not validated server-side —
  the filter trusts the template; the parent grant matrix (§4) is the
  validator, so a bogus name yields a region that can never be granted.
- **Attribute misuse is the theme author's contract**: applying the filter
  inside an HTML attribute produces visibly broken markup in preview (the
  span lands inside the attribute) and clean output in live. Documented with
  `image.twig`'s `alt` as the counter-example. `<span>` is phrasing content,
  valid inside `h1/p/a/button/figcaption`.
- New static CSS so empty regions are clickable:
  `.lemma-edit-region:empty { display: inline-block; min-width: 3ch; min-height: 1em; }`
  (preview.css — static rules only, CSP pin).

## 2. Unified protocol

One breaking-shape change, both sides ship together (the `?v=` cache-buster
makes that safe):

- `lemma:edit-request {id, field}` — the bridge resolves the double-clicked
  region via `closest('.lemma-edit-region')` (scoped inside the wrapper) and
  reads its `data-lemma-edit-field`. Double-click inside the wrapper but
  outside any region: if the wrapper contains EXACTLY ONE region, that one is
  used (preserves v3's whole-block prose feel); otherwise no request.
- `lemma:edit-grant {id, field, kind}` with `kind: 'rich' | 'string' | 'text'`
  — parent-decided (§4). The bridge activates per grant kind only.
- **One-region rule is per (block, field)**: exactly one region matching both
  attributes, or no editing (fail-safe, unchanged spirit from v3).
- `lemma:text-changed {id, field, html?}` for rich (unchanged) and
  `{id, field, text?}` for string/text — the parent patches whichever the
  re-validated kind dictates.
- `edit-flush`/`edit-flushed`, Escape/blur commit, toolbar detach, clone
  stripping, and the mirror-duplicate `data-lemma-edit-block` idMap rewrite:
  all unchanged from v3 (the rewrite already covers the new spans — same
  attribute).

## 3. Bridge session per kind

- `rich`: exactly v3 — `contenteditable="true"`, commit `innerHTML`.
- `string` / `text`: `contenteditable="plaintext-only"` where supported,
  `"true"` fallback (best-effort pin); commit reads `innerText` (markup can
  never persist; newlines survive as `\n` for `text`).
- Enter: `string` → commit-and-exit (single-line convention); `text` →
  default newline behavior.
- Everything else (debounce 400ms, blur/Escape, flush participation, editing
  ring class, caret-at-point) identical to v3.

## 4. Parent grant/patch matrix (the authority)

One helper replaces `proseFieldOf`:

`editableKindOf(id, field): 'rich' | 'string' | 'text' | null`

- Look up the block's type slug (`blockTypeOfBlock`) and its schema field by
  name; deny (`null`) for unknown block/type/field.
- Schema field `type text, format rich` AND the type matches the prose
  convention (`proseRichFieldName(type) === field`) → `'rich'`.
- Schema field `type string` → `'string'`.
- Schema field `type text` (non-rich) → `'text'`.
- Everything else (blocks, reference, media, enum, number, …) → `null`.

`edit-request` grants with the resolved kind; `text-changed` re-validates
with the SAME helper before `patchBlockDataById` (v3's
requests-not-authority pin, matrix-shaped): rich patches the `html` payload,
string/text patch the `text` payload; kind mismatch or `null` → ignored.
Plain values are patched as raw strings — `FieldValidator` at Apply/Save and
Twig escaping at render own safety, unchanged.

## 5. Starter theme adoption

| Template | Fields via `editable_text` | Notes |
| --- | --- | --- |
| `hero.twig` | `heading`, `subheading`, `cta_label` | cta_label inside `<a>`/`<span>` — included by pin |
| `section.twig` | `title` | |
| `quote.twig` | `text`, `attribution` | `text` is multiline plain text |
| `image.twig` | `caption` | `alt` stays UNFILTERED — attribute (the documented counter-example) |
| `cta.twig` | `heading`, `body`, `button_label` | `body` multiline |

`rich_text.twig` is untouched (prose marking via `safe_html`).

**Conditional emissions stay conditional (hard rule for the implementer):**
adoption means wrapping the VALUE expression inside the template's existing
structure — never restructuring the template around the filter. Concretely:

```twig
{% if data.cta_label %}
  …<a …>{{ data.cta_label|editable_text('cta_label') }}</a>…
{% endif %}
```

stays exactly this shape. Do NOT unwrap `{% if %}` guards into
always-rendered empty targets "so blanks are editable" — that changes the
theme's live layout (empty CTAs, dangling headings) for a canvas
convenience. The empty-span rule (§0) applies only where the template
ALREADY renders the field location unconditionally (e.g. `hero.heading`,
`cta.heading`); conditionally omitted fields are inspector-first by design.

## 6. Testing

- **PHP** (extension + starter render): annotated span shape with both
  attributes and escaped value (`<`/quotes in the VALUE and the field name);
  live mode emits exactly the escaped value (no span); empty value → empty
  span annotated / empty string live; no marking outside a block frame;
  starter templates carry the §5 regions in an annotated render and none
  live.
- **Bridge direct suite**: request carries the region's field;
  single-region fallback on wrapper-level double-click; per-(block, field)
  rule with two different-field regions in one block; grant kind drives
  behavior — string Enter commits-and-exits, text Enter newlines,
  `innerText` commit strips pasted markup; plaintext-only attribute
  attempted.
- **Vitest**: `editableKindOf` matrix (rich/string/text/deny incl. unknown
  field, non-prose rich, reference field); grant wiring per kind;
  text-changed deny cases (kind mismatch, unknown field) leave the tree
  untouched; kind-correct patches land.
- **Manual (recorded)**: editing inside the CTA `<a>`, multiline quote
  editing, blank-region click target, IME.

## 7. Out of scope (recorded follow-ups)

- Editable attributes (`alt`, `href`) — a different mechanism entirely.
- Enum/select, number, media fields in place.
- `editor_mode` metadata escape hatch.
- An overloaded general `|editable` filter family.
