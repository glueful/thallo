# Container Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One layout block, Container, replaces Columns, Grid and Section: flex and grid layout join the style contract, a Layout tab edits them, a new container offers a structure picker on the stage, and the three retired types leave once their compositions match frozen references.

**Architecture:** layout properties are ordinary managed properties (`settings.style`) on two container targets — `root` (the band) and `inner` (the content area) — plus item properties on the `root` of any block declaring `layout.item`. The compiler emits utilities into `@layer settings`, and the emitter writes layout classes at every breakpoint where they resolve, so each breakpoint's state is explicit in the markup and every rule that depends on breakpoint state is matched by an equal-specificity rule at every later breakpoint. Theme defaults (containment release, gutter initialisation, spacing normalization) live in `@layer theme`, so authored and class-supplied values win by layer order and `revert-layer` falls back to them. The Layout tab is a view over the contract at the active breakpoint; the structure picker is editor-session state that commits through the page's existing apply transaction path.

**Tech Stack:** PHP 8.4 (contracts, core, render pack), Twig theme templates and CSS, the preview bridge (plain script), Nuxt UI admin (Vue 3, vitest), Playwright for the admin builder proofs (`admin/e2e`) and the real-browser layout proofs (`tools/runtime-browser`, npm).

**Spec:** `docs/internal/superpowers/specs/2026-09-17-container-layout-design.md` (§ numbers below refer to it). Plan reviewed 2026-09-17; the review's corrections are folded in and marked **(review)**.

## Global Constraints

- **Fresh-install scope** (§1). No stored content is rewritten and no upgrade path is claimed.
- **One release, one beta cut** (§10). Phases are build order on `dev`; every commit passes the gates its task names; the beta is cut once, after Task 4.3, on the user's word.
- **Presentation in `settings.style`; structure in `data`** (§2).
- **Layout settings never remove children or implicitly change their visibility** (§2.4).
- **Theme defaults only, authored values win** (§3.4, §3.6, §3.8). Nothing written into `settings.style` reproduces a default.
- **Every breakpoint-dependent rule is undone by an equal-specificity rule at a later breakpoint (review).** A rule never depends on a class that "may be absent later"; resolved emission guarantees each breakpoint carries its own state class, and every state — each value, **reset**, and absence — has a rule. Reset and absence both mean the property's theme default, and dependent rules treat them identically (see "Default states for dependent rules").
- **Reuse, don't compete** (§2.5).
- **Presets write explicit values or resets for owned paths at every breakpoint** (§6.4); deletion only where a preset lists it (none do).
- **Closed disposition tables** (§7.7, §7.10).
- **Frozen references, no legacy renderer (review).** Old Container, Section, Columns and Grid appearance is captured once, before any shared CSS changes, as measurements committed to the repo. Later proofs compare current output against those measurements; nothing retains an executable copy of a retired template or its CSS.
- **No legacy code, no compatibility.** Superseded container data fields leave in Task 2.1; Columns, Grid, Section and migration 028 leave in Task 4.2.
- **The PHP contract and the admin mirror move together** with `packages/thallo-render/resolver-fixtures/v1`.
- PHP gates: `COMPOSER_PROCESS_TIMEOUT=0 composer test` (one suite at a time), `composer phpcs` by exit code, `composer boundaries`; a template change re-records `THALLO_RECORD_FRAGMENT_VERIFICATION=1 DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit --filter FragmentVerificationTest`.
- Admin gates: `pnpm exec vitest run --pool=forks --maxWorkers=2`, `pnpm type-check`, `pnpm lint`, `pnpm fmt:check`; `pnpm exec oxfmt <files>` only; builder proofs `cd admin/e2e && pnpm test`.
- Real-browser gate: `php scripts/build-layout-proof-fixtures && cd tools/runtime-browser && npm test`.
- `git diff` before every commit; no AI attribution trailers; never push, never tag.

**Steps 1–4**, wherever a task says so, are the TDD cycle: write the failing tests the task names, run them and watch each fail for the stated reason, implement the minimum, run the file and the task's gates green. **Step 5** is the commit with the message given.

## Shared contracts (named once, used by every task)

### Properties (`Thallo\Contracts\Style\StyleSchema`, mirrored in `admin/src/style/schema.ts`)

| Path | Group | Kinds | Responsive | Domain / choices | Class stem |
|---|---|---|---|---|---|
| `layout.display` | `layout` | choice, reset | yes | `block`, `flex`, `grid` | `display` |
| `layout.direction` | `layout` | choice, reset | yes | `row`, `column`, `row-reverse`, `column-reverse` | `dir` |
| `layout.wrap` | `layout` | choice, reset | yes | `nowrap`, `wrap` | `wrap` |
| `alignment.content` (existing) | `alignment` | choice, reset | yes | `start`, `center`, `end`, `between`, `around`, `evenly` | `content` |
| `layout.align_items` | `layout` | choice, reset | yes | `start`, `center`, `end`, `stretch`, `baseline` | `items` |
| `layout.columns` | `layout` | choice, reset | yes | `1`, `2`, `3`, `4`, `6`, `12`, `1-2`, `2-1`, `1-3`, `3-1`, `1-2-1`, `1-1-2`, `2-1-1` | `cols` |
| `layout.gap.column` | `layout` | token, reset | yes | `spacing` | `gapx` |
| `layout.gap.row` | `layout` | token, reset | yes | `spacing` | `gapy` |
| `layout.content_width` | `layout` | token, reset | yes | `width` | `cw` |
| `layout.gutter` | `layout` | token, reset | yes | `spacing` | `gutter` |
| `layout.min_height` | `layout` | choice, reset | yes | `auto`, `half`, `screen` | `minh` |
| `layout.overflow` | `layout` | choice, reset | **no** | `visible`, `hidden`, `auto` | `overflow` |
| `layout.span` | `layout.item` | choice, reset | yes | `1`…`12`, `full` | `span` |
| `layout.basis` | `layout.item` | choice, reset | yes | `auto`, `1/4`, `1/3`, `1/2`, `2/3`, `3/4`, `full` | `basis` |
| `layout.grow` | `layout.item` | choice, reset | yes | `0`, `1` | `grow` |
| `layout.shrink` | `layout.item` | choice, reset | yes | `0`, `1` | `shrink` |
| `layout.align_self` | `layout.item` | choice, reset | yes | `start`, `center`, `end`, `stretch` | `aself` |

- Declaring the capability `layout.item` grants its five paths (the existing group expansion).
- `StyleSchema::VERSION` → `2`; `StyleCompiler::VERSION` → `3`.
- `ClassNames::valueName`: `/` becomes `-` (`t-basis-1-3`).
- **Target kind rules** (`StyleTargets::validateAgainst`; a rule names a set of kinds) **(review)**:
  - `layout.display`, `layout.direction`, `layout.wrap`, `layout.align_items`, `layout.columns`, `layout.gap.column`, `layout.gap.row`, `layout.content_width`, `layout.gutter` → `stack`.
  - `layout.min_height`, `layout.overflow` → `box`.
  - item paths → `box`, `row` or `text`.
  - `alignment.content` → `row` or `stack` (today `row`).
  - `alignment.self` → `box` or `text` (today `box`).
  - `alignment.text` → `text` (unchanged).
  - Why `text` is admitted: every starter `text` target is a block-level element (`<hN>`, a `<div>`, a titled `<h3>`), so Placement and item sizing are meaningful on it. The linter (Task 1.2) enforces that a target carrying `layout.item` is the template's outermost element; `width` has no kind rule today and keeps none.

### Emission (`Thallo\Render\Style\BlockStyleEmitter`)

- **Resolved layout emission.** For every path in the `layout` and `layout.item` groups and `alignment.content`, the emitter writes a class at every breakpoint where the property resolves to a value or a reset (inherited values included).
- **Absent track count (review).** When `layout.columns` is absent at a breakpoint, the emitter writes `bp:t-cols-auto` for that breakpoint (`t-cols-auto` at base). A span therefore always meets a track-count class at its own breakpoint.
- **Default states for dependent rules (review).** Two properties drive other rules: `layout.columns` (span pairing) and `layout.display` (spacing normalization). For each, **reset and absence are one state — the theme default** — and every dependent rule that names the default names both forms:
  - track state `default` ≡ `t-cols-auto` or `t-cols-reset`; its track count is the theme default's, which is **1** (the theme sets no `grid-template-columns` on `__inner`, pinned by `ContainerThemeDefaultsTest`);
  - display state `default` ≡ `t-display-reset`, or no display class at that breakpoint; it behaves as `block` (the theme sets no `display` on `__inner`, pinned by the same test).

### Compiled CSS (`Thallo\Render\Style\StyleCompiler`, `@layer settings`)

- `t-display-*` → `display`; `t-dir-*` → `flex-direction`; `t-wrap-*` → `flex-wrap`; `t-items-*` → `align-items` (`start`/`end` → `flex-start`/`flex-end`); `t-content-between|around|evenly` → `justify-content: space-*`.
- `t-cols-N` → `grid-template-columns: repeat(N, minmax(0, 1fr))`; ratio presets → a `minmax(0, Xfr)` list; `t-cols-auto` → `grid-template-columns: none`.
- `t-gapx-*` → `column-gap`; `t-gapy-*` → `row-gap`.
- **Content width and gutter (review).** `t-cw-*` → `max-width` (`width.full` → `none`), `margin-inline: auto`, `--thallo-default-gutter` (`var(--t-spacing-lg)`; `width.full` → `0px`). `t-cw-reset` → `max-width: revert-layer; margin-inline: revert-layer; --thallo-default-gutter: revert-layer`. `t-gutter-*` → `padding-inline`; `t-gutter-reset` → `padding-inline: revert-layer`.
- **Min height (review).** The min-height utilities **never write `display`**, so managed `visibility` (which writes `display: none`, and `revert-layer` for visible) stays the only managed authority over display. `t-minh-half|screen` → `min-height: 50vh|100vh; --thallo-root-layout: flex`; `t-minh-auto` → `min-height: auto; --thallo-root-layout: block`; `t-minh-reset` → `min-height: revert-layer; --thallo-root-layout: revert-layer`. The theme's root rule consumes the variable (Theme defaults). Composition: hidden → `display: none` from the settings layer beats the theme's display at every breakpoint; visible → `revert-layer` → the theme rule → the variable's current value, so centring survives a hidden → visible change; a min-height reset returns the variable to the theme's `block` and leaves visibility untouched.
- `t-overflow-*` → `overflow` (base only).
- **Item sizing and self-alignment:** `t-basis-*` → `flex-basis` (`auto`, `25%`, `33.333%`, `50%`, `66.667%`, `75%`, `100%`); `t-grow-*` → `flex-grow`; `t-shrink-*` → `flex-shrink`; `t-aself-*` → `align-self`. These are single-class rules on the element itself (specificity 0,1,0 at every breakpoint), overridden by resolved emission at later breakpoints.
- **Span (review).** There is **no single-class span rule**. For every breakpoint `bp`, every track class `T ∈ {auto, reset, 1, 2, 3, 4, 6, 12, 1-2, 2-1, 1-3, 3-1, 1-2-1, 1-1-2, 2-1-1}` and every span class `S ∈ {1…12, full, reset}`, the compiler emits exactly one rule in two selector forms with the same breakpoint prefix on both classes:
  - `.bp\:t-cols-T > .bp\:t-span-S` (specificity 0,2,0) and `.bp\:t-cols-T > .thallo-preview-block > .bp\:t-span-S` (0,3,0; a stage element only ever matches this form).
  - Declaration: `S = reset` → `grid-column: revert-layer` (the theme default, per the contract's reset semantics — never a hard-coded value); `S = full` → `grid-column: 1 / -1`; numeric `S ≤ tracks(T)` → `grid-column: span S`; numeric `S > tracks(T)` → `grid-column: 1 / -1`. `tracks(auto) = tracks(reset) = 1` (the default track state); a ratio preset's tracks are its parts.
  - Because both the parent's track class and the child's span class are emitted at every breakpoint where they resolve, exactly one rule of a form matches per breakpoint, and a later breakpoint's rule has equal specificity and later source order — clamped → unclamped, unclamped → clamped and span reset all resolve correctly.
- **Dormancy.** Flex-only declarations are inert on grid and block elements; `grid-template-columns` and `grid-column` are inert on flex and block elements; a block element ignores gaps and alignment. Proven in Task 1.2.

### Theme defaults (`packages/thallo-render/themes/default/assets/blocks.css`, `@layer theme`)

- **Container root layout (review):** `.thallo-block-container { --thallo-root-layout: block; display: var(--thallo-root-layout); flex-direction: column; }` — the variable is initialised on every container root, so a nested container never inherits an ancestor's flex sizing; `flex-direction` is inert while the root is `block`.
- **Gutter initialisation (review):** `.thallo-block-container__inner { --thallo-default-gutter: 0px; padding-inline: var(--thallo-default-gutter); flex: 1 1 auto; }` — the local initialisation stops a boxed ancestor's gutter reaching a nested container; `flex` is inert unless the root is a column flex box (min height).
- **Containment release (review):** one rule per inventoried page-level containment (Task 1.2's inventory): for each block class `B` whose root rule sets `max-width` to `var(--container)` or `var(--content)` with `margin-inline: auto` and `padding-inline`, the release `.thallo-block-container__inner > .B, .thallo-block-container__inner > .thallo-preview-block > .B { max-width: none; margin-inline: 0; padding-inline: 0; }`. Only those three properties, only those classes; a component's own internal padding (a card's, a CTA panel's) is untouched.
- **Spacing normalization (review):** rules keyed to the resolved display class at each breakpoint, inside the same media queries as the compiler (768px, 1024px), each with explicit restoration:
  - `.bp\:t-display-flex > .thallo-block, .bp\:t-display-grid > .thallo-block` (and the wrapper forms) `{ margin-block: 0; }`
  - the default display state, both forms together: `.bp\:t-display-block > .thallo-block, .bp\:t-display-reset > .thallo-block` (and wrapper forms) `{ margin-block: var(--space-5); }`, followed by the same two selectors with `> :first-child { margin-top: 0; }` and `> :last-child { margin-bottom: 0; }` (wrapper forms: `> .thallo-preview-block:first-child > .thallo-block`, `> .thallo-preview-block:last-child > .thallo-block`).
  - no display class at a breakpoint: `.thallo-block-container__inner > :first-child { margin-top: 0; }` and `> :last-child { margin-bottom: 0; }` (0,2,0) with wrapper forms, placed **before** the breakpoint rules; a display class at that or a later breakpoint wins by equal specificity and later source order, and resolved emission means a declared display is never followed by an unclassed breakpoint.
  - `.thallo-block-rich_text > :first-child { margin-top: 0; } .thallo-block-rich_text > :last-child { margin-bottom: 0; }`.

### Frozen references (review)

- `tools/runtime-browser/references/<case>.json` — committed measurements, per named element of an old rendering, at widths 375, 700, 800 and 1280, grouped into **assertion profiles** **(review)**:
  - `geometry` — the bounding rect relative to the case root (compared with a 0.5px tolerance);
  - `spacing` — the element's computed `margin-*` and `padding-*`;
  - `typography` — `font-size`, `font-weight`, `line-height`, `text-align`, `color`;
  - `surface` — `background-color`;
  - `semantics` — role, accessible name, heading level, and the element's position in reading order.
  Layout-mechanism values (`display`, `flex-direction`, `flex-wrap`, `justify-content`, `align-items`, gaps, `grid-template-columns`, `max-width`, `width`, `min-height`) are **not recorded**: the old and new mechanisms differ by design (an old block root, a new flex root), and the mechanism is proven separately by `layout.spec.js`.
- `tests/fixtures/layout/references/<case>.json` — the case definition: `old` (the block tree rendered at capture), `elements` (name → selector in the old rendering, and the profiles that apply to it), and, added later, `new`, `map` (name → selector in the new rendering) and `allow`.
- **Allow rows (review)** are the closed §7.7 and §7.10 rows, each with an id, the properties it relaxes, the element it names, and `affects`: the mapped elements whose `geometry` may shift as a consequence (for a typography row: the element itself and the elements after it in the same flow container, listed by name). An allow row relaxes exactly those properties on that element and `geometry` on the listed elements; every other profile on every element is still compared, and the relaxed values are asserted to equal the row's specified result.
- `tools/runtime-browser/tests/support/compare.js` — `compareToReference(page, reference, caseDef)`: measures the current rendering with the same procedure and fails on every difference not relaxed by `allow`, naming case, width, element, profile and property.
- **Capture-time only (review):** determinism is checked when references are captured (`scripts/capture-layout-references` measures each page twice and refuses to write a reference whose two measurements differ). The reference pages stay gitignored and the capture script is deleted in Task 4.2; permanent CI validates the committed JSON's shape and compares current renders against it, never the old pages.

### Admin

- `admin/src/editor/inspector/tabMap.ts`: `export type InspectorTab = 'content' | 'layout' | 'style' | 'advanced'`; `export function tabForPath(path: string): InspectorTab` — `layout.*`, `width`, `alignment.self`, `alignment.content` → `'layout'`; every other style path → `'style'`.
- `admin/src/editor/inspector/layoutContext.ts` (created in Task 2.2 — review): `effectiveValue(block: BlockInstance, path: string, bp: Breakpoint, classes: StyleClassRef[]): string | null`; `effectiveDisplay(block, bp, classes): 'block' | 'flex' | 'grid'`; `dormantPaths(block, mode, role: 'parent' | 'item', classes): string[]`.
- `admin/src/editor/structure/legality.ts` **(review)**: `export function insertCandidate(doc: EditorDocument, position: Position, block: BlockInstance, ctx: LegalityContext): EditorDocument | null` **(review)** — the candidate document with a **new** block inserted: the root field is `position.slot` when `position.parent` is null, otherwise the root field that contains `position.parent` (the same lookup `candidateTree` uses); the list is rebuilt with `createBlockListOps(ctx.regionsOf).insertAt(list, { parentId: position.parent, region: position.parent === null ? null : position.slot, index: position.index }, block)`; `null` when the parent is not found. (`candidateTree` moves blocks already in the document and is not used for a new instance.) `export function checkInsertSubtree(doc, position, block, ctx): Legality` — `checkInsert` for the block at `position`; then, on `insertCandidate(doc, position, block, ctx)`, every descendant of `block` checked against its own parent's slot definition (allow-list, the tabs cap) and its depth, first refusal returned with the descendant's path in the message; `checkInsertSequence(doc, inserts: { position: Position; block: BlockInstance }[], ctx): Legality` — each insert checked with `checkInsertSubtree` against the document produced by `insertCandidate` for the earlier inserts.
- `admin/src/editor/structure/presets.ts`: `PresetKey`, `Preset { key; label; owned: OwnedWrite[]; children: ChildSpec[] }`, `OwnedWrite = { path: string; values: Record<Breakpoint, { kind: 'value'; value: StyleValue } | { kind: 'reset' }> } | { dataField: 'element'; value: string }` (**no delete kind** — review), `ChildSpec`, `PRESETS`, `planPreset(container: BlockInstance, preset: Preset, instances: BlockInstance[], classes: StyleClassRef[]): OperationBody[]` **(review)** — pure: `classes` is the snapshot of the container's applied style classes (the page's `classRefsFor(container)`), used with `resolve()` from `admin/src/style/resolver.ts`; for every preset except Stack a write is planned where the instance value differs; Stack's writes are planned where the value resolved through `instance + classes` is not `block`. `presetDepth(preset)`.
- `admin/src/editor/structure/structurePicker.ts`: `createStructurePicker(deps)` with `offer(id)`, `choose(id, key)`, `skip(id)`, `onDocumentChange(ops)`, `pendingIds()`, `state(id)`. `StructurePickerDeps.commit(ops: OperationBody[]): Promise<void>` is the page's **`applyDrop`** (review) — `history.beginTransaction()`, `opsSinceApply.push(history.record(body))` for each op, `commitNow()`, `replayHistory()`, `scheduleCommit(true)` — never a separate recording path.
- Bridge messages (§6.2): parent → bridge `thallo:structure-offer { offers: [{ id, presets: [{ key, label, enabled, reason? }] }] }`; bridge → parent `thallo:structure-choose { id, preset }`, `thallo:structure-skip { id }`; `useCanvasBridge.structureOffer`, `onStructureChoose`, `onStructureSkip`.
- Test ids: `layout-section-box|container|children|item`, `layout-display-<mode>`, `layout-dir-<value>`, `layout-wrap-<value>`, `layout-track-<preset>`, `layout-gap`, `layout-dormant-parent`, `layout-dormant-item`, `layout-item-parent-link`, `structure-tile-<key>`, `structure-skip`.

---

## Phase 0 — Frozen references

## Task 0.1: capture old appearance before any shared CSS changes (review)

**Files:**
- Create: `tests/fixtures/layout/references/*.json` — case definitions with `old` trees and `elements` for every configuration of: §4's container parity (width contained, narrow, full; min height half and screen × content align top, center, bottom; flex × each direction, justify, align items, wrap, gap); §7.6 Section matrix; §7.8 Columns matrix; §7.9 Grid matrix (counts 1–4, each gap, full and partial rows); §7.10 leaf cases (heading, rich text, button alone and together in a Section content area, a Section links row, a Columns column, a Grid cell).
- Create: `scripts/capture-layout-references` (PHP) — renders each case's `old` tree with the current templates and theme into a self-contained page (theme artifact and compiled settings artifact inlined; fonts as data URIs) under a gitignored `tools/runtime-browser/fixtures/references/`, then runs `node tools/runtime-browser/scripts/measure.js <page> <case>` twice; identical results are written to `tools/runtime-browser/references/<case>.json` (committed), differing results abort the capture naming the case.
- Create: `tools/runtime-browser/scripts/measure.js` and `tools/runtime-browser/tests/support/compare.js` (one measuring procedure shared by capture and comparison).
- Test: `tools/runtime-browser/tests/references.spec.js` (permanent; reads only committed files) — every reference JSON has the four widths, every named element of its case definition, and the fields of each element's declared profiles; no mechanism field is present.
- Test: `tools/runtime-browser/tests/compare.spec.js` (Chromium, synthetic pages) — `compareToReference` against synthetic pages: an unchanged page passes; a 2px geometry shift fails naming the element; an allowed typography row relaxes `font-size` on its element and `geometry` on its `affects` list only, and a spacing change on the same element still fails; a relaxed value that differs from the row's specified result fails.
- [ ] **Step 1:** write `measure.js`, `compare.js`, `compare.spec.js` and `references.spec.js`; `references.spec.js` fails (no references yet).
- [ ] **Step 2:** write the case definitions and `capture-layout-references`; run it at the current `HEAD` (no template or CSS change on the tree); the capture's double measurement passes.
- [ ] **Step 3:** run `references.spec.js` and `compare.spec.js` green.
- [ ] **Step 5: Commit** `test(render): frozen layout references for Container, Section, Columns, Grid and leaf blocks`.

---

## Phase 1 — Contract and proofs

## Task 1.1: layout properties, compiler and resolved emission (one commit — review)

**Files:**
- Modify: `packages/thallo-contracts/src/Style/StyleSchema.php`, `packages/thallo-contracts/src/Style/StyleTargets.php` (kind-set rules), `admin/src/style/schema.ts`, `packages/thallo-render/resolver-fixtures/v1/table.json`, new `packages/thallo-render/resolver-fixtures/v1/layout.json`, `packages/thallo-render/src/Style/ClassNames.php`, `packages/thallo-render/src/Style/StyleCompiler.php`, `packages/thallo-render/src/Style/BlockStyleEmitter.php`
- Test: `tests/Unit/Contracts/StyleSchemaTest.php` (every row; `alignment.content` six choices; `pathsInGroup('layout.item')`; version 2), `tests/Unit/Contracts/StyleTargetsTest.php` (each kind set accepted and refused with both kinds named; item paths on `text` accepted; `alignment.self` on `text` accepted; `layout.columns` on `box` refused), `tests/Unit/Render/CascadeResolverFixturesTest.php` and `admin/src/__tests__/style-resolver.spec.ts` (`layout.json`), `tests/Integration/Content/BlockSettingsValidationTest.php` (a declaring type accepts `grid` at `md`, refuses `table`; `layout.overflow` with a breakpoint key refused)
- Test: `tests/Unit/Render/StyleCompilerTest.php` (extend): each declaration in the shared contracts; `t-cols-auto`; `t-cw-reset` and `t-gutter-reset` declarations; `t-minh-auto` sets `--thallo-root-layout: block` and no `display`; **span rules:** no `.t-span-2 {` single-class rule exists; `.md\:t-cols-2 > .md\:t-span-3` → `1 / -1`; `.md\:t-cols-4 > .md\:t-span-2` → `span 2`; `.md\:t-cols-auto > .md\:t-span-2` → `1 / -1`; **`.md\:t-cols-reset > .md\:t-span-2` → `1 / -1` and `.md\:t-cols-reset > .md\:t-span-1` → `span 1`**; **`.lg\:t-cols-3 > .lg\:t-span-reset` → `grid-column: revert-layer`**; `t-cols-1-2-1` counts 3; every rule has its wrapper form; rule count = 3 breakpoints × 15 track classes × 14 span classes × 2; **`t-minh-half` declares `--thallo-root-layout: flex` and no `display`; `t-minh-reset` reverts `min-height` and `--thallo-root-layout` only**
- Test: `tests/Unit/Render/BlockStyleEmitterTest.php` (extend): flex at base emits the class at base, md, lg; grid at md over flex at base; a reset at lg; `layout.columns` absent at every breakpoint emits `t-cols-auto md:t-cols-auto lg:t-cols-auto`; `spacing.padding.top` emission unchanged
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(style): layout properties join the contract, compiled and emitted per breakpoint with paired span rules`.

## Task 1.2: layout items, the linter, theme defaults and layout proofs

**Files:**
- Inventory first **(review)**: list every `blocks.css` root rule of a starter or pack block that sets `max-width: var(--container)` or `var(--content)` with `margin-inline: auto` and `padding-inline`:
  ```bash
  grep -nE "^\.thallo-block-[a-z_]+ \{[^}]*max-width: var\(--(container|content)\)" packages/thallo-render/themes/default/assets/blocks.css
  grep -nE "^:where\(\.thallo-block-[a-z_]+" packages/thallo-render/themes/default/assets/blocks.css
  ```
  Record the list in `tests/fixtures/layout/containment-inventory.json` (block class, file, line).
- Modify: `packages/thallo-render/src/Templates/TemplateLinter.php` (a target carrying `layout.item` must be emitted on the template's outermost element), `packages/thallo-render/themes/default/assets/blocks.css` (theme defaults from the shared contracts; the containment release for exactly the inventoried classes), `core/src/Content/Blocks/StarterBlockTypes.php` (`layout.item` on every starter whose root is its outermost element — heading, rich_text, button, image, card, cta, hero, feature, tabs, separator, form, code, video, audio, file, logo, icon, html, shortcode — **not** container; **heading and rich_text also gain `width` and `alignment.self` (review)**, on their `text` root), every pack block type declaring style capabilities (`grep -rn "style_capabilities" packages/*/src`), `packages/thallo-render/fragments-verified.json` (re-record)
- Create: `scripts/build-layout-proof-fixtures` — registers the test-only block type `layout_fixture` (targets `root` box, `inner` stack; every `layout` capability; template `tests/fixtures/layout/layout_fixture.twig`), renders each `tests/fixtures/layout/cases/*.json` public and annotated into gitignored `tools/runtime-browser/fixtures/layout/`; `.github/workflows/runtime-browser.yml` gains PHP, `composer install`, a Postgres service and the script, and its path filter adds `packages/thallo-render/**`, `packages/thallo-contracts/src/Style/**`, `tests/fixtures/layout/**`.
- Create: `tests/fixtures/layout/cases/`: `dormancy-flex-then-grid-md`, `dormancy-grid-inherited`, `nested-flex-grid-flex`, `span-mobile-stack`, `span-asymmetric`, `span-inherited`, `span-nested-grid`, **`span-clamped-then-unclamped`** (1 track at base, 4 at md, span 2), **`span-unclamped-then-clamped`** (4 at base, 1 at md, span 2), **`span-reset-md`**, **`span-parent-tracks-absent`**, **`span-parent-tracks-reset`**, `gutter-boxed-to-full`, `gutter-authored-kept`, `gutter-reset`, `gutter-none-explicit`, **`gutter-nested-full-in-boxed`**, `band-half-centred`, **`minh-half-lg-only`** (auto on mobile), **`minh-half-then-auto-md`**, **`minh-half-then-reset-md`**, **`minh-hidden-base-half-lg`** (visibility hidden at base, min height half introduced at lg), **`minh-half-hidden-then-visible-md`** (half at base, hidden at base, visible at md), **`minh-reset-while-hidden`** (half at base, min height reset at md, hidden at every breakpoint), `item-participation`, `nested-clamp-authored` (heading and rich text with authored `width` and `alignment.self`, button with padding, a card with `layout.basis`), **`containment-preserves-component-padding`** (a card and a CTA inside a container keep their internal padding), `spacing-normalization`, **`spacing-flex-then-block-md`**, **`spacing-grid-then-block-lg`**, **`spacing-flex-then-reset-md`**, **`span-parent-grid-then-reset-md`** (tracks 2 at base, reset at md, span 2 child)
- Test: `tests/Integration/Render/TemplateLinterTest.php` (`layout.item` on an inner element is a violation), `tests/Integration/Render/LayoutFixturesRenderTest.php` (every case renders public and annotated with the expected resolved classes), `tests/Integration/Render/ContainmentInventoryTest.php` (the release rules name exactly the inventoried classes), `tests/Integration/Render/ContainerThemeDefaultsTest.php` (the theme sets no `grid-template-columns` and no `display` on `.thallo-block-container__inner`; the container root rule initialises `--thallo-root-layout: block`)
- Test: `tools/runtime-browser/tests/layout.spec.js` (both renders, widths 375, 700, 800, 1280): each case's expected computed layout — for the span cases the child's `grid-column-start`/`end` and rect at each width; for the min-height cases root `display` and `min-height` at each width, **including the three visibility compositions — `display: none` wherever hidden, the column flex root wherever visible with half height, a centred child after hidden → visible, and `display: none` retained after the min-height reset**; for the gutter cases `__inner` `padding-inline`, including the nested full-width container at `0px`; for spacing normalization each child's `margin-block` after a mode change, **flex → reset restoring default margins exactly as flex → block**; for `span-parent-grid-then-reset-md` the child fills the single default track at 800; for containment the card's and CTA's internal padding unchanged
- [ ] **Steps 1–4.** Write the proofs before the theme rules.
- [ ] **Step 5: Commit** `feat(render): layout items, theme layout defaults and real-browser layout proofs`.

## Task 1.3: phase gate

- [ ] **Step 1:** PHP gates, admin gates, real-browser gate.
- [ ] **Step 2:** the production `container` declares no `layout` capability yet; `BlockSettingsValidationTest` case "a container refuses layout settings before its cutover" passes.
- [ ] **Step 3:** no commit unless a gate required a fix; any fix is its own commit naming the failing proof.

---

## Phase 2 — Container cutover and the Layout tab

## Task 2.1: the container cuts over to layout settings

**Files:**
- Modify: `core/src/Content/Blocks/StarterBlockTypes.php` (`container`: `element` enum `div`, `section`, `article`, `aside`, `header`, `footer`; deletes the nine layout data fields; targets `root` box and `inner` stack; capabilities and map per §4), `packages/thallo-render/themes/default/templates/blocks/container.twig` (root tag from `data.element` through an allowlist defaulting to `div`; `style_classes('inner')` on `__inner`; superseded modifiers deleted), `packages/thallo-render/themes/default/assets/blocks.css` (superseded container rules deleted), `packages/thallo-render/docs/THEMING.md`, `packages/thallo-render/fragments-verified.json`
- Modify: `tests/fixtures/layout/references/container-*.json` — add `new` trees (the §4 parity mapping) and `map`
- Test: `tests/Integration/Render/StarterTemplatesTest.php` (`element` rendering; inner classes; no superseded modifier), `tests/Integration/Content/BlockSettingsValidationTest.php` (deleted fields refused; `layout.display` on `inner` accepted, on `root` refused), `tests/Integration/Content/BlockFactoryTest.php` (a factory-created container: `element: div`, no layout settings), `tools/runtime-browser/tests/parity.spec.js` (`compareToReference` for every `container-*` case at all widths with every recorded profile — geometry, spacing, typography, surface, semantics — and `allow` empty; the new root's flex mechanism is **not** compared here and is proven by `layout.spec.js`'s `band-half-centred` case), `tools/runtime-browser/tests/layout.spec.js` (extend: each `container-*` new tree's mechanism — root `display` from `--thallo-root-layout`, `__inner` display and alignment — matches its §4 mapping)
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(blocks): the container's layout is style settings on root and inner; its layout data fields are gone`.

## Task 2.2: tab map, layout context, and the Box, Container and Children sections

**Files:**
- Create: `admin/src/editor/inspector/tabMap.ts`, `admin/src/editor/inspector/layoutContext.ts` **(moved here — review)**, `admin/src/editor/inspector/LayoutTab.vue` (Box, Container, Children; one active breakpoint on every section header; Overflow labelled "all sizes"; Children rendered from `effectiveDisplay(block, activeBreakpoint, classes)`: flex → direction and wrap (icon segmented), `alignment.content`, `layout.align_items`, gaps as a `BoxField` with sides `column` and `row`; grid → track swatches, `alignment.content`, `layout.align_items`, gaps; block → "Children stack. Switch to flex or grid to arrange them." and the display buttons; the gutter shows the width-dependent default as the theme value), `admin/src/editor/inspector/controls/IconChoiceControl.vue`
- Modify: `admin/src/editor/inspector/BlockInspector.vue` (tabs Content, Layout, Style, Advanced; Layout when any declared path maps to `'layout'`; multi-selection shows Layout and Style), `admin/src/editor/inspector/StyleTab.vue` (only `'style'` paths), `admin/src/pages/content/[type]/[uuid]/design/[locale].vue` (passes the selected block's `parent` and `parentType`)
- Test: `admin/src/__tests__/tab-map.spec.ts`, `admin/src/__tests__/layout-context.spec.ts` (`effectiveDisplay` inherits base into md; a class supplies lg; a reset returns `block`), `admin/src/__tests__/layout-tab.spec.ts` (a container shows Box, Container, Children; a heading shows Box with width and Placement; Button shows `alignment.content` without Container or Children; flex at base, grid at md re-renders Children on switching breakpoint; a swatch writes `layout.columns` at the active breakpoint; Overflow writes a null breakpoint), `admin/src/__tests__/block-inspector.spec.ts` (tab order; Style no longer lists `width` or `alignment.self`)
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(inspector): a Layout tab — Box, Container and Children over the layout contract; tabs by property`.

## Task 2.3: As an item and dormant notices

**Files:**
- Modify: `admin/src/editor/inspector/LayoutTab.vue` (As an item: grid parent → span, align self; flex parent → basis, grow, shrink, align self; block parent → the line and `layout-item-parent-link`; `layout-dormant-parent` and `layout-dormant-item` from `dormantPaths`, counting class-supplied declarations), `BlockInspector.vue` (re-emits `select-parent`), `[locale].vue` (`select-parent`; `parent` passed for a multi-selection only when all share one parent)
- Test: `admin/src/__tests__/layout-context.spec.ts` (`dormantPaths` for parent and item roles with class values), `admin/src/__tests__/layout-tab.spec.ts` (item controls per parent mode and breakpoint; dormant notices both ways; sibling multi-selection with mixed values and intersected capabilities; two parents → no item section)
- Test: `admin/e2e/tests/layout-mode-switch.spec.ts` (grid container with three headings → switch to flex → apply → rects share a top edge → switch back → three tracks)
- [ ] **Steps 1–4.** The builder proof fixture gains the grid container (`scripts/build-builder-proof-fixtures`).
- [ ] **Step 5: Commit** `feat(inspector): As an item controls follow the immediate parent's mode; dormant settings disclosed both ways`.

---

## Phase 3 — Structure picker

## Task 3.1: whole-candidate legality (review)

**Files:**
- Modify: `admin/src/editor/structure/legality.ts` (`checkInsertSubtree`, `checkInsertSequence`)
- Test: `admin/src/__tests__/structure-legality.spec.ts`:
  - a container allowed at its destination holding a nested child whose type its column's slot forbids → refused, message names the nested slot;
  - the nested tabs cap exceeded inside a candidate subtree → refused;
  - depth counted from the destination through the deepest descendant;
  - `checkInsertSequence`: the second insert is judged against the document containing the first;
  - a valid multi-level candidate → `{ ok: true }`;
  - `insertCandidate` places a new block at a root position and at a nested position, and returns `null` for a missing parent;
  - the existing `checkInsert` cases unchanged.
- Test: the shared legality fixtures in `tests/fixtures/structure/legality/` gain `nested-forbidden-descendant.json`, run by `admin/src/__tests__/structure-legality.spec.ts` and by `tests/Integration/Content/TreeLegalityFixturesTest.php`. If the PHP checker that test drives does not already reject a forbidden descendant, extend it in this task so both runtimes agree.
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(editor): whole-candidate legality — every descendant of an inserted subtree is checked`.

## Task 3.2: presets as pure, cascade-safe plans

**Files:**
- Create: `admin/src/editor/structure/presets.ts`
- Test: `admin/src/__tests__/structure-presets.spec.ts`:
  - `cols-33-67` over an empty, unstyled container: explicit values for `layout.display` grid and `layout.columns` `1`/`1-2`/`1-2` and both gaps at every breakpoint; two column containers inserted into `content`.
  - **class conflict:** a class sets `layout.columns` `3` and `layout.display` `flex` at `lg`; resolving the planned instance values with the class yields `1-2` and `grid` at `lg`; every `from` equals the prior instance value.
  - **Stack (review — explicit values, judged on the resolved result):** Stack owns `layout.display` and writes an explicit `block` value — never a reset — at each breakpoint where the **resolved** display (instance and class layers) is not already `block`. A fresh container with nothing declared resolves to `block` everywhere → no operations, so dismissing it records nothing (§6.4). A container whose class sets `flex` at `lg` → one `SetSetting` `block` at `lg`. An instance `flex` at `md` → one `SetSetting` `block` at `md` and one at `lg` (inherited from md).
  - `section` writes `element` `section`, root padding top and bottom `spacing.3xl`, `layout.content_width` `width.container`, `layout.columns` reset at every breakpoint, children header group, content, links; `section-split` writes flex at base and md, grid at lg, `layout.columns` `2` at lg.
  - `presetDepth('section')` is 3; `presetDepth('cols-33-67')` is 2.
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(editor): structure presets as explicit, cascade-safe operation plans`.

## Task 3.3: the picker's state and commit through apply

**Files:**
- Create: `admin/src/editor/structure/structurePicker.ts` (states `pending` → `preparing` → `ended`; `choose` only in `pending`; a token checked after every await; §6.1 cancellation from `onDocumentChange` for any `InsertBlock`, `InsertBlocks`, `MoveBlock` or `DuplicateBlock` into the container's `content`, from `skip`, and from deletion; emptiness re-checked after the factory; the candidate validated with `checkInsertSequence` on the **real factory instances**; a refusal → `pending` with refreshed reasons; a factory failure → `pending` and a notice; commit through `deps.commit`, awaited; every successful choose ends the offer; an empty plan ends it without commit)
- Modify: `[locale].vue` (one picker; `planPreset` receives `classRefsFor(container)` at plan time, re-read after every await; `offer(id)` only from `insertFromPalette` and the palette drag's successful drop of an empty `container`; `onDocumentChange` fed wherever operations are recorded; `deps.commit = applyDrop`; the offer's tile legality from `checkInsertSequence` on placeholder instances, re-derived when the document changes)
- Test: `admin/src/__tests__/structure-picker.spec.ts` (offer → choose → one commit → ended; duplicate choose; skip during factory load; factory failure; content arrives while pending → ended → undo → delayed choose → no commit; emptiness lost during preparation; legality refusal with a **factory response that differs from the placeholder** — the real instance nests a forbidden child — → no commit, pending, reason republished; Stack on an already-stacked container → no commit, ended)
- Test: `admin/src/__tests__/canvas-page.spec.ts` **(review)**:
  - inserting a container from the Blocks tab publishes an offer; duplicate, preset children and version restore publish none;
  - choosing `cols-33-67` → the next apply request carries every preset operation, all with **one** `transaction_id`, in plan order;
  - the apply is rejected → the existing rollback contract: the transaction is discarded from history (`discardTip`), its operations leave `opsSinceApply`, the document returns to the empty container, and the rejection is reported;
  - a legality refusal leaves `fields`, history length and `opsSinceApply` unchanged.
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(editor): the structure picker — session-only offers, validated on the real subtree, committed through apply`.

## Task 3.4: the stage tiles

**Files:**
- Modify: `packages/thallo-render/assets/preview/preview-bridge.js` (offers by id; an offered container's empty `content` slot shows the tiles and Skip instead of the ordinary placeholder; disabled tiles carry the reason; clicks post choose or skip without selecting; an offer absent from the latest message removes its tiles; a container that gains a child shows none), `packages/thallo-render/assets/preview/preview.css`, `admin/src/composables/useCanvasBridge.ts`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts`, `admin/src/__tests__/canvas-bridge.spec.ts` (the cases named in the file list, and malformed messages dropped)
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(canvas): structure picker tiles in a new container's empty slot`.

## Task 3.5: picker proofs

**Files:**
- Create: `admin/e2e/tests/structure-picker.spec.ts` (insert → tile → one history entry and two columns at 1280px → undo empty, no tiles → redo same ids; a depth-limited `structure-tile-section` disabled with the reason; offer → drag a heading in → undo → a stale choose message → no history entry)
- Modify: `admin/e2e/README.md`
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `test(builder): structure picker proofs`.

---

## Phase 4 — Retirement

## Task 4.1: compositions against the frozen references

**Files:**
- Modify: `tests/fixtures/layout/references/section-*.json`, `columns-*.json`, `grid-*.json`, `leaf-*.json` — add each case's `new` composition (§7.3–§7.5, §7.8, §7.9, the leaf cases inside those compositions), `map` and `allow` (row ids from §7.7 and §7.10 only)
- Modify: `scripts/build-layout-proof-fixtures` (renders the `new` trees public and annotated)
- Test: `tools/runtime-browser/tests/retirement.spec.js` — `compareToReference` for every case at 375, 700, 800, 1280 on both renders; the semantics comparison (landmarks, heading levels, names, reading order) with the §7.4 exception on reversed cases; each `allow` row relaxing only its named properties and the `geometry` of its `affects` list, and asserted as its specified result (title `font-size` equals the theme `h2` clamp, `affects` the title and the description, content and links after it; description `font-size` equals `typography.size.lg`, `affects` the description and the content and links after it; inverted description `color` equals `--accent-ink`, `affects` nothing; leaves inside a composition have `padding-inline: 0` unless authored, `affects` that leaf and its following siblings in the cell; the two-column step at 768px, stacked at 700, `affects` every column and its descendants at 700 only); composed-container spacing has no `allow`
- [ ] **Steps 1–4.** A difference outside §7.7 and §7.10 is fixed in the composition, the presets or the theme defaults — never allowlisted without a spec amendment by the user.
- [ ] **Step 5: Commit** `test(render): retirement compositions match the frozen references within the closed dispositions`.

## Task 4.2: remove Columns, Grid and Section

**Files:** start from the inventory, reviewed line by line (commerce tables, navigation's `layout=columns`, tenancy reports and `ShopCatalogController` are unrelated and stay):

```bash
grep -rlnE "'(columns|grid|section)'|\"(columns|grid|section)\"|thallo-block-(columns|grid|section)|blocks/(columns|grid|section)\.twig|col_[123]" \
  --include='*.php' --include='*.ts' --include='*.vue' --include='*.twig' --include='*.css' --include='*.json' --include='*.md' --include='*.js' . \
  | grep -v "node_modules\|vendor/\|core/resources/admin\|\.git/\|docs/internal/\|storage/\|tools/runtime-browser/references/\|tests/fixtures/layout/references/"
```

- Delete: `packages/thallo-render/themes/default/templates/blocks/{columns,grid,section}.twig`; their CSS in `blocks.css` (masonry included); `core/database/migrations/028_AlignmentFieldsOnSectionBlockType.php`; `tests/Integration/Blocks/SectionAlignmentMigrationTest.php`; `admin/src/fields/components/blocks/ColumnsLayoutField.vue`; `admin/src/__tests__/columnsLayoutField.spec.ts`.
- Modify: `core/src/Content/Blocks/StarterBlockTypes.php`; `core/database/migrations/021_ReseedBlockTypesForThemeRewrite.php` (`DRIFTED` loses `section`, `grid`); `core/src/Content/Regions/RegionDefinitions.php` (`columns` removed); `admin/src/fields/components/blocks/BlockFields.vue`; `CanvasOutline.vue`; `BlockList.vue` and `admin/src/editor/palette/target.ts` (columns-specific branches); `preview-bridge.js` (columns-specific branches); `admin/src/queries/blockFactory.spec.ts`; `tests/fixtures/composition/five-deep.json` and every `tests/fixtures/structure/legality/*.json` naming a retired type (same depth and slot shape, expectations unchanged); `scripts/build-builder-proof-fixtures` and `admin/e2e/tests/*.spec.ts` ids; every admin and PHP spec in the inventory; `packages/thallo-render/docs/THEMING.md`, `packages/thallo-render/docs/refs.md`; `fragments-verified.json`; `CHANGELOG.md` (Unreleased: Added — Layout tab, structure picker, layout properties; Changed — Container, Heading and Rich text Placement and width; Removed — Columns, Grid, Section, masonry; the §7.7 and §7.10 changes and the 768px column step verbatim)
- **The references stay.** `tools/runtime-browser/references/` and `tests/fixtures/layout/references/` keep their `old` trees as data only; `scripts/capture-layout-references` is deleted in this task, so nothing can render them again, and `retirement.spec.js` keeps comparing the `new` compositions against the frozen measurements.
- Test: `tests/Integration/Content/SeedBlockTypesTest.php`, `tests/Integration/Render/TemplateLinterTest.php`, `tools/runtime-browser/tests/retirement.spec.js` still green; the inventory command returns only the reviewed unrelated uses
- [ ] **Steps 1–4.** The before and after inventory lists go in the commit body.
- [ ] **Step 5: Commit** `feat(blocks)!: Columns, Grid and Section are retired — Container compositions replace them`.

## Task 4.3: release gates

- [ ] **Step 1:** one at a time: `COMPOSER_PROCESS_TIMEOUT=0 composer test`, `composer phpcs`, `composer boundaries`, `COMPOSER_PROCESS_TIMEOUT=0 composer test:skeleton`, `COMPOSER_PROCESS_TIMEOUT=0 composer test:distribution`; admin gates; `cd admin/e2e && pnpm test`; the real-browser gate.
- [ ] **Step 2:** report the gate table to the user. The beta cut happens only on the user's word.

## Self-review

- **Spec coverage.** §2 → Global Constraints. §3.1–3.2 → 1.1. §3.3, §3.7 → 1.1 (mechanism), 1.2 (proofs). §3.4, §3.5, §3.8 → shared theme defaults, 1.2. §3.6 → 1.2 (linter, inventory-scoped release, authored proofs; Heading and Rich text Placement and width). §3.9 → 4.2 CHANGELOG. §4 → 2.1 against 0.1's references. §5 → 2.2, 2.3. §6.1–6.4 → 3.1 (whole-candidate legality), 3.2, 3.3 (apply path, rollback). §6.2 → 3.4. §6.5 → 3.2. §7 → 0.1 (references), 3.2 (compositions), 4.1 (comparison). §8 → 4.2. §9 → every task's tests. §10 → phases, 4.3.
- **Review corrections.** Span pairing at every breakpoint with absent and reset states (shared CSS, 1.1, 1.2 cases); min height with explicit restoration; gutter initialised locally with resets; containment release limited to an inventory; spacing restoration after mode changes; `text` targets admitted for item paths and Placement with Heading and Rich text gaining `width` and `alignment.self`; picker commits through `applyDrop` with the transaction and rollback proofs; whole-candidate legality as its own task with a divergent factory proof; references frozen in Phase 0 and compared without a legacy renderer; schema and compiler in one commit; `layoutContext.ts` created in 2.2; Stack uses explicit values.
- **Placeholder scan.** None.
- **Type consistency.** `checkInsertSubtree`, `checkInsertSequence`, `effectiveDisplay`, `dormantPaths`, `tabForPath`, `planPreset`, `presetDepth`, `createStructurePicker`, `compareToReference` and the bridge messages are defined once and used with the same signatures.
