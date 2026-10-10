// Appearance › Colours › the palette (custom palette spec §2, §3, §5.1): a Custom neutral pre-filled
// from the family the first time, a dark-mode base shown only when the site has a dark mode, the
// brand colour list (Add up to the limit, reorder, remove an unsaved row, Clear a saved one, a
// running replacement's progress, a reserved colour), the revision each save edits from, contrast
// checks of the pending look, and the unsaved palette in the preview.
import { afterEach, describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { config, mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { GeneralSettings } from '@/queries/generalSettings'

const settingsData = ref<GeneralSettings | undefined>(undefined)
const saveMock = vi.fn()
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

// The style schema's palette (slot states, colour mode) and the palette endpoints.
const schemaData = ref<Record<string, unknown> | undefined>(undefined)
vi.mock('@/queries/styleSchema', () => ({ useStyleSchema: () => ({ data: schemaData }) }))
const paletteApi = vi.hoisted(() => ({
  previewPalette: vi.fn(),
  fetchPaletteJobs: vi.fn(),
  fetchPaletteUsage: vi.fn(),
}))
vi.mock('@/queries/palette', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/palette')>()),
  previewPalette: paletteApi.previewPalette,
  fetchPaletteJobs: paletteApi.fetchPaletteJobs,
  fetchPaletteUsage: paletteApi.fetchPaletteUsage,
}))

vi.mock('@/queries/generalSettings', () => ({
  useGeneralSettings: () => ({ data: settingsData, status: ref('success') }),
  useGeneralSettingsMutations: () => ({
    save: { mutateAsync: saveMock, isLoading: ref(false) },
  }),
}))
// "Preview on site" mints a preview session through the homepage entry.
const postMock = vi.hoisted(() => vi.fn())
vi.mock('@/api/client', () => ({ client: { POST: postMock } }))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: notify.success, error: notify.error }),
}))
// The page renders FaviconPreview from blobDisplayUrl(form.site_favicon).
vi.mock('@/queries/media', () => ({
  blobDisplayUrl: (uuid: string) => `/blobs/${uuid}`,
  // The font fields (Custom typefaces) upload through it; their own spec covers the upload.
  useUploadMedia: () => ({ mutateAsync: vi.fn(), isLoading: { value: false } }),
}))
// Theme card options come from the render pack's themes endpoint.
const fetchRenderThemesMock = vi.hoisted(() => vi.fn())
vi.mock('@/queries/templates', () => ({
  fetchRenderThemes: fetchRenderThemesMock,
}))
// The open tab is the URL's ?tab=. One reactive route and router, shared by both module ids the
// page's router imports may resolve through (vue-router and vue-router/auto): push() and replace()
// record the call and move the route, as the router would.
const nav = await vi.hoisted(async () => {
  const { reactive } = await import('vue')
  const route = reactive({
    path: '/appearance',
    params: {},
    query: {} as Record<string, string>,
    hash: '',
  })
  const pushed = vi.fn()
  const replaced = vi.fn()
  const go = (to: { query: Record<string, string> }) => {
    route.query = { ...to.query }
  }
  // Recorded and then acted on: clearMocks drops a spy's implementation between tests.
  const router = {
    push: (to: { query: Record<string, string> }) => {
      pushed(to)
      go(to)
    },
    replace: (to: { query: Record<string, string> }) => {
      replaced(to)
      go(to)
    },
    resolve: () => ({}),
  }
  return { route, router, pushed, replaced }
})
const routeState = nav.route
const routerPush = nav.pushed
const routerReplace = nav.replaced
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => nav.route,
  useRouter: () => nav.router,
}))
vi.mock('vue-router/auto', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => nav.route,
  useRouter: () => nav.router,
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))
// The real AssetField opens the blob picker; the page only needs v-model.
vi.mock('@/fields/components/AssetField.vue', () => ({
  default: {
    name: 'AssetField',
    props: {
      field: { type: Object, required: true },
      modelValue: { type: String, default: '' },
      emptyValue: { type: String, default: undefined },
    },
    emits: ['update:modelValue'],
    template:
      '<span><button type="button" data-test="stub-logo-pick" ' +
      "@click=\"$emit('update:modelValue', 'blob00000042')\">{{ modelValue }}</button>" +
      '<button type="button" data-test="stub-logo-clear" ' +
      '@click="$emit(\'update:modelValue\', emptyValue)">clear</button></span>',
  },
}))

// The font library (the Typefaces card, Custom's pickers): its own specs cover it; here, the fixture.
const fontLib = vi.hoisted(() => ({ data: null as unknown }))
vi.mock('@/queries/fontLibrary', async (importOriginal) => {
  const { ref: vref } = await import('vue')
  const { fontLibrary } = await import('./helpers/fontLibraryFixture')
  fontLib.data = vref(fontLibrary())
  const op = () => ({ mutateAsync: vi.fn(), isLoading: { value: false } })
  return {
    ...(await importOriginal<typeof import('@/queries/fontLibrary')>()),
    useFontLibrary: () => ({ data: fontLib.data }),
    fetchFontUsage: vi.fn(() => new Promise(() => {})),
    useFontLibraryMutations: () => ({
      create: op(),
      update: op(),
      addFace: op(),
      remove: op(),
      restore: op(),
      purge: op(),
      readAgain: op(),
    }),
  }
})
vi.mock('@/fonts/loadFamilyFaces', () => ({ loadFamilyFaces: vi.fn(() => Promise.resolve()) }))
// A saved appearance tells the admin's other tabs, whose open stages reload.
const told = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useAppearanceChanges', () => ({
  useAppearanceChanges: () => ({ notify: told, onChange: () => () => {}, dispose: () => {} }),
}))

import AppearancePage from '@/pages/appearance/index.vue'

config.global.stubs = { ...config.global.stubs, Tooltip: { template: '<div><slot /></div>' } }

const settings = (): GeneralSettings => ({
  site_name: 'Thallo',
  site_preview_url: '',
  default_locale: 'en',
  default_per_page: 20,
  max_per_page: 100,
  cache_ttl: 60,
  scheduler_enabled: true,
  webhooks_enabled: true,
  search_enabled: false,
  homepage_entry: '',
  site_logo: '',
  site_logo_dark: '',
  site_favicon: '',
  theme: 'default',
  theme_accent: 'blue',
  theme_neutral: 'slate',
  theme_radius: 'round',
  theme_font: 'sans',
  theme_font_text_family: '',
  theme_font_headings_family: '',
  theme_background: 'plain',
  admin_url: '',
  listing_types: ['post'],
})

const FAMILY_LIGHT = {
  background: '#ffffff',
  surface: '#f6f7f9',
  'surface-2': '#eef0f4',
  text: '#0f172a',
  muted: '#64748b',
  line: '#e2e8f0',
}

type Rows = Array<{ fg: string; on: string; mode: string; ratio: number; passes: boolean }>
function mockPreview(light: Record<string, string> = FAMILY_LIGHT, rows: Rows = []) {
  paletteApi.previewPalette
    .mockReset()
    .mockResolvedValue({ rows, swatches: {}, values: { light, dark: {} } })
  return paletteApi.previewPalette
}

/** The stored list the tests start from: Gold (4) then Rose (1), Teal (7) cleared, revision 3. */
const STORED =
  '{"revision":3,"colors":[{"id":4,"name":"Gold","hex":"#8a6a2a"},{"id":1,"name":"Rose","hex":"#c98a8a"}],' +
  '"removed":[{"id":7,"name":"Teal"}]}'

/** A stored list: colours `[id, name, hex]`, removed `[id, name]`, at a revision. */
function stored(
  revision: number,
  colors: Array<[number, string, string]>,
  removed: Array<[number, string]> = [],
): string {
  return JSON.stringify({
    revision,
    colors: colors.map(([id, name, hex]) => ({ id, name, hex })),
    removed: removed.map(([id, name]) => ({ id, name })),
  })
}

function schema(
  slots: Record<string, Record<string, unknown>> = {},
  colorMode = true,
  limit = 3,
  order: string[] = Object.keys(slots).filter((k) => slots[k]!.state !== 'removed'),
) {
  const labels: Record<string, string> = {}
  for (const [key, slot] of Object.entries(slots)) labels[`color.${key}`] = String(slot.name ?? key)
  return {
    palette: {
      limit,
      order,
      can_manage: true,
      slots,
      swatches: {},
      labels,
      color_mode: colorMode,
      generation: 1,
      replacements: { after: 1, through: 1, records: [] },
    },
  }
}
const GOLD_ROSE = {
  'brand-4': {
    name: 'Gold',
    hex: '#8a6a2a',
    state: 'configured',
    reserved: false,
    replacing: null,
  },
  'brand-1': {
    name: 'Rose',
    hex: '#c98a8a',
    state: 'configured',
    reserved: false,
    replacing: null,
  },
  'brand-7': { name: 'Teal', state: 'removed' },
}

async function mountAppearance(
  storedSettings: Partial<GeneralSettings & Record<string, string>> = {},
  opts: {
    colorMode?: boolean
    schemaPalette?: Record<string, Record<string, unknown>>
    jobs?: unknown[]
    limit?: number
  } = {},
) {
  settingsData.value = {
    ...settings(),
    theme_neutral_custom: '',
    theme_dark_base: '',
    theme_brand_colors: '',
    ...storedSettings,
  } as GeneralSettings
  schemaData.value = schema(opts.schemaPalette ?? {}, opts.colorMode ?? true, opts.limit ?? 3)
  paletteApi.fetchPaletteJobs.mockReset().mockResolvedValue(opts.jobs ?? [])
  const w = mount(AppearancePage)
  await flushPromises()
  return w
}

/** The Nuxt UI select whose rendered trigger carries the test id. */
function selectNamed(w: ReturnType<typeof mount>, testId: string) {
  return w
    .findAllComponents({ name: 'Select' })
    .find((c) => c.find(`[data-test="${testId}"]`).exists())
}

async function choose(w: ReturnType<typeof mount>, testId: string, value: string) {
  const select = selectNamed(w, testId)
  expect(select, testId).toBeTruthy()
  select!.vm.$emit('update:modelValue', value)
  await flushPromises()
}

function selected(w: ReturnType<typeof mount>, testId: string): unknown {
  return selectNamed(w, testId)?.props('modelValue')
}

async function savedPayload(w: ReturnType<typeof mount>): Promise<Record<string, string>> {
  await w.find('[data-test="appearance-save"]').trigger('click')
  await flushPromises()
  const calls = saveMock.mock.calls
  return calls[calls.length - 1]![0] as Record<string, string>
}

const inputValue = (w: ReturnType<typeof mount>, selector: string) =>
  (w.find(selector).element as HTMLInputElement).value

describe('Appearance › Theme colors › palette', () => {
  beforeEach(() => {
    routeState.query = {}
    routerPush.mockClear()
    routerReplace.mockClear()
    setActivePinia(createPinia())
    saveMock.mockReset().mockResolvedValue({ ...settings() })
    fetchRenderThemesMock
      .mockReset()
      .mockResolvedValue({ themes: [], active: 'default', cards: [] })
    mockPreview()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('pre-fills Custom from the current family the first time, and keeps the values across a family switch', async () => {
    const w = await mountAppearance({ theme_neutral: 'slate', theme_neutral_custom: '' })
    await choose(w, 'theme-neutral', 'custom')
    expect(paletteApi.previewPalette).toHaveBeenCalledWith(
      expect.objectContaining({ theme_neutral: 'slate' }),
    )
    expect(inputValue(w, '[data-test="neutral-custom-bg"] input')).toBe('#ffffff')
    expect(selected(w, 'theme-dark-base')).toBe('slate')
    await w.find('[data-test="neutral-custom-bg"] input').setValue('#F8F4EC')
    await choose(w, 'theme-neutral', 'stone')
    await choose(w, 'theme-neutral', 'custom')
    expect(inputValue(w, '[data-test="neutral-custom-bg"] input')).toBe('#f8f4ec')
  })

  it('does not pre-fill again when Custom values are stored', async () => {
    const stored = JSON.stringify({
      bg: '#f8f4ec',
      surface: '#ffffff',
      surface_2: '#efe7d8',
      ink: '#1b1712',
      muted: '#6b6156',
      line: '#e2d8c6',
    })
    const w = await mountAppearance({ theme_neutral: 'stone', theme_neutral_custom: stored })
    await choose(w, 'theme-neutral', 'custom')
    expect(inputValue(w, '[data-test="neutral-custom-bg"] input')).toBe('#f8f4ec')
    expect(paletteApi.previewPalette).not.toHaveBeenCalledWith(
      expect.objectContaining({ theme_neutral: 'stone' }),
    )
  })

  it('Reset sends an empty custom value with the family', async () => {
    const w = await mountAppearance({
      theme_neutral: 'custom',
      theme_dark_base: 'slate',
      theme_neutral_custom:
        '{"bg":"#f8f4ec","surface":"#ffffff","surface_2":"#efe7d8","ink":"#1b1712","muted":"#6b6156","line":"#e2d8c6"}',
    })
    await w.find('[data-test="neutral-reset"]').trigger('click')
    expect(await savedPayload(w)).toMatchObject({
      theme_neutral: 'slate',
      theme_neutral_custom: '',
    })
  })

  it('hides the dark base while colour mode is off, and never sends it unchanged', async () => {
    const w = await mountAppearance(
      {
        theme_neutral: 'custom',
        theme_dark_base: 'stone',
        theme_neutral_custom:
          '{"bg":"#f8f4ec","surface":"#ffffff","surface_2":"#efe7d8","ink":"#1b1712","muted":"#6b6156","line":"#e2d8c6"}',
      },
      { colorMode: false },
    )
    expect(w.find('[data-test="theme-dark-base"]').exists()).toBe(false)
    expect(await savedPayload(w)).not.toHaveProperty('theme_dark_base')
  })

  it('renders contrast rows from the preview endpoint, warning below 4.5', async () => {
    vi.useFakeTimers()
    mockPreview(FAMILY_LIGHT, [
      { fg: 'muted', on: 'background', mode: 'dark', ratio: 3.1, passes: false },
      { fg: 'text', on: 'background', mode: 'light', ratio: 12.6, passes: true },
    ])
    const w = await mountAppearance({})
    await vi.advanceTimersByTimeAsync(700)
    await flushPromises()
    expect(w.find('[data-test="contrast-row-dark-muted-background"]').classes()).toContain(
      'text-warning',
    )
    expect(w.find('[data-test="contrast-row-light-text-background"]').text()).toContain(
      'Text on Background (light) — 12.6:1',
    )
    expect(w.text()).toContain(
      'These pairs are checked; other combinations a block can make are not.',
    )
  })

  // ── The brand colour list (custom palette spec §2.3, §5.1) ─────────────────────────────────────
  const rowNames = (w: ReturnType<typeof mount>) =>
    w
      .findAll('[data-test="brand-colors"] input[aria-label$=" name"]')
      .map((i) => (i.element as HTMLInputElement).value)
  const last = <T>(items: T[]): T => items[items.length - 1]!
  const lastName = (w: ReturnType<typeof mount>) =>
    last(w.findAll('[data-test="brand-colors"] input[aria-label$=" name"]'))
  const lastHex = (w: ReturnType<typeof mount>) =>
    last(w.findAll('[data-test="brand-colors"] [data-test$="-hex"] input'))
  const sentList = (payload: Record<string, string>) =>
    JSON.parse(payload.theme_brand_colors!) as {
      base: number
      colors: Array<{ id?: number; name: string; hex: string }>
    }
  async function addSky(w: ReturnType<typeof mount>) {
    await w.find('[data-test="brand-add"]').trigger('click')
    await lastName(w).setValue('Sky')
    await lastHex(w).setValue('#38BDF8')
    await flushPromises()
  }
  async function rename(w: ReturnType<typeof mount>, id: number, name: string) {
    await w.find(`[data-test="brand-row-${id}-name"]`).setValue(name)
    await flushPromises()
  }

  it('lists the brand colours in order, with the count against the limit', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    expect(rowNames(w)).toEqual(['Gold', 'Rose'])
    expect(w.find('[data-test="brand-row-4"]').exists()).toBe(true)
    expect(w.find('[data-test="brand-count"]').text()).toBe('2 of 3')
  })

  it('Add colour appends an empty row with a plain remove button, and saves it without an id', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await addSky(w)
    expect(w.findAll('[data-test="brand-row-remove"]')).toHaveLength(1)
    expect(w.findAll('[data-test="brand-row-clear"]')).toHaveLength(2)
    const list = sentList(await savedPayload(w))
    expect(list.base).toBe(3)
    expect(list.colors[list.colors.length - 1]).toEqual({ name: 'Sky', hex: '#38bdf8' })
  })

  it('Add is disabled at the limit and says so', async () => {
    const full = stored(3, [
      [4, 'Gold', '#8a6a2a'],
      [1, 'Rose', '#c98a8a'],
      [2, 'Ink', '#111111'],
    ])
    const w = await mountAppearance({ theme_brand_colors: full }, { schemaPalette: GOLD_ROSE })
    expect(w.find('[data-test="brand-add"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="brand-limit"]').text()).toBe('This site allows 3 brand colours')
  })

  it('removing an unsaved row sends nothing', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await w.find('[data-test="brand-add"]').trigger('click')
    await w.find('[data-test="brand-row-remove"]').trigger('click')
    expect(await savedPayload(w)).not.toHaveProperty('theme_brand_colors')
  })

  it('reordering sends the new order', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    const draggable = w.findComponent({ name: 'VueDraggable' })
    const rows = draggable.props('modelValue') as unknown[]
    draggable.vm.$emit('update:modelValue', [...rows].reverse())
    await flushPromises()
    expect(sentList(await savedPayload(w)).colors.map((c) => c.id)).toEqual([1, 4])
  })

  it('Clear on a saved colour opens the dialog for that id', async () => {
    paletteApi.fetchPaletteUsage.mockReset().mockReturnValue(new Promise(() => {}))
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await w.find('[data-test="brand-row-4"] [data-test="brand-row-clear"]').trigger('click')
    await flushPromises()
    expect(paletteApi.fetchPaletteUsage).toHaveBeenCalledWith(4)
    const dialog = w.findComponent({ name: 'ClearBrandDialog' })
    expect(dialog.props('id')).toBe(4)
    expect(dialog.props('name')).toBe('Gold')
  })

  it('shows a replacing colour as progress and a reserved one without Clear', async () => {
    const w = await mountAppearance(
      { theme_brand_colors: STORED },
      {
        schemaPalette: {
          'brand-4': {
            name: 'Gold',
            state: 'replacing',
            replacing: { to: 'color.brand-1', to_label: 'Rose' },
          },
          'brand-1': { name: 'Rose', state: 'configured', reserved: true },
        },
        jobs: [
          {
            id: 'job1',
            slot: 4,
            to: 'color.brand-1',
            status: 'running',
            work_items_done: 2,
            work_items_total: 5,
          },
        ],
      },
    )
    expect(w.find('[data-test="brand-row-4-progress"]').text()).toContain(
      'Replacing Gold with Rose — 2 of 5',
    )
    expect(w.find('[data-test="brand-row-4-name"]').exists()).toBe(false)
    expect(w.find('[data-test="brand-row-1"] [data-test="brand-row-clear"]').exists()).toBe(false)
    expect(w.find('[data-test="brand-row-1-reserved"]').text()).toContain(
      'Reserved by the Gold replacement',
    )
  })

  it('the section is hidden when the limit is 0', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { limit: 0 })
    expect(w.find('[data-test="brand-colors"]').exists()).toBe(false)
  })

  it('a refused list shows its message on the Colours tab', async () => {
    const { ApiError } = await import('@/api/errors')
    saveMock
      .mockReset()
      .mockRejectedValue(
        new ApiError(
          'The given data was invalid.',
          422,
          { theme_brand_colors: 'This site allows 3 brand colours' },
          {},
        ),
      )
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await addSky(w)
    await w.find('[data-test="appearance-save"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="appearance-tab-error-colours"]').exists()).toBe(true)
    expect(w.find('[data-test="brand-colors-error"]').text()).toBe(
      'This site allows 3 brand colours',
    )
  })

  it('the preview carries the pending list, new rows numbered above every id seen', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await addSky(w)
    const look = w.findComponent({ name: 'AppearancePreview' }).props('look') as {
      palette: { brands: Array<{ id: number; name: string; hex: string }> }
    }
    expect(look.palette.brands[look.palette.brands.length - 1]).toEqual({
      id: 8,
      name: 'Sky',
      hex: '#38bdf8',
    })
  })

  it('adopts the assigned ids from the save response, so an edit before the refetch saves cleanly', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await addSky(w)
    // The settings query keeps its old value: the refetch is "delayed" for the whole test.
    saveMock.mockReset().mockResolvedValue({
      ...settings(),
      theme_brand_colors: stored(
        4,
        [
          [4, 'Gold', '#8a6a2a'],
          [1, 'Rose', '#c98a8a'],
          [8, 'Sky', '#38bdf8'],
        ],
        [[7, 'Teal']],
      ),
    })
    await savedPayload(w)
    await rename(w, 8, 'Sky blue')
    expect(w.find('[data-test="appearance-tab-dirty-colours"]').exists()).toBe(true)
    const list = sentList(await savedPayload(w))
    expect(list.base).toBe(4)
    expect(list.colors).toEqual([
      { id: 4, name: 'Gold', hex: '#8a6a2a' },
      { id: 1, name: 'Rose', hex: '#c98a8a' },
      { id: 8, name: 'Sky blue', hex: '#38bdf8' },
    ])
  })

  it('an edit made while a save is in flight stays unsaved', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await rename(w, 4, 'Amber')
    let resolve!: (v: unknown) => void
    saveMock.mockReset().mockReturnValue(new Promise((r) => (resolve = r)))
    await w.find('[data-test="appearance-save"]').trigger('click')
    await rename(w, 1, 'Blush')
    resolve({
      ...settings(),
      theme_brand_colors: stored(
        4,
        [
          [4, 'Amber', '#8a6a2a'],
          [1, 'Rose', '#c98a8a'],
        ],
        [[7, 'Teal']],
      ),
    })
    await flushPromises()
    expect(rowNames(w)).toEqual(['Amber', 'Blush'])
    expect(w.find('[data-test="appearance-tab-dirty-colours"]').exists()).toBe(true)
    expect(sentList(await savedPayload(w)).base).toBe(4)
  })

  it('settings arriving after mount install the rows and their revision together', async () => {
    schemaData.value = schema(GOLD_ROSE)
    paletteApi.fetchPaletteJobs.mockReset().mockResolvedValue([])
    settingsData.value = undefined
    const w = mount(AppearancePage)
    await flushPromises()
    settingsData.value = {
      ...settings(),
      theme_brand_colors: stored(5, [
        [4, 'Gold', '#8a6a2a'],
        [1, 'Rose', '#c98a8a'],
      ]),
    } as GeneralSettings
    await flushPromises()
    expect(rowNames(w)).toEqual(['Gold', 'Rose'])
    expect(w.find('[data-test="appearance-tab-dirty-colours"]').exists()).toBe(false)
    await rename(w, 1, 'Blush')
    expect(sentList(await savedPayload(w)).base).toBe(5)
  })

  it('a migrated list at revision 0 installs, and new rows are numbered above its reserved ids', async () => {
    schemaData.value = schema(GOLD_ROSE)
    paletteApi.fetchPaletteJobs.mockReset().mockResolvedValue([])
    settingsData.value = undefined
    const w = mount(AppearancePage)
    await flushPromises()
    settingsData.value = {
      ...settings(),
      theme_brand_colors: stored(
        0,
        [[1, 'Gold', '#8a6a2a']],
        [
          [2, 'Brand 2'],
          [3, 'Brand 3'],
        ],
      ),
    } as GeneralSettings
    await flushPromises()
    expect(rowNames(w)).toEqual(['Gold'])
    await addSky(w)
    const look = w.findComponent({ name: 'AppearancePreview' }).props('look') as {
      palette: { brands: Array<{ id: number }> }
    }
    expect(look.palette.brands.map((b) => b.id)).toEqual([1, 4])
    expect(sentList(await savedPayload(w)).base).toBe(0)
  })

  it('a clean-form refetch changes the list and its revision together', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    settingsData.value = {
      ...settings(),
      theme_brand_colors: stored(
        4,
        [
          [4, 'Amber', '#8a6a2a'],
          [1, 'Rose', '#c98a8a'],
        ],
        [[7, 'Teal']],
      ),
    } as GeneralSettings
    await flushPromises()
    expect(rowNames(w)).toEqual(['Amber', 'Rose'])
    await rename(w, 1, 'Blush')
    const list = sentList(await savedPayload(w))
    expect(list.base).toBe(4)
    expect(list.colors[0]).toEqual({ id: 4, name: 'Amber', hex: '#8a6a2a' })
  })

  it('a dirty-form refetch keeps the edited rows and the base they began from', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await rename(w, 1, 'Blush')
    settingsData.value = {
      ...settings(),
      theme_brand_colors: stored(
        4,
        [
          [4, 'Amber', '#8a6a2a'],
          [1, 'Rose', '#c98a8a'],
        ],
        [[7, 'Teal']],
      ),
    } as GeneralSettings
    await flushPromises()
    expect(rowNames(w)).toEqual(['Gold', 'Blush'])
    expect(w.find('[data-test="appearance-tab-dirty-colours"]').exists()).toBe(true)
    const list = sentList(await savedPayload(w))
    expect(list.base).toBe(3) // the server refuses it: the values are revision 3's
    expect(list.colors[0]).toEqual({ id: 4, name: 'Gold', hex: '#8a6a2a' })
  })

  it('a Clear takes its own result when nothing changed in between', async () => {
    paletteApi.fetchPaletteUsage.mockReset().mockReturnValue(new Promise(() => {}))
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await rename(w, 1, 'Blush')
    await w.find('[data-test="brand-row-4"] [data-test="brand-row-clear"]').trigger('click')
    await flushPromises()
    w.findComponent({ name: 'ClearBrandDialog' }).vm.$emit('done', {
      cleared: 4,
      brandColors: stored(
        4,
        [[1, 'Rose', '#c98a8a']],
        [
          [4, 'Gold'],
          [7, 'Teal'],
        ],
      ),
    })
    await flushPromises()
    expect(w.find('[data-test="brand-row-4"]').exists()).toBe(false)
    expect(rowNames(w)).toEqual(['Blush'])
    expect(w.find('[data-test="appearance-tab-dirty-colours"]').exists()).toBe(true)
    expect(sentList(await savedPayload(w)).base).toBe(4)
  })

  it('a Clear after someone else’s change drops the row but keeps the base', async () => {
    paletteApi.fetchPaletteUsage.mockReset().mockReturnValue(new Promise(() => {}))
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await rename(w, 1, 'Blush')
    await w.find('[data-test="brand-row-4"] [data-test="brand-row-clear"]').trigger('click')
    await flushPromises()
    w.findComponent({ name: 'ClearBrandDialog' }).vm.$emit('done', {
      cleared: 4,
      brandColors: stored(
        5,
        [[1, 'Rose', '#c98a8a']],
        [
          [4, 'Gold'],
          [7, 'Teal'],
        ],
      ),
    })
    await flushPromises()
    expect(w.find('[data-test="brand-row-4"]').exists()).toBe(false)
    expect(sentList(await savedPayload(w)).base).toBe(3)
  })

  it('a stale list’s 409 says to reload', async () => {
    const { ApiError } = await import('@/api/errors')
    const message = 'Brand colours changed since you opened this page — reload to see the latest'
    saveMock.mockReset().mockRejectedValue(new ApiError(message, 409, {}, {}))
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await rename(w, 1, 'Blush')
    await w.find('[data-test="appearance-save"]').trigger('click')
    await flushPromises()
    expect(notify.error).toHaveBeenCalled()
    const calls = notify.error.mock.calls
    expect((calls[calls.length - 1]![0] as Error).message).toBe(message)
    expect(rowNames(w)).toEqual(['Gold', 'Blush'])
  })

  it('a new colour removed while its save is in flight comes back once saved', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await addSky(w)
    let resolve!: (v: unknown) => void
    saveMock.mockReset().mockReturnValue(new Promise((r) => (resolve = r)))
    await w.find('[data-test="appearance-save"]').trigger('click')
    await w.find('[data-test="brand-row-remove"]').trigger('click')
    resolve({
      ...settings(),
      theme_brand_colors: stored(
        4,
        [
          [4, 'Gold', '#8a6a2a'],
          [1, 'Rose', '#c98a8a'],
          [8, 'Sky', '#38bdf8'],
        ],
        [[7, 'Teal']],
      ),
    })
    await flushPromises()
    expect(rowNames(w)).toEqual(['Gold', 'Rose', 'Sky'])
    saveMock.mockReset().mockResolvedValue({ ...settings() })
    await rename(w, 8, 'Sky blue')
    const list = sentList(await savedPayload(w))
    expect(list.base).toBe(4)
    expect(list.colors.map((c) => c.id)).toEqual([4, 1, 8])
  })

  it('a half-filled new row marks the Colours tab as unsaved', async () => {
    const w = await mountAppearance({ theme_brand_colors: STORED }, { schemaPalette: GOLD_ROSE })
    await w.find('[data-test="brand-add"]').trigger('click')
    await lastName(w).setValue('Sky') // no colour yet: Save would not send it
    await flushPromises()
    expect(w.find('[data-test="appearance-tab-dirty-colours"]').exists()).toBe(true)
  })
})
