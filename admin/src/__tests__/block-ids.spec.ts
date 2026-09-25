import { describe, it, expect, afterEach, vi } from 'vitest'
import { allocateIds } from '@/queries/blockFactory'
import { newEditorSession, newOperationId, newTransactionId } from '@/editor/ops/session'
import { createDragCoordinator } from '@/editor/structure/coordinator'

// Test-only block ids (regions stage plan R8): an E2E build may queue the ids the next inserted
// blocks take, so a proof's accepted document names blocks it can match. Only block instances take
// them — the editor's session, operation, transaction and drag ids never touch the queue.

type E2eWindow = { __thalloE2eBlockIds?: string[] }
const queue = (ids: string[] | undefined) => ((window as E2eWindow).__thalloE2eBlockIds = ids)

const card = { type: 'card', data: { title: 'A' }, settings: {} }
const columns = {
  type: 'columns',
  data: {
    items: [
      { type: 'card', data: {}, settings: {} },
      { type: 'card', data: {}, settings: {} },
    ],
  },
  settings: {},
}

afterEach(() => {
  vi.unstubAllEnvs()
  queue(undefined)
})

describe('test-only block ids', () => {
  it('in an E2E build, allocateIds takes the queued ids, the block first then its starters', () => {
    vi.stubEnv('VITE_E2E', '1')
    queue(['e2ecol000001', 'e2ecrd000001', 'e2ecrd000002'])
    const block = allocateIds(columns)
    expect(block.id).toBe('e2ecol000001')
    expect((block.data.items as { id: string }[]).map((b) => b.id)).toEqual([
      'e2ecrd000001',
      'e2ecrd000002',
    ])
  })

  it('in an E2E build with an empty queue, the id is generated', () => {
    vi.stubEnv('VITE_E2E', '1')
    queue([])
    expect(allocateIds(card).id).toMatch(/^[a-z0-9]{12}$/)
  })

  it('outside an E2E build, a queue is ignored', () => {
    vi.stubEnv('VITE_E2E', '')
    queue(['e2enew000001'])
    expect(allocateIds(card).id).not.toBe('e2enew000001')
    expect((window as E2eWindow).__thalloE2eBlockIds).toEqual(['e2enew000001'])
  })

  it('session, operation, transaction and drag ids leave the queue untouched', () => {
    vi.stubEnv('VITE_E2E', '1')
    queue(['e2enew000001'])
    newEditorSession()
    newOperationId()
    newTransactionId()
    createDragCoordinator({
      doc: () => ({ fields: {} }),
      legality: () => ({
        regionsOf: () => [],
        blockTypes: () => [],
        rootSlots: () => ({}),
        maxDepth: 6,
      }),
    }).begin('stage', { blocks: ['x'] })
    expect((window as E2eWindow).__thalloE2eBlockIds).toEqual(['e2enew000001'])
    expect(allocateIds(card).id).toBe('e2enew000001')
  })
})
