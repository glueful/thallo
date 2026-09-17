# Container Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One layout block, Container, replaces Columns, Grid and Section: flex and grid layout join the style contract, a Layout tab edits them, a new container offers a structure picker on the stage, and the three retired types leave once their compositions are proven.

**Architecture:** layout properties are ordinary managed properties (`settings.style`) on two container targets — `root` (the band) and `inner` (the content area) — plus item properties on the `root` of any block declaring `layout.item`. The compiler emits utilities into `@layer settings`; the theme's default containment, gutters and spacing normalization live in `@layer theme`, so every authored or class-supplied value wins by layer order and a reset (`revert-layer`) falls back to the context default. The Layout tab is a view over the contract resolved at the active breakpoint; the structure picker is editor-session state that commits one history transaction of `SetSetting`, `SetField` and `InsertBlock` operations.

**Tech Stack:** PHP 8.4 (contracts, core, render pack), Twig theme templates and CSS, the preview bridge (plain script), Nuxt UI admin (Vue 3, vitest), Playwright for the admin builder proofs (`admin/e2e`) and the real-browser layout proofs (`tools/runtime-browser`).

**Spec:** `docs/internal/superpowers/specs/2026-09-17-container-layout-design.md` (§ numbers below refer to it). Reviewed four times during brainstorming (2026-09-17); every correction is folded into the spec.

## Global Constraints

- **Fresh-install scope** (§1). No stored content is rewritten and no upgrade path is claimed. Parity tests prove visual and semantic equivalence only.
- **One release, one beta cut** (§10). Phases 1–4 are build order on `dev`; each task ends with its gates green; the beta is cut once, after Task 4.3, on the user's word.
- **Presentation in `settings.style`; structure in `data`** (§2). Child lists, `element` and background assets are data.
- **Layout settings never remove children or implicitly change their visibility** (§2.4).
- **Theme defaults only, authored values win** (§3.4, §3.6, §3.8). Default containment, default gutters and spacing normalization are `@layer theme` rules; nothing written into `settings.style` reproduces a default.
- **Reuse, don't compete** (§2.5): `width`, `alignment.self` ("Placement"), `alignment.text` and `alignment.content` keep their meaning; `alignment.content` gains `between`, `around`, `evenly`.
- **Presets write value, reset or listed deletion for every owned path at every breakpoint** (§6.4). Deletion is never used to mean "default".
- **Closed disposition tables** (§7.7, §7.10). A difference a matrix finds that the table does not list fails the task.
- **No legacy code, no compatibility.** Superseded container data fields, their template modifiers and CSS leave in Task 2.1; Columns, Grid, Section and migration 028 leave in Task 4.2.
- **The PHP contract and the admin mirror move together.** `StyleSchema::properties()` and `admin/src/style/schema.ts` change in the same commit, and `packages/thallo-render/resolver-fixtures/v1` pins that both runtimes agree.
- PHP gates: `COMPOSER_PROCESS_TIMEOUT=0 composer test` (suite gates run one at a time, never concurrently), `composer phpcs` judged by exit code, `composer boundaries`; a template change re-records `THALLO_RECORD_FRAGMENT_VERIFICATION=1 … --filter FragmentVerificationTest`.
- Admin gates: `pnpm exec vitest run --pool=forks --maxWorkers=2`, `pnpm type-check`, `pnpm lint`, `pnpm fmt:check`; admin files formatted with `pnpm exec oxfmt <files>` only; proofs `cd admin/e2e && pnpm test`.
- Real-browser gate: `php scripts/build-layout-proof-fixtures && cd tools/runtime-browser && npm test` (Chromium; the harness uses npm).
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

- The `layout.item` group is a capability entry: declaring `layout.item` grants its five paths (the existing group expansion in `StyleCapabilities::fromDeclaration`).
- `StyleSchema::VERSION` becomes `2`; `StyleCompiler::VERSION` becomes `3`.
- **Class value names** (`ClassNames::valueName`): a `/` becomes `-` (`layout.basis` `1/3` → `t-basis-1-3`); every other value is used as is.
- **Target kind rules** (`StyleTargets::validateAgainst`; a rule may now name a set of kinds): `layout.display`, `layout.direction`, `layout.wrap`, `layout.align_items`, `layout.columns`, `layout.gap.column`, `layout.gap.row`, `layout.content_width`, `layout.gutter` require `stack`; `layout.min_height`, `layout.overflow` require `box`; the item paths require `box` or `row`; **`alignment.content` requires `row` or `stack`** (today `row` only — the container's `inner` is a `stack`, and Button and Navigation keep their `row` targets). `alignment.text` and `alignment.self` unchanged.

### Emission (`Thallo\Render\Style\BlockStyleEmitter`)

- **Resolved layout emission.** For every path in the `layout` and `layout.item` groups and for `alignment.content`, the emitter emits a class at **every breakpoint the property resolves to a value or a reset**, not only at exact declarations. For other properties emission is unchanged. This makes each breakpoint's mode and track count visible to selectors at that breakpoint (§3.3, §3.7).

### Compiled CSS (`Thallo\Render\Style\StyleCompiler`, `@layer settings`)

- `t-display-block|flex|grid` → `display`.
- `t-dir-*` → `flex-direction`; `t-wrap-*` → `flex-wrap`; `t-items-*` → `align-items` (`start`→`flex-start`, `end`→`flex-end`); `t-content-between|around|evenly` → `justify-content: space-between|space-around|space-evenly`.
- `t-cols-N` → `grid-template-columns: repeat(N, minmax(0, 1fr))`; ratio presets → `minmax(0, Xfr)` lists (`1-2` → `minmax(0,1fr) minmax(0,2fr)`).
- `t-gapx-*` → `column-gap`; `t-gapy-*` → `row-gap`.
- `t-cw-*` → `max-width` (`width.full` → `none`), `margin-inline: auto`, and the custom property `--thallo-default-gutter` (`var(--t-spacing-lg)` for every width token except `width.full`, where it is `0px`).
- `t-gutter-*` → `padding-inline`.
- `t-minh-auto|half|screen` → `min-height: auto|50vh|100vh`.
- `t-overflow-*` → `overflow`.
- Item classes, matched through the stage's annotation wrapper with the child selector `:is(> *, > .thallo-preview-block > *)` written out as two selectors:
  - `t-span-N` → `grid-column: span N`; `t-span-full` → `grid-column: 1 / -1`.
  - **Span clamping (§3.7):** for every track count `T` and span `S > tracks(T)`, a rule at the same breakpoint `.bp\:t-cols-T > .bp\:t-span-S` (and the wrapper form) → `grid-column: 1 / -1`. `tracks` of a ratio preset is its number of parts.
  - `t-basis-*` → `flex-basis` (`auto`, `25%`, `33.333%`, `50%`, `66.667%`, `75%`, `100%`); `t-grow-*` → `flex-grow`; `t-shrink-*` → `flex-shrink`; `t-aself-*` → `align-self`.
- **Mode dormancy (§3.3)** follows from CSS itself plus resolved emission: flex-only declarations (`flex-direction`, `flex-wrap`, `flex-basis`, `flex-grow`, `flex-shrink`) are inert on a grid or block element, grid-only ones (`grid-template-columns`, `grid-column`) are inert on a flex or block element, and a block element ignores gaps and alignment. The proofs in Task 1.4 are the gate for this mechanism; if one fails, the task pairs the property rule with the mode class at the same breakpoint instead.

### Theme defaults (`packages/thallo-render/themes/default/assets/blocks.css`, `@layer theme`)

- **Container inner gutter (§3.4):** `.thallo-block-container__inner { padding-inline: var(--thallo-default-gutter, 0px); }`.
- **Min height reaching inner (§3.5):** `.thallo-block-container:is(.t-minh-half, .t-minh-screen, [class*=":t-minh-half"], [class*=":t-minh-screen"]) { display: flex; flex-direction: column; }` and `… > .thallo-block-container__inner { flex: 1 1 auto; }`.
- **Nested containment release (§3.6):** `.thallo-block-container__inner > .thallo-block, .thallo-block-container__inner > .thallo-preview-block > .thallo-block { max-width: none; margin-inline: 0; padding-inline: 0; }`.
- **Spacing normalization (§3.8):** in flex and grid mode (`.t-display-flex`, `.t-display-grid` and their breakpoint forms on `__inner`) the direct children's `margin-block` is `0`; in block mode the first child's `margin-top` and the last child's `margin-bottom` are `0`; `.thallo-block-rich_text > :first-child { margin-top: 0; }` and `> :last-child { margin-bottom: 0; }`.

### Admin

- `admin/src/editor/inspector/tabMap.ts`: `export type InspectorTab = 'content' | 'layout' | 'style' | 'advanced'`; `export function tabForPath(path: string): InspectorTab` — `layout.*`, `width`, `alignment.self`, `alignment.content` → `'layout'`; every other style path → `'style'`.
- `admin/src/editor/inspector/layoutContext.ts`:
  - `export function effectiveValue(block: BlockInstance, path: string, bp: Breakpoint, classes: StyleClassRef[]): string | null` — the resolver's value at `bp`, inherited values included, class layers included; `null` for absent or reset.
  - `export function effectiveDisplay(block, bp, classes): 'block' | 'flex' | 'grid'` — `effectiveValue(block, 'layout.display', bp, classes) ?? 'block'`.
  - `export function dormantPaths(block, mode: 'block' | 'flex' | 'grid', role: 'parent' | 'item', classes): string[]` — declared (instance or class) paths that the given mode does not use.
- `admin/src/editor/structure/presets.ts`:
  - `export type PresetKey = 'stack' | 'row' | 'cols-50-50' | 'cols-33-67' | 'cols-67-33' | 'cols-25-75' | 'cols-75-25' | 'cols-3' | 'cols-25-50-25' | 'cols-50-25-25' | 'cols-25-25-50' | 'cols-4' | 'grid-2x2' | 'section' | 'section-split'`.
  - `export interface Preset { key: PresetKey; label: string; owned: OwnedWrite[]; children: ChildSpec[] }` where `OwnedWrite = { path: string; target: 'self'; values: Record<Breakpoint, { kind: 'value'; value: StyleValue } | { kind: 'reset' } | { kind: 'delete' }> } | { dataField: 'element'; value: string }` and `ChildSpec = { type: string; settings: Record<string, unknown>; data: Record<string, unknown>; children?: ChildSpec[]; slot?: string }`.
  - `export const PRESETS: readonly Preset[]`.
  - `export function planPreset(container: BlockInstance, preset: Preset, instances: BlockInstance[]): OperationBody[]` — pure; `instances` are the factory-created children in `ChildSpec` order with ids already allocated.
  - `export function presetDepth(preset: Preset): number`.
- `admin/src/editor/structure/structurePicker.ts`: `export function createStructurePicker(deps: StructurePickerDeps): StructurePicker` with `offer(id)`, `choose(id, key)`, `skip(id)`, `onDocumentChange(ops: OperationBody[])`, `pendingIds(): string[]`, `state(id): 'pending' | 'preparing' | 'ended' | null`.
- Bridge messages (§6.2): parent → bridge `thallo:structure-offer { offers: [{ id, presets: [{ key, label, enabled, reason? }] }] }`; bridge → parent `thallo:structure-choose { id, preset }`, `thallo:structure-skip { id }`. `useCanvasBridge`: `structureOffer(offers)`, `onStructureChoose(cb: (id: string, preset: string) => void)`, `onStructureSkip(cb: (id: string) => void)`.
- Test ids: `inspector-tab-layout`, `layout-section-box`, `layout-section-container`, `layout-section-children`, `layout-section-item`, `layout-display-<mode>`, `layout-dir-<value>`, `layout-wrap-<value>`, `layout-track-<preset>`, `layout-gap` (a `BoxField` with cells `box-cell-layout.gap.column` and `box-cell-layout.gap.row`), `layout-dormant-parent`, `layout-dormant-item`, `layout-item-parent-link`, `structure-offer-<id>`, `structure-tile-<key>`, `structure-skip`.

---

## Phase 1 — Contract and proofs

## Task 1.1: layout properties in the contract, both runtimes

**Files:**
- Modify: `packages/thallo-contracts/src/Style/StyleSchema.php` (the table's properties, `VERSION = 2`, `alignment.content` choices extended; the `layout.item` group), `packages/thallo-contracts/src/Style/StyleTargets.php` (`validateAgainst` kind rules from the shared contracts), `admin/src/style/schema.ts` (mirror, same order), `packages/thallo-render/resolver-fixtures/v1/table.json` (the new rows), a new `packages/thallo-render/resolver-fixtures/v1/layout.json` (responsive inheritance of `layout.display` and `layout.columns` across `base`/`md`/`lg`; a reset at `md` terminating `base`; a class supplying `lg`; `layout.overflow` non-responsive)
- Test: `tests/Unit/Contracts/StyleSchemaTest.php` (every row's group, kinds, responsiveness, choices or domain; `alignment.content` six choices; `pathsInGroup('layout.item')` is the five item paths; `VERSION` 2), `tests/Unit/Contracts/StyleTargetsTest.php` (a `layout.columns` capability on a `box` target is refused naming `stack`; `layout.min_height` on a `stack` target refused naming `box`; `layout.span` accepted on `box` and `row`; `alignment.content` accepted on `row` and `stack`, refused on `box` and `text` with a message naming both allowed kinds), `tests/Unit/Render/CascadeResolverFixturesTest.php` and `admin/src/__tests__/style-resolver.spec.ts` (both run `layout.json` unchanged), `tests/Integration/Content/BlockSettingsValidationTest.php` (a block type declaring `layout.display` accepts `grid` at `md`, refuses `table`, refuses a value for a path it does not declare; `layout.overflow` with a breakpoint key refused)
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(style): flex, grid, content width, gutter and item properties join the style contract`.

## Task 1.2: compiler, class names and resolved layout emission

**Files:**
- Modify: `packages/thallo-render/src/Style/ClassNames.php` (stems; `/` → `-` in `valueName`), `packages/thallo-render/src/Style/StyleCompiler.php` (`VERSION = 3`; declarations per the shared contracts; the span clamping pairs; item selectors in both the plain and the wrapper form), `packages/thallo-render/src/Style/BlockStyleEmitter.php` (resolved emission for the `layout`, `layout.item` groups and `alignment.content`)
- Test: `tests/Unit/Render/StyleCompilerTest.php` (extend; existing assertions stay):
  - `t-display-grid` → `display: grid`; `md\:t-display-flex` inside `@media (min-width: 768px)`.
  - `t-cols-1-2` → `grid-template-columns: minmax(0, 1fr) minmax(0, 2fr)`; `t-cols-12` → `repeat(12, minmax(0, 1fr))`.
  - `t-content-between` → `justify-content: space-between`; the existing `t-content-start` unchanged.
  - `t-basis-1-3` → `flex-basis: 33.333%`.
  - `t-cw-container` sets `max-width: var(--t-width-container)`, `margin-inline: auto` and `--thallo-default-gutter: var(--t-spacing-lg)`; `t-cw-full` sets `max-width: none` and `--thallo-default-gutter: 0px`.
  - clamping: `.md\:t-cols-2 > .md\:t-span-3` and `.md\:t-cols-2 > .thallo-preview-block > .md\:t-span-3` → `grid-column: 1 / -1`; no clamp rule for `t-cols-3` with `t-span-3`; ratio `1-2-1` counts 3 tracks.
  - `t-overflow-hidden` appears only at base.
- Test: `tests/Unit/Render/BlockStyleEmitterTest.php` (extend: a `layout.display` of `flex` at `base` only emits `t-display-flex md:t-display-flex lg:t-display-flex`; `grid` at `md` over `flex` at `base` emits `t-display-flex md:t-display-grid lg:t-display-grid`; a reset at `lg` emits `lg:t-display-reset`; `spacing.padding.top` at `base` still emits only `t-pt-*` — resolved emission is scoped to layout)
- [ ] **Steps 1–4.** Re-record fragment verification only if a template changed (none should).
- [ ] **Step 5: Commit** `feat(render): compile and emit layout utilities, resolved at every breakpoint, with span clamping`.

## Task 1.3: `layout.item`, the linter rule, theme defaults and the layout fixtures

**Files:**
- Modify: `packages/thallo-render/src/Templates/TemplateLinter.php` (a block type whose declaration maps `layout.item` to a target must emit that target's `style_classes()` on the template's outermost element; violation message `layout.item target "<name>" must be the template's outermost element`), `packages/thallo-render/themes/default/assets/blocks.css` (the theme defaults from the shared contracts: inner gutter, min height reaching inner, nested containment release, spacing normalization), `core/src/Content/Blocks/StarterBlockTypes.php` (add `layout.item` to the capabilities and the `root` map of every starter whose root target is its outermost element — at least heading, rich_text, button, image, card, cta, hero, feature, tabs, separator, form, code, video, audio, file, logo, icon, html, shortcode; **not** `container` yet — Task 2.1), every pack block type declaring style capabilities (`grep -rn "style_capabilities" packages/*/src core/src`), `packages/thallo-render/fragments-verified.json` (re-record)
- Create: `scripts/build-layout-proof-fixtures` (PHP, the shape of `scripts/build-builder-proof-fixtures`): boots the app against `app_test`, registers a **test-only** block type `layout_fixture` (targets `root` box and `inner` stack; capabilities every `layout` path; template `tests/fixtures/layout/layout_fixture.twig` = `<div class="thallo-block{{ style_classes('root') }}"{{ style_attrs('root') }}><div class="thallo-block-container__inner{{ style_classes('inner') }}"{{ style_attrs('inner') }}{{ slot_attrs('content') }}>{{ blocks(data.content) }}</div></div>`), renders each JSON case in `tests/fixtures/layout/*.json` twice — public and annotated (canvas marking on) — into `tools/runtime-browser/fixtures/layout/<case>.public.html` and `<case>.canvas.html` with the compiled settings artifact and the theme artifact inlined. The output directory is gitignored; `tools/runtime-browser/README.md` documents the script, and `.github/workflows/runtime-browser.yml` gains PHP, `composer install` and a Postgres service (the shape of the admin proofs' workflow) and runs the script before `npm test`; the workflow's path filter adds `packages/thallo-render/**`, `packages/thallo-contracts/src/Style/**` and `tests/fixtures/layout/**`.
- Create: `tests/fixtures/layout/` cases (JSON block trees): `dormancy-flex-then-grid-md`, `dormancy-grid-inherited`, `nested-flex-grid-flex`, `span-mobile-stack`, `span-asymmetric`, `span-inherited`, `span-nested-grid`, `gutter-boxed-to-full`, `gutter-authored-kept`, `gutter-reset`, `gutter-none-explicit`, `band-half-centred`, `item-participation` (heading, rich_text, button, card as items of flex and grid parents), `nested-clamp-authored` (the same leaves with authored `width`, `alignment.self`, padding and `layout.basis`), `spacing-normalization` (block, flex and grid parents with default-margin children; a single- and a multi-paragraph rich text)
- Test: `tests/Integration/Render/TemplateLinterTest.php` (a `layout.item` target on an inner element is a violation; on the outermost element it passes), `tests/Integration/Render/LayoutFixturesRenderTest.php` (every case renders, public and annotated, and the expected classes are present — the resolved emission of Task 1.2 on real templates)
- Test: `tools/runtime-browser/tests/layout.spec.js` (Chromium; each assertion on both `.public.html` and `.canvas.html`, at viewport widths 375, 700, 800 and 1280):
  - dormancy: `dormancy-flex-then-grid-md` is `display:flex` with the flex direction at 375 and `display:grid` with its tracks at 800, and the flex direction is inert at 800 (children laid out by tracks); `dormancy-grid-inherited` is grid at 1280 without an `lg` declaration; `nested-flex-grid-flex` — each level's children follow **their immediate parent's** mode.
  - span: `span-mobile-stack` — a span-2 child fills the single track at 375; `span-asymmetric` — span-2 in `1-2` fills the row; `span-inherited` — a base span-3 under `md` 2 tracks fills the row at 800; `span-nested-grid` — the inner grid's children clamp against the inner grid, not the outer.
  - gutter: computed `padding-inline` of `__inner` for the four gutter cases (§3.4 pinned cases).
  - `band-half-centred`: root height is 50% of the viewport, the inner's child is vertically centred inside root's padding box.
  - `item-participation`: each leaf's outermost element is a direct layout item (its `getBoundingClientRect` matches its grid cell or flex slot) in both renders.
  - `nested-clamp-authored`: unauthored leaves fill their cell with `padding-inline: 0`; authored `width`, `alignment.self`, padding and `layout.basis` compute exactly as authored; the same leaves at page level keep their default clamp.
  - `spacing-normalization`: flex and grid children have `margin-block: 0`; block-mode first/last edge margins are `0` and inner margins are `var(--space-5)`; a single-paragraph rich text's paragraph has no top or bottom margin; a multi-paragraph rich text keeps the margin between paragraphs.
- [ ] **Steps 1–4.** The real-browser proofs are part of the red phase: write them before the theme rules, watch them fail.
- [ ] **Step 5: Commit** `feat(render): layout items, theme layout defaults, and real-browser layout proofs on public and annotated renders`.

## Task 1.4: phase gate

- [ ] **Step 1:** `COMPOSER_PROCESS_TIMEOUT=0 composer test`, `composer phpcs`, `composer boundaries`, admin gates, `php scripts/build-layout-proof-fixtures && cd tools/runtime-browser && npm test`. If any dormancy or clamping proof fails, change the mechanism per the shared contracts' fallback (pair the rule with the mode class at the same breakpoint), re-run Task 1.3's proofs, and record the chosen mechanism in the shared contracts section of this plan in the same commit.
- [ ] **Step 2:** confirm the production `container` does not yet declare any `layout` capability (API validation of a container carrying `layout.display` fails): `tests/Integration/Content/BlockSettingsValidationTest.php` case "a container refuses layout settings before its cutover".
- [ ] **Step 3: Commit** only if Step 1 changed the mechanism: `fix(render): pair layout rules with their mode at each breakpoint`.

---

## Phase 2 — Container cutover and the Layout tab

## Task 2.1: the container cuts over to layout settings

**Files:**
- Modify: `core/src/Content/Blocks/StarterBlockTypes.php` (`container`: schema keeps `content` and the Background group and gains `element` (enum `div`, `section`, `article`, `aside`, `header`, `footer`); deletes `width`, `min_height`, `content_align`, `layout`, `flex_direction`, `justify`, `align_items`, `gap`, `flex_wrap`; targets `root` box and `inner` stack; capabilities and map exactly §4), `packages/thallo-render/themes/default/templates/blocks/container.twig` (the root tag from `data.element` through an allowlist map defaulting to `div`; `style_classes('inner')`/`style_attrs('inner')` on `__inner`; every superseded modifier and its `{% set %}` deleted; background and overlay markup unchanged), `packages/thallo-render/themes/default/assets/blocks.css` (delete `--contained`, `--narrow`, `--h-*`, `--align-*`, `--layout-flex`, `--dir-*`, `--justify-*`, `--items-*`, `--wrap`, `--gap-*` container rules), `core/src/Content/Regions/RegionDefinitions.php` (unchanged list; `container` stays), `packages/thallo-render/docs/THEMING.md` (container section: targets, `element`, layout properties), `packages/thallo-render/fragments-verified.json` (re-record)
- Modify: `tests/fixtures/layout/` — add `container-parity-*` cases, one per old configuration: `width` contained, narrow, full; `min_height` half and screen with `content_align` top, center, bottom; flex with each direction, justify, align items, wrap and gap. Each case pairs the **old** rendering (a frozen copy of today's `container.twig` output checked in as `tests/fixtures/layout/container-parity-*.old.html`, captured before this task's template change) with the **new** block tree.
- Test: `tests/Integration/Render/StarterTemplatesTest.php` (container renders `<section>` for `element: section`, `<div>` for an unknown element; `__inner` carries inner classes; no superseded modifier class appears), `tests/Integration/Content/BlockSettingsValidationTest.php` (a container refuses the deleted data fields; accepts `layout.display` on `inner`; refuses `layout.display` mapped to `root`), `tests/Integration/Content/BlockFactoryTest.php` (extend: a factory-created container has `element: div` and no layout settings), `tools/runtime-browser/tests/layout.spec.js` (each `container-parity-*` pair compares computed `max-width`, `margin-inline`, `padding-inline`, `min-height`, `display`, `flex-direction`, `justify-content`, `align-items`, `flex-wrap`, `gap` of root and `__inner` and the children's rects at 375, 700, 800, 1280 — equal)
- [ ] **Steps 1–4.** Capture the `.old.html` files first, from the current template, before touching it.
- [ ] **Step 5: Commit** `feat(blocks): the container's layout is style settings on root and inner; its layout data fields are gone`.

## Task 2.2: the tab map and the Layout tab's Box, Container and Children sections

**Files:**
- Create: `admin/src/editor/inspector/tabMap.ts`, `admin/src/editor/inspector/LayoutTab.vue` (props as `StyleTab.vue` plus `parent: BlockInstance | null`, `parentType: BlockType | null`; emits as `StyleTab.vue`; sections `Box`, `Container`, `Children` in this task, `As an item` in Task 2.3; one breakpoint chip row on each section header bound to the single `activeBreakpoint`; the Container section's `layout.display` as `layout-display-<mode>` segmented buttons; `layout.content_width` as token pills with the gutter as a `BoxField`-style two-side row labelled "Gutter" showing the context default as the theme value; `layout.overflow` labelled "Overflow — all sizes"; the Children section rendered from `effectiveDisplay(block, activeBreakpoint, classes)`: flex → direction and wrap as icon segmented controls (`i-lucide-arrow-right`, `-arrow-down`, `-arrow-left`, `-arrow-up`; `i-lucide-wrap-text`), `alignment.content` and `layout.align_items` as icon segmented controls, gaps as a `BoxField` with sides `column` and `row`; grid → `layout.columns` as proportional swatches (`layout-track-<preset>`, each swatch a flex row of cells sized by the preset's parts), `alignment.content`, `layout.align_items`, gaps; block → the text "Children stack. Switch to flex or grid to arrange them." with the display buttons), `admin/src/editor/inspector/controls/IconChoiceControl.vue` (props `choices: { value: string; icon: string; label: string }[]`, `modelValue: string | null`; emits `update:modelValue`; `aria-pressed`, `title` from label)
- Modify: `admin/src/editor/inspector/BlockInspector.vue` (tabs Content, Layout, Style, Advanced; Layout present when any declared path maps to `'layout'`; a multi-selection shows Layout and Style), `admin/src/editor/inspector/StyleTab.vue` (renders only paths whose `tabForPath` is `'style'`), `admin/src/pages/content/[type]/[uuid]/design/[locale].vue` (passes `parent` and `parentType` of the selected block, located through `createBlockListOps(regionsOf).locateById` over `blockFields()`)
- Test: `admin/src/__tests__/tab-map.spec.ts` (every path of `styleProperties()` maps to exactly one tab; `alignment.text` → style; `alignment.self`, `alignment.content`, `width` → layout), `admin/src/__tests__/layout-tab.spec.ts` (a container shows Box, Container and Children; a heading shows Box only; Button shows `alignment.content` in Box's place without Container or Children; switching the active breakpoint to `md` on a container that is flex at base and grid at md re-renders Children as grid; a track swatch click emits `set` for `layout.columns` at the active breakpoint; the gutter shows "theme" and the width-dependent default when absent; Overflow writes with a null breakpoint), `admin/src/__tests__/block-inspector.spec.ts` (tab order; Style no longer lists `width` or `alignment.self`; the existing Style tests adjusted for the moved paths)
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(inspector): a Layout tab — Box, Container and Children sections over the layout contract; tabs by property`.

## Task 2.3: As an item, parent-mode resolution and dormant notices

**Files:**
- Create: `admin/src/editor/inspector/layoutContext.ts`
- Modify: `admin/src/editor/inspector/LayoutTab.vue` (`As an item` section for a block declaring `layout.item` whose `parent` is a container: grid parent → `layout.span` choice pills and `layout.align_self`; flex parent → `layout.basis` pills, `layout.grow` and `layout.shrink` toggles, `layout.align_self`; block parent → "The parent stacks its children." with `layout-item-parent-link` emitting `select-parent`; dormant notices `layout-dormant-parent` on a container ("N grid settings kept for grid mode" / "N flex settings kept for flex mode") and `layout-dormant-item` on an item, counting instance and class-supplied declarations through `dormantPaths`), `BlockInspector.vue` (re-emits `select-parent`), `[locale].vue` (`select-parent` selects the parent; a multi-selection passes `parent` only when every selected block has the same parent, else `null`, and the section is not rendered)
- Test: `admin/src/__tests__/layout-context.spec.ts` (`effectiveDisplay` inherits `base` into `md`; a class supplies `lg`; a reset at `md` returns `block`; `dormantPaths` on a flex container with a class-supplied `layout.columns` returns it for role `parent`; on an item under a grid parent with `layout.basis` returns it for role `item`), `admin/src/__tests__/layout-tab.spec.ts` (under a grid parent the item shows span and align self; switching the active breakpoint where the parent becomes flex shows basis, grow, shrink; under a block parent the link emits `select-parent`; a container switched from grid to flex shows `layout-dormant-parent` with the count including a class value; siblings sharing a parent show the item section with mixed values and only the intersected capabilities; blocks from two parents show no item section)
- Test: `admin/e2e/tests/layout-mode-switch.spec.ts` (on the builder fixture page, select a container that is grid with three children, switch display to flex in the Layout tab, apply; the stage's children are laid out in a row — their rects share a top edge — and switching back restores the three tracks)
- [ ] **Steps 1–4.** The builder proof fixture gains a grid container with three heading children (`scripts/build-builder-proof-fixtures`, `tests/fixtures/composition/five-deep.json` untouched until Task 4.2).
- [ ] **Step 5: Commit** `feat(inspector): As an item controls follow the immediate parent's mode; dormant settings are disclosed both ways`.

---

## Phase 3 — Structure picker

## Task 3.1: presets as pure, cascade-safe plans

**Files:**
- Create: `admin/src/editor/structure/presets.ts` (the `PRESETS` table: §6.5 rows with every owned path written at `base`, `md` and `lg`; column containers per §6.5's column container; `section` and `section-split` per §7.3 and §7.5 with owned paths §7.2; a path a variant does not use is `{ kind: 'reset' }` at every breakpoint; no `delete` entry in this table unless the spec lists one — none do), `planPreset`, `presetDepth`
- Test: `admin/src/__tests__/structure-presets.spec.ts`:
  - `cols-33-67` over an empty container with no settings yields `SetSetting` `layout.display` grid at base, md, lg; `layout.columns` `1`, `1-2`, `1-2`; both gaps `spacing.lg` at every breakpoint; two `InsertBlock` at indexes 0 and 1 into `inner`'s `content` slot whose blocks are column containers (`layout.display` flex, `layout.direction` column, `layout.gap.row` `spacing.md` at every breakpoint).
  - **class conflict:** the container carries a style class setting `layout.columns` `3` at `lg` and `layout.display` `flex` at `lg`; the plan's `lg` writes are explicit values, so resolving the planned settings with the class yields `1-2` and `grid` at `lg`.
  - `from` values in every `SetSetting` equal the container's current instance values (absent or present), so the inverse restores them exactly.
  - `stack` over a container already `block` at every breakpoint yields no operations; over one that is `flex` at `md` yields resets.
  - `section` writes `SetField` `element` `section`, root padding top and bottom `spacing.3xl`, inner `layout.content_width` `width.container`, and `layout.columns` reset at every breakpoint; children in order header group, content, links; `section-split` writes `layout.display` flex at base and md and grid at lg with `layout.columns` `2` at lg.
  - `presetDepth('section')` is 3; `presetDepth('cols-33-67')` is 2.
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(editor): structure presets as scoped, cascade-safe operation plans`.

## Task 3.2: the picker's session state and commit sequence

**Files:**
- Create: `admin/src/editor/structure/structurePicker.ts` — `StructurePickerDeps`: `factory(slug): Promise<BlockInstance>`, `allocateId(): string`, `findBlock(id): BlockInstance | null`, `isEmpty(block): boolean`, `validate(ops: OperationBody[]): Legality`, `commit(ops: OperationBody[]): void` (one transaction), `notify(message: string): void`, `publishOffers(offers): void`. States per id: `pending` → `preparing` → `ended`; `choose` accepted only in `pending`, sets `preparing`, runs §6.3 steps 2–6 with a token checked after every await; `skip`, deletion and `onDocumentChange` with an `InsertBlock`, `InsertBlocks`, `MoveBlock` or `DuplicateBlock` whose position is inside the container's `content` slot move the id to `ended` from `pending` or `preparing`; `ended` ignores every later message; a legality refusal returns `preparing` to `pending` and republishes the offer with reasons; a factory failure returns to `pending` and notifies; every preset whose plan is non-empty commits, and every successful choose ends the offer (a Stack with an empty plan ends it without committing).
- Modify: `[locale].vue` (one picker instance; `offer(id)` from `insertFromPalette` and the palette drag's `onDrop` when the inserted block is a `container` with an empty `content` — **not** from duplicate, paste, version restore, redo, or preset children; `onDocumentChange` fed from the same place ops are recorded; `validate` = `checkInsert` for each `InsertBlock` in plan order against a document with the earlier inserts applied, plus `presetDepth` against the container's depth; `commit` = `history.beginTransaction()`, `history.record` each op, `commitNow()`, `replayHistory()`, `scheduleCommit(true)`; the tile legality in the offer is `enabled: validate(planPreset(container, preset, placeholderInstances)).ok` with the legality message as `reason`)
- Test: `admin/src/__tests__/structure-picker.spec.ts`:
  - offer → choose `cols-33-67` → one `commit` with the plan; state `ended`; undo in a real history returns the container's settings and children exactly; redo reuses the ids.
  - duplicate `choose` while preparing → one factory call set, one commit.
  - `skip` while the factory is pending → no commit when it resolves; a later `choose` ignored.
  - factory rejects → no commit, `notify` called, state `pending`, a second `choose` works.
  - offer → `onDocumentChange([InsertBlock into the container])` → `ended`; then an undo op sequence and a delayed `choose` → no commit.
  - emptiness lost during preparation (the container gains a child before the factory resolves) → no commit, `ended`.
  - legality refusal (validate returns `{ ok: false, message }`) → no commit, state `pending`, `publishOffers` called with the reason.
  - `stack` on an already-stacked container → no commit, `ended`.
- Test: `admin/src/__tests__/canvas-page.spec.ts` (inserting a container from the Blocks tab publishes an offer for its id; duplicating a container publishes none; a preset's child containers get no offer; restoring a version publishes none)
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(editor): the structure picker — session-only offers, prepare-then-commit in one transaction`.

## Task 3.3: the stage tiles

**Files:**
- Modify: `packages/thallo-render/assets/preview/preview-bridge.js` (`thallo:structure-offer` stores offers by id and re-marks slots; in `markEmptySlots`, an empty `content` slot whose owning block id has an offer gets the structure placeholder instead of the ordinary one: a `.thallo-structure-offer` box with one `button[data-structure-tile=<key>]` per preset — disabled with `title` = reason when not enabled — each drawn as a proportional swatch, and a `button[data-structure-skip]`; the click handler posts `thallo:structure-choose { id, preset }` or `thallo:structure-skip { id }` and never selects the owner; an offer absent from the latest message removes its placeholder), `packages/thallo-render/assets/preview/preview.css` (the tile grid and swatches), `admin/src/composables/useCanvasBridge.ts` (`structureOffer`, `onStructureChoose`, `onStructureSkip`, message validation)
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts` (an offer renders tiles in that container's empty slot only; a disabled tile carries the reason and posts nothing; a tile click posts `structure-choose` without `block-select`; Skip posts `structure-skip`; a later offer without the id removes the tiles and restores the ordinary placeholder; a container that gains a child shows no tiles), `admin/src/__tests__/canvas-bridge.spec.ts` (malformed choose and skip messages are dropped)
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(canvas): structure picker tiles in a new container's empty slot`.

## Task 3.4: picker proofs

**Files:**
- Create: `admin/e2e/tests/structure-picker.spec.ts`:
  - insert a Container from the Blocks tab; the tiles appear in it; click `structure-tile-cols-33-67`; history has one new entry; the stage shows two columns at 1280px; undo → the container is empty and shows no tiles; redo → the same child ids.
  - a container placed where one nesting level remains shows `structure-tile-section` disabled with the depth reason.
  - offer → drag a heading into the container → undo → click a tile left over in a stale frame (dispatched via the bridge message) → no history entry.
- Modify: `admin/e2e/README.md` (the new proofs)
- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `test(builder): structure picker proofs — one transaction, undo and redo, depth refusal, stale choose`.

---

## Phase 4 — Retirement

## Task 4.1: compositions for the retired types and their matrices

**Files:**
- Create: `tests/fixtures/layout/retire-section-*.json`, `retire-columns-*.json`, `retire-grid-*.json`, `retire-leaf-*.json` — each a pair: the **old** block tree (Section, Columns or Grid, rendered by today's templates) and the **new** composition (§7.3–§7.5, §7.8, §7.9) — covering exactly the matrices: §7.6 (orientation × reverse, four backgrounds, per-part alignment start/center/end, each absent header field and all three, empty content and content with blocks, no link, one link, several wrapping links), §7.8 (every ratio, each vertical alignment, one and several blocks per column, empty columns), §7.9 (counts 1–4 per its table, each gap, full and partial last rows), §7.10 (heading, rich text, button, alone and together, in a Section content area, a Section links row, a Columns column and a Grid cell, each also with authored `width`, `alignment.self` and padding)
- Modify: `scripts/build-layout-proof-fixtures` (renders both sides of each pair, public and annotated)
- Test: `tools/runtime-browser/tests/retirement.spec.js` at widths 375, 700, 800, 1280, on both renders:
  - **semantics:** same landmark and heading elements and levels, same accessible names, same reading order — except `retire-section-*-reversed`, whose new reading order is content, header, links (§7.4).
  - **computed equivalence** for spacing, width, alignment, colour and type size of each corresponding element, **except** the listed §7.7 and §7.10 rows, each asserted as its specified result: title `font-size` equals the theme `h2` clamp; description `font-size` equals `typography.size.lg`; inverted description colour equals `--accent-ink` at full strength; leaf blocks inside a retired type fill their cell with `padding-inline: 0` unless authored; Columns and Grid two-column step at 768px (at 700 the new side stacks where the old did not — asserted as the recorded difference).
  - **closed table:** a helper collects every computed difference not in the allowlist for its case and fails with the element path and property.
  - composed-container spacing (§7.6 normalization): no difference allowed.
- [ ] **Steps 1–4.** Write the matrices first; watch each composition fail before adjusting presets and theme defaults. A difference outside the closed tables is fixed, never allowlisted, unless the spec is amended by the user's decision first.
- [ ] **Step 5: Commit** `test(render): retirement matrices — Section, Columns, Grid and leaf blocks against their Container compositions`.

## Task 4.2: remove Columns, Grid and Section

**Files:** start from the inventory, re-run and reviewed line by line (commerce tables, navigation's `layout=columns`, tenancy reports and `ShopCatalogController` are unrelated uses of the words and stay):

```bash
grep -rlnE "'(columns|grid|section)'|\"(columns|grid|section)\"|thallo-block-(columns|grid|section)|blocks/(columns|grid|section)\.twig|col_[123]" \
  --include='*.php' --include='*.ts' --include='*.vue' --include='*.twig' --include='*.css' --include='*.json' --include='*.md' --include='*.js' . \
  | grep -v "node_modules\|vendor/\|core/resources/admin\|\.git/\|docs/internal/\|storage/"
```

- Delete: `packages/thallo-render/themes/default/templates/blocks/{columns,grid,section}.twig`; their CSS in `blocks.css` (the `section`, `columns` and `grid` rule groups, including masonry); `core/database/migrations/028_AlignmentFieldsOnSectionBlockType.php`; `tests/Integration/Blocks/SectionAlignmentMigrationTest.php`; `admin/src/fields/components/blocks/ColumnsLayoutField.vue` and `admin/src/__tests__/columnsLayoutField.spec.ts`.
- Modify: `core/src/Content/Blocks/StarterBlockTypes.php` (the three definitions removed); `core/database/migrations/021_ReseedBlockTypesForThemeRewrite.php` (`DRIFTED` loses `section` and `grid`); `core/src/Content/Regions/RegionDefinitions.php` (`columns` removed from both allowlists); `admin/src/fields/components/blocks/BlockFields.vue` (the columns rule and the layout picker); `admin/src/pages/content/[type]/[uuid]/design/components/CanvasOutline.vue` (the `col_3` rule); `admin/src/fields/components/blocks/BlockList.vue` and `admin/src/editor/palette/target.ts` (any columns-specific branch); `packages/thallo-render/assets/preview/preview-bridge.js` (any columns-specific branch); `admin/src/queries/blockFactory.spec.ts` (fixtures use `container`); `tests/fixtures/composition/five-deep.json` and every `tests/fixtures/structure/legality/*.json` naming a retired type (rewritten to containers with the same depth and slot shape — the legality expectations do not change); `scripts/build-builder-proof-fixtures` and the builder proofs naming `sect…`/`cols…` ids (`admin/e2e/tests/*.spec.ts`, rebuilt ids from the rewritten fixture); every admin spec in the inventory; `packages/thallo-render/docs/THEMING.md` and `packages/thallo-render/docs/refs.md` (the three types removed, Container documented); `packages/thallo-render/fragments-verified.json` (re-record); `tests/Integration/**` tests in the inventory (retired types removed from fixtures and expectations); `CHANGELOG.md` (Unreleased: Added — Layout tab, structure picker, layout properties; Changed — Container; Removed — Columns, Grid, Section, masonry; the §7.7 and §7.10 behaviour changes and the 768px column step listed verbatim)
- Test: `tests/Integration/Content/SeedBlockTypesTest.php` (the starter list no longer includes the three), `tests/Integration/Render/TemplateLinterTest.php` (no template references a missing type), the inventory command returns only the reviewed unrelated uses
- [ ] **Steps 1–4.** Run the inventory command before and after; the after-list is pasted into the commit message body.
- [ ] **Step 5: Commit** `feat(blocks)!: Columns, Grid and Section are retired — Container compositions replace them`.

## Task 4.3: release gates

- [ ] **Step 1:** one at a time: `COMPOSER_PROCESS_TIMEOUT=0 composer test`, `composer phpcs`, `composer boundaries`, `COMPOSER_PROCESS_TIMEOUT=0 composer test:skeleton`, `COMPOSER_PROCESS_TIMEOUT=0 composer test:distribution`; admin gates; `cd admin/e2e && pnpm test`; `php scripts/build-layout-proof-fixtures && cd tools/runtime-browser && npm test`.
- [ ] **Step 2:** report to the user with the gate table. The beta cut (`scripts/release-bake`, release commit, `scripts/verify-dist-archive`) happens only on the user's word.

## Self-review

- **Spec coverage.** §2 → Global Constraints. §3.1–3.2 → 1.1. §3.3, §3.7 → 1.2 (mechanism), 1.3 and 1.4 (proofs). §3.4, §3.5, §3.8 → 1.3 (theme defaults and proofs). §3.6 → 1.3 (linter, release, authored proofs). §3.9 → Removed in 4.2's CHANGELOG. §4 → 2.1 (including the parity table and factory defaults). §5 → 2.2 (tab map, Box, Container, Children, Button and Navigation), 2.3 (As an item, dormant notices, multi-selection, rendering proof). §6.1–6.4 → 3.1, 3.2. §6.2 → 3.3. §6.5 → 3.1. §7.1–7.10 → 3.1 (compositions), 4.1 (matrices). §8 → 4.2. §9 → the tests of every task. §10 → phases, 4.3.
- **Placeholder scan.** No TBD or "handle edge cases"; every test names its cases.
- **Type consistency.** `effectiveDisplay`, `dormantPaths`, `tabForPath`, `planPreset`, `presetDepth`, `createStructurePicker` and the bridge message names are defined once in Shared contracts and used with the same signatures in 2.2, 2.3, 3.1, 3.2 and 3.3.
