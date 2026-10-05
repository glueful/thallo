import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref, watch } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import type { FieldDef } from '@/fields/types'
import type { FieldEditorExposed, StageHost } from '@/editor/stage/types'
import type { AppearanceChange } from '@/composables/useAppearanceChanges'

// Open stages stay fresh (block typeface plan Task 11): a change announced by another tab, or found
// by asking the host on focus and every minute, reloads the stage once — after any apply in flight,
// with the working copy and its history untouched, and edits made during the reload applied once
// the stage is ready. Asking never mints, applies or renews. The same for every stage's host.

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
const TREE = { body: [{ id: 'blockaaa0001', type: 'card', data: { title: 'A' }, settings: {} }] }

let revision = 10
const applied = () => ({
  epoch: 'e1',
  revision: ++revision,
  baseline: revision - 1,
  style_generation: 0,
  applied_at: '2026-10-05T00:00:00Z',
  fragments: null,
})

function fakeHost(fingerprint: () => string) {
  return {
    schema: ref(SCHEMA),
    initial: ref<Record<string, unknown> | null>(null),
    mint: vi.fn<StageHost['mint']>(async () => ({
      token: 't1',
      themeUrl: 'https://site.test/_preview/t1',
      accepted: { epoch: 'e1', revision: 4 },
    })),
    apply: vi.fn<StageHost['apply']>(async () => applied()),
    reconcileOnOpen: false,
    renew: vi.fn<StageHost['renew']>(),
    appearanceFingerprint: vi.fn<StageHost['appearanceFingerprint']>(async () => fingerprint()),
  }
}

function mountEditor(host: StageHost) {
  let editor!: StageEditor
  const wrapper = mount(
    defineComponent({
      setup() {
        editor = useStageEditor(host, {
          iframe: ref(null),
          fieldEditor: ref<FieldEditorExposed | null>(null),
          stage: ref(null),
        })
        return () => null
      },
    }),
  )
  // Count full reloads: the iframe's src is emptied and put back.
  const reloads = { count: 0 }
  watch(editor.iframeSrc, (src) => {
    if (src === '') reloads.count++
  })
  return { editor, reloads, unmount: () => wrapper.unmount() }
}

/** The stage finished loading, rendered with `fingerprint`. */
function stageReady(fingerprint: string | null): void {
  callbacks.onStageState!(false as never, fingerprint as never)
}

function edit(editor: StageEditor, title: string): void {
  editor.fields.value = {
    body: [{ id: 'blockaaa0001', type: 'card', data: { title }, settings: {} }],
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  blockTypes.value = []
  revision = 10
  localStorage.clear()
  localStorage.setItem('thallo.canvas.auto_apply', '0')
})
afterEach(() => vi.useRealTimers())

describe.each(['entry design', 'region', 'layout'])('the %s stage', () => {
  async function opened(fingerprint = () => 'fp-1') {
    const host = fakeHost(fingerprint)
    const mounted = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    stageReady('fp-1')
    await flushPromises()
    host.mint.mockClear()
    return { host, ...mounted }
  }

  it('asks only the host on focus, and an unchanged answer changes nothing', async () => {
    const { host, editor, reloads, unmount } = await opened()
    edit(editor, 'B')
    await flushPromises()
    const accepted = editor.accepted.value
    window.dispatchEvent(new Event('focus'))
    await flushPromises()
    expect(host.appearanceFingerprint).toHaveBeenCalledTimes(1)
    expect(host.mint).not.toHaveBeenCalled()
    expect(host.apply).not.toHaveBeenCalled()
    expect(host.renew).not.toHaveBeenCalled()
    expect(reloads.count).toBe(0)
    expect(editor.accepted.value).toEqual(accepted)
    expect((editor.fields.value.body as { data: { title: string } }[])[0]!.data.title).toBe('B')
    unmount()
  })

  it('asks every minute while the page is visible, never while it is hidden', async () => {
    vi.useFakeTimers()
    const { host, unmount } = await opened()
    await vi.advanceTimersByTimeAsync(60_000)
    expect(host.appearanceFingerprint).toHaveBeenCalledTimes(1)
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    await vi.advanceTimersByTimeAsync(60_000)
    expect(host.appearanceFingerprint).toHaveBeenCalledTimes(1)
    visibility.mockRestore()
    unmount()
  })

  it('a change made elsewhere — found on focus — reloads the stage once', async () => {
    let current = 'fp-1'
    const { reloads, unmount } = await opened(() => current)
    current = 'fp-2'
    window.dispatchEvent(new Event('focus'))
    await flushPromises()
    expect(reloads.count).toBe(1)
    stageReady('fp-2')
    window.dispatchEvent(new Event('focus'))
    await flushPromises()
    expect(reloads.count).toBe(1)
    unmount()
  })

  it('another tab’s change waits for the apply in flight, reloads once, and keeps the edit and its history', async () => {
    const { host, editor, reloads, unmount } = await opened()
    let release!: () => void
    host.apply.mockImplementationOnce(
      () => new Promise((resolve) => (release = () => resolve(applied()))),
    )
    edit(editor, 'B')
    await flushPromises()
    editor.commitNow()
    const applying = editor.applyWorking()
    await flushPromises()
    changes.listener!({ kind: 'appearance', at: Date.now() })
    await flushPromises()
    expect(reloads.count).toBe(0)
    release()
    await applying
    await flushPromises()
    expect(reloads.count).toBe(1)
    expect((editor.fields.value.body as { data: { title: string } }[])[0]!.data.title).toBe('B')
    expect(editor.historyState.value.canUndo).toBe(true)
    unmount()
  })

  it('an edit made during the reload is applied once the stage is ready, exactly once', async () => {
    const { host, editor, reloads, unmount } = await opened()
    changes.listener!({ kind: 'fonts', at: Date.now() })
    await flushPromises()
    expect(reloads.count).toBe(1)
    edit(editor, 'C')
    await flushPromises()
    editor.commitNow()
    await editor.applyWorking()
    await flushPromises()
    expect(host.apply).not.toHaveBeenCalled()
    stageReady('fp-2')
    await flushPromises()
    expect(host.apply).toHaveBeenCalledTimes(1)
    expect((host.apply.mock.calls[0]![1].body as { data: { title: string } }[])[0]!.data.title).toBe(
      'C',
    )
    unmount()
  })

  it('removing and then restoring the chosen family reload once each, and the selection stays', async () => {
    const { editor, reloads, unmount } = await opened()
    editor.selectOne('blockaaa0001')
    changes.listener!({ kind: 'fonts', at: Date.now() })
    await flushPromises()
    stageReady('fp-2')
    await flushPromises()
    changes.listener!({ kind: 'fonts', at: Date.now() })
    await flushPromises()
    stageReady('fp-3')
    await flushPromises()
    expect(reloads.count).toBe(2)
    expect(editor.selected.value).toBe('blockaaa0001')
    unmount()
  })
})
