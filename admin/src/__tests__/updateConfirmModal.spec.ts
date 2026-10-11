import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import UpdateConfirmModal from '@/components/UpdateConfirmModal.vue'
import { ApiError } from '@/api/errors'
import { REASON_TEXT, UPDATE_TOKEN_KEY, startUpdate } from '@/queries/updates'

vi.mock('@/queries/updates', async (importActual) => ({
  ...(await importActual<typeof import('@/queries/updates')>()),
  startUpdate: vi.fn(),
}))

const props = {
  open: true,
  current: '1.0.0-beta.21',
  target: '1.0.0-beta.22',
  notesUrl: 'https://example.test/notes',
}

describe('UpdateConfirmModal', () => {
  const assign = vi.fn()

  afterEach(() => {
    vi.mocked(startUpdate).mockReset()
    assign.mockReset()
    sessionStorage.clear()
  })

  async function mountModal() {
    const wrapper = mount(UpdateConfirmModal, {
      props: { ...props, navigate: assign },
      attachTo: document.body,
    })
    await flushPromises() // the modal renders into document.body after a tick
    return wrapper
  }

  it('says what will happen before anything does', async () => {
    const wrapper = await mountModal()
    const body = document.body.textContent ?? ''

    expect(body).toContain('1.0.0-beta.21')
    expect(body).toContain('1.0.0-beta.22')
    expect(body).toContain('closed to visitors and to other admins')
    expect(body).toContain('verified database backup')
    expect(startUpdate).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('starts the update, keeps the token for /_update, and goes there', async () => {
    vi.mocked(startUpdate).mockResolvedValue({ id: 'op1', token: 'a'.repeat(64) })
    const wrapper = await mountModal()

    ;(document.body.querySelector('[data-testid="update-confirm-start"]') as HTMLElement).click()
    await flushPromises()

    expect(startUpdate).toHaveBeenCalledTimes(1)
    // The version the dialog showed: the server refuses if a newer one appeared meanwhile.
    expect(startUpdate).toHaveBeenCalledWith('1.0.0-beta.22')
    expect(sessionStorage.getItem(UPDATE_TOKEN_KEY)).toBe('a'.repeat(64))
    expect(assign).toHaveBeenCalledWith('/_update')
    wrapper.unmount()
  })

  it('shows why a start was refused and stays here', async () => {
    vi.mocked(startUpdate).mockRejectedValue(
      new ApiError(
        'Updating from the admin is unavailable.',
        409,
        {},
        {
          error: { details: { reasons: ['in_progress'] } },
        },
      ),
    )
    const wrapper = await mountModal()

    ;(document.body.querySelector('[data-testid="update-confirm-start"]') as HTMLElement).click()
    await flushPromises()

    expect(
      document.body.querySelector('[data-testid="update-confirm-reasons"]')?.textContent,
    ).toContain(REASON_TEXT.in_progress)
    expect(assign).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
