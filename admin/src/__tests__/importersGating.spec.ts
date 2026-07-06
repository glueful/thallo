import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

vi.mock('@/api/authFetch', () => ({
  authFetch: vi.fn().mockResolvedValue({ data: { capabilities: [] } }),
}))
vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin', defaultLocale: 'en' },
}))

// Realistic adapters list: core snapshot importer + the three format-pack adapters.
// The snapshot key ('thallo.content') matches ContentImporter::key() in the PHP core.
const MOCK_ADAPTERS = {
  importers: [
    { key: 'thallo.content', label: 'Thallo snapshot (NDJSON)' },
    { key: 'csv.content', label: 'CSV' },
    { key: 'markdown.content', label: 'Markdown / MDX' },
    { key: 'wordpress.content', label: 'WordPress (WXR)' },
  ],
  exporters: [{ key: 'thallo.content', label: 'Thallo snapshot (NDJSON)' }],
}

vi.mock('@/queries/importExport', () => ({
  useAdapters: () => ({ data: ref(MOCK_ADAPTERS) }),
  useJobs: () => ({
    data: ref([]),
    status: ref('success'),
    refresh: vi.fn(),
    isLoading: ref(false),
  }),
  useJobErrors: (_: unknown) => ({ data: ref([]), status: ref('success') }),
  useImportExportMutations: () => ({
    runExport: { mutateAsync: vi.fn(), isLoading: ref(false) },
    runImport: { mutateAsync: vi.fn(), isLoading: ref(false) },
    cancel: { mutateAsync: vi.fn() },
    retry: { mutateAsync: vi.fn() },
  }),
  uploadImportFile: vi.fn(),
  downloadExport: vi.fn(),
  isJobActive: vi.fn(() => false),
}))
vi.mock('@/queries/contentTypes', () => ({
  useContentTypes: () => ({ data: ref([]) }),
}))
vi.mock('@vueuse/core', async () => {
  const actual = await vi.importActual<typeof import('@vueuse/core')>('@vueuse/core')
  return { ...actual, useIntervalFn: vi.fn() }
})
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import { useCapabilitiesStore } from '@/stores/capabilities'
import ImportExportPage from '@/pages/settings/import-export/index.vue'

/**
 * These tests assert the *core promise* of the importers extraction: the snapshot
 * Import card is core-owned and stays usable when `thallo.importers` is off, while the
 * format-adapter wizard (CSV/Markdown/WordPress mapping) is gated.
 *
 * We assert on plain DOM hooks (`data-test` / `data-testid`) the page owns, not on the
 * Nuxt UI `USelect` dropdown contents: `USelect` renders its options into a Reka portal
 * that only mounts on open, so the option list isn't in the jsdom tree. The dropdown
 * itself filters the three format-adapter keys out of `importerItems` when the capability
 * is off — pure logic that is, in any case, backstopped by the authoritative backend gate
 * (each format adapter fails closed at `plan()` regardless of what the UI offers).
 */
describe('format-import gating (thallo.importers capability)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('hides the format wizard but keeps the core snapshot import when thallo.importers is disabled', () => {
    const caps = useCapabilitiesStore()
    // Mark as already-loaded so ensureLoaded() is a no-op and we control the set directly.
    caps.loaded = true
    caps.enabledIds = new Set()

    const wrapper = mount(ImportExportPage)

    // The format-adapter wizard section must not be in the DOM.
    expect(wrapper.find('[data-test="format-import"]').exists()).toBe(false)

    // The Import card — its adapter picker (which still offers the core snapshot importer),
    // file picker, and import button — must still render so snapshot import stays accessible.
    expect(wrapper.find('[data-testid="importer-adapter"]').exists()).toBe(true)
  })

  it('shows the format wizard when thallo.importers is enabled', () => {
    const caps = useCapabilitiesStore()
    caps.loaded = true
    caps.enabledIds = new Set(['thallo.importers'])

    const wrapper = mount(ImportExportPage)

    expect(wrapper.find('[data-test="format-import"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="importer-adapter"]').exists()).toBe(true)
  })
})
