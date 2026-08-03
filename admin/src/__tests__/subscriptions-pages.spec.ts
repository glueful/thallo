import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, toValue } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import type {
  SubscriptionsMeta,
  SubscriptionPlan,
  WorkspaceListPage,
  WorkspaceDetail,
} from '@/queries/subscriptionsBilling'
import { ApiError } from '@/api/errors'

// Task 11 (thallo-subscriptions Phase B): page/component-level coverage for the Plans and Billing
// admin pages, mirroring `commerceReviews.spec.ts`'s established mount idiom -- the query module is
// mocked (real refs, not `vi.hoisted()`, so template-bound values get Vue's genuine ref
// auto-unwrap).
//
// USlideover/UModal teleport their body/footer out of the wrapper -- stub both to render the
// slots inline (mirrors commerceDiscounts.spec.ts/commerceOrders.spec.ts's identical teleport
// stubs). A REAL router is installed on every page mount: `EngineStateNotice`'s `UButton :to`
// (RouterLink) calls `useLink()`, which needs an injected router even when never navigated
// (mirrors `contentTypeEditor.spec.ts`'s identical rationale).
const teleportStub = { props: ['open'], template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>' }
const pageStubs = { Slideover: teleportStub, Modal: teleportStub }

function testRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
  })
}

function mountPage<T>(component: T) {
  return mount(component as never, { global: { plugins: [testRouter()], stubs: pageStubs } })
}

const metaData = ref<SubscriptionsMeta | undefined>(undefined)
const metaStatus = ref<'pending' | 'error' | 'success'>('success')

const plansData = ref<SubscriptionPlan[] | undefined>(undefined)
const plansStatus = ref<'pending' | 'error' | 'success'>('success')
const usePlansEnabledSeen = ref<boolean[]>([])

const workspacesData = ref<WorkspaceListPage | undefined>(undefined)
const workspacesStatus = ref<'pending' | 'error' | 'success'>('success')

const workspaceDetailData = ref<WorkspaceDetail | undefined>(undefined)
const workspaceDetailStatus = ref<'pending' | 'error' | 'success'>('success')
const useWorkspaceCalls: string[] = []

const refetchMetaMock = vi.hoisted(() => vi.fn())
const importConfigMock = vi.hoisted(() => vi.fn())
const createPlanMock = vi.hoisted(() => vi.fn())
const updatePlanMock = vi.hoisted(() => vi.fn())
const archivePlanMock = vi.hoisted(() => vi.fn())
const setPlanMock = vi.hoisted(() => vi.fn())
const cancelMock = vi.hoisted(() => vi.fn())
const upsertOverrideMock = vi.hoisted(() => vi.fn())
const deleteOverrideMock = vi.hoisted(() => vi.fn())

vi.mock('@/queries/subscriptionsBilling', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/queries/subscriptionsBilling')>()
  return {
    ...actual,
    useSubscriptionsMeta: () => ({ data: metaData, status: metaStatus, refetch: refetchMetaMock }),
    usePlans: (enabled?: unknown) => {
      usePlansEnabledSeen.value.push(!!toValue(enabled as never))
      return { data: plansData, status: plansStatus }
    },
    usePlanMutations: () => ({
      create: { mutateAsync: createPlanMock, isLoading: ref(false) },
      update: { mutateAsync: updatePlanMock, isLoading: ref(false) },
      archive: { mutateAsync: archivePlanMock, isLoading: ref(false) },
      importConfig: { mutateAsync: importConfigMock, isLoading: ref(false) },
    }),
    useWorkspaces: () => ({ data: workspacesData, status: workspacesStatus }),
    useWorkspace: (uuid: unknown) => {
      useWorkspaceCalls.push(toValue(uuid as never) as string)
      return { data: workspaceDetailData, status: workspaceDetailStatus }
    },
    useWorkspaceMutations: () => ({
      setPlan: { mutateAsync: setPlanMock, isLoading: ref(false) },
      cancel: { mutateAsync: cancelMock, isLoading: ref(false) },
      upsertOverride: { mutateAsync: upsertOverrideMock, isLoading: ref(false) },
      deleteOverride: { mutateAsync: deleteOverrideMock, isLoading: ref(false) },
    }),
  }
})

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

import PlansIndex from '@/pages/subscriptions/plans/index.vue'
import BillingIndex from '@/pages/subscriptions/billing/index.vue'
import EngineStateNotice from '@/pages/subscriptions/components/EngineStateNotice.vue'
import PlanEditor from '@/pages/subscriptions/components/PlanEditor.vue'
import WorkspaceDrawer from '@/pages/subscriptions/components/WorkspaceDrawer.vue'
import WorkspaceDetailPanel from '@/pages/subscriptions/components/WorkspaceDetailPanel.vue'

function meta(overrides: Partial<SubscriptionsMeta> = {}): SubscriptionsMeta {
  return { engine: 'ready', tenancy_enabled: true, default_tenant_uuid: null, ...overrides }
}

function plan(overrides: Partial<SubscriptionPlan> = {}): SubscriptionPlan {
  return {
    uuid: 'p1',
    plan_key: 'pro',
    display_name: 'Pro',
    description: null,
    entitlements: {},
    provider_price_id: null,
    status: 'active',
    sort_order: 0,
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

function tenant(overrides: Record<string, unknown> = {}) {
  return {
    uuid: 't1',
    slug: 'acme',
    name: 'Acme Co',
    status: 'active',
    deleted_at: null,
    deleted_from_status: null,
    purge_after: null,
    ...overrides,
  }
}

function subscriptionSummary(overrides: Record<string, unknown> = {}) {
  return {
    status: 'active',
    plan_key: 'pro',
    plan_display_name: 'Pro',
    trial_ends_at: null,
    grace_ends_at: null,
    provider_managed: false,
    ...overrides,
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  metaData.value = meta()
  metaStatus.value = 'success'
  plansData.value = []
  plansStatus.value = 'success'
  usePlansEnabledSeen.value = []
  workspacesData.value = { rows: [], total: 0, current_page: 1, per_page: 20, total_pages: 0, has_next_page: false, has_previous_page: false }
  workspacesStatus.value = 'success'
  workspaceDetailData.value = undefined
  workspaceDetailStatus.value = 'success'
  useWorkspaceCalls.length = 0
  refetchMetaMock.mockReset()
  importConfigMock.mockReset().mockResolvedValue([plan()])
  createPlanMock.mockReset().mockResolvedValue(plan())
  updatePlanMock.mockReset().mockResolvedValue(plan())
  archivePlanMock.mockReset().mockResolvedValue(plan({ status: 'archived' }))
  setPlanMock.mockReset().mockResolvedValue({})
  cancelMock.mockReset().mockResolvedValue({})
  upsertOverrideMock.mockReset().mockResolvedValue({})
  deleteOverrideMock.mockReset().mockResolvedValue(undefined)
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
})

// ── Plans page ───────────────────────────────────────────────────────────────

describe('subscriptions/plans page', () => {
  it('shows the engine_disabled notice with a link to /extensions, never fetching the plans list', async () => {
    metaData.value = meta({ engine: 'engine_disabled' })
    const wrapper = mountPage(PlansIndex)
    await flushPromises()

    const notice = wrapper.findComponent(EngineStateNotice)
    expect(notice.exists()).toBe(true)
    expect(notice.props('state')).toBe('engine_disabled')
    // UButton renders `to` as an `href` on the resolved anchor, not a passthrough `to` attribute.
    expect(wrapper.find('[data-test="engine-state-action"]').attributes('href')).toBe('/extensions')
    expect(wrapper.find('[data-test="plans-table"]').exists()).toBe(false)
    expect(usePlansEnabledSeen.value.every((v) => v === false)).toBe(true)
  })

  it('shows the schema_not_ready notice (run-migrations state)', async () => {
    metaData.value = meta({ engine: 'schema_not_ready' })
    const wrapper = mountPage(PlansIndex)
    await flushPromises()

    const notice = wrapper.findComponent(EngineStateNotice)
    expect(notice.props('state')).toBe('schema_not_ready')
    // Only the disabled state links to Extensions -- schema_not_ready has no such action.
    expect(wrapper.find('[data-test="engine-state-action"]').exists()).toBe(false)
  })

  it('renders the plan list once the engine is ready', async () => {
    plansData.value = [plan({ plan_key: 'pro', display_name: 'Pro' }), plan({ uuid: 'p2', plan_key: 'free', display_name: 'Free' })]
    const wrapper = mountPage(PlansIndex)
    await flushPromises()

    expect(wrapper.findComponent(EngineStateNotice).exists()).toBe(false)
    expect(wrapper.findAll('[data-test="plan-row"]')).toHaveLength(2)
    expect(wrapper.find('[data-test="plan-display-name"]').text()).toBe('Pro')
  })

  it('shows the empty state when there are no plans', async () => {
    plansData.value = []
    const wrapper = mountPage(PlansIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="plans-empty"]').exists()).toBe(true)
  })

  it('opens the editor in create mode from "New plan" and submits the exact create payload', async () => {
    const wrapper = mountPage(PlansIndex)
    await flushPromises()

    await wrapper.find('[data-test="new-plan"]').trigger('click')
    await flushPromises()

    const editor = wrapper.findComponent(PlanEditor)
    expect(editor.props('open')).toBe(true)
    expect(editor.props('plan')).toBeNull()

    await editor.find('[data-test="plan-key-input"]').setValue('growth')
    await editor.find('[data-test="plan-display-name-input"]').setValue('Growth')
    // The submit button lives in the slideover FOOTER slot, associated with the form via
    // `form="plan-form"` -- jsdom doesn't reliably honor that cross-element association, so
    // submit the form directly (mirrors commerceDiscounts.spec.ts's identical workaround).
    await editor.find('#plan-form').trigger('submit')
    await flushPromises()

    expect(createPlanMock).toHaveBeenCalledWith(
      expect.objectContaining({ plan_key: 'growth', display_name: 'Growth', status: 'draft', entitlements: {} }),
    )
  })

  it('opens the editor in edit mode with the plan_key field disabled', async () => {
    plansData.value = [plan({ plan_key: 'pro', display_name: 'Pro' })]
    const wrapper = mountPage(PlansIndex)
    await flushPromises()

    await wrapper.find('[data-test="plan-edit"]').trigger('click')
    await flushPromises()

    const editor = wrapper.findComponent(PlanEditor)
    expect(editor.props('plan')?.plan_key).toBe('pro')
    const keyInput = editor.find('[data-test="plan-key-input"]').element as HTMLInputElement
    expect(keyInput.disabled).toBe(true)
  })

  // Final-wave fix E: a FAILED meta probe is its own state -- not "still loading".
  it('renders a load-failure notice with a retry when the meta probe errors, never the skeleton', async () => {
    metaStatus.value = 'error'
    metaData.value = undefined
    const wrapper = mountPage(PlansIndex)
    await flushPromises()

    expect(wrapper.find('[data-test="plans-meta-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="plans-meta-loading"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="plans-table"]').exists()).toBe(false)
    expect(wrapper.findComponent(EngineStateNotice).exists()).toBe(false)

    await wrapper.find('[data-test="plans-meta-retry"]').trigger('click')
    expect(refetchMetaMock).toHaveBeenCalled()
  })

  // Final-wave fix F: spec §4's import-config action, wired to the existing mutation.
  it('imports plans from config after confirmation, forwarding the force flag', async () => {
    const wrapper = mountPage(PlansIndex)
    await flushPromises()

    await wrapper.find('[data-test="import-plans"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="import-plans-confirm"]').trigger('click')
    await flushPromises()

    expect(importConfigMock).toHaveBeenCalledWith({ force: false })
    expect(notify.success).toHaveBeenCalled()
  })

  it('hides the import action while the engine is not ready', async () => {
    metaData.value = meta({ engine: 'engine_disabled' })
    const wrapper = mountPage(PlansIndex)
    await flushPromises()
    expect(wrapper.find('[data-test="import-plans"]').exists()).toBe(false)
  })

  it('archives a plan after confirmation', async () => {
    plansData.value = [plan({ plan_key: 'pro', display_name: 'Pro', status: 'active' })]
    const wrapper = mountPage(PlansIndex)
    await flushPromises()

    await wrapper.find('[data-test="plan-archive"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="plan-archive-confirm"]').trigger('click')
    await flushPromises()

    expect(archivePlanMock).toHaveBeenCalledWith('pro')
  })
})

// ── Billing page ─────────────────────────────────────────────────────────────

describe('subscriptions/billing page', () => {
  it('shows the engine_disabled notice before ever fetching the workspace directory', async () => {
    metaData.value = meta({ engine: 'engine_disabled', tenancy_enabled: true })
    const wrapper = mountPage(BillingIndex)
    await flushPromises()

    expect(wrapper.findComponent(EngineStateNotice).props('state')).toBe('engine_disabled')
    expect(wrapper.find('[data-test="workspaces-table"]').exists()).toBe(false)
  })

  it('shows the schema_not_ready notice', async () => {
    metaData.value = meta({ engine: 'schema_not_ready' })
    const wrapper = mountPage(BillingIndex)
    await flushPromises()
    expect(wrapper.findComponent(EngineStateNotice).props('state')).toBe('schema_not_ready')
  })

  it('tenancy ON: renders the paginated workspace directory', async () => {
    metaData.value = meta({ engine: 'ready', tenancy_enabled: true })
    workspacesData.value = {
      rows: [
        { tenant: tenant({ uuid: 't1', name: 'Acme Co' }), subscription: subscriptionSummary() },
        { tenant: tenant({ uuid: 't2', name: 'Widgets Inc' }), subscription: null },
      ],
      total: 2,
      current_page: 1,
      per_page: 20,
      total_pages: 1,
      has_next_page: false,
      has_previous_page: false,
    }
    const wrapper = mountPage(BillingIndex)
    await flushPromises()

    expect(wrapper.find('[data-test="billing-default-missing"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="workspace-panel-embedded"]').exists()).toBe(false)
    const rows = wrapper.findAll('[data-test="workspace-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.find('[data-test="workspace-row-name"]').text()).toBe('Acme Co')
  })

  it('tenancy ON: clicking a directory row opens the WorkspaceDrawer as a slideover bound to that uuid', async () => {
    workspacesData.value = {
      rows: [{ tenant: tenant({ uuid: 't7', name: 'Row Co' }), subscription: null }],
      total: 1,
      current_page: 1,
      per_page: 20,
      total_pages: 1,
      has_next_page: false,
      has_previous_page: false,
    }
    const wrapper = mountPage(BillingIndex)
    await flushPromises()

    expect(wrapper.findComponent(WorkspaceDrawer).exists()).toBe(false)

    await wrapper.find('[data-test="workspace-row"]').trigger('click')
    await flushPromises()

    const drawer = wrapper.findComponent(WorkspaceDrawer)
    expect(drawer.exists()).toBe(true)
    expect(drawer.props('uuid')).toBe('t7')
    expect(drawer.props('embedded')).toBeFalsy()
    expect(useWorkspaceCalls).toContain('t7')
  })

  it('tenancy OFF with a real default uuid: renders "This site\'s plan" via the SAME WorkspaceDrawer, embedded', async () => {
    metaData.value = meta({ engine: 'ready', tenancy_enabled: false, default_tenant_uuid: 't_default' })
    const wrapper = mountPage(BillingIndex)
    await flushPromises()

    expect(wrapper.find('[data-test="billing-default-missing"]').exists()).toBe(false)
    const panel = wrapper.find('[data-test="billing-single-panel"]')
    expect(panel.exists()).toBe(true)
    const drawer = wrapper.findComponent(WorkspaceDrawer)
    expect(drawer.exists()).toBe(true)
    expect(drawer.props('uuid')).toBe('t_default')
    expect(drawer.props('embedded')).toBe(true)
    expect(useWorkspaceCalls).toContain('t_default')
  })

  // Final-wave fix E: without a dedicated error branch a failed meta probe fell through to the
  // tenancy-off path (undefined meta ⇒ tenancy false ⇒ default uuid null) and showed the
  // "no default workspace" repair notice -- a wrong diagnosis of a transport failure.
  it('renders a load-failure notice with a retry when the meta probe errors, never the repair notice', async () => {
    metaStatus.value = 'error'
    metaData.value = undefined
    const wrapper = mountPage(BillingIndex)
    await flushPromises()

    expect(wrapper.find('[data-test="billing-meta-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="billing-default-missing"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="billing-meta-loading"]').exists()).toBe(false)
    expect(wrapper.findComponent(EngineStateNotice).exists()).toBe(false)
    expect(useWorkspaceCalls).toEqual([])

    await wrapper.find('[data-test="billing-meta-retry"]').trigger('click')
    expect(refetchMetaMock).toHaveBeenCalled()
  })

  // Final-wave fix C: the directory now shows each workspace's OWN lifecycle status, because a
  // non-active workspace is listed but refuses billing writes with 409 `workspace_not_active`.
  it('renders each workspace lifecycle status in the directory', async () => {
    workspacesData.value = {
      rows: [
        { tenant: tenant({ uuid: 't1', name: 'Acme Co', status: 'active' }), subscription: subscriptionSummary() },
        { tenant: tenant({ uuid: 't2', name: 'Paused Ltd', status: 'suspended' }), subscription: null },
      ],
      total: 2,
      current_page: 1,
      per_page: 20,
      total_pages: 1,
      has_next_page: false,
      has_previous_page: false,
    }
    const wrapper = mountPage(BillingIndex)
    await flushPromises()

    const statuses = wrapper.findAll('[data-test="workspace-row-tenant-status"]').map((n) => n.text())
    expect(statuses).toEqual(['active', 'suspended'])
  })

  it('tenancy OFF with a null default: renders the repair state and issues NO workspace request', async () => {
    metaData.value = meta({ engine: 'ready', tenancy_enabled: false, default_tenant_uuid: null })
    const wrapper = mountPage(BillingIndex)
    await flushPromises()

    expect(wrapper.find('[data-test="billing-default-missing"]').exists()).toBe(true)
    expect(wrapper.findComponent(WorkspaceDrawer).exists()).toBe(false)
    expect(useWorkspaceCalls).toEqual([])
  })
})

// ── WorkspaceDetailPanel: provider-managed refusal, overrides (active/expired) ──

describe('WorkspaceDetailPanel', () => {
  it('disables set-plan/cancel and shows the explanation when the subscription is provider_managed', async () => {
    workspaceDetailData.value = {
      tenant: tenant(),
      subscription: subscriptionSummary({ provider_managed: true }),
      overrides: [],
    }
    const wrapper = mount(WorkspaceDetailPanel, { props: { uuid: 't1' } })
    await flushPromises()

    expect(wrapper.find('[data-test="provider-managed-notice"]').exists()).toBe(true)
    const setPlanButton = wrapper.find('[data-test="workspace-set-plan"]').element as HTMLButtonElement
    const cancelButton = wrapper.find('[data-test="workspace-cancel"]').element as HTMLButtonElement
    expect(setPlanButton.disabled).toBe(true)
    expect(cancelButton.disabled).toBe(true)
  })

  it('renders the server 409 provider_managed_subscription message verbatim if a set-plan attempt slips through', async () => {
    workspaceDetailData.value = {
      tenant: tenant(),
      subscription: subscriptionSummary({ provider_managed: false }),
      overrides: [],
    }
    setPlanMock.mockRejectedValue(
      new ApiError(
        'this subscription is managed by a payment provider and cannot be changed locally',
        409,
        {},
        {},
      ),
    )
    const wrapper = mount(WorkspaceDetailPanel, { props: { uuid: 't1' } })
    await flushPromises()

    // selectedPlanKey is already pre-filled from the current subscription's plan_key -- no need
    // to drive the (portal-rendered) USelect to exercise the submit path.
    await wrapper.find('[data-test="workspace-set-plan"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain(
      'this subscription is managed by a payment provider and cannot be changed locally',
    )
  })

  // Final-wave fix C: the new structured 409 goes through the SAME verbatim rendering the
  // provider-managed refusal already proved -- no per-code special-casing needed.
  it('renders the server 409 workspace_not_active message verbatim on a refused set-plan', async () => {
    workspaceDetailData.value = {
      tenant: tenant({ status: 'suspended' }),
      subscription: subscriptionSummary({ provider_managed: false }),
      overrides: [],
    }
    setPlanMock.mockRejectedValue(
      new ApiError('this workspace is suspended and its billing cannot be changed', 409, {}, {}),
    )
    const wrapper = mount(WorkspaceDetailPanel, { props: { uuid: 't1' } })
    await flushPromises()

    await wrapper.find('[data-test="workspace-set-plan"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('this workspace is suspended and its billing cannot be changed')
  })

  it('shows BOTH active and expired overrides, with expiry and reason intact', async () => {
    workspaceDetailData.value = {
      tenant: tenant(),
      subscription: subscriptionSummary(),
      overrides: [
        { entitlement: 'seats', value: 25, expires_at: '2099-01-01 00:00:00', reason: 'promo', created_at: null, updated_at: null },
        { entitlement: 'api', value: true, expires_at: '2000-01-01 00:00:00', reason: 'legacy trial', created_at: null, updated_at: null },
      ],
    }
    const wrapper = mount(WorkspaceDetailPanel, { props: { uuid: 't1' } })
    await flushPromises()

    const rows = wrapper.findAll('[data-test="override-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.find('[data-test="override-reason"]').text()).toBe('promo')
    expect(rows[1]!.find('[data-test="override-expired-badge"]').exists()).toBe(true)
    expect(rows[0]!.find('[data-test="override-expired-badge"]').exists()).toBe(false)
    expect(rows[1]!.find('[data-test="override-reason"]').text()).toBe('legacy trial')
  })

  it('edits an existing override by pre-filling the form, then saves via upsert', async () => {
    workspaceDetailData.value = {
      tenant: tenant(),
      subscription: subscriptionSummary(),
      overrides: [
        { entitlement: 'seats', value: 25, expires_at: '2099-01-01', reason: 'promo', created_at: null, updated_at: null },
      ],
    }
    const wrapper = mount(WorkspaceDetailPanel, { props: { uuid: 't1' } })
    await flushPromises()

    await wrapper.find('[data-test="override-edit"]').trigger('click')
    await flushPromises()

    expect((wrapper.find('[data-test="override-entitlement-input"]').element as HTMLInputElement).value).toBe(
      'seats',
    )

    await wrapper.find('[data-test="override-limit-input"]').setValue('50')
    // Submit the form directly rather than clicking the button -- mirrors the same
    // click-vs-submit-event workaround used for PlanEditor/DiscountForm above.
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(upsertOverrideMock).toHaveBeenCalledWith(
      expect.objectContaining({ uuid: 't1', entitlement: 'seats', input: expect.objectContaining({ value: 50 }) }),
    )
  })

  it('removes an override', async () => {
    workspaceDetailData.value = {
      tenant: tenant(),
      subscription: subscriptionSummary(),
      overrides: [
        { entitlement: 'seats', value: 25, expires_at: null, reason: null, created_at: null, updated_at: null },
      ],
    }
    const wrapper = mount(WorkspaceDetailPanel, { props: { uuid: 't1' } })
    await flushPromises()

    await wrapper.find('[data-test="override-remove"]').trigger('click')
    await flushPromises()

    expect(deleteOverrideMock).toHaveBeenCalledWith({ uuid: 't1', entitlement: 'seats' })
  })

  it('shows the "no subscription yet" state and allows starting one', async () => {
    workspaceDetailData.value = { tenant: tenant(), subscription: null, overrides: [] }
    const wrapper = mount(WorkspaceDetailPanel, { props: { uuid: 't1' } })
    await flushPromises()

    expect(wrapper.find('[data-test="workspace-no-subscription"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="workspace-cancel"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="workspace-set-plan"]').text()).toBe('Start subscription')
  })
})
