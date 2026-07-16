import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import type { NavMenuDetail, NavMenuSummary } from '@/queries/navigation'
import { ApiError } from '@/api/errors'

const menusData = ref<NavMenuSummary[] | undefined>(undefined)
const menusLoading = ref(false)
const detailData = ref<NavMenuDetail | undefined>(undefined)
const refetch = vi.fn().mockResolvedValue(undefined)
const saveMock = vi.fn()
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
const capsEnabled = vi.hoisted(() => ({ value: true }))
const capsStatus = vi.hoisted(() => ({ value: 'ready' as string }))
const capsRetry = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
const reorderMock = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
const renameMock = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
const removeMock = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))

vi.mock('@/queries/navigation', () => ({
  useNavMenus: () => ({ data: menusData, isLoading: menusLoading }),
  useNavMenu: () => ({ data: detailData, refetch }),
  useNavigationMutations: () => ({
    create: { mutateAsync: vi.fn() },
    rename: { mutateAsync: renameMock },
    remove: { mutateAsync: removeMock },
    save: { mutateAsync: saveMock },
    reorder: { mutateAsync: reorderMock },
  }),
}))
vi.mock('@/queries/locales', () => ({
  useLocales: () => ({ data: ref([{ code: 'en' }, { code: 'fr' }]) }),
}))
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({
    isEnabled: () => capsEnabled.value,
    get status() {
      return capsStatus.value
    },
    get settled() {
      return capsStatus.value === 'ready' || capsStatus.value === 'error'
    },
    retry: capsRetry,
  }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: notify.success, error: notify.error }),
}))
// Nuxt UI's Link override pulls useRoute from vue-router/auto (UButton renders through it).
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/navigation', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))
vi.mock('@/queries/contentTypes', () => ({
  useContentTypes: () => ({
    data: ref([{ slug: 'pages', name: 'Pages', public_delivery: true, schema: [] }]),
  }),
}))
// The real picker is a USelectMenu over the entries query; the page only needs
// its `picked` event, so a stub button stands in for the selection.
vi.mock('@/fields/components/ReferencePicker.vue', () => ({
  default: {
    name: 'ReferencePicker',
    props: { target: { type: String, required: true }, modelValue: { type: String, default: '' } },
    emits: ['picked', 'update:modelValue'],
    template:
      '<button type="button" data-test="stub-pick" ' +
      '@click="$emit(\'picked\', { uuid: \'e-9\', title: \'Hello Page\' })">pick</button>',
  },
}))

import NavigationPage from '@/pages/navigation/index.vue'

// The real @nuxt/ui UDashboardPanel renders #header/#body fine in jsdom (it's auto-imported,
// so name-based global.stubs are no-ops anyway). We only stub RouterLink because Nuxt UI's
// UButton link override resolves through vue-router. The overflow menu (UDropdownMenu)
// teleports its items, so tests invoke its `items` onSelect closures directly rather than
// clicking the portal.
const mountPage = () =>
  mount(NavigationPage, {
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
      },
    },
  })

const detail = (): NavMenuDetail => ({
  slug: 'main',
  name: 'Main',
  locale: 'en',
  lock_version: 1,
  items: [{ uuid: 'u-1', kind: 'url', url: '/about', labels: { en: 'About' }, children: [] }],
})

describe('navigation page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    menusData.value = undefined
    detailData.value = undefined
    saveMock.mockReset()
    refetch.mockClear()
    reorderMock.mockClear()
    renameMock.mockClear()
    removeMock.mockClear()
    capsEnabled.value = true
    capsStatus.value = 'ready'
    menusLoading.value = false
    capsRetry.mockClear()
    notify.success.mockClear()
    notify.error.mockClear()
  })

  it('shows the zero-menu empty state with a create CTA', async () => {
    menusData.value = []
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.findAll('[data-test="nav-menu-row"]')).toHaveLength(0)
    expect(wrapper.text()).toContain('No menus yet.')
    expect(wrapper.find('[data-test="nav-menu-new"]').exists()).toBe(true)
  })

  it('auto-selects the first menu when none is selected', async () => {
    menusData.value = [
      { slug: 'main', name: 'Main', item_count: 2, lock_version: 0 },
      { slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 },
    ]
    detailData.value = { slug: 'main', name: 'Main', locale: 'en', lock_version: 0, items: [] }
    const wrapper = mountPage()
    await flushPromises()
    const rows = wrapper.findAll('[data-test="nav-menu-row"]')
    expect(rows[0]!.attributes('aria-current')).toBe('true')
  })

  it('reconciles a stale selection to the first menu when the selected slug disappears', async () => {
    menusData.value = [{ slug: 'main', name: 'Main', item_count: 2, lock_version: 0 }]
    const wrapper = mountPage()
    await flushPromises()
    // 'main' selected. Now it's deleted elsewhere and the list refetches without it.
    menusData.value = [{ slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 }]
    await flushPromises()
    const rows = wrapper.findAll('[data-test="nav-menu-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0]!.attributes('aria-current')).toBe('true') // reconciled to 'footer'
  })

  it('hides "New menu" when the navigation capability is disabled', async () => {
    capsEnabled.value = false
    menusData.value = []
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="nav-menu-new"]').exists()).toBe(false)
  })

  // The four capability states are never conflated (flicker/reload fix): while discovery
  // is still LOADING the page must claim nothing — no lock message, no menu list.
  it('shows a skeleton, not the lock message, while capabilities are loading', async () => {
    capsStatus.value = 'loading'
    capsEnabled.value = false
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-testid="nav-caps-loading"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Navigation isn’t enabled.')
    expect(wrapper.find('[data-testid="capability-error-panel"]').exists()).toBe(false)
  })

  it('shows the shared Retry panel, not "disabled", when capability discovery errored', async () => {
    capsStatus.value = 'error'
    capsEnabled.value = false
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-testid="capability-error-panel"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Navigation isn’t enabled.')

    await wrapper.find('[data-testid="capability-error-retry"]').trigger('click')
    expect(capsRetry).toHaveBeenCalledTimes(1)
  })

  it('shows the lock message only when discovery succeeded and the capability is off', async () => {
    capsStatus.value = 'ready'
    capsEnabled.value = false
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-testid="nav-caps-disabled"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Navigation isn’t enabled.')
  })

  it('distinguishes menu-list loading from a genuinely empty list', async () => {
    menusLoading.value = true
    menusData.value = undefined
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-testid="nav-menus-loading"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('No menus yet.')

    menusLoading.value = false
    menusData.value = []
    await flushPromises()
    expect(wrapper.find('[data-testid="nav-menus-loading"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('No menus yet.')
  })

  it('preserves the selected menu by slug across a reorder', async () => {
    menusData.value = [
      { slug: 'main', name: 'Main', item_count: 2, lock_version: 0 },
      { slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 },
    ]
    const wrapper = mountPage()
    await flushPromises()
    // Select 'footer'.
    await wrapper.findAll('[data-test="nav-menu-row"]')[1]!.trigger('click')
    await flushPromises()
    // Reorder puts footer first; the list refetches in the new order.
    menusData.value = [
      { slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 },
      { slug: 'main', name: 'Main', item_count: 2, lock_version: 0 },
    ]
    await flushPromises()
    const rows = wrapper.findAll('[data-test="nav-menu-row"]')
    // Footer is now row 0 AND still the selected one (selection follows slug, not index).
    expect(rows[0]!.attributes('aria-current')).toBe('true')
    expect(rows[0]!.text()).toContain('Footer')
  })

  it('committing a sidebar reorder calls reorder with the full new slug order', async () => {
    menusData.value = [
      { slug: 'main', name: 'Main', item_count: 2, lock_version: 0 },
      { slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 },
    ]
    const wrapper = mountPage()
    await flushPromises()
    // Simulate a drag through the real VueDraggable: v-model updates to the new order,
    // then @end commits via the mutation. (Both the grip drag and the overflow "Move
    // up/down" funnel through this same commitOrder path.)
    const drag = wrapper.findComponent(VueDraggable)
    drag.vm.$emit('update:modelValue', [
      { slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 },
      { slug: 'main', name: 'Main', item_count: 2, lock_version: 0 },
    ])
    await flushPromises()
    drag.vm.$emit('end')
    await flushPromises()
    expect(reorderMock).toHaveBeenCalledWith(['footer', 'main'])
  })

  it('overflow "Rename" opens a prefilled modal and renames the row via the mutation', async () => {
    menusData.value = [
      { slug: 'main', name: 'Main', item_count: 2, lock_version: 0 },
      { slug: 'footer', name: 'Footer', item_count: 1, lock_version: 0 },
    ]
    const wrapper = mountPage()
    await flushPromises()

    // The overflow menu teleports its items, so invoke the row's `items` onSelect
    // closure directly (index 1 = the 'footer' row).
    const dropdowns = wrapper.findAllComponents({ name: 'DropdownMenu' })
    expect(dropdowns).toHaveLength(2)
    const items = dropdowns[1]!.props('items') as { label: string; onSelect?: () => void }[][]
    items.flat().find((i) => i.label === 'Rename')!.onSelect!()
    await flushPromises()

    // The rename modal teleports to <body>, prefilled with the row's name. Accept the
    // prefilled name and confirm via the Save button.
    const form = document.body.querySelector('[data-test="nav-menu-rename"]')
    expect(form).not.toBeNull()
    const save = document.body.querySelector('[data-test="nav-menu-rename-save"]') as HTMLElement
    save.click()
    await flushPromises()

    expect(renameMock).toHaveBeenCalledWith({ slug: 'footer', name: 'Footer' })
    expect(notify.success).toHaveBeenCalled()
  })

  it('overflow "Delete" requires the confirm modal before removing the menu', async () => {
    menusData.value = [{ slug: 'main', name: 'Main', item_count: 2, lock_version: 0 }]
    const wrapper = mountPage()
    await flushPromises()

    const dropdown = wrapper.findAllComponents({ name: 'DropdownMenu' })[0]!
    const items = dropdown.props('items') as { label: string; onSelect?: () => void }[][]
    items.flat().find((i) => i.label === 'Delete')!.onSelect!()
    await flushPromises()

    // Opening the overflow item only arms the confirm modal — nothing is deleted yet.
    expect(removeMock).not.toHaveBeenCalled()

    // Confirm in the teleported modal actually deletes.
    const confirm = document.body.querySelector('[data-test="nav-menu-delete"]') as HTMLElement
    expect(confirm).not.toBeNull()
    confirm.click()
    await flushPromises()

    expect(removeMock).toHaveBeenCalledWith('main')
    expect(notify.success).toHaveBeenCalled()
  })

  it('selecting a menu renders the editor and save sends the working tree', async () => {
    menusData.value = [{ slug: 'main', name: 'Main', item_count: 1, lock_version: 1 }]
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="nav-menu-row"]').trigger('click')
    detailData.value = detail()
    await flushPromises()

    expect(wrapper.findAll('[data-test="tree-item"]')).toHaveLength(1)

    // Make an edit so Save enables, then save.
    await wrapper.find('[data-test="tree-add-root"]').trigger('click')
    saveMock.mockResolvedValue(detail())
    await wrapper.find('[data-test="tree-save"]').trigger('click')
    await flushPromises()

    expect(saveMock).toHaveBeenCalledTimes(1)
    const arg = saveMock.mock.calls[0]![0] as { slug: string; lockVersion: number; items: unknown[] }
    expect(arg.slug).toBe('main')
    expect(arg.lockVersion).toBe(1)
    expect(arg.items).toHaveLength(2)
    expect(notify.success).toHaveBeenCalled()
  })

  it('Add page reveals the entry picker panel (nav-entry-items design)', async () => {
    menusData.value = [{ slug: 'main', name: 'Main', item_count: 1, lock_version: 1 }]
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="nav-menu-row"]').trigger('click')
    detailData.value = detail()
    await flushPromises()

    expect(wrapper.find('[data-test="add-page-picker"]').exists()).toBe(false)
    await wrapper.find('[data-test="tree-add-page"]').trigger('click')
    const picker = wrapper.find('[data-test="add-page-picker"]')
    expect(picker.exists()).toBe(true)
    expect(picker.text()).toContain('Pick a type first.')
  })

  it('picking a page adds an entry item whose label placeholder shows the page title', async () => {
    menusData.value = [{ slug: 'main', name: 'Main', item_count: 1, lock_version: 1 }]
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="nav-menu-row"]').trigger('click')
    detailData.value = detail()
    await flushPromises()

    await wrapper.find('[data-test="tree-add-page"]').trigger('click')
    // The USelect v-model listener sits on Reka's SelectRoot (finding the
    // component by the data-test CSS selector lands on SelectTrigger, whose
    // emits go nowhere).
    const root = wrapper
      .findAllComponents({ name: 'SelectRoot' })
      .find((r) => r.element.querySelector?.('[data-test="add-page-type"]'))
    root!.vm.$emit('update:modelValue', 'pages')
    await flushPromises()

    await wrapper.find('[data-test="stub-pick"]').trigger('click')
    await flushPromises()

    const rows = wrapper.findAll('[data-test="tree-item"]')
    expect(rows).toHaveLength(2)
    // The new entry item's label input inherits the page title as its
    // placeholder immediately — no save/reload required.
    const label = rows[1]!.find('[data-test="tree-item-label"]')
    expect(label.attributes('placeholder')).toBe('Hello Page')
    expect((label.element as HTMLInputElement).value).toBe('')
  })

  it('a 409 on save reloads the menu and notifies instead of overwriting', async () => {
    menusData.value = [{ slug: 'main', name: 'Main', item_count: 1, lock_version: 1 }]
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="nav-menu-row"]').trigger('click')
    detailData.value = detail()
    await flushPromises()

    await wrapper.find('[data-test="tree-add-root"]').trigger('click')
    saveMock.mockRejectedValue(new ApiError('conflict', 409, {}, null))
    await wrapper.find('[data-test="tree-save"]').trigger('click')
    await flushPromises()

    expect(refetch).toHaveBeenCalledTimes(1)
    expect(notify.error).toHaveBeenCalled()
    expect(notify.success).not.toHaveBeenCalled()
  })
})
