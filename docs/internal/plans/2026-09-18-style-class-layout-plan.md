# Style Class Layout Editing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The style class editor can see and edit every property §5 moved to the Layout tab — `width`, `alignment.self`, `alignment.content` and all of `layout.*` — through a class Layout tab that borrows no context, and both class tabs describe a declaration's state truthfully: set, inherited (naming the declaring breakpoint), an explicit reset, or not set in this class.

**Architecture:** Nothing in the contract, the compiler, the validator or the renderer changes. A pure function, `classFieldState`, answers what one class declares for one property at one breakpoint, through the class's own breakpoint inheritance only, using `resolve` for the value and `declarationOrigin` for the declaring breakpoint (the resolver reports an inherited reset exactly like one authored here). The shared field controls — `ResponsiveField`, `BoxField` — take a `context` prop, default `'block'`; in `'class'` they read that function for their label and offer **Remove** and **Use theme default**. `ResponsiveField` gains a `control` slot so direction, wrap and columns keep their icon and track choosers while the state and the two actions come from the same wrapper as every other row. `ClassLayoutTab.vue` is a new, separate component composed from those controls, with membership from `tabMap`; `LayoutTab.vue` is not touched. The class pages keep the draft on a refused save and show the server's field errors.

**Tech Stack:** Nuxt UI admin (Vue 3, vitest); one PHPUnit integration test against the existing `StyleClassController`.

**Spec:** `docs/internal/superpowers/specs/2026-09-17-container-layout-design.md` **§12** (amendment, 2026-09-18). § numbers refer to it.

## Global Constraints

- **No borrowed context** (§12.1). Nothing in the class editor is shown, hidden, disabled, collapsed or described because of a mode, a parent or a default. No Fill, no grid outline, no parent link, no structure picker, no dashed default marker, no pressed choice for an unset property. **"Nothing pressed" means the value chooser** — the token pills, choice buttons, icon choices and track swatches of that row. The active breakpoint chip and a box's link toggle use `aria-pressed` for their own state and are never part of this claim; tests scope the assertion to the chooser's element.
- **The block inspector is unchanged** (§12.4): its labels (`set`, `inherited`, `reset`, `theme`), "Reset to theme", "Clear" and every visibility rule in `LayoutTab.vue`. `context` defaults to `'block'`, and the existing `responsive-field.spec.ts`, `box-field.spec.ts` and `layout-tab.spec.ts` pass **without edits** — an edit to one of them to make it pass is a failure of this constraint.
- **`LayoutTab.vue` is not given a class mode** (§12.2). It is not imported by the class editor.
- **Editing changes what was edited and nothing else** (§12.5), proven by deep comparison of the editor's emitted value.
- **An unsupported stored value is never silently replaced** (§12.5): no valid choice is pressed in its place, and nothing but Replace, Remove or the author's own choice on that control rewrites it.
- **Preservation is the editor's; persistence is the server's** (§12.5). **No validator relaxation.** `SettingsValidator` and `StyleClassController` are read, not changed.
- **A non-responsive property is stored bare** (§12.4) — never under `base`, `md` or `lg`. The validator refuses the wrapper ("is not responsive").
- **Wording is pinned** — these strings are asserted literally:
  - states: `Not set in this class` · `Inherited from <bp>` · `Theme default, from <bp>` · `Theme default, set here` · `Set` · `Invalid`
  - non-responsive, in place of a breakpoint: `Applies at all sizes`
  - actions: `Remove` · `Use theme default`
  - families: `Applies in Flex` · `Applies in Grid` · `Applies in a Grid parent` · `Applies in a Flex parent`
  - retention: `Flex settings are retained. They apply wherever the block's effective layout is Flex.` and the Grid counterpart; `These apply wherever the block's parent lays out its children as a grid.` and the flex counterpart
  - capability guidance: `A declaration applies only to blocks that support that property. On a block that does not, it is kept and unused.`
- Admin gates: `pnpm exec vitest run`, `pnpm type-check` (not `vue-tsc --noEmit`), `pnpm lint`, `pnpm fmt:check`; format touched files only, with `pnpm exec oxfmt <files>`.
- PHP gates: `COMPOSER_PROCESS_TIMEOUT=0 composer test` (never concurrent with another suite), `composer phpcs` judged by exit code, `composer boundaries`.
- Searches that must see gitignored paths use `/usr/bin/grep` or `git grep`.
- `git diff` before every commit; no AI attribution trailers; never push, never tag. A beta is cut only on the user's word.

**Steps 1–4**, wherever a task says so, are the TDD cycle: write the failing tests the task names, run them and watch each fail for the stated reason, implement the minimum, run the file and the task's gates green. A test that passes before its implementation exists is checked for teeth — remove the mechanism, watch it fail, restore. **Step 5** is the commit with the message given.

## Shared contracts

Defined once here; every task uses these names.

```ts
// admin/src/editor/inspector/classFieldState.ts
import type { Breakpoint, StyleValue } from '@/style/types'

export type ClassFieldKind =
  | 'set'              // a value declared at the breakpoint being edited
  | 'reset-here'       // an explicit reset declared at the breakpoint being edited
  | 'inherited'        // a value declared at an earlier breakpoint of this class
  | 'inherited-reset'  // a reset declared at an earlier breakpoint of this class
  | 'not-set'          // nothing applies through this class's own inheritance
  | 'invalid'          // a stored value the contract does not offer for this property — or a
                       // non-responsive property stored under a breakpoint, which the resolver
                       // reads as nothing and the server refuses: invalid, never invisible

export interface ClassFieldState {
  kind: ClassFieldKind
  /** The declaring breakpoint: the one being edited for set / reset-here, an earlier one for the
   *  inherited kinds, null for not-set and for every non-responsive property. */
  from: Breakpoint | null
  /** The declared value; null for the reset kinds and not-set. For 'invalid', the stored value. */
  value: StyleValue | null
  /** The pinned wording (Global Constraints). */
  label: string
  /** Whether a declaration exists AT the breakpoint being edited (or, non-responsive, at all). */
  declaredHere: boolean
}

// `def` is the resolver's own `PropertyDefinition` (`@/style/types`): path, group, responsive,
// tokenDomain, choices. No second definition type.

/** One class's own declarations for one property at one breakpoint. No other layer is consulted. */
export function classFieldState(
  def: PropertyDefinition,
  style: Record<string, unknown>,
  breakpoint: Breakpoint,
  /** The vocabulary's names for the property's token domain; absent, tokens are not judged. */
  tokens?: readonly string[],
): ClassFieldState
```

```ts
// ResponsiveField.vue and BoxField.vue — one new prop each, default 'block'
context?: 'block' | 'class'

// ResponsiveField.vue — one new scoped slot, falling back to the token / choice control
// <slot name="control" :value="string | null" :pick="(raw: string) => void" />
// `value` is null for the reset kinds, not-set and invalid: nothing is pressed.
```

```ts
// StyleTab.vue — passes it through to every field it renders
context?: 'block' | 'class'
```

```
admin/src/pages/settings/style-classes/components/ClassLayoutTab.vue
  props: { modelStyle: Record<string, unknown>; schema: StyleSchemaResult; activeBreakpoint: Breakpoint }
  emits: set(path, breakpoint | null, value | null) · 'set-all'(path, value) · 'update:activeBreakpoint'(bp)
```

**The payload fixtures** — `tests/fixtures/style-classes/editor-payloads.json` — are read by both suites, so what the editor is proven to emit is what the server is proven to accept or refuse. `valid` and `bare_reset` are what the editor **authors**; `invalid_value` and `unknown_path` are what it **preserves** and the server refuses on purpose; `wrapped_non_responsive` is what it must **never author**:

```json
{
  "valid": {
    "layout": { "display": { "base": {"type":"choice","value":"grid"} },
                "columns": { "base": {"type":"choice","value":"3"}, "md": {"type":"reset"} },
                "overflow": {"type":"choice","value":"hidden"} },
    "width": { "md": {"type":"token","value":"width.narrow"} },
    "radius": {"type":"token","value":"radius.md"}
  },
  "bare_reset": { "layout": { "overflow": {"type":"reset"} } },
  "invalid_value": { "layout": { "display": { "md": {"type":"choice","value":"block"} } },
                     "spacing": { "padding": { "top": { "base": {"type":"token","value":"spacing.lg"} } } } },
  "wrapped_non_responsive": { "layout": { "overflow": { "base": {"type":"choice","value":"hidden"} } } },
  "unknown_path": { "layout": { "nonesuch": { "base": {"type":"choice","value":"x"} } } }
}
```

Token and choice names are taken from the live schema and vocabulary when the file is written (Task 1.1, Step 1) — the ones above are the intended shape, and a name the schema does not have is corrected there, not worked around later.

---

## Task 1.1: `classFieldState` — what one class declares

**Files:** Create `admin/src/editor/inspector/classFieldState.ts`, `admin/src/__tests__/class-field-state.spec.ts`, `tests/fixtures/style-classes/editor-payloads.json`.

**Consumes:** `resolve(path, classes, style, def)` and `declarationOrigin(property, classes, instance, def, target)` from `@/style/resolver`, both called with `classes = []` so the class's own style is the only layer.

**Tests** (`class-field-state.spec.ts`), a responsive choice property (`layout.direction`) unless said:

- nothing declared → `not-set`, `from: null`, `value: null`, label `Not set in this class`, `declaredHere: false`.
- a value at `base`, read at `base` → `set`, `from: 'base'`, `declaredHere: true`, label `Set`.
- a value at `base`, read at `md` and at `lg` → `inherited`, `from: 'base'`, label `Inherited from base`, `declaredHere: false`, `value` the base value.
- a value at `md` over a value at `base`, read at `md` → `set`; read at `lg` → `inherited` from `md`, not from `base`.
- a reset at `base`, read at `base` → `reset-here`, label `Theme default, set here`, `value: null`, `declaredHere: true`.
- **a reset at `base`, read at `md` → `inherited-reset`, `from: 'base'`, label `Theme default, from base`, `declaredHere: false`.** This is the case the resolver cannot tell apart: the test also asserts `resolve(...).md.state === 'reset'` for the same input, so the reason `declarationOrigin` is needed is written down where it is used.
- a value at `base` and a reset at `md`, read at `lg` → `inherited-reset` from `md`.
- **non-responsive** (`layout.overflow`): bare value → `set`, `from: null`, label `Set`; bare reset → `reset-here`, label `Theme default, set here`; absent → `not-set`. Read at `base`, `md` and `lg`: identical every time, and never an inherited kind.
- **invalid:** a stored `block` on `layout.display` at `md`, read at `md` → `invalid`, `value` the stored value, label `Invalid`, `declaredHere: true`; read at `lg` → `invalid`, `from: 'md'`. A token outside the supplied `tokens` list → `invalid`; with `tokens` omitted the same input is `set` (the function does not guess).
- a token property (`layout.gap.row`) and a box side (`spacing.padding.top`) each through set / inherited / not-set, so every control kind in §12.6 has its state covered here.

- [x] **Steps 1–4.** In Step 1, write the fixture file first and check every token and choice name in it against `useStyleSchema`'s live output (`admin/src/__tests__` has schema fixtures; `packages/thallo-contracts/src/Style/StyleSchema.php` is the source) — correct any that do not exist.
- [x] **Step 5: Commit** `feat(inspector): classFieldState — what one class declares for a property, through its own breakpoints`.

## Task 1.2: the shared fields speak truthfully in a class

**Files:** Modify `admin/src/editor/inspector/controls/ResponsiveField.vue`, `admin/src/editor/inspector/controls/BoxField.vue`, `admin/src/editor/inspector/StyleTab.vue`, `admin/src/pages/settings/style-classes/components/StyleClassEditor.vue`. Test: create `admin/src/__tests__/class-fields.spec.ts`; extend `admin/src/__tests__/style-class-editor-repair.spec.ts`.

**What changes, in `context: 'class'` only:**

- The state badge (`data-test="style-state"`) shows `classFieldState(...).label`, with `data-kind` carrying the kind. The class-source badge (`style-source`) never renders: there is no other class here.
- A **non-responsive** property shows `Applies at all sizes` (`data-test="style-all-sizes"`) where the breakpoint chips would be. "Apply to all breakpoints" is already `v-if="def.responsive"`; assert it, do not re-implement it.
- Actions: `data-test="style-use-theme-default"` ("Use theme default") emits `set(path, bp, {type:'reset'})`; `data-test="style-remove"` ("Remove") emits `set(path, bp, null)` and renders **only when `declaredHere`**. `bp` is `null` for a non-responsive property. The block context's `style-reset` / `style-clear` are not rendered in class context, and the class ones are not rendered in block context.
- The chooser's `model-value` — and the `control` slot's `value` — is the declared or inherited **value**, and `null` for `reset-here`, `inherited-reset`, `not-set` and `invalid`: nothing pressed, no token highlighted.
- For `invalid` the row shows the stored value in words (`data-test="style-invalid-value"`, e.g. `Stored: block`) and the chooser stays usable — choosing is one of the three ways §12.5 allows the value to change.
- `BoxField`: each cell's state comes from `classFieldState` for that side; the open cell's actions are the same two, with the same `declaredHere` rule per targeted side (linked: Remove shows when **any** targeted side is declared here, and removes only those).
- `StyleTab` forwards `context` to every `ResponsiveField` and `BoxField` it renders. `StyleClassEditor` passes `context="class"`.

**The `control` slot** on `ResponsiveField`: `<slot name="control" :value="..." :pick="onPick">` wrapping the existing token / choice control as its fallback. No behaviour change for a caller that passes no slot.

**Tests** (`class-fields.spec.ts`), mounting `ResponsiveField` and `BoxField` directly:

- each pinned label renders for its kind, for a choice, a token and a box side; `Theme default, from base` for a base reset read at `md`, and the row has **no** `style-remove` there (nothing is declared at `md`).
- Remove at `md` over a `base` value emits `set(path,'md',null)`; re-mounted with the resulting style, the row reads `Inherited from base`.
- Use theme default at `md` emits `{type:'reset'}` at `md`; re-mounted, the row reads `Theme default, set here` and offers Remove.
- `not-set`: nothing pressed **in the value chooser** — the count of `aria-pressed="true"` is zero *within the chooser's root element*, not within the row: the breakpoint chips are mounted in these tests and the active one is legitimately pressed, as is a box's link toggle. No Remove, no value text.
- non-responsive (`layout.overflow`, and `radius` for the Style tab): `Applies at all sizes` is shown, no breakpoint chips, no "Apply to all breakpoints"; a pick emits `set(path, null, value)`; the two actions emit with `null`. Asserted with `activeBreakpoint` at `base`, `md` and `lg` — identical emissions.
- invalid: `Invalid` badge, `Stored: block`, nothing pressed; **mounting, changing `activeBreakpoint` and re-rendering emit nothing**.
- the `control` slot: a custom chooser receives `value` and its `pick` emits the same `set` the built-in one would.
- **block context, explicit:** the same inputs with no `context` prop render `theme` / `inherited` / `reset` / `set`, "Reset to theme" and "Clear", and no class action. Plus the constraint: `responsive-field.spec.ts`, `box-field.spec.ts` and `layout-tab.spec.ts` pass unedited.

Extend `style-class-editor-repair.spec.ts`: the class Style tab shows `Not set in this class` for an untouched property and the two class actions; Needs attention still lists a stored `block` with Replace and Remove.

- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(style-classes): a class's fields say what the class declares — inherited, reset or not set — on the Style tab`.

## Task 2.1: the class Layout tab

**Files:** Create `admin/src/pages/settings/style-classes/components/ClassLayoutTab.vue`, `admin/src/__tests__/class-layout-tab.spec.ts`.

**Consumes:** `ResponsiveField` (`context="class"`, `control` slot), `BoxField` (`context="class"`), `IconChoiceControl`, `TrackSwatchControl`, `pathsForTab` / `tabOf` from `@/editor/inspector/tabMap`, `classFieldState`. It does **not** import `LayoutTab.vue`, `layoutContext`'s `effectiveDisplay` or `dormantPaths`, `gridFill`, or `THEME_DEFAULT`.

**Structure** — three sections, `data-test="class-layout-group-{container|box|item}"`, every row always rendered:

| Section | Rows, in order |
|---|---|
| Container | `layout.display` · family **Applies in Flex** (`class-layout-family-flex`): `layout.direction`, `layout.wrap` · family **Applies in Grid** (`class-layout-family-grid`): `layout.columns` · then `alignment.content`, `layout.align_items`, Gap (`BoxField`: `layout.gap.column`, `layout.gap.row`) · `layout.content_width`, `layout.gutter` |
| Box | `width`, `alignment.self`, `layout.min_height`, `layout.overflow` |
| As an item | family **Applies in a Grid parent** (`class-layout-family-grid-parent`): `layout.span` · family **Applies in a Flex parent** (`class-layout-family-flex-parent`): `layout.basis`, `layout.grow`, `layout.shrink` · then `layout.align_self` |

- Direction and wrap use `IconChoiceControl`, columns uses `TrackSwatchControl`, each **inside `ResponsiveField`'s `control` slot** and with **no `default-value`** — the dashed marker is the block tab's.
- **A row for every Layout path.** The component renders from an ordered list; any path `pathsForTab(schema paths, 'layout')` returns that the list does not name is rendered at the end of Box as a plain `ResponsiveField` marked `data-fallback="true"`, so a property added to the contract later is editable the day it lands. The ordered list is exported as `CLASS_LAYOUT_SECTIONS` so the placement test below can hold it against the schema: the fallback keeps the editor complete, the test keeps the placement deliberate.
- **Retention notes** (`data-test="class-layout-retained-{flex|grid}"`): shown when the class **declares** `layout.display` at the breakpoint being edited or inherits it from an earlier one (via `classFieldState`, kinds `set` / `inherited`) **and** holds any declaration in the other family at any breakpoint. The pinned sentence, no action, no warning colour. An `invalid` display shows neither note.
- **Item notes** (`data-test="class-layout-item-note-{grid|flex}"`): the pinned sentences, permanently under each item family's label.
- Breakpoint chips once, at the top of the tab, as the Style tab's group header does; rows pass `hide-breakpoints`.

**Tests** (`class-layout-tab.spec.ts`):

- **Coverage:** every path in `pathsForTab(allSchemaPaths, 'layout')` has exactly one `style-field-<path>` (or gap cell) in the tab; `width`, `alignment.self` and `alignment.content` asserted by name. The fallback below makes this pass for a path nobody placed — that is its job, and why coverage alone is not enough.
- **Deliberate placement:** the component exports its ordered section assignment (`CLASS_LAYOUT_SECTIONS`: section → families → paths). The test asserts that the set of paths it names **equals** `pathsForTab(allSchemaPaths, 'layout')` for the current schema — none missing, none extra, none twice — and that **no row is rendered by the fallback** (`[data-fallback="true"]` count is zero). A Layout property added to the contract fails here until someone decides where it goes.
- **The fallback itself:** the tab mounted with a schema carrying one **synthetic** extra Layout property (`layout.synthetic`, a choice) renders it at the end of Box, marked `data-fallback="true"`, editable, with the class states and both actions — and the placement assertion, run against that schema, fails naming the path.
- **No context:** empty style → both families and both item families present, every control enabled, the four family labels and both item notes present; no `[data-default="true"]`, no `layout-fill-cells`, no `layout-dormant-*`, and **no pressed value**: for every row, zero `aria-pressed="true"` within its value chooser. The same test asserts the two things that *are* pressed and must stay so — the active breakpoint chip and the Gap box's link toggle — so the scoping is proven rather than assumed.
- with Grid set, direction is still editable: choosing one emits `set('layout.direction', bp, …)`.
- **Retention:** Grid at `base` + a stored direction at `md` → the Flex retained note, in the pinned words, at `base`, `md` and `lg`; removing the direction removes the note; Flex + a stored columns → the Grid note; no mode declared + both families stored → neither note.
- **States on the custom controls:** direction with a `base` value read at `md` → `Inherited from base`, the inherited icon pressed, no Remove; with a `base` reset read at `md` → `Theme default, from base`, nothing pressed; columns likewise. Use theme default and Remove emit as in Task 1.2.
- **Non-responsive:** `layout.overflow` shows `Applies at all sizes` and emits bare from each of the three breakpoints.
- **Invalid:** a stored `block` at `md` → the Layout row is `Invalid`, neither Flex nor Grid pressed, neither retained note; switching breakpoint emits nothing.
- **Mode switch writes one declaration:** choosing Grid with Flex settings stored emits exactly one `set`, for `layout.display`.

- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(style-classes): a class Layout tab — every layout property, labelled by where it applies, with no borrowed context`.

## Task 2.2: two tabs in the class editor, and the editor's output

**Files:** Modify `admin/src/pages/settings/style-classes/components/StyleClassEditor.vue`. Test: create `admin/src/__tests__/style-class-editor-output.spec.ts`; extend `style-class-editor-repair.spec.ts`.

- Tabs **Style** and **Layout** (`data-test="style-class-tabs"`), Style first; the remembered tab is per editor instance, not persisted. The active breakpoint is shared between them.
- **Needs attention stays above the tabs**, unchanged, so a stored `block` is listed whichever tab is open.
- **Capability guidance** (`data-test="style-class-capability-note"`), the pinned sentence, rendered **once above the tabs' content and outside both panels** so it is present on both and cannot be lost with a tab's markup. Not dismissible.
- The class Style tab must not show Layout paths and the Layout tab must not show Style paths: `StyleTab` already filters by `tabOf`; assert it for the class.

**Tests** (`style-class-editor-output.spec.ts`) — the editor's emitted `update:modelValue`, no server:

- **Deep comparison, one declaration.** A class built from the fixture's `valid` payload **plus** both families' settings, style declarations at three breakpoints and `unknown_path`'s key. Open the Layout tab, **unlink the Gap box** (`box-link` — it is linked by default and re-links whenever both sides match, so the test asserts `aria-pressed="false"` before going on), open the Row cell and set it at `md`: the emitted value deep-equals the original with that one declaration added — `expect(emitted).toEqual(expected)` where `expected` is the original cloned and patched, so an extra, a missing or a re-ordered-into-a-wrapper key fails. `layout.gap.column` is untouched and the unknown path is still there.
- **Deep comparison, linked.** The same class with the Gap box **linked**: setting a value at `md` writes **both** `layout.gap.row.md` and `layout.gap.column.md` — that is what linked means, and it is the intended edit. The emitted value deep-equals the original patched with exactly those two declarations; every other key, the other breakpoints of both gaps included, is unchanged. A single-row control (`layout.direction`) is asserted the one-declaration way as well, so the claim does not rest on the box alone.
- The same after switching the class's mode (one declaration differs).
- **No edit, no emission:** open each tab, step through `base`, `md`, `lg` on each — `emitted('update:modelValue')` is undefined.
- **Invalid survives:** with `invalid_value`, editing the padding on the Style tab emits a value whose `layout.display.md` is still the stored `block`.
- **Non-responsive is bare:** choosing an overflow value from `md` emits `layout.overflow` as `{type, value}` with no breakpoint key, deep-equal to the fixture's shape; Use theme default emits the fixture's `bare_reset` shape exactly; Remove deletes the key and leaves no empty `layout` object behind if it was the only one (assert whatever `setPath` does today and pin it — do not change `setPath`). `radius` on the Style tab the same way.
- **The fixtures are what the editor emits:** the `valid` payload loaded and re-emitted after a no-op-equivalent edit and its inverse deep-equals the fixture.

Extend the repair spec: the capability note is present with either tab open; Needs attention is present with either tab open.

- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(style-classes): Style and Layout tabs in the class editor — one edit changes one declaration`.

## Task 3.1: a refused save keeps the draft and names the field

**Files:** Modify `admin/src/pages/settings/style-classes/[id].vue` and `new.vue`. Test: extend `admin/src/__tests__/styleClassesPage.spec.ts`; create `tests/Integration/Content/StyleClassEditorPayloadsTest.php`.

**Read first:** `StyleClassController::style()` returns `Response::validation($errors)` with keys like `style.layout.display.md`; `[id].vue`'s `onSave` today toasts `Couldn’t save the style class` and nothing else. Confirm in Step 1 the exact body shape the admin receives for a validation response (`apiErrorDetails`, `admin/src/api/errors.ts`) by reading an existing page that renders field errors, and reuse its helper rather than parsing the body again.

- On a validation refusal: **the draft is untouched** (`name`, `description`, `style`, the loaded version), the confirm dialog closes, and the page shows the errors (`data-test="style-class-save-errors"`): one line per field — the property's label, the breakpoint when the key carries one, and the server's message. A key the page cannot map to a property (an unknown path) is shown by its raw key. The toast stays.
- The errors clear on the next successful save, and on the next save attempt before its answer.
- The version-conflict path is unchanged.

**Tests, admin** (`styleClassesPage.spec.ts`, the update mutation mocked):

- **Save and reload, valid:** the `valid` fixture is edited and saved; the mutation receives the edited style; the page is re-mounted with what was sent and each row shows the state it was stored in — the `md` reset on columns reads `Theme default, set here`, overflow reads `Set` with `Applies at all sizes`.
- **Rejected save, repair, save:** `invalid_value` loaded; the padding is edited; save is answered with a validation error for `style.layout.display.md`. Asserted: the error block names Layout, `md` and the message; the draft still holds the padding edit **and** the `block`; Needs attention still lists it. Then Replace with Flex → one declaration changes → save → the mutation receives a style with `flex` at `md` and the padding edit, and the error block is gone.
- An unknown-path refusal shows the raw key and leaves the draft intact.

**Test, PHP** (`StyleClassEditorPayloadsTest.php`, reading the same fixture file through the real controller):

- `valid` and `bare_reset` are accepted on create and on update, and read back **deep-equal** (`assertEquals` — key order does not survive a JSON round-trip).
- `invalid_value` is refused naming `style.layout.display.md`; `wrapped_non_responsive` is refused naming `style.layout.overflow` with "is not responsive"; `unknown_path` is refused naming `style.layout.nonesuch` with "unknown style property".
- Its own test class, so its assertions cannot pollute another test's listing.
- **No production PHP changes.** The rule for a refusal depends on what was refused:
  - a declaration the editor **newly authored** from a valid choice — a picked value, Use theme default, a non-responsive value, anything in `valid` and `bare_reset` — must be accepted. If the server refuses one, the editor emitted the wrong shape: fix it in Task 1.2–2.2's code, never in the validator.
  - a **preserved** invalid value or unknown path (`invalid_value`, `unknown_path`) is emitted **on purpose** (§12.5) and refused **on purpose**. That refusal is the contract working, and is exactly what the admin half of this task handles; it is not a defect in either side.
  - `wrapped_non_responsive` is a shape the editor must **never author**: the PHP test proves the server refuses it, and Task 2.2's bare-emission test proves the editor does not produce it.

- [ ] **Steps 1–4.**
- [ ] **Step 5: Commit** `feat(style-classes): a refused save keeps the draft and names the field; the editor's payloads proven against the validator`.

## Task 4.1: documentation

**Files:** `CHANGELOG.md` (Unreleased — **Added:** a Layout tab in the style class editor, with what it covers and the applicability labels. **Fixed:** since beta.40 a class could hold width, placement, content alignment and layout settings and could not edit them; a class's fields said `theme` where the class simply declared nothing; a refused class save now says which field. **Changed:** in the class editor the actions are named Remove and Use theme default); `packages/thallo-render/docs/THEMING.md` "Style classes" (one paragraph: a class may carry layout; per-property cascade means a class's Flex settings apply wherever the effective layout is Flex, whatever mode the class itself sets).

- [ ] **Step 1:** write both. **Step 2: Commit** `docs: style classes edit layout — the class Layout tab and truthful field states`.

## Task 4.2: gates

- [ ] **Step 1:** one at a time: `COMPOSER_PROCESS_TIMEOUT=0 composer test`, `composer phpcs`, `composer boundaries`; the admin gates (`pnpm exec vitest run`, `pnpm type-check`, `pnpm lint`, `pnpm fmt:check`); the builder proofs (`cd admin/e2e && pnpm exec playwright test`) — the Design page imports the shared fields, so they are run although no proof is added. `test:skeleton`, `test:distribution` and the real-browser suite are release gates and run at the beta cut: nothing here touches a theme, the bridge, the renderer or packaging.
- [ ] **Step 2:** report the gate table to the user. The beta cut happens only on the user's word.

## Self-review

- **Spec coverage:** §12.1 → Global Constraints and 2.1's no-context test; §12.2 sections, labels, separate component, exclusions → 2.1; tab membership from the map → 2.1's reach test and 2.2's cross-tab assertion; §12.3 retention and item wording → 2.1; capability guidance on both tabs → 2.2; §12.4 states and the inherited reset → 1.1; both actions and their exact meaning → 1.2; non-responsive → 1.1, 1.2, 2.1, 2.2 and the PHP test; every control carries the states, custom controls included → 1.2's slot and 2.1; both class tabs, block inspector unchanged → 1.2; §12.5 survival and invalid values → 2.2; preservation against persistence → 3.1; §12.6's three-way proof split → 2.2 (output), 3.1 admin (save/reload; reject, repair, save) and 3.1 PHP.
- **Type consistency:** `classFieldState`, `ClassFieldState`, `ClassFieldKind`, the `context` prop and the `control` slot are defined once under Shared contracts and used under those names in every task. `data-test` names are given where first introduced and reused verbatim.
- **Open by design:** the exact body shape of a validation refusal and the admin helper that reads it (3.1) are confirmed by reading, not assumed; token and choice names in the fixture (1.1) are checked against the live schema. Both are stated as a Step 1 action with what to do when the assumption is wrong.
- **Not in this plan:** a builder proof. `admin/e2e` drives the Design page only, and nothing here changes a Design-page behaviour; the class editor's behaviour is proven in vitest and, for persistence, against the real controller in PHPUnit.
