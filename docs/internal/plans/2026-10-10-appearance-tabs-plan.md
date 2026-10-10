# Appearance tabs — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Site › Appearance becomes five tabs (Theme · Colours · Design · Typefaces · Logos & site
icon) beside the pinned preview. It keeps one form and one Save, shows per-tab unsaved and error
dots, and puts the open tab in `?tab=`.

**Architecture:**
- **Helpers.** A small pure module, `admin/src/pages/appearance/appearanceTabs.ts`, owns:
  - the tab list and which settings keys each tab holds;
  - query parsing and the fallback;
  - mapping field names to tabs.
- **The page.** `admin/src/pages/appearance/index.vue` keeps its single `useSettingsForm` and Save.
  - It wraps today's cards in `v-show` panels under a `UTabs` (`:content="false"`), like the commerce products page.
  - It derives the open tab from the route, so the URL is the only state.
  - Panels use `v-show`, not `v-if`. Switching tabs never unmounts a control, so no unsaved local state (a half-typed hex, a dialog) is lost.
- **Server.** No server change.

**Tech Stack:** Vue 3, Nuxt UI 4 (`UTabs`), vue-router 5, vitest + @vue/test-utils, Playwright (`admin/e2e`), oxfmt/oxlint, `pnpm type-check`.

**Spec:** `docs/internal/superpowers/specs/2026-10-10-appearance-tabs-design.md` (committed in `4c43251d`).

## Rulings made while planning (from the code)

1. **The pairing select (`theme_font`) moves to Typefaces with its Text and Headings pickers.**
   - The spec's table gives Design only Corners and Page ground. The Text and Headings pickers show only when the pairing is Custom, so the select that reveals them belongs on their tab.
   - **Cost if wrong:** one `UFormField` moves back.
2. **The open tab is computed from `route.query.tab`, never kept in its own ref.**
   - The products page keeps a ref plus two watchers. Here the tab list itself changes (Theme appears once themes load), and a computed getter re-resolves on its own.
   - The setter calls `router.replace`, and the default tab removes the query.
3. **While the theme list is loading, the Theme tab counts as present.**
   - Its panel shows a skeleton. Only a finished load with no cards (failure, or no list) hides it.
   - Without this, a page opened with no query would show Colours first and then jump to Theme when the fetch resolves.
   - **Cost if wrong:** a skeleton flashes in the Theme tab on a site whose theme fetch fails.
4. **A tab's unsaved dot comes from the keys Save would send that differ from the stored value**, i.e. `savePayload()` filtered against `data`, not from `useSettingsForm`'s single `dirty`.
   - `useSettingsForm` stays unchanged, as the spec says.
   - A palette key that `savePayload()` leaves out (an incomplete brand slot) shows no dot, because Save would not send it.
5. **Error dots clear when the next Save starts.**
   - A 422 field name maps to its tab by the part before the first `.` (`theme_brand_2.hex` → `theme_brand_2`).
   - Field names no tab owns map to no tab. The toast still names them.

## Global Constraints

- Tabs, in order: **Theme**, **Colours**, **Design**, **Typefaces**, **Logos & site icon**; query values `theme`, `colours`, `design`, `typefaces`, `logos`.
- The default tab has no query. An unknown value falls back to the default. Back and forward move between tabs.
- Theme is the default. When the theme list loads with no cards, the Theme tab is hidden and Colours is the default.
- One form, one Save in the navbar. Its unsaved dot is unchanged. Save sends every tab's pending changes. Switching tabs never discards or saves.
- `TypefacesCard` keeps its immediate actions (not part of Save).
- The preview stays pinned beside every tab at `xl` and above, and above the tabs below `xl`.
- Dot text equivalents: `"<Tab> — unsaved changes"`, `"<Tab> — has errors"`.
- Admin gates: `pnpm test`, `pnpm type-check`, `pnpm lint`, `pnpm fmt:check` (format touched files only).
- Changelog: one `[Unreleased]` **Changed** bullet in the same commit as the change: "Appearance is split into tabs — Theme, Colours, Design, Typefaces, Logos & site icon".
- Commits: no AI attribution trailers; never push.
- `$SCRATCH` below means the session scratchpad directory; long test output goes there, never to `/tmp`.

## Review Focus

1. A deep link to a tab that is hidden (`?tab=theme` with no themes) should open the default (Colours), not a blank panel. Covered in Task 1 (`tabFromQuery`) and Task 2.
2. An edit on one tab, then a switch to another and back, must keep the edit and its dot. `v-show` panels guarantee it, and Task 2 tests it.
3. A 422 naming a nested field (`theme_brand_2.name`) should land on Colours. Covered in Task 1 (`tabsHolding`).
4. Loading with no query while the theme fetch is still pending must not bounce from Colours to Theme. Covered by Ruling 3 and a Task 2 test with a deferred fetch.
5. Clicking the tab that is already default must clear `?tab`, not write `?tab=theme`. Covered in Task 2.
6. Links elsewhere in the admin that lead to the typeface library (`/appearance#typefaces`) would land on Theme with the card hidden. Task 2 points them at `?tab=typefaces#typefaces`.

---

### Task 1: The tab model

**Files:**
- Create: `admin/src/pages/appearance/appearanceTabs.ts`
- Test: `admin/src/__tests__/appearanceTabs.spec.ts`

**Interfaces:**
- Produces:
  - `type AppearanceTab = 'theme' | 'colours' | 'design' | 'typefaces' | 'logos'`
  - `const TAB_LABELS: Record<AppearanceTab, string>`
  - `const TAB_KEYS: Record<AppearanceTab, readonly string[]>` (the settings keys each tab holds)
  - `function availableTabs(hasThemes: boolean): AppearanceTab[]`
  - `function tabFromQuery(value: unknown, tabs: readonly AppearanceTab[]): AppearanceTab`
  - `function tabsHolding(fields: Iterable<string>): Set<AppearanceTab>`

- [ ] **Step 1: Write the failing test**

```ts
// admin/src/__tests__/appearanceTabs.spec.ts
// Appearance's tabs: which exist, which one a URL opens, and which tab holds a settings key.
import { describe, it, expect } from 'vitest'
import {
  TAB_KEYS,
  availableTabs,
  tabFromQuery,
  tabsHolding,
} from '@/pages/appearance/appearanceTabs'

describe('appearance tabs', () => {
  it('lists the five tabs in order, and drops Theme when there are no themes', () => {
    expect(availableTabs(true)).toEqual(['theme', 'colours', 'design', 'typefaces', 'logos'])
    expect(availableTabs(false)).toEqual(['colours', 'design', 'typefaces', 'logos'])
  })

  it('opens the queried tab, and falls back to the first tab for unknown or hidden ones', () => {
    const all = availableTabs(true)
    expect(tabFromQuery('logos', all)).toBe('logos')
    expect(tabFromQuery(undefined, all)).toBe('theme')
    expect(tabFromQuery('nope', all)).toBe('theme')
    expect(tabFromQuery(['colours'], all)).toBe('theme')
    expect(tabFromQuery('theme', availableTabs(false))).toBe('colours')
  })

  it('maps settings keys, nested field names included, to the tabs that hold them', () => {
    expect([...tabsHolding(['site_favicon'])]).toEqual(['logos'])
    expect([...tabsHolding(['theme_brand_2.hex', 'theme_radius'])].sort()).toEqual([
      'colours',
      'design',
    ])
    expect([...tabsHolding(['theme_font_text_family'])]).toEqual(['typefaces'])
    expect(tabsHolding(['site_name']).size).toBe(0)
  })

  it('gives every key the page owns to exactly one tab', () => {
    const keys = Object.values(TAB_KEYS).flat()
    expect(new Set(keys).size).toBe(keys.length)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && pnpm vitest run src/__tests__/appearanceTabs.spec.ts`
Expected: FAIL. The module `@/pages/appearance/appearanceTabs` cannot be resolved.

- [ ] **Step 3: Write minimal implementation**

```ts
// admin/src/pages/appearance/appearanceTabs.ts
// Site › Appearance's tabs (appearance tabs spec §2): which exist, which one the URL's `?tab=`
// opens, and which tab holds each of the page's settings keys — for the unsaved and error dots.

export type AppearanceTab = 'theme' | 'colours' | 'design' | 'typefaces' | 'logos'

const ORDER: readonly AppearanceTab[] = ['theme', 'colours', 'design', 'typefaces', 'logos']

export const TAB_LABELS: Record<AppearanceTab, string> = {
  theme: 'Theme',
  colours: 'Colours',
  design: 'Design',
  typefaces: 'Typefaces',
  logos: 'Logos & site icon',
}

export const TAB_KEYS: Record<AppearanceTab, readonly string[]> = {
  theme: ['theme'],
  colours: [
    'theme_accent',
    'theme_neutral',
    'theme_neutral_custom',
    'theme_dark_base',
    'theme_brand_1',
    'theme_brand_2',
    'theme_brand_3',
  ],
  design: ['theme_radius', 'theme_background'],
  typefaces: ['theme_font', 'theme_font_text_family', 'theme_font_headings_family'],
  logos: ['site_logo', 'site_logo_dark', 'site_favicon'],
}

/** The tabs shown: Theme only while there is a theme to choose. */
export function availableTabs(hasThemes: boolean): AppearanceTab[] {
  return ORDER.filter((tab) => hasThemes || tab !== 'theme')
}

/** The tab a `?tab=` value opens; anything else — absent, unknown, hidden — opens the first. */
export function tabFromQuery(value: unknown, tabs: readonly AppearanceTab[]): AppearanceTab {
  return tabs.includes(value as AppearanceTab) ? (value as AppearanceTab) : tabs[0]!
}

/** The tabs holding these fields; a nested name (`theme_brand_2.hex`) counts as its key. */
export function tabsHolding(fields: Iterable<string>): Set<AppearanceTab> {
  const out = new Set<AppearanceTab>()
  for (const field of fields) {
    const key = field.split('.')[0]!
    for (const tab of ORDER) if (TAB_KEYS[tab].includes(key)) out.add(tab)
  }
  return out
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd admin && pnpm vitest run src/__tests__/appearanceTabs.spec.ts`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
cd admin && pnpm exec oxfmt src/pages/appearance/appearanceTabs.ts src/__tests__/appearanceTabs.spec.ts
git add src/pages/appearance/appearanceTabs.ts src/__tests__/appearanceTabs.spec.ts
git commit -m "feat(admin): Appearance's tab model — the tabs, ?tab= parsing, and which tab holds each settings key"
```

### Task 2: The page in tabs

**Files:**
- Modify: `admin/src/pages/appearance/index.vue` (script: imports, router, tabs state, `onSave`; template: the left column, lines ~405–603)
- Modify: `admin/src/__tests__/appearancePage.spec.ts` (router mocks and a new `describe('tabs')`)
- Modify: `admin/src/__tests__/appearance-palette.spec.ts` (router mocks only)
- Modify: `admin/src/editor/inspector/controls/FontFamilyControl.vue:145` and `admin/src/pages/media/components/MediaPanel.vue:283` (their `/appearance#typefaces` links)
- Modify: `admin/src/__tests__/fontFamilyControl.spec.ts:112`, `admin/src/__tests__/mediaPanelFontLibrary.spec.ts:86`
- Modify: `CHANGELOG.md` (`[Unreleased]` → `### Changed`)

**Interfaces:**
- Consumes: `AppearanceTab`, `TAB_LABELS`, `availableTabs`, `tabFromQuery`, `tabsHolding` (Task 1).
- Produces:
  - The tab panels carry `data-test="appearance-panel-<tab>"`.
  - The dots carry `data-test="appearance-tab-dirty-<tab>"` / `data-test="appearance-tab-error-<tab>"`.
  - The tab list carries `data-test="appearance-tabs"`.
  - Every existing `data-test` (`theme-card`, `theme-colors-card`, `theme-design-card`, `logos-card`, the fields) is kept.

- [ ] **Step 1: Make the page's specs route-aware (no behaviour asserted yet)**

The page will call `useRoute`/`useRouter` from `vue-router`, as the products page does. In
**both** `appearancePage.spec.ts` and `appearance-palette.spec.ts`, replace the existing
`vi.mock('vue-router/auto', …)` block with:

```ts
// The open tab is the URL's ?tab=; replace() writes it back, as the router would.
const routeState = vi.hoisted(() => ({ path: '/appearance', params: {}, query: {} as Record<string, string> }))
const routerReplace = vi.hoisted(() => vi.fn())
vi.mock('vue-router', async (importOriginal) => {
  const { reactive } = await import('vue')
  const route = reactive(routeState)
  routerReplace.mockImplementation(({ query }: { query: Record<string, string> }) => {
    route.query = { ...query }
  })
  return {
    ...(await importOriginal<typeof import('vue-router')>()),
    useRoute: () => route,
    useRouter: () => ({ push: vi.fn(), replace: routerReplace, resolve: vi.fn() }),
  }
})
vi.mock('vue-router/auto', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => routeState,
  useRouter: () => ({ push: vi.fn(), replace: routerReplace, resolve: vi.fn() }),
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))
```

In each file's `beforeEach`, add `routeState.query = {}` and `routerReplace.mockClear()`.

Run: `cd admin && pnpm vitest run src/__tests__/appearancePage.spec.ts src/__tests__/appearance-palette.spec.ts`
Expected: PASS. The page doesn't read the route yet, so nothing changes.

- [ ] **Step 2: Write the failing tests**

Append to `appearancePage.spec.ts`:

```ts
const tabButton = (wrapper: ReturnType<typeof mount>, label: string) =>
  wrapper.findAll('[role="tab"]').find((t) => t.text().startsWith(label))!
async function openTab(wrapper: ReturnType<typeof mount>, label: string) {
  await tabButton(wrapper, label).trigger('mousedown', { button: 0 })
  await flushPromises()
}
const shown = (wrapper: ReturnType<typeof mount>, tab: string) =>
  wrapper.get(`[data-test="appearance-panel-${tab}"]`).isVisible()

describe('appearance tabs', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    settingsData.value = settings()
    saveMock.mockReset().mockResolvedValue({ ...settings() })
    notify.error.mockClear()
    routeState.query = {}
    routerReplace.mockClear()
    fetchRenderThemesMock.mockReset().mockResolvedValue({
      themes: ['default'],
      active: 'default',
      cards: [themeCard('default', 'Default')],
    })
  })

  it('shows five tabs, opening on Theme with no query', async () => {
    const wrapper = mount(AppearancePage)
    await flushPromises()
    expect(wrapper.findAll('[role="tab"]').map((t) => t.text())).toEqual([
      'Theme',
      'Colours',
      'Design',
      'Typefaces',
      'Logos & site icon',
    ])
    expect(shown(wrapper, 'theme')).toBe(true)
    expect(shown(wrapper, 'colours')).toBe(false)
  })

  it('puts each control on its own tab', async () => {
    const wrapper = mount(AppearancePage)
    await flushPromises()
    const panel = (tab: string) => wrapper.get(`[data-test="appearance-panel-${tab}"]`)
    expect(panel('theme').find('[data-test="theme-card"]').exists()).toBe(true)
    expect(panel('colours').find('[data-test="theme-colors-card"]').exists()).toBe(true)
    expect(panel('design').find('[data-test="theme-radius"]').exists()).toBe(true)
    expect(panel('design').find('[data-test="theme-background"]').exists()).toBe(true)
    expect(panel('design').find('[data-test="theme-font"]').exists()).toBe(false)
    expect(panel('typefaces').find('[data-test="theme-font"]').exists()).toBe(true)
    expect(panel('typefaces').find('[data-test="typefaces-card"]').exists()).toBe(true)
    expect(panel('logos').find('[data-test="logos-card"]').exists()).toBe(true)
  })

  it('opens the tab the URL names, writes the tab back, and leaves the default without a query', async () => {
    routeState.query = { tab: 'colours' }
    const wrapper = mount(AppearancePage)
    await flushPromises()
    expect(shown(wrapper, 'colours')).toBe(true)

    await openTab(wrapper, 'Logos')
    expect(routerReplace).toHaveBeenLastCalledWith({ query: { tab: 'logos' } })
    expect(shown(wrapper, 'logos')).toBe(true)

    await openTab(wrapper, 'Theme')
    expect(routerReplace).toHaveBeenLastCalledWith({ query: {} })
    expect(shown(wrapper, 'theme')).toBe(true)
  })

  it('follows back and forward', async () => {
    const wrapper = mount(AppearancePage)
    await flushPromises()
    routerReplace({ query: { tab: 'design' } }) // what a history step does to the route
    await flushPromises()
    expect(shown(wrapper, 'design')).toBe(true)
  })

  it('falls back to the default for an unknown tab', async () => {
    routeState.query = { tab: 'nope' }
    const wrapper = mount(AppearancePage)
    await flushPromises()
    expect(shown(wrapper, 'theme')).toBe(true)
  })

  it('hides Theme when there are no themes, opening on Colours', async () => {
    fetchRenderThemesMock.mockReset().mockRejectedValue(new Error('no pack'))
    routeState.query = { tab: 'theme' }
    const wrapper = mount(AppearancePage)
    await flushPromises()
    expect(wrapper.findAll('[role="tab"]').map((t) => t.text())[0]).toBe('Colours')
    expect(shown(wrapper, 'colours')).toBe(true)
  })

  it('does not jump from Colours to Theme while the theme list is still loading', async () => {
    let resolve!: (v: unknown) => void
    fetchRenderThemesMock.mockReset().mockReturnValue(new Promise((r) => (resolve = r)))
    const wrapper = mount(AppearancePage)
    await flushPromises()
    expect(shown(wrapper, 'theme')).toBe(true)
    resolve({ themes: ['default'], active: 'default', cards: [themeCard('default', 'Default')] })
    await flushPromises()
    expect(shown(wrapper, 'theme')).toBe(true)
    expect(routerReplace).not.toHaveBeenCalled()
  })

  it('marks the tab holding an unsaved change, and keeps the change across a tab switch', async () => {
    const wrapper = mount(AppearancePage)
    await flushPromises()
    await openTab(wrapper, 'Logos')
    await wrapper.find('[data-test="stub-logo-pick"]').trigger('click')
    await flushPromises()
    const dot = wrapper.get('[data-test="appearance-tab-dirty-logos"]')
    expect(dot.text()).toBe('Logos & site icon — unsaved changes')
    expect(wrapper.find('[data-test="appearance-tab-dirty-colours"]').exists()).toBe(false)

    await openTab(wrapper, 'Colours')
    await openTab(wrapper, 'Logos')
    expect(wrapper.find('[data-test="stub-logo-pick"]').text()).toBe('blob00000042')
    expect(wrapper.find('[data-test="appearance-tab-dirty-logos"]').exists()).toBe(true)
  })

  it('saves every tab’s changes from any tab, and clears the dots', async () => {
    const wrapper = mount(AppearancePage)
    await flushPromises()
    await openTab(wrapper, 'Logos')
    await wrapper.find('[data-test="stub-logo-pick"]').trigger('click')
    await openTab(wrapper, 'Design')
    const radius = wrapper.findComponent('[data-test="theme-radius"]')
    radius.vm.$emit('update:modelValue', 'sharp')
    await flushPromises()
    expect(await save(wrapper)).toMatchObject({ site_logo: 'blob00000042', theme_radius: 'sharp' })
    settingsData.value = { ...settings(), site_logo: 'blob00000042', theme_radius: 'sharp' }
    await flushPromises()
    expect(wrapper.find('[data-test^="appearance-tab-dirty-"]').exists()).toBe(false)
  })

  it('a refused save opens the first tab holding a named field and marks every such tab', async () => {
    const { ApiError } = await import('@/api/errors')
    saveMock.mockReset().mockRejectedValue(
      new ApiError(
        'The given data was invalid.',
        422,
        { 'theme_brand_2.name': 'too long', site_favicon: 'not an image' },
        {},
      ),
    )
    routeState.query = { tab: 'design' }
    const wrapper = mount(AppearancePage)
    await flushPromises()
    await wrapper.get('[data-test="appearance-save"]').trigger('click')
    await flushPromises()
    expect(shown(wrapper, 'colours')).toBe(true)
    expect(wrapper.get('[data-test="appearance-tab-error-colours"]').text()).toBe(
      'Colours — has errors',
    )
    expect(wrapper.find('[data-test="appearance-tab-error-logos"]').exists()).toBe(true)
    expect(notify.error).toHaveBeenCalled()

    // The next save starts clean.
    saveMock.mockReset().mockResolvedValue({ ...settings() })
    await wrapper.get('[data-test="appearance-save"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test^="appearance-tab-error-"]').exists()).toBe(false)
  })

  it('a failure that names no field stays on the open tab', async () => {
    saveMock.mockReset().mockRejectedValue(new Error('network'))
    routeState.query = { tab: 'design' }
    const wrapper = mount(AppearancePage)
    await flushPromises()
    await wrapper.get('[data-test="appearance-save"]').trigger('click')
    await flushPromises()
    expect(shown(wrapper, 'design')).toBe(true)
  })
})
```

The typeface library is now behind a tab, so the two links that lead to it (the font control's
Restore link, the media panel's font row) must name the tab. In `fontFamilyControl.spec.ts:112`
and `mediaPanelFontLibrary.spec.ts:86`, change the expected `'/appearance#typefaces'` to
`'/appearance?tab=typefaces#typefaces'`.

Also update the first existing test ("shows the four appearance cards…") so it reads "shows the
appearance cards…". Its body is unchanged: `v-show` panels keep every card in the DOM.

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd admin && pnpm vitest run src/__tests__/appearancePage.spec.ts src/__tests__/fontFamilyControl.spec.ts src/__tests__/mediaPanelFontLibrary.spec.ts`
Expected: the new `appearance tabs` tests and the two link assertions FAIL (no `[role="tab"]`, no
`appearance-panel-*`, the old href), and the other existing tests PASS.

- [ ] **Step 4: Implement the tabs in the page**

Script additions in `index.vue`:

```ts
import { useRoute, useRouter } from 'vue-router'
import {
  TAB_LABELS,
  availableTabs,
  tabFromQuery,
  tabsHolding,
  type AppearanceTab,
} from './appearanceTabs'
```

Replace the theme fetch block, so that a load still pending keeps the Theme tab (Ruling 3):

```ts
// Live theme options (theme-setting spec §4): fetched from the render pack. A fetch failure (pack
// absent, no permission) or a body without the list hides the Theme tab once the load ends; while
// it is pending the tab stays, so a page opened on Theme never jumps there from Colours.
const themeCards = ref<ThemeCard[]>([])
const themesLoaded = ref(false)
onMounted(async () => {
  try {
    const cards: unknown = (await fetchRenderThemes()).cards
    themeCards.value = Array.isArray(cards) ? (cards as ThemeCard[]) : []
  } catch {
    themeCards.value = []
  } finally {
    themesLoaded.value = true
  }
})
```

Then the tabs (after `savePayload`, which they read):

```ts
// ── The tabs (appearance tabs spec §2–§3) ─────────────────────────────────────────────────────
// The open tab is the URL's ?tab= — the default leaves it out — so a refresh, a shared link, and
// back and forward all open the same tab. Panels are v-show: switching never unmounts a control,
// so nothing unsaved is lost.
const route = useRoute()
const router = useRouter()
const tabs = computed(() => availableTabs(!themesLoaded.value || themeCards.value.length > 0))
const tab = computed<AppearanceTab>({
  get: () => tabFromQuery(route.query.tab, tabs.value),
  set: (next) => {
    const query = { ...route.query }
    if (next === tabs.value[0]) delete query.tab
    else query.tab = next
    void router.replace({ query })
  },
})
const tabItems = computed(() => tabs.value.map((value) => ({ label: TAB_LABELS[value], value })))

/** The keys Save would send that differ from what is stored: each one's tab shows a dot. */
const dirtyTabs = computed(() => {
  const stored = (data.value ?? {}) as Record<string, unknown>
  const out = savePayload()
  return tabsHolding(
    Object.keys(out).filter((key) => String(out[key] ?? '') !== String(stored[key] ?? '')),
  )
})
/** The tabs holding a field the last refused save named; cleared when the next save starts. */
const errorTabs = ref(new Set<AppearanceTab>())
```

Replace `onSave`:

```ts
async function onSave() {
  errorTabs.value = new Set()
  try {
    await save.mutateAsync(savePayload())
    saved()
    appearanceChanges.notify('appearance')
    success('Appearance saved', 'Changes apply on the next page view.')
  } catch (e) {
    // A refused save opens the first tab, in tab order, holding a field it names (spec §3).
    const fields = (e as { fieldErrors?: Record<string, string> }).fieldErrors ?? {}
    const holding = tabsHolding(Object.keys(fields))
    errorTabs.value = holding
    const first = tabs.value.find((t) => holding.has(t))
    if (first !== undefined) tab.value = first
    notifyError(e, 'Couldn’t save the appearance settings')
  }
}
```

Template: the left column (`<div class="order-2 space-y-6 xl:order-1">…</div>`) becomes the
following. The cards' inner markup moves **unchanged**. The only change is that the pairing
select and the `custom-fonts` block leave the Design card for a new Typefaces card.

```vue
<div class="order-2 xl:order-1">
  <UTabs
    v-model="tab"
    variant="link"
    :items="tabItems"
    :content="false"
    class="mb-4"
    data-test="appearance-tabs"
  >
    <template #trailing="{ item }">
      <span
        v-if="errorTabs.has(item.value as AppearanceTab)"
        class="inline-block size-2 rounded-full bg-error"
        :data-test="`appearance-tab-error-${item.value}`"
        ><span class="sr-only">{{ item.label }} — has errors</span></span
      >
      <span
        v-else-if="dirtyTabs.has(item.value as AppearanceTab)"
        class="inline-block size-2 rounded-full bg-warning"
        :data-test="`appearance-tab-dirty-${item.value}`"
        ><span class="sr-only">{{ item.label }} — unsaved changes</span></span
      >
    </template>
  </UTabs>

  <div v-show="tab === 'theme'" class="space-y-6" data-test="appearance-panel-theme" role="tabpanel">
    <USkeleton v-if="!themesLoaded" class="h-40" />
    <UCard v-else-if="themeCards.length > 0" data-test="theme-card">
      <!-- today's Theme card body, unchanged -->
    </UCard>
  </div>

  <div v-show="tab === 'colours'" class="space-y-6" data-test="appearance-panel-colours" role="tabpanel">
    <UCard data-test="theme-colors-card">
      <!-- today's Theme colors card, unchanged, header renamed "Colours" -->
    </UCard>
  </div>

  <div v-show="tab === 'design'" class="space-y-6" data-test="appearance-panel-design" role="tabpanel">
    <UCard data-test="theme-design-card">
      <template #header>
        <h2 class="font-semibold text-default">Design</h2>
      </template>
      <div class="space-y-6">
        <p class="text-sm text-muted">
          Site-wide shape and ground. Each choice re-maps theme tokens only; a button can still
          pick its own shape.
        </p>
        <div class="grid gap-6">
          <!-- Corners UFormField, unchanged -->
          <!-- Page ground UFormField, unchanged -->
        </div>
      </div>
    </UCard>
  </div>

  <div v-show="tab === 'typefaces'" class="space-y-6" data-test="appearance-panel-typefaces" role="tabpanel">
    <UCard data-test="theme-typefaces-card">
      <template #header>
        <h2 class="font-semibold text-default">Typefaces</h2>
      </template>
      <div class="grid gap-6">
        <!-- the "Typefaces" pairing UFormField (data-test="theme-font"), moved from Design -->
        <!-- the v-if="form.theme_font === 'custom'" custom-fonts block, moved from Design -->
      </div>
    </UCard>
    <TypefacesCard />
  </div>

  <div v-show="tab === 'logos'" class="space-y-6" data-test="appearance-panel-logos" role="tabpanel">
    <UCard data-test="logos-card">
      <!-- today's Logos & site icon card, unchanged -->
    </UCard>
  </div>
</div>
```

Rename the pairing field's label from "Typefaces" to "Pairing" (description "Headings and body
text."), because the tab and card are now called Typefaces. Update the comment above the grid
("The settings on the left…") to say the tabs sit on the left. Also update the header comment at
the top of the script to list the five tabs.

`TypefacesCard.vue` already carries `id="typefaces" data-test="typefaces-card"`; keep both.

In `FontFamilyControl.vue:145` and `MediaPanel.vue:283`, change `to="/appearance#typefaces"` to
`to="/appearance?tab=typefaces#typefaces"`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd admin && pnpm vitest run src/__tests__/appearancePage.spec.ts src/__tests__/appearance-palette.spec.ts src/__tests__/appearanceCustomFamilies.spec.ts src/__tests__/appearanceTabs.spec.ts src/__tests__/clear-brand-dialog.spec.ts src/__tests__/fontFamilyControl.spec.ts src/__tests__/mediaPanelFontLibrary.spec.ts`
Expected: PASS, every file. If an existing test asserted the label "Typefaces" on the pairing
select, update it to "Pairing" and say so in the ledger.

- [ ] **Step 6: Gates and changelog**

Add under `CHANGELOG.md` `## [Unreleased]` → `### Changed` (create the heading after `### Added`
if it is missing):

```markdown
- **Appearance is split into tabs** — Theme, Colours, Design, Typefaces, Logos & site icon — with
  the preview beside every tab. One Save still saves them all; a tab with unsaved changes or with a
  field a save refused shows a dot, and the open tab is in the address (`/appearance?tab=colours`).
```

Run: `cd admin && pnpm test > "$SCRATCH/vitest.log" 2>&1; tail -5 "$SCRATCH/vitest.log"; pnpm type-check && pnpm lint && pnpm exec oxfmt --check src/pages/appearance src/__tests__/appearancePage.spec.ts src/__tests__/appearance-palette.spec.ts src/__tests__/appearanceTabs.spec.ts`
Expected: all vitest files pass; type-check, lint and fmt exit 0.

- [ ] **Step 7: Commit**

```bash
git add admin/src/pages/appearance admin/src/editor/inspector/controls/FontFamilyControl.vue admin/src/pages/media/components/MediaPanel.vue admin/src/__tests__/appearancePage.spec.ts admin/src/__tests__/appearance-palette.spec.ts admin/src/__tests__/fontFamilyControl.spec.ts admin/src/__tests__/mediaPanelFontLibrary.spec.ts CHANGELOG.md
git commit -m "feat(admin): Appearance in tabs — Theme, Colours, Design, Typefaces, Logos & site icon; one Save, per-tab unsaved and error dots, the open tab in ?tab="
```

### Task 3: Browser specs select their tab; the guide follows the tabs

**Files:**
- Modify: `admin/e2e/tests/appearance-page.spec.ts`
- Modify: `docs/guides/01-appearance.md`

**Interfaces:**
- Consumes: the tab list `data-test="appearance-tabs"` and the tab labels (Task 2).

- [ ] **Step 1: Run the e2e spec to see it fail**

First rebuild fixtures if the DB was reset since they were built:
`rm -rf admin/e2e/fixtures && CACHE_DRIVER=array DB_PGSQL_DATABASE=app_test APP_ENV=testing php scripts/build-builder-proof-fixtures`
(MAMP PHP first on PATH: `export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:$PATH`).

Run: `cd admin && npx playwright test tests/appearance-page.spec.ts`
Expected: FAIL. The `toBeVisible` checks on `theme-design-card` / `logos-card` and the
`theme-radius` click time out, because those panels are hidden behind their tabs.

- [ ] **Step 2: Add a tab helper and select tabs**

At the top of `appearance-page.spec.ts`, after the imports:

```ts
/** Opens an Appearance tab by its label (appearance tabs spec §2). */
async function openTab(page: Page, name: string) {
  await page.locator('[data-test="appearance-tabs"]').getByRole('tab', { name, exact: true }).click()
}
```

(Add `type Page` to the `@playwright/test` import if it is not imported.)

Then, in each test:
- **"Appearance is in the Site group…":**
  - wait on `appearance-tabs` instead of `theme-colors-card`;
  - assert the five tab names in order with `getByRole('tab')`;
  - replace the card visibility loop with `await openTab(page, 'Design')` before the `theme-radius` click.
  - Keep the rest.
- **"the theme gallery…":** it stays on the default tab (Theme). Replace any initial wait on `theme-colors-card` with one on `theme-card`.
- **"a brand colour says how it will read…":** after `page.goto`, wait on `appearance-tabs`, then `await openTab(page, 'Colours')` before the brand colour steps, and `await openTab(page, 'Typefaces')` before any font family step.
- Add one test:

```ts
test('a link can open Appearance on a tab, and the tab is kept in the address', async ({ page }) => {
  await openDesignPage(page)
  await routeSettings(page)
  await routePreview(page)
  await page.goto('/admin/appearance?tab=colours')
  await expect(page.locator('[data-test="theme-colors-card"]')).toBeVisible({ timeout: 20_000 })
  await openTab(page, 'Logos & site icon')
  await expect(page).toHaveURL(/\/admin\/appearance\?tab=logos$/)
  await expect(page.locator('[data-test="logos-card"]')).toBeVisible()
  await page.goBack()
  await expect(page.locator('[data-test="theme-colors-card"]')).toBeVisible()
})
```

**"Settings › General no longer holds the appearance cards…"** only checks links. Change any
`toBeVisible` on an Appearance card in it to the tab list.

- [ ] **Step 3: Run the e2e spec to verify it passes**

Run: `cd admin && npx playwright test tests/appearance-page.spec.ts`
Expected: PASS (5 tests).

- [ ] **Step 4: The guide**

In `docs/guides/01-appearance.md`:
- Line ~20: the list of cards becomes the five tabs, "each on its own tab, with the preview beside them".
- "Set the accent and neutral colours" says "the **Colours** tab" instead of "The **Theme colors** card".
- "Set the corners, typefaces and page ground" becomes "Set the corners and page ground" (the **Design** tab), and the pairing and Text/Headings move into "Add your own typefaces", which opens with "On the **Typefaces** tab, **Pairing** picks…".
- "Upload your logo and favicon" opens with "On the **Logos & site icon** tab".
- Add one sentence under "Open Site › Appearance": "**Save** saves every tab at once; a dot on a tab marks unsaved changes, or a field a save refused."
- Screenshots: if any `docs/images/appearance*.png` is referenced, note in the ledger that it predates the tabs. Don't regenerate images in this task.

- [ ] **Step 5: Full admin gates, then commit**

Run: `cd admin && pnpm test > "$SCRATCH/vitest.log" 2>&1; tail -5 "$SCRATCH/vitest.log"; pnpm type-check && pnpm lint && pnpm exec oxfmt --check e2e/tests/appearance-page.spec.ts`
Then the whole e2e suite once, attached: `cd admin && npx playwright test > "$SCRATCH/e2e.log" 2>&1; tail -5 "$SCRATCH/e2e.log"`
Expected: vitest, type-check, lint and fmt clean. e2e all pass. A shop spec timing out at sign-in
during a Vite reload is a known flake: rerun that spec alone before calling it a failure.

```bash
git add admin/e2e/tests/appearance-page.spec.ts docs/guides/01-appearance.md
git commit -m "test(admin): Appearance browser specs open their tab; a deep link opens Colours and back returns; the Appearance guide follows the tabs"
```
