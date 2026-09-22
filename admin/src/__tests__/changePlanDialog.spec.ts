import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { ref } from 'vue'

const changePlan = vi.hoisted(() => vi.fn())
vi.mock('@/queries/workspaceBilling', async (orig) => ({
  ...(await orig<object>()),
  useWorkspaceChangePlanMutation: () => ({ mutateAsync: changePlan, isLoading: ref(false) }),
}))
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

import ChangePlanDialog from '@/pages/billing/components/ChangePlanDialog.vue'

const PLANS = [
  {
    plan_key: 'starter',
    name: 'Starter',
    price_amount: 900,
    price_currency: 'USD',
    billing_interval: 'month',
  },
  {
    plan_key: 'pro',
    name: 'Pro',
    price_amount: 1900,
    price_currency: 'USD',
    billing_interval: 'month',
  },
]

function mountDialog(supported: boolean): VueWrapper {
  return mount(ChangePlanDialog, {
    props: { open: true, plans: PLANS, currentPlanKey: 'starter', supported },
    attachTo: document.body,
  })
}

// UModal renders into a portal; read and click through the document.
const q = (sel: string) => document.body.querySelector(sel) as HTMLElement | null

describe('changing plan', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    changePlan.mockReset().mockResolvedValue({ plan_key: 'pro' })
    notify.success.mockReset()
    document.body.innerHTML = ''
  })

  it('offers the other plans and requests the change', async () => {
    const w = mountDialog(true)
    await flushPromises()

    expect(q('[data-test="change-plan-confirm"]')).not.toBeNull()
    q('[data-test="change-plan-confirm"]')!.click()
    await flushPromises()

    expect(changePlan).toHaveBeenCalledWith('pro')
    expect(notify.success).toHaveBeenCalled()
    const opened = w.emitted('update:open') ?? []
    expect(opened[opened.length - 1]).toEqual([false])
    w.unmount()
  })

  it('explains how to change plan where the provider cannot switch a live one', async () => {
    const w = mountDialog(false)
    await flushPromises()

    expect(q('[data-test="change-plan-unsupported"]')?.textContent).toMatch(/cancel/i)
    expect(q('[data-test="change-plan-confirm"]')).toBeNull()
    q('[data-test="change-plan-cancel-instead"]')!.click()
    await flushPromises()

    expect(w.emitted('cancel-instead')).toHaveLength(1)
    w.unmount()
  })
})
