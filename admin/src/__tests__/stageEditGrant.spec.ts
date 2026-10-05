import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { FieldDef } from '@/fields/types'
import type { FieldEditorExposed, StageHost } from '@/editor/stage/types'
import type { AppearanceChange } from '@/composables/useAppearanceChanges'

// A double-click on the stage's text before the document is ready (final review): the request is
// held, and granted once the tree holds the block — never silently dropped. A request the tree never
// comes to hold expires, and a newer one replaces it.

const blockTypes = ref<BlockType[]>([])
vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypes: () => ({ data: blockTypes }),
}))
vi.mock('@/queries/styleSchema', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/styleSchema')>()),
  useStyleSchema: () => ({ data: ref(null) }),
}))
vi.mock('@/queries/styleClasses', () => ({
  useStyleClasses: () => ({ data: ref({ generation: 0, classes: [] }), refetch: vi.fn() }),
  useStyleClassMutations: () => ({
    create: { mutateAsync: vi.fn(), isLoading: ref(false) },
    deleteUnreferenced: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
}))
vi.mock('@/queries/blockFactory', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockFactory')>()),
  useBlockFactory: () => ({ make: vi.fn(), instance: vi.fn() }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))

const callbacks = vi.hoisted(() => ({}) as Record<string, (...args: never[]) => void>)
const bridge = vi.hoisted(() => {
  const noop = () => {}
  return new Proxy(
    {
      editFlush: async () => undefined,
      stageRefresh: async () => ({ mode: 'patched', epoch: null, revision: null }),
      stageFragments: async () => ({ mode: 'patched', epoch: null, revision: null }),
    } as Record<string, unknown>,
    {
      get: (target, key: string) =>
        target[key] ??
        (key.startsWith('on') ? (cb: (...args: never[]) => void) => (callbacks[key] = cb) : noop),
    },
  )
})
vi.mock('@/composables/useCanvasBridge', () => ({ useCanvasBridge: () => bridge }))

const changes = vi.hoisted(() => ({ listener: null as ((c: AppearanceChange) => void) | null }))
vi.mock('@/composables/useAppearanceChanges', () => ({
  useAppearanceChanges: () => ({
    notify: () => {},
    onChange: (cb: (c: AppearanceChange) => void) => {
      changes.listener = cb
      return () => (changes.listener = null)
    },
    dispose: () => {},
  }),
}))

import { useStageEditor, type StageEditor } from '@/editor/stage/useStageEditor'

const SCHEMA: FieldDef[] = [{ name: 'body', type: 'blocks', label: 'Body' } as FieldDef]

function host(): StageHost {
  return {
    schema: ref(SCHEMA),
    initial: ref<Record<string, unknown> | null>(null),
    mint: vi.fn(async () => ({
      token: 't1',
      themeUrl: 'https://site.test/_preview/t1',
      accepted: { epoch: 'e1', revision: 1 },
    })),
    apply: vi.fn(),
    reconcileOnOpen: false,
    renew: vi.fn(),
    appearanceFingerprint: vi.fn(async () => 'fp'),
  } as unknown as StageHost
}

function mountEditor(tree: { has: boolean }) {
  let editor!: StageEditor
  const fieldEditor = ref({
    blockTypeOfBlock: (id: string) => (tree.has && id === 'blockaaa0001' ? 'card' : null),
  } as unknown as FieldEditorExposed)
  mount(
    defineComponent({
      setup() {
        editor = useStageEditor(host(), { iframe: ref(null), fieldEditor, stage: ref(null) })
        return () => null
      },
    }),
  )
  return editor
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.setItem('thallo.canvas.auto_apply', '0')
  blockTypes.value = [
    {
      slug: 'card',
      schema: [{ name: 'title', type: 'string', label: 'Title' }],
    } as unknown as BlockType,
  ]
})
afterEach(() => vi.useRealTimers())

describe('an edit asked for before the document is ready', () => {
  it('is granted once the tree holds the block', async () => {
    const grant = vi.fn()
    ;(bridge as Record<string, unknown>).editGrant = grant
    const tree = { has: false }
    mountEditor(tree)
    await flushPromises()
    callbacks.onEditRequest!('blockaaa0001' as never, 'title' as never)
    expect(grant).not.toHaveBeenCalled()
    // The tree comes to hold the block on its own — nothing the editor watches changes.
    tree.has = true
    await new Promise((resolve) => setTimeout(resolve, 250))
    expect(grant).toHaveBeenCalledTimes(1)
    expect(grant).toHaveBeenCalledWith('blockaaa0001', 'title', 'string')
    delete (bridge as Record<string, unknown>).editGrant
  })

  it('expires when the tree never holds it', async () => {
    vi.useFakeTimers()
    const grant = vi.fn()
    ;(bridge as Record<string, unknown>).editGrant = grant
    const tree = { has: false }
    mountEditor(tree)
    await flushPromises()
    callbacks.onEditRequest!('blockaaa0001' as never, 'title' as never)
    await vi.advanceTimersByTimeAsync(3_100)
    tree.has = true
    await vi.advanceTimersByTimeAsync(500)
    expect(grant).not.toHaveBeenCalled()
    delete (bridge as Record<string, unknown>).editGrant
  })
})
