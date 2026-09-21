import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { adminAddress } from '@/runtime/adminAddress'

vi.mock('@/runtime/adminAddress', async () => {
  const actual =
    await vi.importActual<typeof import('@/runtime/adminAddress')>('@/runtime/adminAddress')
  return { ...actual, runningAdminAddress: () => 'https://example.com/admin' }
})

import AdminUrlField from '@/pages/settings/general/components/AdminUrlField.vue'

describe('where this admin is', () => {
  it('is the page’s origin and the path the admin is served under, never the origin alone', () => {
    expect(adminAddress('https://example.com', '/admin/')).toBe('https://example.com/admin')
    expect(adminAddress('https://admin.example.com', '/')).toBe('https://admin.example.com')
  })
})

describe('the Admin URL setting', () => {
  const mountField = (modelValue: string) => mount(AdminUrlField, { props: { modelValue } })

  it('says an empty value works, and what it works out to', () => {
    const wrapper = mountField('')
    expect(wrapper.find('[data-test="admin-url-mismatch"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('https://example.com/admin')
  })

  it('is quiet when the value is where this admin runs', () => {
    expect(
      mountField('https://example.com/admin/').find('[data-test="admin-url-mismatch"]').exists(),
    ).toBe(false)
  })

  it('warns when the links would go somewhere this admin is not, and one click corrects it', async () => {
    // What the setup screen used to save: the site's address, without /admin.
    const wrapper = mountField('https://example.com')
    const warning = wrapper.get('[data-test="admin-url-mismatch"]')
    expect(warning.text()).toContain('https://example.com/admin')

    await wrapper.get('[data-test="admin-url-use-running"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([['https://example.com/admin']])
  })
})
