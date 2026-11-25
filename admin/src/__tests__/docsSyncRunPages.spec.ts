import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

// A run's pages link to the entries they wrote (review M9).

vi.mock('@/queries/docsSync', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/docsSync')>()),
  useDocsSyncRun: () => ({
    isLoading: ref(false),
    data: ref({
      run: {},
      pages: [
        {
          path: 'one.md',
          slug: 'one',
          status: 'created',
          message: null,
          broken_links: null,
          entry_uuid: 'ent000000001',
        },
        {
          path: 'gone.md',
          slug: null,
          status: 'gone',
          message: null,
          broken_links: null,
          entry_uuid: 'ent000000002',
        },
        {
          path: 'bad.md',
          slug: 'bad',
          status: 'failed',
          message: 'Not valid',
          broken_links: null,
          entry_uuid: null,
        },
      ],
    }),
  }),
}))

import DocsSyncRunPages from '@/pages/settings/import-export/DocsSyncRunPages.vue'

describe('a docs sync run’s pages', () => {
  it('link to the entry each page wrote, and say why a page failed', () => {
    const w = mount(DocsSyncRunPages, {
      props: { runId: 'run000000001', contentType: 'docs' },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } },
    })
    const links = w.findAll('a').map((a) => a.attributes('href'))
    expect(links).toEqual(['/content/docs/ent000000001', '/content/docs/ent000000002'])
    expect(w.text()).toContain('Not valid')
  })
})
