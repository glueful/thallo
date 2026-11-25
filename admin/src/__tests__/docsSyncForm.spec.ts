import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { ApiError } from '@/api/errors'

// The docs source form: a refusal names the field it is about (docs sync design §4).

const save = vi.fn()
vi.mock('@/queries/docsSync', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/docsSync')>()),
  useSaveDocsSource: () => ({ mutateAsync: save, isLoading: ref(false) }),
}))
vi.mock('@/queries/docs', () => ({
  useSetupDocs: () => ({ mutateAsync: vi.fn(), isLoading: ref(false) }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import DocsSyncForm from '@/pages/settings/import-export/DocsSyncForm.vue'

describe('the docs source form', () => {
  it('shows the message the server gave for the field', async () => {
    save.mockRejectedValueOnce(
      new ApiError(
        'Validation failed',
        422,
        {
          repo: 'acme/secret is not a public repository: the docs sync reads public repositories only.',
        },
        null,
      ),
    )
    const w = mount(DocsSyncForm, {
      props: { source: null, contentTypes: [{ slug: 'docs', name: 'Docs' }] },
    })
    await w.find('[data-test="docs-sync-repo"]').setValue('acme/secret')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('acme/secret is not a public repository')
  })

  it('offers Set up documentation when there is no docs type', () => {
    const w = mount(DocsSyncForm, { props: { source: null, contentTypes: [] } })
    expect(w.text()).toContain('Set up documentation')
  })
})
