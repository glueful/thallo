# Navigation Redesign + Drag Reordering — Design

**Date:** 2026-07-09
**Scope:** the navigation admin page redesign (§1–§7, admin-only) **plus** two drag-to-reorder features: tree items incl. nested children (§8, admin-only) and the sidebar menus themselves (§9, full-stack across `thallo-navigation` + admin).
**Type:** UI/UX refactor + a small full-stack ordering feature. §1–§8 touch admin only; §9 adds a `position` column, a repo method, a reorder endpoint, and a query mutation.

## Problem

The navigation page reads like a loose form, not a dashboard master-detail editor:

1. **No `UDashboardPanel`.** It hand-rolls `<div class="p-6"><h1>Navigation</h1>`, so it lacks the panel chrome (navbar, page-level action slot, standard body scroll) that 33 other admin pages have. `regions/index.vue` is the precedent for what it should be.
2. **Ambiguous menu rows.** A row is `name` + a bare number, which reads like a section heading, not a selectable menu: no icon, no affordance, the slug (the real identity) is hidden, the count is unstyled, the selected state is faint, and nothing is auto-selected — so a fresh visit lands on an empty "Select or create a menu" pane with no visible link to the "Main" row on the left.
3. **Create form crammed into the sidebar** under a separator, competing with the list, instead of a header action like every other "add" flow.

## Approach (chosen)

**Adopt `UDashboardPanel` and refine the in-body master-detail**, mirroring `regions/index.vue`. One page, low risk, matches the rest of the admin. (Rejected: splitting into `/navigation` + `/navigation/[slug]` routes — bigger change, loses at-a-glance master-detail, no real gain here. Rejected: lightest-touch restyle keeping the sidebar create form — less clean.)

## Design

### 1. Page shell

```
UDashboardPanel id="navigation"           (data-test="nav-page" moves here)
  #header → UDashboardNavbar title="Navigation"
              #right → UButton icon="i-lucide-plus" "New menu"
  #body   → responsive master-detail
```

- The **"New menu"** button is shown only when the capability is enabled: `v-if="enabled"` (a modal that cannot save is worse than no button). When disabled, users reach only the disabled empty state (§5).

### 2. Responsive master-detail body

```
<div class="flex flex-col gap-6 lg:flex-row">
  <aside class="w-full lg:w-80 lg:shrink-0" data-test="nav-menu-list"> … rows … </aside>
  <div class="min-w-0 flex-1"> … editor OR empty state … </div>
</div>
```

Stacked and full-width on small screens; fixed-width sidebar beside the editor only at `lg`, so the editor is never squeezed off-canvas.

### 3. Menu list rows

Each row is a **`<li>` wrapper** containing three **sibling** controls — never nested interactive elements (a handle inside a button is invalid and fights selection, P2):

1. **Grip handle** — a dedicated `<button data-test="nav-menu-drag">` (the drag handle, §9). `pointerdown` on it starts a drag and must NOT select the menu.
2. **Select button** — a `<button type="button" data-test="nav-menu-row" @click="selected = menu.slug">` spanning the leading `i-lucide-menu` icon, the **name** (medium weight) with the **slug** as muted `text-xs` beneath it, and the trailing item-count `UBadge color="neutral" variant="subtle"`. Carries the selected state: highlighted background + accent left-border (`border-l-2`) and `:aria-current="selected === menu.slug ? 'true' : undefined"` so keyboard nav and tests assert selection. This is the large, obvious click target.
3. **Overflow menu** — a `UDropdownMenu data-test="nav-menu-menu"` with "Move up" / "Move down" (the keyboard-accessible reorder fallback, §9).

The wrapper carries `hover:bg-elevated` and the selected background; the grip and overflow are visually secondary (muted, hover-revealed). The three controls being siblings keeps every interaction valid and independently focusable. Empty list text ("No menus yet.") is replaced by the empty states in §5.

### 4. Create modal

`UModal v-model:open="createOpen" title="New menu"` (house style: `redirects` uses `UModal` + `UFormField` + inline-disabled submit — no `UForm`/zod):

- `#body`: a real `<form data-test="nav-menu-create" @submit.prevent="submitCreate">` wrapping two `UFormField`/`UInput`s (slug placeholder `slug (e.g. main)`, name placeholder `Name`), so **Enter in either input submits**. The form contains a visually-hidden/`sr-only` submit input (or the footer Create button is `type="submit"` bound to the same form via `form` attr) so Enter has a submit target.
- `#footer`: `Cancel` (ghost) and `Create` — `Create` disabled until both fields are non-empty; both remain visible.
- On success: reuse the existing `createMenu()` logic verbatim (create → toast → `selected = slug` → clear fields), then close the modal.

Because Enter must submit while the footer button lives in a sibling slot, the simplest robust wiring is: the `<form>` has `id="nav-create-form"` and an internal hidden `<button type="submit" class="sr-only">`; the footer `Create` button calls the same `submitCreate` handler. Enter triggers the form's native submit; the visible button triggers the handler directly. Both paths call one function.

### 5. Empty / disabled / selected reconciliation

- **Capability off (`!enabled`):** the detail area (and the whole body) shows a dedicated empty state — icon `i-lucide-lock` + "Navigation isn’t enabled" — instead of the misleading "No menus yet". No sidebar list, no create button.
- **Enabled, zero menus:** empty state in the detail area — `i-lucide-list-tree` + "No menus yet" + a "New menu" button that opens the modal. The sidebar renders (empty) so the layout is stable.
- **Enabled, menu selected:** the existing editor (name + delete, locale tabs, `MenuTreeEditor`, Add link / Add page, Save).
- **Enabled, menus exist, none selected:** transient only (auto-select removes it); if it occurs, a light "Select a menu" hint in the detail area.

**Selection reconciliation (`watch(menus, …)`), replacing naive auto-select:**

```
watch(menus, (rows) => {
  const list = rows ?? []
  const exists = list.some((m) => m.slug === selected.value)
  // Only touch `selected` when it is INVALID — never clobber a valid (possibly dirty) selection.
  if (!exists) {
    selected.value = list.length > 0 ? list[0]!.slug : ''
  }
}, { immediate: true })
```

This covers three cases with one rule: first load (`selected === ''` → pick first), and **stale selection after delete/reload** (selected slug no longer in `menus` → pick first, or clear to `''` when the list is now empty). A still-valid selection — including one with unsaved edits — is left untouched.

### 6. Preserved verbatim (no behavior change)

All script logic stays; only the template and the reconciliation watch change:

- `working` reactive tree clone + `dirty` + `mergeBadges` locale-merge (`watch(detail, …)`)
- locale tabs (`data-test="nav-locale-tab"`)
- `MenuTreeEditor`
- Add link (`tree-add-root`) / Add page picker (`tree-add-page`, `add-page-picker`, `add-page-type`) + `onEntryPicked`
- Save with 409 reload (`tree-save`) and Delete (`nav-menu-delete`) — the existing `deleteMenu` already clears `selected` on self-delete; the reconciliation watch is the backstop for delete-from-elsewhere / reload.

## 8. Tree-item drag (incl. nested children) — `MenuTreeEditor.vue`, admin-only

`MenuTreeEditor` is a recursive level: it binds to a real array (`items`; children are passed as `item.children`), mutates in place, and bubbles `changed`. Precedent: `admin/src/fields/components/blocks/BlockList.vue` (nested `vue-draggable-plus` with a shared group + handle).

- Wrap each level's list in `VueDraggable` with a **shared `:group="'nav-tree'"`**, so items drag **within a level and across levels** — giving reordering *and* re-nesting (drop onto another item's children, or out to the parent) in one gesture.
- **Drag by a handle only** — a grip button `data-test="tree-item-drag"` (`handle="[data-test='tree-item-drag']"`) — so the row's inputs stay clickable.
- Each item **always** renders a droppable children container, even when `children.length === 0` (a thin dashed drop-zone), so nesting-by-drag has a target. Today the children block only renders when non-empty; that guard is removed for the container (the `MenuTreeEditor` recursion still only renders for non-empty children, but the drop container wraps it).
- **Model discipline (avoids vue-draggable-plus's recursive double-apply):** each level drives a **local mirror** of its array and commits the resulting order/parent to the working tree on `@end`, mirroring `BlockList`'s thin-binding rule; then emits `changed()`. `dirty` flips.
- **Whole-tree shape rides the existing menu-save path — NOT the §9 reorder endpoint.** The §9 `/menus/reorder` endpoint governs top-level menu order ONLY. Tree drag (including re-nesting) commits through the unchanged `PUT /menus/{slug}/items` path: `MenuTreeDTO::walk` already recursively flattens the posted nested tree into flat rows with `parent_uuid` + `position` for arbitrary depth, and `itemsOf`/`tree()` rebuild the nesting on load. So a drag that moves a child under a different parent is persisted and restored exactly — **no backend change** for the tree. The save payload preserves nested `children` as-is (drag only reorders/re-parents the same `NavTreeItem` objects).
- **Handle isolation:** the grip is a dedicated `<button data-test="tree-item-drag">`; `handle="[data-test='tree-item-drag']"` scopes dragging to it, and its `pointerdown` must not focus/activate the row's inputs or trigger edit — dragging never mutates a field.
- **Keyboard fallback kept:** the existing up/down + indent/outdent + remove buttons stay as the accessible reordering path (drag is pointer-only). All their `data-test` hooks (`tree-item-up`, `tree-item-down`, `tree-item-indent`, `tree-item-outdent`, `tree-item-remove`) are retained.
- **Round-trip test (required):** drag a child item under a *different* parent, Save, reload the menu, and assert the new nesting survives (the child now resolves under its new parent, in the new position) — proving the drag→save→load path preserves shape, not just sibling order.

## 9. Sidebar menu reordering — full-stack (`thallo-navigation` + admin)

Menus sort `ORDER BY slug ASC` with no position today. Menus are independent named lists (main, footer, …), so `position` affects only the admin list order — no delivery/render impact.

### Backend (`packages/thallo-navigation`)

- **Migration (fold pre-launch):** add `$table->integer('position')->default(0)` into the **original** `migrations/001_CreateNavigationMenusTable.php` (not an ALTER migration), per the project's pre-launch migration convention. Already-migrated dev DBs are synced manually (throwaway script or admin), not via a new migration file.
- `MenuRepository::createMenu` — set `position` to `MAX(position) + 1` (new menus append to the end).
- `MenuRepository::listMenus` — `ORDER BY m.position ASC, m.slug ASC` (slug as the stable tiebreak).
- `MenuRepository::reorderMenus(array $slugs): void` — the caller passes the **full ordered slug list**. In one transaction, write **dense `0..n-1`** positions in list order (a total rewrite, not a sparse patch). The controller validates the set *before* calling; the repo write is all-or-nothing (transaction → no partial write on failure).
- **Endpoint** `POST /menus/reorder` → `NavigationAdminController::reorder`, body `{ "slugs": string[] }`, validated by a `MenuReorderDTO`. Validation is a **deterministic full-set rewrite contract**, all → **422 with no write**:
  - each slug non-empty and a string;
  - **no duplicates** in the payload;
  - the payload set **equals** the current menu set exactly — **no unknown** slugs (not an existing menu) and **no missing** slugs (every existing menu must appear). This forces the client to send the complete order, so the result is always a dense, gapless `0..n-1`.
  On success dispatch `MenuUpdated` and return the reordered summaries. **Route-ordering pin:** register `/menus/reorder` **before** `/menus/{slug}` in `routes/admin-routes.php` so the `{slug}` param route can't capture `reorder` (directly relevant to the recent route-cache param desync fix).
- **Concurrency:** reorder is a cross-menu op and deliberately does **not** bump per-menu `lock_version`; last-write-wins on ordering is acceptable for an admin nicety (the per-menu tree PUT keeps its own optimistic lock unchanged). Because the payload is the full set written densely, a lost update degrades only to a stale-but-valid ordering, never to gaps or duplicate positions.

### Frontend (`admin`)

- `queries/navigation.ts`: `reorderMenus(slugs: string[])` calling `POST …/menus/reorder` with the **complete** ordered slug list (matching the full-set backend contract), plus a `reorder` mutation in `useNavigationMutations` that optimistically applies the new order to the cached menu list and invalidates on success; on error it reverts and toasts.
- Sidebar rows (§3) wrapped in `VueDraggable` (local mirror of `menus`) dragged by the grip handle `data-test="nav-menu-drag"`; `@end` derives the full new slug order from the mirror and fires the reorder mutation with **all** slugs.
- **Keyboard fallback:** the per-row overflow `UDropdownMenu` (`data-test="nav-menu-menu"`, §3) "Move up" / "Move down" swap the row with its neighbour in the local list and fire the same reorder mutation with the full order — the accessible equivalent of drag.
- **Selection is preserved by slug, not index (P2).** Reorder must never reassign `selected`: the mutation reorders the list only, and the §5 reconciliation watch changes `selected` **solely** when the selected slug is invalid. Because reorder keeps the exact menu set, a valid selection stays put — the same menu remains open and highlighted even though its row moved. (Do not key selection off the row index anywhere; index-based selection would silently follow the moved row.) The optimistic update and the post-success refetch both leave `selected` untouched.

## Testing

- Retain every existing `data-test` hook (relocated, never removed): `nav-page`, `nav-menu-list`, `nav-menu-row`, `nav-menu-create`, `nav-locale-tab`, `nav-menu-delete`, `tree-add-root`, `tree-add-page`, `add-page-picker`, `add-page-type`, `tree-save`, `tree-item-up/down/indent/outdent/remove`.
- **Admin (vitest):** (a) auto-select picks the first menu when none selected; (b) a stale `selected` slug reconciles to the first menu after the list changes; (c) "New menu" is absent when the capability is disabled; (d) a selected row exposes `aria-current="true"`; (e) the menu-row overflow "Move up/down" calls `reorderMenus` with the **full** expected order (drag is not unit-testable in jsdom — assert wiring via the keyboard fallback); (f) the tree drag-commit helper: given a "move child X under parent Y at index i" descriptor, the working tree reflects the new parent + order (unit test on the commit function, since the drag gesture itself isn't jsdom-testable); (g) selection survives a reorder — with menu B selected, invoking reorder (via the overflow "Move up/down") leaves `selected === 'b'` even though B's row index changed (guards the by-slug, not by-index, rule). Verify `pnpm type-check`, `pnpm test`, `pnpm lint`.
- **Backend (`thallo-navigation`, phpunit):**
  - Menu order: `createMenu` appends (`max+1`); `reorderMenus` writes dense `0..n-1` and `listMenus` returns the new order.
  - Reorder validation — each returns **422 with positions unchanged** (assert the stored order is identical after the rejected call, proving no partial write): duplicate slug in payload; a missing slug (existing menu omitted); an unknown slug (not a menu). A complete, unique, exact-set payload returns 200 and dense positions.
  - Routing: `POST /menus/reorder` resolves to `reorder`, not `rename` via `/menus/{slug}`.
  - **Tree-shape round-trip (the P1 nesting test, placed on the save path where it's deterministic):** `PUT /menus/{slug}/items` with child X under parent A → GET asserts X nested under A at its position; then PUT the same tree with X moved under parent B → GET asserts X now nests under B. Proves the menu-save path preserves re-nesting (what a tree drag produces), independent of the UI gesture.
  - Run the package's phpunit + `composer ci`.

## Constraints

- Admin files: `admin/src/pages/navigation/index.vue`, `admin/src/pages/navigation/components/MenuTreeEditor.vue`, `admin/src/queries/navigation.ts` (+ specs).
- Backend files: `packages/thallo-navigation/migrations/001_CreateNavigationMenusTable.php`, `src/MenuRepository.php`, `src/Http/Controllers/NavigationAdminController.php`, `src/Http/MenuReorderDTO.php` (new, `Thallo\Navigation\Http`), `routes/admin-routes.php` (+ tests).
- No route split of the page; the tree drag adds no backend change; keep all existing tree/editor and save/delete/locale behavior unchanged.
- **Suggested plan sequencing** (one plan, ordered tasks so each is independently testable): §9 backend → §9 admin query+sidebar drag → §8 tree drag → §1–§7 page redesign. The page-shell redesign lands last so the drag work isn't rebased across a large template rewrite.
