import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

vi.mock('@/api/authFetch', () => ({
  authFetch: vi.fn().mockResolvedValue({ data: { capabilities: [] } }),
}))
vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin', defaultLocale: 'en' },
}))

const runImport = vi.fn().mockResolvedValue(null)
const uploadImportFile = vi.fn().mockResolvedValue({ disk: 'uploads', path: 'import-export/x.zip' })
const JOBS = ref<Record<string, unknown>[]>([])
const ROWS = ref<Record<string, unknown>[]>([])

vi.mock('@/queries/importExport', () => ({
  // The folder import alone, so it is the adapter the page opens on.
  useAdapters: () => ({
    data: ref({
      importers: [{ key: 'markdown.folder', label: 'Markdown folder (.zip)' }],
      exporters: [],
    }),
  }),
  useJobs: () => ({ data: JOBS, status: ref('success'), refresh: vi.fn(), isLoading: ref(false) }),
  useJobErrors: () => ({ data: ROWS, status: ref('success') }),
  useImportExportMutations: () => ({
    runExport: { mutateAsync: vi.fn(), isLoading: ref(false) },
    runImport: { mutateAsync: runImport, isLoading: ref(false) },
    cancel: { mutateAsync: vi.fn() },
    retry: { mutateAsync: vi.fn() },
  }),
  uploadImportFile: (file: File) => uploadImportFile(file),
  downloadExport: vi.fn(),
  isJobActive: (status: string) => status === 'running' || status === 'queued',
}))
vi.mock('@/queries/contentTypes', () => ({
  useContentTypes: () => ({
    data: ref([
      {
        slug: 'docs',
        name: 'Docs',
        schema: [
          { name: 'title', type: 'string' },
          { name: 'body', type: 'text' },
        ],
      },
    ]),
  }),
}))
vi.mock('@/queries/docs', () => ({
  useSetupDocs: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
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

function mountPage() {
  const caps = useCapabilitiesStore()
  caps.status = 'ready'
  caps.enabledIds = new Set(['thallo.importers'])
  return mount(ImportExportPage, { attachTo: document.body })
}

async function pick(wrapper: ReturnType<typeof mountPage>, file: File) {
  const input = wrapper.get('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
  await flushPromises()
}

describe('importing a folder of Markdown from the admin', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    runImport.mockClear()
    uploadImportFile.mockClear()
    JOBS.value = []
    ROWS.value = []
  })

  it('takes a .zip, asks for the docs choices and sends them with the upload', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="markdown-folder-fields"]').exists()).toBe(true)
    expect(wrapper.get('input[type="file"]').attributes('accept')).toBe('.zip')

    await wrapper.get('[data-test="folder-exclude"]').setValue('internal')
    await pick(wrapper, new File(['zip'], 'docs.zip', { type: 'application/zip' }))
    await wrapper.get('[data-test="run-import"]').trigger('click')
    await flushPromises()

    expect(uploadImportFile).toHaveBeenCalledTimes(1)
    expect(runImport).toHaveBeenCalledWith({
      adapter: 'markdown.folder',
      disk: 'uploads',
      path: 'import-export/x.zip',
      mode: 'dry_run',
      options: { content_type: 'docs', publish: false, locale: 'en', exclude: ['internal'] },
    })
  })

  it('shows a finished folder import’s report even when nothing failed', async () => {
    JOBS.value = [
      {
        uuid: 'job1',
        type: 'import',
        adapter: 'markdown.folder',
        status: 'completed',
        mode: 'dry_run',
        processed_records: 2,
        total_records: 2,
        failed_records: 0,
        error_overflow_count: 0,
        created_at: '2026-09-21T10:00:00Z',
      },
    ]
    ROWS.value = [
      {
        uuid: 'r1',
        record_number: 1,
        severity: 'info',
        code: 'markdown_page_created',
        message: 'install.md would be created at /docs/install',
        created_at: null,
      },
    ]
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="job-report-job1"]').trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('install.md would be created at /docs/install')
    wrapper.unmount()
  })
})
