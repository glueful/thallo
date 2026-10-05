import { describe, it, expect, vi, beforeEach, type Mock } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import { ApiError } from '@/api/errors'
import type { BlockType } from '@/queries/blockTypes'
import type { FieldDef } from '@/fields/types'
import type {
  FieldEditorExposed,
  RevisionPair,
  StageHost,
  StageRenewal,
} from '@/editor/stage/types'

// The stage editor against a fake host (regions stage spec §5.2): what the editor asks of its host
// — mint, apply, renew — and what it does with the answers. The Design page's own specs cover the
// editing itself, through the entry host.

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
const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))

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

import { useStageEditor, type StageEditor } from '@/editor/stage/useStageEditor'
import { patternKey, type Pattern } from '@/queries/patterns'
import { present } from '@/editor/ops/types'

const SCHEMA: FieldDef[] = [{ name: 'body', type: 'blocks', label: 'Body' } as FieldDef]
const TREE = { body: [{ id: 'blockaaa0001', type: 'card', data: { title: 'A' }, settings: {} }] }
const PAIR: RevisionPair = { epoch: 'e1', revision: 4 }

let revision = 10
const applied = () => ({
  epoch: 'e1',
  revision: ++revision,
  baseline: revision - 1,
  style_generation: 0,
  applied_at: '2026-09-25T00:00:00Z',
  fragments: null,
})

function fakeHost(options: { reconcileOnOpen?: boolean; renew?: Mock<StageHost['renew']> } = {}) {
  return {
    schema: ref(SCHEMA),
    initial: ref<Record<string, unknown> | null>(null),
    mint: vi.fn<StageHost['mint']>(async () => ({
      token: 't1',
      themeUrl: 'https://site.test/_preview/t1',
      accepted: PAIR,
    })),
    apply: vi.fn<StageHost['apply']>(async () => applied()),
    reconcileOnOpen: options.reconcileOnOpen ?? false,
    renew:
      options.renew ??
      vi.fn<StageHost['renew']>(async () => ({
        token: 't2',
        themeUrl: 'https://site.test/_preview/t2',
        accepted: { epoch: 'e2', revision: 1 },
        retryWithExistingPair: false,
      })),
    appearanceFingerprint: vi.fn<StageHost['appearanceFingerprint']>(async () => 'fp'),
  }
}

function mountEditor(host: StageHost): { editor: StageEditor; unmount: () => void } {
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
  return { editor, unmount: () => wrapper.unmount() }
}

/** An edit to the document, as the inspector makes one. */
function edit(editor: StageEditor, title: string): void {
  editor.fields.value = {
    body: [{ id: 'blockaaa0001', type: 'card', data: { title }, settings: {} }],
  }
}

const expired = () => new ApiError('Preview session expired', 410, {}, null)

beforeEach(() => {
  setActivePinia(createPinia())
  blockTypes.value = []
  revision = 10
  notify.error.mockReset()
  localStorage.clear()
  // Auto-apply off: each test applies when it says so.
  localStorage.setItem('thallo.canvas.auto_apply', '0')
})

describe('the stage editor and its host', () => {
  it('mints the session once on mount and loads it as the canvas', async () => {
    const host = fakeHost()
    const { editor, unmount } = mountEditor(host)
    await flushPromises()
    expect(host.mint).toHaveBeenCalledTimes(1)
    expect(editor.iframeSrc.value).toBe('https://site.test/_preview/t1?canvas=1')
    expect(editor.accepted.value).toEqual(PAIR)
    unmount()
  })

  it('without reconcileOnOpen, loading the stage applies nothing', async () => {
    const host = fakeHost()
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    editor.onIframeLoad()
    await flushPromises()
    expect(host.apply).not.toHaveBeenCalled()
    unmount()
  })

  it('with reconcileOnOpen, loading the stage applies the hydrated tree once', async () => {
    const host = fakeHost({ reconcileOnOpen: true })
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    editor.onIframeLoad()
    editor.onIframeLoad()
    await flushPromises()
    expect(host.apply).toHaveBeenCalledTimes(1)
    expect(host.apply.mock.calls[0]![1]).toEqual(TREE)
    unmount()
  })

  it('an edit records history and applies with the drained operations', async () => {
    const host = fakeHost()
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    edit(editor, 'B')
    await flushPromises()
    editor.commitNow()
    expect(editor.historyState.value.canUndo).toBe(true)

    await editor.applyWorking()
    await flushPromises()
    const [token, fields, options] = host.apply.mock.calls[0]!
    expect(token).toBe('t1')
    expect((fields.body as { data: { title: string } }[])[0]!.data.title).toBe('B')
    expect(options.epoch).toBe('e1')
    expect(options.base_revision).toBe(4)
    expect(options.operations.length).toBeGreaterThan(0)
    expect(editor.accepted.value).toEqual({ epoch: 'e1', revision: 11 })

    // Drained: the next apply sends nothing already accepted.
    await editor.applyWorking()
    expect(host.apply.mock.calls[1]![2].operations).toEqual([])
    unmount()
  })

  it('onAccepted fires only for accepted responses: an out-of-order older revision does not call it', async () => {
    const host = { ...fakeHost(), onAccepted: vi.fn() }
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    edit(editor, 'B')
    await flushPromises()
    await editor.applyWorking()
    await flushPromises()
    expect(host.onAccepted).toHaveBeenCalledTimes(1)
    expect(host.onAccepted.mock.calls[0]![0]).toMatchObject({ epoch: 'e1', revision: 11 })

    // A delayed answer carrying an older revision is dropped: the page hears nothing of it.
    host.apply.mockResolvedValueOnce({ ...applied(), revision: 5 })
    edit(editor, 'C')
    await flushPromises()
    await editor.applyWorking()
    await flushPromises()
    expect(host.onAccepted).toHaveBeenCalledTimes(1)
    expect(editor.accepted.value).toEqual({ epoch: 'e1', revision: 11 })
    unmount()
  })

  it('a stale pair adopts the server’s current pair and retries once', async () => {
    const host = fakeHost()
    host.apply.mockRejectedValueOnce(
      new ApiError(
        'stale',
        409,
        {},
        {
          error: {
            details: { code: 'PREVIEW_REVISION_STALE', current: { epoch: 'e1', revision: 9 } },
          },
        },
      ),
    )
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    edit(editor, 'B')
    await flushPromises()
    await editor.applyWorking()
    await flushPromises()
    expect(host.apply).toHaveBeenCalledTimes(2)
    expect(host.apply.mock.calls[1]![2].base_revision).toBe(9)
    expect(editor.accepted.value).toEqual({ epoch: 'e1', revision: 11 })
    unmount()
  })

  it('a dead session is the host’s to renew: renew once with the document, nothing else re-mints', async () => {
    const host = fakeHost()
    host.apply.mockRejectedValueOnce(expired())
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    expect(editor.accepted.value).toEqual(PAIR)
    edit(editor, 'B')
    await flushPromises()
    await editor.applyWorking()
    await flushPromises()
    expect(host.renew).toHaveBeenCalledTimes(1)
    const [document] = host.renew.mock.calls[0]!
    expect((document.body as { data: { title: string } }[])[0]!.data.title).toBe('B')
    expect(host.mint).toHaveBeenCalledTimes(1) // mount only
    unmount()
  })

  it('retryWithExistingPair: loads the new session and retries with the pair already held', async () => {
    const host = fakeHost({
      renew: vi.fn<StageHost['renew']>(async () => ({
        token: 't2',
        themeUrl: 'https://site.test/_preview/t2',
        accepted: { epoch: 'e9', revision: 99 },
        retryWithExistingPair: true,
      })),
    })
    host.apply.mockRejectedValueOnce(expired())
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    edit(editor, 'B')
    await flushPromises()
    await editor.applyWorking()
    await flushPromises()
    expect(editor.iframeSrc.value).toBe('https://site.test/_preview/t2?canvas=1')
    expect(host.apply).toHaveBeenCalledTimes(2)
    const [token, , options] = host.apply.mock.calls[1]!
    expect(token).toBe('t2')
    expect(options.epoch).toBe('e1') // the pair we held, not the renewal's
    expect(options.base_revision).toBe(4)
    unmount()
  })

  it('a restoring renewal swaps the stage only once it resolves, adopts its pair, and applies edits made meanwhile once', async () => {
    let finish!: (r: StageRenewal) => void
    const host = fakeHost({
      renew: vi.fn<StageHost['renew']>(
        () => new Promise<StageRenewal>((resolve) => (finish = resolve)),
      ),
    })
    host.apply.mockRejectedValueOnce(expired())
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    edit(editor, 'B')
    await flushPromises()
    const run = editor.applyWorking()
    await flushPromises()
    expect(host.renew).toHaveBeenCalledTimes(1)
    expect(editor.iframeSrc.value).toBe('https://site.test/_preview/t1?canvas=1') // not yet

    edit(editor, 'C') // while the renewal is pending
    await flushPromises()
    editor.commitNow()
    finish({
      token: 't2',
      themeUrl: 'https://site.test/_preview/t2',
      accepted: { epoch: 'e2', revision: 1 },
      retryWithExistingPair: false,
    })
    await run
    await flushPromises()

    expect(editor.iframeSrc.value).toBe('https://site.test/_preview/t2?canvas=1')
    expect(host.apply).toHaveBeenCalledTimes(2) // the refused one, then the follow-up
    const [token, fields, options] = host.apply.mock.calls[1]!
    expect(token).toBe('t2')
    expect((fields.body as { data: { title: string } }[])[0]!.data.title).toBe('C')
    expect(options.epoch).toBe('e2')
    expect(options.base_revision).toBe(1)
    expect(options.operations.length).toBeGreaterThan(0)
    unmount()
  })

  it('switchSession runs the same path: renew with the document, adopt, apply edits made meanwhile', async () => {
    let finish!: (r: StageRenewal) => void
    const renew = vi.fn<StageHost['renew']>(
      () => new Promise<StageRenewal>((resolve) => (finish = resolve)),
    )
    const host = fakeHost()
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()

    const switching = editor.switchSession(renew)
    await flushPromises()
    expect(renew).toHaveBeenCalledTimes(1)
    expect(renew.mock.calls[0]).toEqual([TREE])
    expect(editor.iframeSrc.value).toBe('https://site.test/_preview/t1?canvas=1')

    edit(editor, 'D')
    await flushPromises()
    editor.commitNow()
    finish({
      token: 't3',
      themeUrl: 'https://site.test/_preview/t3',
      accepted: { epoch: 'e3', revision: 2 },
      retryWithExistingPair: false,
    })
    await switching
    await flushPromises()

    expect(editor.iframeSrc.value).toBe('https://site.test/_preview/t3?canvas=1')
    expect(host.apply).toHaveBeenCalledTimes(1)
    const [token, fields, options] = host.apply.mock.calls[0]!
    expect(token).toBe('t3')
    expect((fields.body as { data: { title: string } }[])[0]!.data.title).toBe('D')
    expect(options.epoch).toBe('e3')
    expect(options.base_revision).toBe(2)
    expect(host.mint).toHaveBeenCalledTimes(1)
    unmount()
  })

  it('a stage that reports its session expired is renewed with the document, once', async () => {
    const host = fakeHost()
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    callbacks.onSessionExpired!()
    await flushPromises()
    expect(host.renew).toHaveBeenCalledTimes(1)
    expect(host.renew.mock.calls[0]).toEqual([TREE])
    expect(editor.iframeSrc.value).toBe('https://site.test/_preview/t2?canvas=1')
    expect(editor.accepted.value).toEqual({ epoch: 'e2', revision: 1 })
    unmount()
  })

  it('no apply reaches the old session during a switch; the latest edit is applied to the new one after', async () => {
    let finishApply!: (v: ReturnType<typeof applied>) => void
    let finishRenew!: (r: StageRenewal) => void
    const host = fakeHost({
      renew: vi.fn<StageHost['renew']>(() => new Promise((resolve) => (finishRenew = resolve))),
    })
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    edit(editor, 'B')
    await flushPromises()
    host.apply.mockImplementationOnce(() => new Promise((resolve) => (finishApply = resolve)))
    const first = editor.applyWorking() // in flight to the old session
    await flushPromises()
    const switching = editor.switchSession(host.renew)
    await flushPromises()
    finishApply(applied()) // the old apply answers mid-switch
    await first
    edit(editor, 'C') // an edit while "Switching…"
    await flushPromises()
    await editor.applyWorking()
    await flushPromises()
    expect(host.apply).toHaveBeenCalledTimes(1) // nothing more went to the old session

    finishRenew({
      token: 't2',
      themeUrl: 'https://site.test/_preview/t2',
      accepted: { epoch: 'e2', revision: 1 },
      retryWithExistingPair: false,
    })
    await switching
    await flushPromises()
    expect(host.apply).toHaveBeenCalledTimes(2)
    const [token, fields, options] = host.apply.mock.calls[1]!
    expect(token).toBe('t2')
    expect((fields.body as { data: { title: string } }[])[0]!.data.title).toBe('C')
    expect(options.epoch).toBe('e2')
    unmount()
  })
})

describe('the layout hooks (sections and templates design §4)', () => {
  const CARD = {
    uuid: 'card',
    slug: 'card',
    label: 'Card',
    icon: null,
    category: 'Content',
    active: true,
    schema: [{ name: 'title', type: 'string' }],
    style_capabilities: [],
    style_targets: null,
  } as unknown as BlockType
  const section = (slug: string): Pattern => ({
    slug,
    kind: 'section',
    label: slug,
    category: 'Article',
    description: '',
    blocks: [{ type: 'card', data: { title: slug }, settings: {} }],
    scope: 'layout',
    surface: 'entry',
  })
  const template: Pattern = {
    slug: 'tpl',
    kind: 'page',
    label: 'Magazine',
    category: 'Layouts',
    description: '',
    blocks: [
      { type: 'card', data: { title: 'one' }, settings: {} },
      { type: 'card', data: { title: 'two' }, settings: {} },
    ],
    scope: 'layout',
    surface: 'entry',
    settings: { width: 'full' },
  }

  async function ready(host: StageHost) {
    blockTypes.value = [CARD]
    const mounted = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    edit(mounted.editor, 'A') // the first edit builds the history
    await flushPromises()
    return mounted
  }

  it('a section the host refuses is dimmed, and inserting it changes nothing', async () => {
    notify.warning.mockReset()
    const refusal = {
      ok: false as const,
      reason: 'type-not-allowed' as const,
      message: 'The Product buy box can appear only once',
    }
    const candidateCheck = vi.fn((_doc: unknown) => refusal)
    const host = { ...fakeHost(), patterns: () => [section('entry-part')], candidateCheck }
    const { editor, unmount } = await ready(host)
    expect(editor.paletteClickable(patternKey('entry-part'))).toEqual(refusal)
    const before = JSON.stringify(editor.fields.value)
    const sequence = editor.currentSequence()
    await editor.insertFromPalette(patternKey('entry-part'))
    await flushPromises()
    expect(JSON.stringify(editor.fields.value)).toBe(before)
    expect(editor.currentSequence()).toBe(sequence)
    expect(notify.warning).toHaveBeenCalledWith(expect.any(String), refusal.message)
    // The candidate the host judged is the whole document with the section in it.
    const calls = candidateCheck.mock.calls
    const judged = calls[calls.length - 1]![0] as { fields: { body: { type: string }[] } }
    expect(judged.fields.body).toHaveLength(2)
    unmount()
  })

  it('the host’s library is the one the editor inserts from', async () => {
    const host = { ...fakeHost(), patterns: () => [section('only-here')] }
    const { editor, unmount } = await ready(host)
    await editor.insertFromPalette(patternKey('only-here'))
    await flushPromises()
    const body = editor.fields.value.body as { data: { title: string } }[]
    expect(body.map((b) => b.data.title)).toContain('only-here')
    unmount()
  })

  it('a replacement carries the host’s operations in the same undo step, and redo keeps the ids', async () => {
    const host = {
      ...fakeHost(),
      patterns: () => [template],
      pageReplace: (p: Pattern) => ({
        ops: [
          {
            type: 'SetPageSettings' as const,
            field: '_layout_settings',
            from: present<unknown>({}),
            to: present<unknown>({ ...(p.settings ?? {}) }),
          },
        ],
      }),
    }
    const { editor, unmount } = await ready(host)
    editor.fields.value = { ...editor.fields.value, _layout_settings: {} }
    await flushPromises()
    await editor.replaceWithPage('tpl', 'body')
    await flushPromises()
    const titles = () =>
      (editor.fields.value.body as { data: { title: string } }[]).map((b) => b.data.title)
    expect(titles()).toEqual(['one', 'two'])
    expect(editor.fields.value._layout_settings).toEqual({ width: 'full' })
    const minted = (editor.fields.value.body as { id: string }[]).map((b) => b.id)

    await editor.undo()
    await flushPromises()
    expect(titles()).toEqual(['A'])
    expect(editor.fields.value._layout_settings).toEqual({})

    await editor.redo()
    await flushPromises()
    expect((editor.fields.value.body as { id: string }[]).map((b) => b.id)).toEqual(minted)
    expect(editor.fields.value._layout_settings).toEqual({ width: 'full' })
    unmount()
  })
})

// What the stage renders a target in (block typeface plan Task 10): the editor counts the stage's
// renders — each load and each in-place patch — so the inspector asks again, and a selection
// change drops every answer still pending.
describe('the stage typography probe', () => {
  it('counts each stage load and each patched refresh', async () => {
    const host = fakeHost()
    const { editor, unmount } = mountEditor(host)
    host.initial.value = structuredClone(TREE)
    await flushPromises()
    const before = editor.stageTypography.renders.value
    callbacks.onStageState!(false as never)
    expect(editor.stageTypography.renders.value).toBe(before + 1)
    edit(editor, 'B')
    await flushPromises()
    editor.commitNow()
    await editor.applyWorking()
    await flushPromises()
    expect(editor.stageTypography.renders.value).toBe(before + 2)
    unmount()
  })

  it('a selection change drops the pending answers', async () => {
    const drop = vi.fn()
    ;(bridge as Record<string, unknown>).dropTypography = drop
    const { editor, unmount } = mountEditor(fakeHost())
    await flushPromises()
    editor.selectOne('blockaaa0001')
    await flushPromises()
    expect(drop).toHaveBeenCalledTimes(1)
    editor.clearSelection()
    await flushPromises()
    expect(drop).toHaveBeenCalledTimes(2)
    delete (bridge as Record<string, unknown>).dropTypography
    unmount()
  })
})
