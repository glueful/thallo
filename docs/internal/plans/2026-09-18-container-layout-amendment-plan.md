# Container Layout Amendment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Container arranges its children in two modes, Flex and Grid — a flex column is the default and replaces block flow — the Layout tab sets the mode once, in a Container section that comes first and holds the mode's controls; a grid is drawn on the stage; and "Fill empty cells" gives a grid real, independently fillable columns.

**Architecture:** `block` leaves `layout.display` in the contract, the compiler and the admin mirror together. The theme's default for a container's content area becomes `display: flex; flex-direction: column` with both gaps at `spacing.xl` (`--space-5`, the margin it replaces), so the per-mode, per-breakpoint margin rules collapse into one: a container's direct children carry no default vertical margin. The Layout tab is reordered and its Children section folded into Container. The stage's grid outline is a bridge-owned layer positioned from the slot's resolved tracks — outside the slot, so no structural selector or placement rule can see it. Fill is a pure occupancy function plus a small session controller that reuses the block factory and the page's transaction path, with its own preparation conditions.

**Tech Stack:** PHP 8.4 (contracts, render pack), theme CSS, the preview bridge (plain script), Nuxt UI admin (Vue 3, vitest), Playwright for the builder proofs (`admin/e2e`) and the real-browser layout proofs (`tools/runtime-browser`, npm).

**Spec:** `docs/internal/superpowers/specs/2026-09-17-container-layout-design.md`, as amended 2026-09-18. § numbers refer to it; **§11** is the amendment, and §3.2, §3.8, §4, §5, §6.5, §7.11 and §9 carry amended text.

## Global Constraints

- **No conversion, no compatibility** (§11.1). A stored `layout.display` of `block` is invalid; nothing reads, maps or rewrites it. The only code that knows the word is the generic "a stored choice the contract no longer offers" state (Task 1.2), which names no value.
- **An untouched stack keeps its distances** (§3.2, §3.8). The claim is that narrow and is held by the frozen references: `tools/runtime-browser` stays at zero unexplained differences. A difference the change produces is fixed in the theme defaults — never allowlisted without a spec amendment by the user.
- **Recorded consequence** (§3.8): a flex row or grid whose gaps were never set gains `spacing.xl`. It goes in the CHANGELOG verbatim.
- **Theme defaults only, authored values win.** The new defaults live in `@layer theme`; nothing is written into `settings.style` to reproduce them, and a preset's skip-when-equal rule (§6.4) compares against them.
- **The outline is inert** (§11.2): it is never a child of the slot, takes no pointer events, and the proof measures children's boxes identical with it shown and hidden.
- **The placeholder's rule is unchanged** (§11.2): only while the slot is empty. Only its size in an empty grid slot changes.
- **Fill has its own preparation conditions** (§11.3) — it does not reuse the picker's "new and empty" qualification.
- **The PHP contract and the admin mirror move together**, in one commit, with `packages/thallo-render/resolver-fixtures/v1`.
- PHP gates: `COMPOSER_PROCESS_TIMEOUT=0 composer test` (one suite at a time, never concurrent), `composer phpcs` judged by exit code, `composer boundaries`; a theme or template change re-records `THALLO_RECORD_FRAGMENT_VERIFICATION=1 DB_PGSQL_DATABASE=app_test APP_ENV=testing vendor/bin/phpunit --filter FragmentVerificationTest`.
- Admin gates: `pnpm exec vitest run`, `pnpm type-check`, `pnpm lint`, `pnpm fmt:check`; `pnpm exec oxfmt <files>` only; builder proofs `cd admin/e2e && pnpm test` after `scripts/build-builder-proof-fixtures`.
- Real-browser gate, from a clean state: `php scripts/build-layout-proof-fixtures && php scripts/build-parity-fixtures && cd tools/runtime-browser && npm test` (npm, not pnpm — the harness's lockfile is `package-lock.json`).
- Searches that must see gitignored paths use `/usr/bin/grep` or `git grep`: the shell's `grep` is a wrapper that skips them.
- `git diff` before every commit; no AI attribution trailers; never push, never tag. The beta is cut only on the user's word.

**Steps 1–4**, wherever a task says so, are the TDD cycle: write the failing tests the task names, run them and watch each fail for the stated reason, implement the minimum, run the file and the task's gates green. **Step 5** is the commit with the message given.

## Shared contracts (named once, used by every task)

```ts
// admin/src/editor/inspector/layoutContext.ts
export type LayoutDisplay = 'flex' | 'grid'                     // was 'block' | 'flex' | 'grid'
export function effectiveDisplay(block, breakpoint, classes): LayoutDisplay   // default 'flex'

// admin/src/style/resolver.ts
/**
 * Where the value in force at `target` is DECLARED. `Resolution.breakpoint` cannot answer this: it
 * is always the breakpoint that was asked about, so an `md` declaration inherited at `lg` reports
 * `lg`. This walks the same cascade and returns the layer and breakpoint that actually hold it.
 */
export interface DeclarationOrigin { breakpoint: Breakpoint; source: string }   // 'instance' | 'class:<id>'
export function declarationOrigin(path: string, classes: StyleClassRef[], style: unknown,
  def: PropertyDefinition, target: Breakpoint): DeclarationOrigin | null        // null: theme default

// admin/src/editor/inspector/layoutContext.ts (continued)
/** A stored choice the contract no longer offers, where the cascade says it is in force. */
export interface InvalidChoice {
  path: string
  value: string                       // the stored value, verbatim
  breakpoint: Breakpoint              // from declarationOrigin — where it is DECLARED
  source: 'instance' | { classId: string }
}
export function invalidChoiceAt(path: string, block: BlockInstance, breakpoint: Breakpoint,
  classes: StyleClassRef[]): InvalidChoice | null
/** Every invalid choice a style record declares itself, at every breakpoint — the class editor's view. */
export function invalidChoicesIn(style: unknown): Omit<InvalidChoice, 'source'>[]
/** The value "Replace" writes for a path; a path absent from it offers Remove only. Names no stored value. */
export const REPLACEMENT: Record<string, string>      // { 'layout.display': 'flex' }

// admin/src/editor/structure/gridOccupancy.ts   (pure; no Vue, no document mutation)
export function trackCount(columns: string): number            // '3' → 3, '12' → 12, '1-2-1' → 3
export function effectiveSpan(span: string | null, tracks: number): number   // 'full' and >tracks → tracks
/** Free cells after the last item, by ordinary append placement. 0 when the last row is full. */
export function lastRowFree(container: BlockInstance, breakpoint: Breakpoint,
  classesFor: (id: string) => StyleClassRef[]): number

// admin/src/editor/structure/gridFill.ts
export type FillRefusal = 'not-grid' | 'row-full' | 'no-room-for-content' | 'illegal'
export interface FillAvailability { enabled: boolean; cells: number; reason?: string }
export function createGridFill(deps: GridFillDeps): {
  availability(id: string, breakpoint: Breakpoint): FillAvailability
  fill(id: string, breakpoint: Breakpoint): Promise<void>
  preparing(id: string): boolean
}
/** What the stage is told, one entry per EMPTY grid container at the active breakpoint. */
export interface PublishedFillState { id: string; enabled: boolean; preparing: boolean; reason?: string }

// admin/src/composables/useCanvasBridge.ts
publishGridFill(states: PublishedFillState[]): void   // the complete list, every time, like publishStructureOffers
onGridFill(cb: (id: string) => void): void
```

```js
// packages/thallo-render/assets/preview/preview-bridge.js  — messages
// stage → parent
{ type: 'thallo:grid-fill', nonce, id }                 // the empty grid placeholder's Fill button
// parent → stage: the ONLY source of the stage button's existence and state
{ type: 'thallo:grid-fill-state', nonce, states: [{ id, enabled, preparing, reason? }] }
```

## File Structure

| File | Responsibility |
|---|---|
| `packages/thallo-contracts/src/Style/StyleSchema.php` | `layout.display` choices `flex, grid`; `VERSION` 3 |
| `packages/thallo-render/src/Style/StyleCompiler.php` | the `block` declaration removed; `VERSION` 4 |
| `packages/thallo-render/themes/default/assets/blocks.css` | the content area's default mode, direction and gaps; one margin rule; the placed-child fill |
| `admin/src/style/schema.ts`, `admin/src/editor/inspector/layoutContext.ts`, `admin/src/editor/structure/presets.ts` | the admin mirror of the contract and its default |
| `admin/src/editor/inspector/LayoutTab.vue` | sections Container → Box → As an item; the Layout group; the invalid-choice state; the Fill button |
| `admin/src/style/resolver.ts` | `declarationOrigin` — the layer and breakpoint that declare the value in force |
| `admin/src/editor/inspector/controls/InvalidChoiceNotice.vue` (create) | the invalid value, its breakpoint, its source and the two actions |
| `admin/src/pages/settings/style-classes/components/StyleClassEditor.vue` | the class's own invalid choices, repairable where "Open class" lands |
| `admin/src/editor/structure/gridOccupancy.ts` (create) | pure occupancy at a breakpoint |
| `admin/src/editor/structure/gridFill.ts` (create) | Fill's session controller |
| `packages/thallo-render/assets/preview/preview-bridge.js`, `preview.css` | the grid outline layer; the first-cell placeholder in an empty grid; the placeholder's Fill button |
| `admin/src/composables/useCanvasBridge.ts` | `onGridFill` |
| `admin/src/pages/content/[type]/[uuid]/design/[locale].vue` | wiring Fill into the page's factory, legality and transaction path |

---

## Phase 1 — Flex and Grid only

## Task 1.1: `block` leaves the contract; a flex column is the default (one commit)

The contract, the compiler, the admin mirror and the theme default move together: split, any one of them leaves the editor saying one thing while the page renders another.

**Files:**
- Modify: `packages/thallo-contracts/src/Style/StyleSchema.php` (`layout.display` → `['flex', 'grid']`; `VERSION = 3`); `packages/thallo-render/src/Style/StyleCompiler.php` (`CHOICE_DECLARATIONS['layout.display']` loses `block`; `VERSION = 4`); `packages/thallo-render/themes/default/assets/blocks.css`; `admin/src/style/schema.ts`; `admin/src/editor/inspector/layoutContext.ts` (`LayoutDisplay`, `effectiveDisplay` default `'flex'`, `dormantPaths` without the block branch); `admin/src/editor/structure/presets.ts` (`stack` owns `layout.display` = flex and `layout.direction` = column; `THEME_DEFAULT` = `{ 'layout.display': choice('flex'), 'layout.direction': choice('column'), 'layout.gap.row': token('spacing.xl'), 'layout.gap.column': token('spacing.xl') }`); `admin/src/editor/inspector/LayoutTab.vue` (only what no longer type-checks: the `display === 'block'` and `parentDisplay === 'block'` branches and their two lines of copy — the restructure is Task 2.1); `packages/thallo-render/resolver-fixtures/v1/layout.json`; `packages/thallo-render/fragments-verified.json` (re-record); `packages/thallo-render/docs/THEMING.md` §12.3a.
- Modify (fixtures): delete `tests/fixtures/layout/cases/spacing-flex-then-block-md.json` and `spacing-grid-then-block-lg.json` — they proved margins coming back in block mode, which no longer exists; rewrite `spacing-normalization.json` to its two modes; create `spacing-untouched-stack.json` (a container with nothing authored and four default-margin children), `placed-child-in-flex-column.json` (three children with `width` = `width.content` and `alignment.self` start, center, end) and `width-in-flex-row.json` (spec §11.4, added in review: the placed child's mechanism is the `width` utility stating `width: 100%`, a width-contract change, so its row behaviour is proven deliberately — basis auto in a nowrap row, wrap, an explicit basis, and a width reset at `md`, every number derived from the flexbox algorithm).
- Test: `tests/Unit/Contracts/StyleSchemaTest.php`; `tests/Integration/Content/BlockSettingsValidationTest.php`; `tests/Integration/Content/StyleClassApiTest.php`; `tests/Integration/Render/ContainerThemeDefaultsTest.php`; `tests/Integration/Render/LayoutFixturesRenderTest.php`; `tools/runtime-browser/tests/layout.spec.js`; `admin/src/__tests__/{layout-context,structure-presets,layout-tab}.spec.ts`.

**The theme rules** (`@layer theme`, replacing the per-mode, per-breakpoint block in `blocks.css` that begins "Spacing normalization (spec §3.8)"):

```css
.thallo-block-container__inner {
  display: flex;
  flex-direction: column;
  gap: var(--space-5);
}
/* One source of spacing (spec §3.8): the gaps. No mode and no breakpoint restores a margin. */
.thallo-block-container__inner > .thallo-block,
.thallo-block-container__inner > .thallo-preview-block > .thallo-block { margin-block: 0; }
```

The `.t-display-block` / `.t-display-reset` margin rules and their `md:` and `lg:` copies are deleted with it. The placed child (§3.8): an item whose inline margins are `auto` loses the cross-axis stretch, so the rule that gives the content area its `width: 100%` is the model — the mechanism is chosen here and must pass `placed-child-in-flex-column` without moving any frozen reference.

**Interfaces:**
- Consumes: `StyleSchema::property()`, `ClassNames`, the resolved-emission rule for the `layout` group (unchanged).
- Produces: `LayoutDisplay = 'flex' | 'grid'`; `effectiveDisplay()` defaulting to `'flex'`; `THEME_DEFAULT` as above, which Task 3.2's column containers and every preset's skip-when-equal read.

**Tests (each watched failing first):**
- `StyleSchemaTest`: `layout.display` choices are exactly `flex, grid`; `VERSION` is 3.
- `BlockSettingsValidationTest`: a container declaring `layout.display` = `block` at `md` is refused naming `settings.style.layout.display.md`. `tests/Integration/Content/StyleClassApiTest.php`: a **style class** declaring it is refused by the class endpoint the same way.
- `ContainerThemeDefaultsTest`: the theme layer gives the content area `display: flex`, `flex-direction: column` and `gap: var(--space-5)`; no selector in `blocks.css` contains `t-display-block`.
- `LayoutFixturesRenderTest`: the two new cases emit and match public/canvas class parity; the deleted cases are gone from `LayoutFixtureBlockType::cases()`.
- `layout.spec.js`: `spacing-untouched-stack` — consecutive children `--space-5` apart, first and last flush with the content area, at all three widths, both renderings; `placed-child-in-flex-column` — each child's width is **the smaller of the content area's available inline space and the authored maximum** (at a viewport narrower than `--content` that is the available space, not the measure), its position the start, centre and end of the content area, and the page has **no horizontal overflow** (`scrollWidth` ≤ `clientWidth` on the document element) at every width; the 29 container mechanism checks expect the content area `flex` in every case (`innerDisplay` was `block` for the old block containers).
- `layout.spec.js`, **the surviving `spacing-flex-then-reset-md`**: it expects block-flow margins to come back after the reset ("Reset is the same state as block … so the margins return identically"). A reset now lands on the theme default, which is a flex column: the expectation becomes no child margin at any width, with the children `--space-5` apart **at every width** by the default gap — the fixture authors no gap, below `md` or above it. Resetting `layout.display` resets that one property: an independently authored gap is not touched by it, and the case asserts nothing that would imply it is. The expectations for the two deleted cases leave the table with them.
- **The frozen references**: the whole `tools/runtime-browser` suite, 0 new failures. Any difference is fixed in the rules above.
- Admin: `effectiveDisplay` returns `flex` for an untouched container and after a reset; `dormantPaths` lists only the other mode's paths; the Stack preset over an untouched container plans **no operations** (both owned paths equal the theme default), and over a grid container plans display and direction at every breakpoint.

- [x] **Steps 1–4.** Re-record the verified fragments last.
- [x] **Step 5: Commit** `feat(style)!: a container arranges its children as Flex or Grid — block display leaves the contract`.

## Task 1.2: a stored choice the contract no longer offers is shown as invalid — and can be repaired wherever it is stored

**Files:**
- Create: `admin/src/editor/inspector/controls/InvalidChoiceNotice.vue`; `admin/src/__tests__/{declaration-origin,invalid-choice,style-class-editor-repair}.spec.ts`.
- Modify: `admin/src/style/resolver.ts` (`declarationOrigin`); `admin/src/editor/inspector/layoutContext.ts` (`invalidChoiceAt`, `invalidChoicesIn`, `REPLACEMENT`); `admin/src/editor/inspector/LayoutTab.vue` (the Layout control renders the notice instead of a selection when `invalidChoiceAt('layout.display', …)` answers); `admin/src/pages/settings/style-classes/components/StyleClassEditor.vue`.
- Test (PHP): `tests/Integration/Content/StyleClassApiTest.php` — where the class endpoint's validation is already tested; it holds the save-succeeds half of the proof.

**Interfaces:**
- Consumes: `resolve()`, `Resolution` (`value`, `source`), `propertyDefinition(path).choices`.
- Produces: `declarationOrigin`, `InvalidChoice`, `invalidChoiceAt`, `invalidChoicesIn`, `REPLACEMENT` (Shared contracts). All generic over choice paths: none names a stored value.

**Where the declaration is.** `Resolution.breakpoint` is the breakpoint asked about — `resolveAt()` returns `breakpoint: target` on every path — so it cannot say where to repair. `declarationOrigin` walks the same layers from the target down to `base` and answers the first layer and breakpoint that declare the path. An `md` value seen while editing `lg` is repaired at `md`.

**In the block inspector:** the notice reads "`<value>` is not a layout this version offers — set at `<breakpoint>`", never the theme default in its place. From the instance: **Replace with Flex** writes one `SetSetting` of `REPLACEMENT[path]` at the **declaring** breakpoint; **Remove** deletes that one declaration. From a class: it names the class, offers neither write, and **Open class** is a router link to `/settings/style-classes/<id>` (the app's base supplies `/admin/`).

**In the class editor.** `StyleClassEditor.vue` renders only `StyleTab`, and `StyleTab` filters to `pathsForTab(shared, 'style')` — so today the page "Open class" leads to cannot show, let alone repair, a layout value. It gains a **Needs attention** group above the tab, fed by `invalidChoicesIn(style)`: one `InvalidChoiceNotice` per invalid declaration, each with Replace (where `REPLACEMENT` has the path) and Remove, acting on the class's own declaration at its breakpoint. This is the repair path only; editing layout values in a class generally is not part of this plan.

**Tests:**
- `declaration-origin`: an instance `md` declaration asked at `lg` → `{ md, instance }`; the same from a class → `{ md, class:<id> }`; an instance declaration outranking a class at the same breakpoint; a `reset` at `lg` over an `md` value → the reset's own origin; nothing declared → null; a non-responsive path → `base`.
- `invalid-choice`: a local `block` at `md` → the notice with the value, `md`, and both actions, no choice pressed; **with the active breakpoint at `lg` and the declaration at `md`, Replace and Remove each write at `md`**, one operation, and the notice clears; the same value from a class → the class named, no write actions, the link's `to` exactly `/settings/style-classes/<id>`; an untouched container → no notice; a valid `grid` → no notice.
- `style-class-editor-repair`, end to end: a class whose stored style declares `layout.display` = `block` at `md` → the Needs attention group names the value and `md`; **Replace** → the save payload carries `flex` at `md` and no `block`; **Remove** → the payload has no `layout.display.md`; a class with no invalid choice → no group; the group also lists a second invalid path and repairs each independently.
- PHP: the class endpoint refuses the class while it declares `block` (Task 1.1's test), and **accepts the two payloads above** — Replace's and Remove's — so open class → repair → save is proven to succeed, not only to send.

- [x] **Steps 1–4.**
- [x] **Step 5: Commit** `feat(inspector): a stored layout the contract no longer offers is shown as invalid, and repaired where it is stored`.

## Task 1.3: phase gate

- [x] **Step 1:** PHP gates, admin gates, the real-browser gate from a clean state, the builder proofs.
- [x] **Step 2:** `git grep -n "t-display-block\|'block', 'flex'\|choice('block')"` returns nothing in **non-test source** (outside `docs/internal`, `tests/`, `admin/src/__tests__` and `admin/e2e`). Tests legitimately hold the word: they assert that `block` is refused, that `t-display-block` is gone from the theme, and that a stored `block` is shown as invalid.
- [x] **Step 3:** no commit unless a gate required a fix; any fix is its own commit naming the failing proof.

---

## Phase 2 — The Layout tab

## Task 2.1: Container first, the mode set once, its controls under Layout

**Files:**
- Modify: `admin/src/editor/inspector/LayoutTab.vue`; `admin/src/__tests__/layout-tab.spec.ts`; `admin/e2e/tests/layout-mode-switch.spec.ts`.

**The sections, in order (§5):**
1. **Container** — **Layout** (`layout.display`: Flex, Grid — text choices; the icon control that set the mode a second time is removed); directly under it, in the same group, the controls of the mode in force at the active breakpoint (Flex: direction, wrap, `alignment.content`, `layout.align_items`, the linked gap row; Grid: track swatches, `alignment.content`, `layout.align_items`, the linked gap row); then `layout.content_width` and `layout.gutter`; then the dormant notice.
2. **Box** — unchanged contents.
3. **As an item** — unchanged contents, without the block line (gone in 1.1).

The section labelled Children no longer exists. `data-test` names of controls that survive are kept (`track-<n>`, `layout-field-<path>`), so the builder proof changes only where it named the removed section or the mode's old control.

**Tests:** the section headings are exactly `Container`, `Box`, `As an item`, in that order, for a container that is also an item; a non-container block shows `Box` and `As an item` only; the group under Container is headed `Layout` and offers exactly Flex and Grid; one element in the tab sets `layout.display`; with Flex in force the direction, wrap, justify, align and gap controls sit between Layout and Content width, and no track swatch renders; with Grid in force the swatches do and direction does not; the dormant notice still names the kept-but-unused paths after a Flex → Grid switch; Button and Navigation still show `alignment.content` in Box — the existing `contentOnly` rule, which does not depend on the removed section (§5).

- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(inspector): the Layout tab opens on Container — the mode set once, its controls under Layout`.

---

## Phase 3 — The grid on the stage

## Task 3.1: the grid outline, and the first-cell placeholder

**Files:**
- Modify: `packages/thallo-render/assets/preview/preview-bridge.js`; `packages/thallo-render/assets/preview/preview.css`; `admin/src/__tests__/preview-bridge-dom.spec.ts`; `scripts/build-builder-proof-fixtures`; `admin/e2e/tests/{smoke,outline-reparent}.spec.ts` (the fixture gains two containers); create `admin/e2e/tests/grid-outline.spec.ts`.

**The builder proof fixture** gains two root blocks after the call to action: `gridempty001` (grid, 3 tracks, empty) and `gridspan0001` (grid, 3 tracks, one heading with `layout.span` = 2). The smoke count and the Move to… option count rise with them; both specs state the new numbers and why.

**The outline (§11.2):**
- A layer that is a child of `body`, positioned from the slot's `getBoundingClientRect()` — never a child of the slot, so `:first-child`/`:last-child`, `childWrappersOf()`, `markEmptySlots()` and grid placement cannot see it. `pointer-events: none`.
- Cells come from the slot's computed `grid-template-columns`, `grid-template-rows`, `column-gap` and `row-gap` — the resolved tracks, so a `1-2-1` preset outlines those proportions and the breakpoint being edited decides the count.
- Shown for a slot whose computed `display` is `grid` while it is empty, while it or a descendant block is selected, and while a drag's zone is inside it; removed otherwise. Re-measured after every load, patch, swap, mirror, resize and scroll, as the placeholder marking is.

**The placeholder:** `preview.css` keeps `grid-column: 1 / -1` for every slot except an **empty grid** slot, where the placeholder takes one cell (`[data-thallo-slot-empty]` whose computed display is grid — the bridge marks it `data-thallo-slot-grid`, since CSS cannot read computed display). When it appears is untouched: `markEmptySlots()` decides, as today.

**Tests (bridge DOM):** empty 3-track grid → a layer with three cells and the placeholder in the first track; one child → three cells, no placeholder; a full row → outline only; a child spanning 2 of 3 → a cell across both tracks and one free; a `1-2-1` grid → three cells in those proportions; a flex slot → no layer and the full-row placeholder; the layer is not inside any `[data-thallo-slot]`; deselect → the layer is removed; an offered container (§6.2) shows tiles and no outline.
**Tests (builder proof, real browser) — inert:** each child's bounding box in `gridspan0001` and `grid00000001` is identical before selection and with the outline shown; the layer takes no pointer events (a click through it selects the block beneath); nothing of it exists in `tools/runtime-browser/fixtures/layout/*.html` public pages.
**Tests (builder proof, real browser) — aligned.** Identical child boxes prove the outline moves nothing; they do not prove it stays where the grid is. With the outline shown, each outlined cell's rectangle must equal the rectangle of the track it outlines — computed in the test from the slot's resolved `grid-template-columns`, gaps and padding box — within 1px: (a) as first drawn; (b) after the viewport is resized across the `md` boundary, where the track count changes, and the cell count with it; (c) after the stage is scrolled; (d) after an image inserted above the grid by the test, whose response the route holds back, finishes loading and pushes the grid down. The repositioning triggers stay the implementation's choice; these four are what they must satisfy.

- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(canvas): a grid is drawn on the stage — outlined tracks, and the empty grid's placeholder in its first cell`.

## Task 3.2: occupancy — which cells an appended block can reach

**Files:**
- Create: `admin/src/editor/structure/gridOccupancy.ts`; `admin/src/__tests__/grid-occupancy.spec.ts`.

**Interfaces:**
- Consumes: `resolve()` for `layout.columns`, `layout.span` and `visibility` at the breakpoint, class-supplied and inherited values included.
- Produces: `trackCount`, `effectiveSpan`, `lastRowFree` (Shared contracts).

**The placement** is the browser's sparse row flow: a cursor at column 0; for each child visible at the breakpoint, in document order, `span = effectiveSpan(…)`; if `cursor + span > tracks` the cursor wraps to 0; `cursor += span`; a cursor equal to `tracks` wraps to 0. `lastRowFree` is `tracks` for a grid with no visible child, `0` when the cursor is at 0, else `tracks − cursor`. Earlier holes are never counted: nothing appended can reach them.

**Tests:** `trackCount('3')` = 3, `('12')` = 12, `('1-2-1')` = 3, `('2-1')` = 2; `effectiveSpan('full', 3)` = 3, `('6', 3)` = 3, `(null, 3)` = 1; empty 3-track → 3; one child → 2; a child spanning 2 of 3 → 1; **two children spanning 2 of 3 → 1**; three children → 0; a child hidden at `md` not counted at `md` and counted at `base`; tracks inherited from `base` at `lg`; tracks and a span supplied by a style class; a 1-track grid (mobile stack) with two children → 0.

- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(editor): grid occupancy — the cells an appended block can reach, at a breakpoint`.

## Task 3.3: Fill empty cells

**Files:**
- Create: `admin/src/editor/structure/gridFill.ts`; `admin/src/__tests__/grid-fill.spec.ts`; `admin/e2e/tests/grid-fill.spec.ts`.
- Modify: `admin/src/editor/inspector/LayoutTab.vue` (the button under the Grid controls, disabled with its reason); `admin/src/composables/useCanvasBridge.ts` (`onGridFill`); `packages/thallo-render/assets/preview/preview-bridge.js` (a Fill button on the empty grid's placeholder, posting `thallo:grid-fill`); `admin/src/pages/content/[type]/[uuid]/design/[locale].vue` (wiring); `admin/src/__tests__/{canvas-bridge,preview-bridge-dom,canvas-page}.spec.ts`.

**Interfaces:**
- Consumes: `lastRowFree`; **`checkInsertSequence(doc, inserts, ctx)`** (`admin/src/editor/structure/legality.ts`) — the insertion validator that judges each new factory instance against the document the previous inserts produced; `checkMoves` is for blocks already in the tree and is not used here; the block factory; the page's transaction commit (the function the structure picker's `commit` dep is given); the column container of §6.5 (`layout.display` flex, `layout.direction` column, `layout.gap.row` = `spacing.md`).
- Produces: `createGridFill(deps)` with
  ```ts
  interface GridFillDeps {
    doc: () => EditorDocument                       // re-read after every await, never captured
    legality: () => LegalityContext
    classesFor: (id: string) => StyleClassRef[]
    activeBreakpoint: () => Breakpoint
    factory: (slug: string) => Promise<BlockInstance>
    commit: (operations: OperationBody[]) => Promise<void> | void
    notify: (message: string) => void
  }
  ```

**Availability, in this order:** not in grid mode at the breakpoint → hidden; `lastRowFree` = 0 → disabled, "No empty cells in the last row"; the container deeper than depth three → disabled, "A cell here could not hold a block: blocks nest at most five deep" (the cell would be legal at depth five and could never be filled — §11.3); the complete candidate refused by legality → disabled with legality's own reason.

**One availability function, run twice.** `availability()` is the single place the four conditions live. It runs when the button is drawn, and **again, in full, immediately before the commit** — not a subset of it. Subtree legality alone is not enough there: a grid moved from depth three to depth four while the factory loads still passes `checkInsertSequence`, because its new empty cells are legal at depth five — and can never hold a block. Only the re-run depth condition catches it.

**Fill:** one request per container at a time — a second while `preparing(id)` is ignored. Await the factory once; then, against the **current** document and class values: if the target is gone or the active breakpoint is no longer the one requested → write nothing; re-run `availability()` and write nothing unless it is enabled (this recomputes `lastRowFree` — never the count taken at the press — and re-applies mode, depth and legality); build one `InsertBlock` per cell, appended in order, each instance the factory's block with the column container's settings; `checkInsertSequence` over the complete candidate; commit once. Ids are minted once per request, so redo replays the same ids.

**The stage button has no state of its own.** The parent publishes `thallo:grid-fill-state` — the complete list, one entry per empty grid container at the active breakpoint, built from the same `availability()` and `preparing()` the inspector's button reads — on every document change, breakpoint change, accepted apply and preparing transition. The bridge draws a Fill button on an empty grid's placeholder **only for an id in the list**, disabled with `reason` as its title when `enabled` is false, and busy while `preparing`. With no entry there is no button, so the stage can never offer what the inspector refuses.

**Tests (unit):** empty 3-track → three `InsertBlock`s at indices 0–2, one commit; one child → two, at indices 1–2; two children spanning 2 of 3 → one; last row full → disabled with its reason and `fill()` writes nothing; depth four → disabled with the depth reason, depth three → enabled; a child added by another change while the factory is pending → the recomputed count; **the grid moved from depth three to depth four while the factory is pending → nothing written, although `checkInsertSequence` would accept the candidate**; the target removed, switched to Flex, or the breakpoint changed while pending → no commit and no notification of failure; two presses → one commit; a factory failure → notified, nothing written, a later press works; the inserted blocks carry the column container's three settings and nothing else; the candidate is judged by `checkInsertSequence` (a spy), never `checkMoves`.
**Tests (bridge):** with no `grid-fill-state` the empty grid's placeholder has **no** Fill button; an enabled entry → a button that posts `thallo:grid-fill` with the container's id and selects nothing; a disabled entry → the button disabled with the reason as its title, and a click posts nothing; a preparing entry → busy, and a click posts nothing; a later list without the id → the button is removed; an entry for a container that is not empty draws nothing.
**Tests (page) — the two surfaces agree:** for the same container the published entry equals what the Layout tab's button shows (`enabled`, `reason`, preparing) — initially, and again after each of: the container moved to depth four (both disabled, the depth reason); its mode switched to Flex (the inspector's button gone, the entry gone); a block inserted into an empty three-track grid (the **stage** entry gone — the placeholder it sat on exists only while the slot is empty — while the **inspector's** button stays enabled for the two cells still free; the two surfaces agree wherever both exist, and the stage's absence here is the placeholder rule, not a refusal); the active breakpoint changed to one where it has one track; `fill()` pending (both preparing). The page routes `thallo:grid-fill` to `fill(id, activeBreakpoint)`.
**Tests (builder proof):** Fill on `gridempty001` → one history entry of three `InsertBlock`s, one apply; undo → empty; redo → the same three ids.

- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(editor): Fill empty cells — a grid's last row completed with column containers, as one change`.

---

## Phase 4 — Release

## Task 4.1: documentation and the changelog

**Files:** `CHANGELOG.md` (Unreleased — **Changed:** Flex and Grid only, with the recorded consequence of §3.8 verbatim and the refusal of a stored `block`; the Layout tab's new order. **Added:** the grid outline; Fill empty cells; the invalid-layout notice. The blank-page fix already under Fixed stays); `packages/thallo-render/docs/THEMING.md` §12.3a (the content area's default mode, direction and gaps; one margin rule; what a theme overriding `blocks.css` must now provide).

- [ ] **Step 1:** write both. **Step 2: Commit** `docs: Flex and Grid only, the grid on the stage and Fill empty cells`.

## Task 4.2: release gates

- [ ] **Step 1:** one at a time: `COMPOSER_PROCESS_TIMEOUT=0 composer test`, `composer phpcs`, `composer boundaries`, `COMPOSER_PROCESS_TIMEOUT=0 composer test:skeleton`, `COMPOSER_PROCESS_TIMEOUT=0 composer test:distribution`; the admin gates; the builder proofs with fixtures rebuilt; the real-browser gate **from deleted fixture directories**, as CI runs it.
- [ ] **Step 2:** report the gate table to the user. The beta cut happens only on the user's word.

## Self-review

- **Spec coverage:** §3.2 and §11.1 modes → 1.1; §3.8 defaults, the untouched stack and the placed child → 1.1; §4 factory defaults → 1.1 (theme default; nothing written); §6.5 Stack → 1.1; §7.11 → held by 1.1's frozen-reference gate; §11.1 invalid value, shown and repairable from the instance and from the class → 1.2; §5 sections → 2.1; §11.2 outline, inertness, placeholder and picker interaction → 3.1; §11.3 fillable cells and occupancy → 3.2; §11.3 preparation, depth, disabled states and redo ids → 3.3; §9's amended proof list → the Tests of 1.1, 1.2, 2.1, 3.1–3.3.
- **Type consistency:** `LayoutDisplay`, `InvalidChoice`, `lastRowFree`, `createGridFill` and `GridFillDeps` are defined once under Shared contracts and used under those names in every task.
- **Open by design:** the placed child's fill mechanism (1.1) and the outline's repositioning triggers (3.1) are stated as behaviour with a proof, not as code. What decides them: the frozen references and the no-overflow width assertion for the first; the inertness measurement **and** the four alignment proofs for the second.
- **Plan review, 2026-09-18** (folded in): the declaring breakpoint comes from `declarationOrigin`, not `Resolution.breakpoint`; the class editor can repair what "Open class" sends an author to fix; Fill re-runs every availability condition before it commits, depth included, and validates with `checkInsertSequence`; the stage's Fill button is drawn only from published state; the placed child is asserted against available space; `spacing-flex-then-reset-md` is updated rather than left asserting margins that no longer return; the outline is proven aligned, not only inert.
