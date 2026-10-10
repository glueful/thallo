// Appearance › Theme colors › the palette (custom palette spec §2, §3, §5.1): a Custom neutral
// pre-filled from the family the first time, a dark-mode base shown only when the site has a dark
// mode, three named brand colours (Add, Clear, a running replacement's progress, a reserved slot),
// contrast checks of the pending look, and the unsaved palette in the preview.
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
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/appearance', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
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

function schema(slots: Record<string, Record<string, unknown>> = {}, colorMode = true) {
  const slot = (n: number) => ({
    name: null,
    hex: null,
    state: 'unset',
    reserved: false,
    replacing: null,
    ...slots[`brand-${n}`],
  })
  return {
    palette: {
      slots: { 'brand-1': slot(1), 'brand-2': slot(2), 'brand-3': slot(3) },
      swatches: {},
      labels: {},
      color_mode: colorMode,
      generation: 1,
      replacements: { after: 1, through: 1, records: [] },
    },
  }
}

async function mountAppearance(
  stored: Partial<GeneralSettings & Record<string, string>> = {},
  opts: {
    colorMode?: boolean
    schemaPalette?: Record<string, Record<string, unknown>>
    jobs?: unknown[]
  } = {},
) {
  settingsData.value = {
    ...settings(),
    theme_neutral_custom: '',
    theme_dark_base: '',
    theme_brand_1: '',
    theme_brand_2: '',
    theme_brand_3: '',
    ...stored,
  } as GeneralSettings
  schemaData.value = schema(opts.schemaPalette ?? {}, opts.colorMode ?? true)
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

  it('saves a new brand slot as JSON, sends nothing for an unset one, and shows Clear only once configured', async () => {
    const w = await mountAppearance({ theme_brand_3: '{"name":"Ink","hex":"#111111"}' })
    expect(w.find('[data-test="brand-1-clear"]').exists()).toBe(false)
    expect(w.find('[data-test="brand-3-clear"]').exists()).toBe(true)
    await w.find('[data-test="brand-1-name"]').setValue('Gold dark')
    await w.find('[data-test="brand-1-hex"] input').setValue('#8A6A2A')
    const payload = await savedPayload(w)
    expect(JSON.parse(payload.theme_brand_1!)).toEqual({ name: 'Gold dark', hex: '#8a6a2a' })
    expect(payload).not.toHaveProperty('theme_brand_2')
    expect(payload).not.toHaveProperty('theme_brand_3') // unchanged
  })

  it('Clear on a configured slot opens the dialog, which checks where the colour is used', async () => {
    paletteApi.fetchPaletteUsage.mockReset().mockReturnValue(new Promise(() => {}))
    const w = await mountAppearance({ theme_brand_1: '{"name":"Gold","hex":"#8a6a2a"}' })
    await w.find('[data-test="brand-1-clear"]').trigger('click')
    await flushPromises()
    expect(paletteApi.fetchPaletteUsage).toHaveBeenCalledWith(1)
    expect(w.findComponent({ name: 'ClearBrandDialog' }).props('name')).toBe('Gold')
  })

  it('shows a replacing slot as progress and a reserved slot without Clear', async () => {
    const w = await mountAppearance(
      {
        theme_brand_1: '{"name":"Gold","hex":"#8a6a2a"}',
        theme_brand_2: '{"name":"Rose","hex":"#c98a8a"}',
      },
      {
        schemaPalette: {
          'brand-1': {
            name: 'Gold',
            state: 'replacing',
            replacing: { to: 'color.brand-2', to_label: 'Rose' },
          },
          'brand-2': { name: 'Rose', state: 'configured', reserved: true },
        },
        jobs: [
          {
            id: 'job1',
            slot: 1,
            to: 'color.brand-2',
            status: 'running',
            work_items_done: 2,
            work_items_total: 5,
          },
        ],
      },
    )
    expect(w.find('[data-test="brand-1-progress"]').text()).toContain(
      'Replacing Gold with Rose — 2 of 5',
    )
    expect(w.find('[data-test="brand-1-name"]').exists()).toBe(false)
    expect(w.find('[data-test="brand-2-clear"]').exists()).toBe(false)
    expect(w.find('[data-test="brand-2-reserved"]').text()).toContain(
      'Reserved by the Gold replacement',
    )
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

  it('carries the unsaved palette into the preview look', async () => {
    const w = await mountAppearance({})
    await w.find('[data-test="brand-1-name"]').setValue('Gold')
    await w.find('[data-test="brand-1-hex"] input').setValue('#8a6a2a')
    const look = w.findComponent({ name: 'AppearancePreview' }).props('look') as {
      palette: { brands: Record<string, unknown> }
    }
    expect(look.palette.brands['1']).toEqual({ name: 'Gold', hex: '#8a6a2a' })
  })
})
