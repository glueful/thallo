import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { BlockTypeUsage, BlockTypeMigration } from '@/queries/blockTypes'

// ── mocks ──────────────────────────────────────────────────────────────────────

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))

const usage = ref<BlockTypeUsage | null>(null)
const migrations = ref<BlockTypeMigration[]>([])
const { declareMock, deleteMock } = vi.hoisted(() => ({
  declareMock: vi.fn(),
  deleteMock: vi.fn(),
}))

vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypeUsage: () => ({ data: usage, status: ref('success') }),
  useBlockTypeMigrations: () => ({ data: migrations }),
  declareBlockTypeMigration: declareMock,
  deleteBlockType: deleteMock,
}))

vi.mock('@pinia/colada', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@pinia/colada')>()),
  useQueryCache: () => ({ invalidateQueries: vi.fn() }),
}))

const push = vi.fn()
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRouter: () => ({ push }),
}))

const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

import BlockTypeLifecycle from '@/pages/settings/block-types/components/BlockTypeLifecycle.vue'
import { ApiError, apiErrorCode, apiErrorDetails } from '@/api/errors'
import { fetchBlockTypeUsage, fetchBlockTypeMigrations } from '@/queries/blockTypes'

const zeroUsage = (): BlockTypeUsage => ({ total: 0, per_type: [], allowlists: [] })
const someUsage = (): BlockTypeUsage => ({
  total: 3,
  per_type: [
    {
      type: 'page',
      drafts: 2,
      publications: 1,
      sample: [{ entry_uuid: 'e1', title: 'Home' }],
    },
  ],
  allowlists: ['landing'],
})
const runningMigration = (): BlockTypeMigration => ({
  uuid: 'm1',
  status: 'running',
  ops: [{ op: 'rename', from: 'title', to: 'heading' }],
  work_items_total: 5,
  work_items_done: 2,
  work_items_failed: 0,
  created_at: '2026-07-03 12:00:00.100000',
  completed_at: null,
})

function mountLifecycle() {
  return mount(BlockTypeLifecycle, {
    props: {
      slug: 'card',
      schema: [
        { name: 'title', type: 'string', required: false, localized: false, filterable: false },
      ],
    },
  })
}

beforeEach(() => {
  setActivePinia(createPinia())
  usage.value = zeroUsage()
  migrations.value = []
  authFetch.mockReset()
  declareMock.mockReset()
  deleteMock.mockReset()
  push.mockReset()
  notify.success.mockReset()
  notify.warning.mockReset()
  notify.error.mockReset()
})

describe('block-type lifecycle: error-code mapping', () => {
  it('apiErrorCode/apiErrorDetails read the framework error.details shape', () => {
    const e = new ApiError("block type 'card' has a migration in progress", 409, {}, {
      success: false,
      message: "block type 'card' has a migration in progress",
      error: {
        code: 409,
        details: { code: 'BLOCK_MIGRATION_IN_PROGRESS', block_type: 'card' },
      },
    })
    expect(apiErrorCode(e)).toBe('BLOCK_MIGRATION_IN_PROGRESS')
    expect(apiErrorDetails(e)?.block_type).toBe('card')
    // Non-ApiError and detail-less bodies → null (never throws).
    expect(apiErrorCode(new Error('x'))).toBeNull()
    expect(apiErrorCode(new ApiError('x', 409, {}, { success: false }))).toBeNull()
  })
})

describe('block-type lifecycle: queries', () => {
  it('fetchBlockTypeUsage/fetchBlockTypeMigrations unwrap the envelope', async () => {
    authFetch.mockResolvedValueOnce({ data: someUsage() })
    expect((await fetchBlockTypeUsage('card')).total).toBe(3)
    authFetch.mockResolvedValueOnce({ data: { migrations: [runningMigration()] } })
    expect((await fetchBlockTypeMigrations('card'))[0].uuid).toBe('m1')
  })
})

describe('BlockTypeLifecycle component', () => {
  it('shows usage and disables delete while in use', async () => {
    usage.value = someUsage()
    const wrapper = mountLifecycle()
    await flushPromises()
    expect(wrapper.find('[data-testid="block-usage-total"]').text()).toContain('3')
    const del = wrapper.find('[data-testid="block-delete"]')
    expect(del.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('landing') // allowlist reported
  })

  it('deletes at zero usage after confirmation and navigates back', async () => {
    deleteMock.mockResolvedValue(undefined)
    const wrapper = mountLifecycle()
    await flushPromises()
    await wrapper.find('[data-testid="block-delete"]').trigger('click')
    expect(wrapper.find('[data-testid="block-delete-confirm"]').exists()).toBe(true)
    await wrapper.find('[data-testid="block-delete-confirm-yes"]').trigger('click')
    await flushPromises()
    expect(deleteMock).toHaveBeenCalledWith('card')
    expect(push).toHaveBeenCalledWith('/settings/block-types')
  })

  it('shows active-migration status and disables migrate + delete', async () => {
    migrations.value = [runningMigration()]
    const wrapper = mountLifecycle()
    await flushPromises()
    const status = wrapper.find('[data-testid="block-migration-status"]')
    expect(status.exists()).toBe(true)
    expect(status.text()).toContain('2/5')
    expect(wrapper.find('[data-testid="block-migrate"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="block-delete"]').attributes('disabled')).toBeDefined()
  })

  it('migrate dialog declares rename ops', async () => {
    declareMock.mockResolvedValue(undefined)
    const wrapper = mountLifecycle()
    await flushPromises()
    await wrapper.find('[data-testid="block-migrate"]').trigger('click')
    expect(wrapper.find('[data-testid="block-migrate-dialog"]').exists()).toBe(true)
    // Default row: rename `title` → fill the target and submit.
    await wrapper.find('[data-testid="block-migrate-op-0"] input').setValue('heading')
    await wrapper.find('[data-testid="block-migrate-submit"]').trigger('click')
    await flushPromises()
    expect(declareMock).toHaveBeenCalledWith('card', [
      { op: 'rename', from: 'title', to: 'heading' },
    ])
  })
})
