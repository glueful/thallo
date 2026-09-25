import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import type { Me } from '@/queries/account'

// The user menu's account items open the Profile and Security pages, and its avatar is your photo
// when you have set one.

const me = ref<Me | undefined>(undefined)
vi.mock('@/queries/account', () => ({ useMe: () => ({ data: me }) }))
vi.mock('@/composables/useUpdateNotice', () => ({
  useUpdateNotice: () => ({ status: ref(null), visible: ref(false) }),
  versionLabel: () => '',
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRouter: () => ({ push: vi.fn() }),
}))

import UserMenu from '@/components/UserMenu.vue'

const stubs = { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } }

beforeEach(() => {
  setActivePinia(createPinia())
  me.value = undefined
})

describe('the user menu', () => {
  it('Profile and Security open their pages', () => {
    const w = mount(UserMenu, { global: { stubs } })
    const items = (
      w.findComponent({ name: 'DropdownMenu' }).props('items') as {
        label?: string
        to?: string
      }[][]
    ).flat()
    expect(items.find((i) => i.label === 'Profile')?.to).toBe('/account/profile')
    expect(items.find((i) => i.label === 'Security')?.to).toBe('/account/security')
  })

  it('the avatar is your photo once you have set one', async () => {
    const w = mount(UserMenu, { global: { stubs } })
    expect(w.findComponent({ name: 'Avatar' }).props('src')).toBeUndefined()
    me.value = {
      uuid: 'u',
      email: 'ama@example.test',
      username: 'ama',
      two_factor_enabled: false,
      two_factor_available: false,
      profile: { first_name: 'Ama', last_name: null, photo_url: '/v1/blobs/blob00000001' },
    }
    await flushPromises()
    expect(w.findComponent({ name: 'Avatar' }).props('src')).toBe('/v1/blobs/blob00000001')
  })
})
