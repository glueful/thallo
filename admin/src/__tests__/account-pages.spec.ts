import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h, ref } from 'vue'
import { ApiError } from '@/api/errors'
import type { Me } from '@/queries/account'

// The signed-in user's Profile and Security pages: their own name and photo, their password, and
// email two-factor authentication — over the account endpoints, mocked here.

const me = ref<Me | undefined>(undefined)
const q = vi.hoisted(() => ({
  refetch: vi.fn(),
  updateAccount: vi.fn(),
  changePassword: vi.fn(),
  beginTwoFactor: vi.fn(),
  confirmTwoFactor: vi.fn(),
  disableTwoFactor: vi.fn(),
}))
vi.mock('@/queries/account', () => ({
  useMe: () => ({ data: me, status: ref('success'), refetch: q.refetch }),
  updateAccount: q.updateAccount,
  changePassword: q.changePassword,
  beginTwoFactor: q.beginTwoFactor,
  confirmTwoFactor: q.confirmTwoFactor,
  disableTwoFactor: q.disableTwoFactor,
}))
const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))
// The media library's own modal is out of scope: a stub that picks one blob on demand.
vi.mock('@/fields/components/MediaPickerModal.vue', () => ({
  default: defineComponent({
    props: { open: Boolean },
    emits: ['select', 'update:open'],
    setup(props, { emit }) {
      return () =>
        props.open
          ? h('button', { 'data-test': 'pick-blob', onClick: () => emit('select', 'blob00000001') })
          : null
    },
  }),
}))

import ProfilePage from '@/pages/account/profile.vue'
import SecurityPage from '@/pages/account/security.vue'

const stubs = {
  UDashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
  UDashboardNavbar: { template: '<div><slot /><slot name="right" /></div>' },
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}
const account = (overrides: Partial<Me> = {}): Me => ({
  uuid: 'user00000001',
  email: 'ama@example.test',
  username: 'ama',
  two_factor_enabled: false,
  two_factor_available: true,
  profile: { first_name: 'Ama', last_name: null, photo_url: null },
  ui: { hidden: [], landing: null },
  ...overrides,
})

beforeEach(() => {
  setActivePinia(createPinia())
  me.value = account()
  for (const fn of Object.values(q)) fn.mockReset()
  for (const fn of Object.values(notify)) fn.mockReset()
})

describe('Profile', () => {
  it('edits the name and photo, and shows email and username read-only', async () => {
    q.updateAccount.mockResolvedValue(account())
    const w = mount(ProfilePage, { global: { stubs }, attachTo: document.body })
    await flushPromises()
    expect((w.find('[data-test="profile-email"]').element as HTMLInputElement).value).toBe(
      'ama@example.test',
    )
    expect(w.find('[data-test="profile-email"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="profile-username"]').attributes('disabled')).toBeDefined()

    await w.find('[data-test="profile-last-name"]').setValue('Mensah')
    await w.find('[data-test="profile-photo-choose"]').trigger('click')
    await flushPromises()
    await w.find('[data-test="pick-blob"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="profile-photo"]').attributes('src')).toBe('/v1/blobs/blob00000001')

    await w.find('[data-test="profile-save"]').trigger('click')
    await flushPromises()
    expect(q.updateAccount).toHaveBeenCalledWith({
      first_name: 'Ama',
      last_name: 'Mensah',
      photo_url: '/v1/blobs/blob00000001',
    })
    expect(q.refetch).toHaveBeenCalled()
    expect(notify.success).toHaveBeenCalled()
    w.unmount()
  })

  it('removing the photo saves it cleared', async () => {
    me.value = account({
      profile: { first_name: 'Ama', last_name: null, photo_url: '/v1/blobs/old' },
    })
    q.updateAccount.mockResolvedValue(account())
    const w = mount(ProfilePage, { global: { stubs }, attachTo: document.body })
    await flushPromises()
    await w.find('[data-test="profile-photo-remove"]').trigger('click')
    await w.find('[data-test="profile-save"]').trigger('click')
    await flushPromises()
    expect(q.updateAccount.mock.calls[0]![0].photo_url).toBe('')
    w.unmount()
  })
})

describe('Security', () => {
  async function fillPassword(
    w: ReturnType<typeof mount>,
    current: string,
    next: string,
    confirm: string,
  ) {
    await w.find('[data-test="password-current"]').setValue(current)
    await w.find('[data-test="password-new"]').setValue(next)
    await w.find('[data-test="password-confirm"]').setValue(confirm)
    await w.find('[data-test="password-save"]').trigger('click')
    await flushPromises()
  }

  it('changes the password, and says other sessions were signed out', async () => {
    q.changePassword.mockResolvedValue({ other_sessions_signed_out: 2 })
    const w = mount(SecurityPage, { global: { stubs }, attachTo: document.body })
    await flushPromises()
    await fillPassword(w, 'old-password-1', 'new-password-1', 'new-password-1')
    expect(q.changePassword).toHaveBeenCalledWith({
      current_password: 'old-password-1',
      password: 'new-password-1',
    })
    expect(notify.success.mock.calls[0]!.join(' ')).toContain('2')
    expect((w.find('[data-test="password-current"]').element as HTMLInputElement).value).toBe('')
    w.unmount()
  })

  it('a mismatched confirmation is caught before asking; a wrong current password is shown on its field', async () => {
    const w = mount(SecurityPage, { global: { stubs }, attachTo: document.body })
    await flushPromises()
    await fillPassword(w, 'old-password-1', 'new-password-1', 'something-else')
    expect(q.changePassword).not.toHaveBeenCalled()
    expect(w.find('[data-test="password-form"]').text()).toContain('do not match')

    q.changePassword.mockRejectedValue(
      new ApiError('invalid', 422, { current_password: 'is not your current password' }, null),
    )
    await fillPassword(w, 'wrong', 'new-password-1', 'new-password-1')
    expect(w.find('[data-test="password-form"]').text()).toContain('is not your current password')
    w.unmount()
  })

  it('turns two-factor on with the emailed code', async () => {
    q.beginTwoFactor.mockResolvedValue({ challenge_token: 'chal1' })
    q.confirmTwoFactor.mockResolvedValue(undefined)
    const w = mount(SecurityPage, { global: { stubs }, attachTo: document.body })
    await flushPromises()
    expect(w.find('[data-test="twofactor-status"]').text()).toContain('Off')
    await w.find('[data-test="twofactor-enable"]').trigger('click')
    await flushPromises()
    await w.find('[data-test="twofactor-code"]').setValue('123456')
    await w.find('[data-test="twofactor-verify"]').trigger('click')
    await flushPromises()
    expect(q.confirmTwoFactor).toHaveBeenCalledWith('chal1', '123456')
    expect(q.refetch).toHaveBeenCalled()
    w.unmount()
  })

  it('turning it off without a recent code sign-in explains what to do', async () => {
    me.value = account({ two_factor_enabled: true })
    q.disableTwoFactor.mockRejectedValue(new ApiError('reelevate', 403, {}, null))
    const w = mount(SecurityPage, { global: { stubs }, attachTo: document.body })
    await flushPromises()
    expect(w.find('[data-test="twofactor-status"]').text()).toContain('On')
    await w.find('[data-test="twofactor-disable"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="twofactor-reelevate"]').text()).toContain('sign in again')
    expect(q.refetch).not.toHaveBeenCalled()
    w.unmount()
  })

  it('where the install has two-factor switched off, says so and offers nothing to turn on', async () => {
    me.value = account({ two_factor_available: false })
    const w = mount(SecurityPage, { global: { stubs }, attachTo: document.body })
    await flushPromises()
    expect(w.find('[data-test="twofactor-unavailable"]').text()).toContain('TWO_FACTOR_ENABLED')
    expect(w.find('[data-test="twofactor-enable"]').exists()).toBe(false)
    expect(w.find('[data-test="twofactor-disable"]').exists()).toBe(false)
    w.unmount()
  })
})
