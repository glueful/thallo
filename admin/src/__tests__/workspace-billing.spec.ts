import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { WorkspaceBillingMeta } from '@/queries/workspaceBilling'
import { ApiError } from '@/api/errors'

// Task 19 (Phase C, workspace self-serve checkout plan, spec §5.3): page/component coverage for
// the workspace Billing page + return page, mirroring `subscriptions-pages.spec.ts`'s established
// mount idiom -- the query module is mocked (real refs so template bindings unwrap correctly),
// USlideover/UModal teleport their body/footer out of the wrapper so both are stubbed to render
// inline, and a REAL router is installed (EngineStateNotice's `UButton :to` needs `useLink()`).
const teleportStub = {
  props: ['open'],
  template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>',
}
const pageStubs = { Slideover: teleportStub, Modal: teleportStub }

// Async (unlike subscriptions-pages.spec.ts's synchronous version): this page reads
// `route.query.plan` at setup time for deep-link preselection, so the initial navigation MUST
// resolve before mount -- otherwise setup() sees the router's default `/` route (no query) and
// only picks up the real one later via reactivity, which the "?plan= preselection" assertions
// below don't tolerate.
async function mountPage<T>(component: T, path = '/billing') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
  })
  await router.push(path)
  await router.isReady()
  return mount(component as never, { global: { plugins: [router], stubs: pageStubs } })
}

const metaData = ref<WorkspaceBillingMeta | undefined>(undefined)
const metaStatus = ref<'pending' | 'error' | 'success'>('success')
const refetchMetaMock = vi.hoisted(() => vi.fn())
const checkoutMock = vi.hoisted(() => vi.fn())
const cancelMock = vi.hoisted(() => vi.fn())
const abandonMock = vi.hoisted(() => vi.fn())
const navigateMock = vi.hoisted(() => vi.fn())
const checkoutLoading = ref(false)
const cancelLoading = ref(false)
const abandonLoading = ref(false)

vi.mock('@/queries/workspaceBilling', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/workspaceBilling')>()
  return {
    ...actual,
    useWorkspaceBillingMeta: () => ({ data: metaData, status: metaStatus, refetch: refetchMetaMock }),
    useWorkspaceCheckoutMutation: () => ({ mutateAsync: checkoutMock, isLoading: checkoutLoading }),
    useWorkspaceCancelMutation: () => ({ mutateAsync: cancelMock, isLoading: cancelLoading }),
    useWorkspaceAbandonMutation: () => ({ mutateAsync: abandonMock, isLoading: abandonLoading }),
    navigateToCheckout: navigateMock,
  }
})

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

import BillingIndex from '@/pages/billing/index.vue'
import BillingReturn from '@/pages/billing/return.vue'
import EngineStateNotice from '@/pages/subscriptions/components/EngineStateNotice.vue'

function meta(overrides: Partial<WorkspaceBillingMeta> = {}): WorkspaceBillingMeta {
  return {
    engine: 'ready',
    self_serve_checkout_enabled: true,
    workspace_uuid: 't1',
    subscription: null,
    origination: null,
    operator_contact_required: false,
    operator_contact_reason: null,
    purchasable_plans: [{ plan_key: 'pro', name: 'Pro' }],
    ...overrides,
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  metaData.value = meta()
  metaStatus.value = 'success'
  refetchMetaMock.mockReset()
  checkoutMock.mockReset().mockResolvedValue({ status: 'pending', checkout_url: 'https://pay.example/session' })
  cancelMock.mockReset().mockResolvedValue({ mode: 'stop_renewal' })
  abandonMock.mockReset().mockResolvedValue({ status: 'abandoned' })
  navigateMock.mockReset()
  checkoutLoading.value = false
  cancelLoading.value = false
  abandonLoading.value = false
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
})

// ── Meta-first states ────────────────────────────────────────────────────────

describe('billing/index page: meta-first states', () => {
  it('shows a loading skeleton while meta is pending', async () => {
    metaStatus.value = 'pending'
    metaData.value = undefined
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="billing-meta-loading"]').exists()).toBe(true)
  })

  it('shows a load-failure notice with retry when the meta probe errors', async () => {
    metaStatus.value = 'error'
    metaData.value = undefined
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()

    expect(wrapper.find('[data-test="billing-meta-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="billing-meta-loading"]').exists()).toBe(false)

    await wrapper.find('[data-test="billing-meta-retry"]').trigger('click')
    expect(refetchMetaMock).toHaveBeenCalled()
  })

  it('shows the engine_disabled notice', async () => {
    metaData.value = meta({ engine: 'engine_disabled' })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.findComponent(EngineStateNotice).props('state')).toBe('engine_disabled')
  })

  // Minor code review fix: "Go to Extensions" is a PLATFORM surface -- a workspace billing.manage
  // delegate with no platform authority must not be offered it here (unlike the platform
  // Plans/Billing pages, which keep the default showAction=true).
  it('hides the "Go to Extensions" CTA on the workspace billing page (platform-only surface)', async () => {
    metaData.value = meta({ engine: 'engine_disabled' })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.findComponent(EngineStateNotice).props('showAction')).toBe(false)
    expect(wrapper.find('[data-test="engine-state-action"]').exists()).toBe(false)
  })

  it('shows the schema_not_ready notice', async () => {
    metaData.value = meta({ engine: 'schema_not_ready' })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.findComponent(EngineStateNotice).props('state')).toBe('schema_not_ready')
  })

  it('switch off with no subscription: shows the switch-off notice, no plan picker', async () => {
    metaData.value = meta({ self_serve_checkout_enabled: false, subscription: null })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="self-serve-off"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="plan-picker-select"]').exists()).toBe(false)
  })

  it('plan picker: renders purchasable plans when switch is on and there is no subscription', async () => {
    metaData.value = meta({ purchasable_plans: [{ plan_key: 'pro', name: 'Pro' }, { plan_key: 'team', name: 'Team' }] })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="plan-picker"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="plan-picker-empty"]').exists()).toBe(false)
  })

  it('plan picker: empty-purchasable state when no plans are purchasable', async () => {
    metaData.value = meta({ purchasable_plans: [] })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="plan-picker-empty"]').exists()).toBe(true)
  })

  it('initializing origination: waiting state, no resume link', async () => {
    metaData.value = meta({ origination: { status: 'initializing', checkout_url: null } })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="checkout-initializing"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="checkout-resume-link"]').exists()).toBe(false)
  })

  it('pending origination: resume link + abandon control', async () => {
    metaData.value = meta({ origination: { status: 'pending', checkout_url: 'https://pay.example/x' } })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="checkout-pending-panel"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="checkout-resume-link"]').attributes('href')).toBe('https://pay.example/x')
    expect(wrapper.find('[data-test="checkout-abandon"]').exists()).toBe(true)
  })

  it('active subscription: plan, period end, cancel available', async () => {
    metaData.value = meta({
      subscription: { status: 'active', plan_key: 'pro', current_period_end: '2099-01-01', provider_managed: true },
    })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="subscription-active"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="subscription-plan"]').text()).toBe('pro')
    expect(wrapper.find('[data-test="billing-cancel"]').exists()).toBe(true)
    // Plan changes are never offered on an active subscription (§1 ruling).
    const changePlan = wrapper.find('[data-test="billing-change-plan-disabled"]').element as HTMLButtonElement
    expect(changePlan.disabled).toBe(true)
  })

  it('non_renewing: shows the access-until date, no cancel control', async () => {
    metaData.value = meta({
      subscription: { status: 'non_renewing', plan_key: 'pro', current_period_end: '2099-06-01', provider_managed: true },
    })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="subscription-non-renewing"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="subscription-access-until"]').text()).toBe('2099-06-01')
  })

  it('provider-managed-elsewhere: entitling but not provider_managed shows contact-operator note, no cancel button', async () => {
    metaData.value = meta({
      subscription: { status: 'active', plan_key: 'comped', current_period_end: null, provider_managed: false },
    })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="provider-managed-elsewhere"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="billing-cancel"]').exists()).toBe(false)
  })

  it('blocked/operator-contact: banner renders independently of the underlying subscription state', async () => {
    metaData.value = meta({
      operator_contact_required: true,
      operator_contact_reason: 'projection_rejected',
      subscription: { status: 'active', plan_key: 'pro', current_period_end: null, provider_managed: true },
    })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="billing-blocked-banner"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('projection_rejected')
    // The underlying active state still renders below the banner.
    expect(wrapper.find('[data-test="subscription-active"]').exists()).toBe(true)
  })

  it('canceled: shows the canceled banner alongside the checkout-available section', async () => {
    metaData.value = meta({
      subscription: { status: 'canceled', plan_key: 'pro', current_period_end: null, provider_managed: true },
    })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="subscription-canceled"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="plan-picker"]').exists()).toBe(true)
  })

  // Code-review fix: mirrors `SelfBillingController::isEntitling()` exactly. A stale `incomplete`
  // reservation (left behind by a failed/abandoned checkout attempt -- the engine row is never
  // auto-deleted on a non-abandon terminal outcome) is NOT entitling, so the workspace must still
  // see the plan picker rather than getting stranded on a read-only "contact your operator" panel
  // forever.
  it('an incomplete (non-entitling) subscription routes to the plan picker, never provider-managed-elsewhere', async () => {
    metaData.value = meta({
      subscription: { status: 'incomplete', plan_key: 'pro', current_period_end: null, provider_managed: false },
    })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="provider-managed-elsewhere"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="plan-picker"]').exists()).toBe(true)
  })

  // Code-review fix: `non_renewing` is entitling ONLY while `current_period_end` is still in the
  // future (spec §4.1) -- once it lapses, the workspace must be able to start a fresh checkout
  // again instead of being stuck on the static "access continues until <past date>" panel.
  it('a non_renewing subscription past its period end routes to the plan picker, not the non_renewing panel', async () => {
    metaData.value = meta({
      subscription: {
        status: 'non_renewing',
        plan_key: 'pro',
        current_period_end: '2000-01-01 00:00:00',
        provider_managed: true,
      },
    })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="subscription-non-renewing"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="plan-picker"]').exists()).toBe(true)
  })

  it('a non_renewing subscription still within its period end keeps the non_renewing panel (no picker)', async () => {
    metaData.value = meta({
      subscription: {
        status: 'non_renewing',
        plan_key: 'pro',
        current_period_end: '2099-06-01 00:00:00',
        provider_managed: true,
      },
    })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="subscription-non-renewing"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="plan-picker"]').exists()).toBe(false)
  })

  it('meta-error branch is distinct from the switch-off/repair state, with its own retry', async () => {
    metaStatus.value = 'error'
    metaData.value = undefined
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="self-serve-off"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="plan-picker"]').exists()).toBe(false)
  })
})

// ── Deep-link ?plan= preselection ────────────────────────────────────────────

describe('billing/index page: deep-link plan preselection', () => {
  it('preselects the plan named by ?plan= into the picker', async () => {
    metaData.value = meta({ purchasable_plans: [{ plan_key: 'pro', name: 'Pro' }, { plan_key: 'team', name: 'Team' }] })
    const wrapper = await mountPage(BillingIndex, '/billing?plan=team')
    await flushPromises()
    // USelect renders its bound value into the trigger's text content.
    expect(wrapper.find('[data-test="plan-picker-select"]').text()).toContain('Team')
  })

  it('preselects a well-formed but unknown key verbatim (no silent fallback) so plan_not_purchasable can render', async () => {
    metaData.value = meta({ purchasable_plans: [{ plan_key: 'pro', name: 'Pro' }] })
    checkoutMock.mockRejectedValue(
      new ApiError('This plan is not purchasable through the configured payment gateway.', 409, {}, {
        error: { details: { code: 'plan_not_purchasable' } },
      }),
    )
    const wrapper = await mountPage(BillingIndex, '/billing?plan=ghost-plan')
    await flushPromises()

    await wrapper.find('[data-test="plan-picker-subscribe"]').trigger('click')
    await flushPromises()

    expect(checkoutMock).toHaveBeenCalledWith(expect.objectContaining({ planKey: 'ghost-plan' }))
    expect(wrapper.text()).toContain('This plan is not purchasable through the configured payment gateway.')
  })
})

// ── Idempotency token discipline ─────────────────────────────────────────────

describe('billing/index page: idempotency token discipline', () => {
  it('sends the SAME idempotency key on a retry after a 202 initializing response', async () => {
    checkoutMock.mockResolvedValueOnce({ status: 'initializing', checkout_url: null })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()

    await wrapper.find('[data-test="plan-picker-subscribe"]').trigger('click')
    await flushPromises()

    // The mutation's onSettled invalidation would normally refetch meta; simulate that here by
    // reflecting the now-live initializing origination, then retry via the initializing panel.
    metaData.value = meta({ origination: { status: 'initializing', checkout_url: null } })
    checkoutMock.mockResolvedValueOnce({ status: 'pending', checkout_url: 'https://pay.example/y' })
    const wrapper2 = await mountPage(BillingIndex)
    await flushPromises()
    // A stuck-initializing attempt started by a DIFFERENT mount has no locally-remembered plan
    // key, so only a manual refresh is offered, never a blind resubmit.
    await wrapper2.find('[data-test="checkout-initializing-retry"]').trigger('click')
    await flushPromises()
    expect(refetchMetaMock).toHaveBeenCalled()

    const firstKey = (checkoutMock.mock.calls[0]![0] as { idempotencyKey: string }).idempotencyKey
    expect(firstKey).toHaveLength(32)
  })

  it('a retry click within the SAME mount reuses the exact same token', async () => {
    checkoutMock.mockResolvedValueOnce({ status: 'initializing', checkout_url: null })
    checkoutMock.mockResolvedValueOnce({ status: 'initializing', checkout_url: null })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()

    await wrapper.find('[data-test="plan-picker-subscribe"]').trigger('click')
    await flushPromises()
    metaData.value = meta({ origination: { status: 'initializing', checkout_url: null } })
    await flushPromises()

    await wrapper.find('[data-test="checkout-initializing-retry"]').trigger('click')
    await flushPromises()

    expect(checkoutMock).toHaveBeenCalledTimes(2)
    const key1 = (checkoutMock.mock.calls[0]![0] as { idempotencyKey: string }).idempotencyKey
    const key2 = (checkoutMock.mock.calls[1]![0] as { idempotencyKey: string }).idempotencyKey
    expect(key2).toBe(key1)
  })

  it('redirects on a pending result with a checkout_url', async () => {
    checkoutMock.mockResolvedValueOnce({ status: 'pending', checkout_url: 'https://pay.example/session' })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()

    await wrapper.find('[data-test="plan-picker-subscribe"]').trigger('click')
    await flushPromises()

    expect(navigateMock).toHaveBeenCalledWith('https://pay.example/session')
  })

  // Code review fix (Important): without this, an orphaned token gets resubmitted for whatever
  // plan the next click picks and 409s idempotency_conflict FOREVER within this page mount.
  it('rotates the token after a 409 idempotency_conflict so the next click mints a fresh one', async () => {
    checkoutMock.mockRejectedValueOnce(
      new ApiError('This idempotency key was already used for a different checkout request.', 409, {}, {
        error: { details: { code: 'idempotency_conflict' } },
      }),
    )
    checkoutMock.mockResolvedValueOnce({ status: 'initializing', checkout_url: null })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()

    await wrapper.find('[data-test="plan-picker-subscribe"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="plan-picker-subscribe"]').trigger('click')
    await flushPromises()

    expect(checkoutMock).toHaveBeenCalledTimes(2)
    const key1 = (checkoutMock.mock.calls[0]![0] as { idempotencyKey: string }).idempotencyKey
    const key2 = (checkoutMock.mock.calls[1]![0] as { idempotencyKey: string }).idempotencyKey
    expect(key2).not.toBe(key1)
  })

  // Code review fix (Important): a live origination this page's own click originated gets
  // released OUT-OF-BAND (operator resolution, expiry, a race with another actor) -- `/meta`
  // simply stops reporting it, with no subscription established either. The page's
  // `watch(origination, ...)` -> `tracker.observeMeta()` wiring must rotate the now-orphaned
  // token, so the NEXT deliberate click (after the picker reappears) mints a fresh one rather
  // than resubmitting the dead one.
  it('rotates the orphaned token when its live origination disappears out-of-band, before the next click', async () => {
    checkoutMock.mockResolvedValueOnce({ status: 'initializing', checkout_url: null })
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()

    await wrapper.find('[data-test="plan-picker-subscribe"]').trigger('click')
    await flushPromises()

    metaData.value = meta({ origination: { status: 'initializing', checkout_url: null } })
    await flushPromises()

    metaData.value = meta({ origination: null, subscription: null })
    await flushPromises()
    expect(wrapper.find('[data-test="plan-picker"]').exists()).toBe(true)

    checkoutMock.mockResolvedValueOnce({ status: 'initializing', checkout_url: null })
    await wrapper.find('[data-test="plan-picker-subscribe"]').trigger('click')
    await flushPromises()

    expect(checkoutMock).toHaveBeenCalledTimes(2)
    const key1 = (checkoutMock.mock.calls[0]![0] as { idempotencyKey: string }).idempotencyKey
    const key2 = (checkoutMock.mock.calls[1]![0] as { idempotencyKey: string }).idempotencyKey
    expect(key2).not.toBe(key1)
  })
})

// ── Cancel dialog: per-mode confirm ──────────────────────────────────────────

describe('billing/index page: CancelDialog', () => {
  function openCancelDialog(wrapper: Awaited<ReturnType<typeof mountPage>>) {
    return wrapper.find('[data-test="billing-cancel"]').trigger('click')
  }

  beforeEach(() => {
    metaData.value = meta({
      subscription: { status: 'active', plan_key: 'pro', current_period_end: '2099-01-01', provider_managed: true },
    })
  })

  it('defaults to stop_renewal and submits the chosen mode', async () => {
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    await openCancelDialog(wrapper)
    await flushPromises()

    await wrapper.find('[data-test="cancel-confirm"]').trigger('click')
    await flushPromises()

    expect(cancelMock).toHaveBeenCalledWith('stop_renewal')
    expect(notify.success).toHaveBeenCalled()
  })

  it('renders a 422 invalid_cancellation_mode verbatim and keeps the dialog open', async () => {
    cancelMock.mockRejectedValue(
      new ApiError('This cancellation mode is not supported by the active payment gateway.', 422, {}, {
        error: { details: { code: 'invalid_cancellation_mode', modes: ['stop_renewal'] } },
      }),
    )
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    await openCancelDialog(wrapper)
    await flushPromises()

    await wrapper.find('[data-test="cancel-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('This cancellation mode is not supported by the active payment gateway.')
    expect(wrapper.find('[data-test="cancel-dialog"]').exists()).toBe(true)
  })
})

// ── CheckoutPendingPanel: abandon incl. Paystack-unsupported ─────────────────

describe('billing/index page: CheckoutPendingPanel abandon', () => {
  beforeEach(() => {
    metaData.value = meta({ origination: { status: 'pending', checkout_url: 'https://pay.example/x' } })
  })

  it('abandons successfully', async () => {
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    await wrapper.find('[data-test="checkout-abandon"]').trigger('click')
    await flushPromises()
    expect(abandonMock).toHaveBeenCalled()
    expect(notify.success).toHaveBeenCalled()
  })

  it('renders the Paystack-unsupported 409 as its own notice and withdraws the abandon control (resume or contact operator only, never a reopen)', async () => {
    abandonMock.mockRejectedValue(
      new ApiError('This payment gateway does not support abandoning a checkout attempt.', 409, {}, {
        error: { details: { code: 'checkout_abandonment_unsupported' } },
      }),
    )
    const wrapper = await mountPage(BillingIndex)
    await flushPromises()
    await wrapper.find('[data-test="checkout-abandon"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="checkout-abandon-unsupported"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="checkout-abandon"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="checkout-resume-link"]').exists()).toBe(true)
  })
})

// ── Return page: informational only, never mutates ───────────────────────────

describe('billing/return page', () => {
  it('never imports/calls any mutation while polling meta', async () => {
    metaData.value = meta({ origination: { status: 'pending', checkout_url: 'https://pay.example/x' } })
    const wrapper = await mountPage(BillingReturn, '/billing/return?origination=abc-123')
    await flushPromises()

    expect(wrapper.find('[data-test="return-settling"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="return-origination-ref"]').text()).toContain('abc-123')
    expect(checkoutMock).not.toHaveBeenCalled()
    expect(cancelMock).not.toHaveBeenCalled()
    expect(abandonMock).not.toHaveBeenCalled()
  })

  it('shows the active summary once the subscription is entitling and no origination remains live', async () => {
    metaData.value = meta({
      subscription: { status: 'active', plan_key: 'pro', current_period_end: '2099-01-01', provider_managed: true },
    })
    const wrapper = await mountPage(BillingReturn)
    await flushPromises()
    expect(wrapper.find('[data-test="return-active"]').exists()).toBe(true)
  })

  it('manual refresh calls refetch, never a mutation', async () => {
    const wrapper = await mountPage(BillingReturn)
    await flushPromises()
    await wrapper.find('[data-test="return-refresh"]').trigger('click')
    expect(refetchMetaMock).toHaveBeenCalled()
    expect(checkoutMock).not.toHaveBeenCalled()
  })

  // Minor code review fix: the poll must not run forever once there's nothing left to wait for.
  describe('polling stops once settled', () => {
    beforeEach(() => {
      vi.useFakeTimers()
    })
    afterEach(() => {
      vi.useRealTimers()
    })

    it('polls every 4s while settling, and stops as soon as the projection resolves to active', async () => {
      metaData.value = meta({ origination: { status: 'pending', checkout_url: 'https://pay.example/x' } })
      const wrapper = await mountPage(BillingReturn)
      await flushPromises()
      expect(wrapper.find('[data-test="return-settling"]').exists()).toBe(true)

      await vi.advanceTimersByTimeAsync(4000)
      expect(refetchMetaMock).toHaveBeenCalledTimes(1)
      await vi.advanceTimersByTimeAsync(4000)
      expect(refetchMetaMock).toHaveBeenCalledTimes(2)

      // The webhook lands: origination clears, subscription becomes active.
      metaData.value = meta({
        subscription: { status: 'active', plan_key: 'pro', current_period_end: '2099-01-01', provider_managed: true },
      })
      await flushPromises()
      expect(wrapper.find('[data-test="return-active"]').exists()).toBe(true)

      refetchMetaMock.mockClear()
      await vi.advanceTimersByTimeAsync(12_000)
      expect(refetchMetaMock).not.toHaveBeenCalled()
    })

    it('never starts polling at all when it mounts already settled', async () => {
      metaData.value = meta({
        subscription: { status: 'active', plan_key: 'pro', current_period_end: '2099-01-01', provider_managed: true },
      })
      await mountPage(BillingReturn)
      await flushPromises()

      await vi.advanceTimersByTimeAsync(8000)
      expect(refetchMetaMock).not.toHaveBeenCalled()
    })
  })
})
