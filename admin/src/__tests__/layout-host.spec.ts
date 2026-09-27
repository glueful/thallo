import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import { ApiError } from '@/api/errors'
import type { LayoutSession } from '@/queries/layouts'
import type { FieldEditorExposed } from '@/editor/stage/types'

// The layout host (type layouts spec §6.2) with the real stage editor: the document mapping, the
// save baseline, Save and its conflict, a removed layout, and the restore sequence a sample switch or
// an expired session runs.

const q = vi.hoisted(() => ({ mint: vi.fn(), apply: vi.fn(), save: vi.fn(), remove: vi.fn() }))
vi.mock('@/queries/layouts', () => ({
  mintLayoutSession: q.mint,
  applyLayout: q.apply,
  saveLayout: q.save,
  removeLayout: q.remove,
}))
vi.mock('@/queries/blockTypes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/queries/blockTypes')>()),
  useBlockTypes: () => ({ data: ref([]) }),
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
vi.mock('@/queries/blockFactory', () => ({
  useBlockFactory: () => ({ make: vi.fn(), instance: vi.fn() }),
}))
const notify = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }))
const bridge = vi.hoisted(
  () =>
    new Proxy(
      {
        editFlush: async () => undefined,
        stageRefresh: async () => ({ mode: 'patched', epoch: null, revision: null }),
      } as Record<string, unknown>,
      { get: (target, key: string) => target[key] ?? (() => {}) },
    ),
)
vi.mock('@/composables/useCanvasBridge', () => ({ useCanvasBridge: () => bridge }))

import { layoutSchema, toDocument, toPayload, useLayoutHost } from '@/pages/layouts/useLayoutHost'
import { useStageEditor, type StageEditor } from '@/editor/stage/useStageEditor'

const BLOCKS = [
  { id: 'laytitle0001', type: 'entry_title', data: { level: 'h1' }, settings: {} },
  { id: 'laybody00001', type: 'entry_content', data: { field: 'body' }, settings: {} },
]

let sessions = 0
function session(overrides: Partial<LayoutSession> = {}): LayoutSession {
  sessions++
  return {
    token: `tok${sessions}`,
    themeUrl: `/_preview/tok${sessions}`,
    layout: { blocks: structuredClone(BLOCKS), settings: {}, lock_version: 3 },
    starter: false,
    starterLayout: structuredClone(BLOCKS),
    required: [{ type: 'entry_content', field: 'body' }],
    palette: ['entry_title', 'entry_content'],
    sample: { id: 'posta0000001', label: 'Post A' },
    placeholder: false,
    label: 'Posts — single post',
    reach: 'Applies to every post',
    ...overrides,
  }
}
let revision = 0
const accepted = () => ({
  epoch: 'e1',
  revision: ++revision,
  baseline: revision - 1,
  style_generation: 0,
  applied_at: 'now',
  fragments: null,
})

function mountHost() {
  let host!: ReturnType<typeof useLayoutHost>
  let editor!: StageEditor
  const wrapper = mount(
    defineComponent({
      setup() {
        host = useLayoutHost({ surface: 'entry', target: 'post' })
        editor = useStageEditor(host.host, {
          iframe: ref(null),
          fieldEditor: ref<FieldEditorExposed | null>(null),
          stage: ref(null),
        })
        host.bind(editor)
        return () => null
      },
    }),
  )
  return { host, editor, unmount: () => wrapper.unmount() }
}

function editTitle(editor: StageEditor, level: string): void {
  editor.fields.value = {
    ...editor.fields.value,
    blocks: [{ id: 'laytitle0001', type: 'entry_title', data: { level }, settings: {} }, BLOCKS[1]],
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  sessions = 0
  revision = 0
  q.mint.mockReset().mockImplementation(async () => session())
  q.apply.mockReset().mockImplementation(async () => accepted())
  q.save.mockReset()
  q.remove.mockReset()
  notify.error.mockReset()
  notify.success.mockReset()
  localStorage.clear()
  localStorage.setItem('thallo.canvas.auto_apply', '0')
})

describe('the layout document', () => {
  it('maps the server’s layout to one document and back', () => {
    const layout = { blocks: BLOCKS, settings: { width: 'full' } }
    const doc = toDocument(layout)
    expect(doc).toEqual({ blocks: BLOCKS, _layout_settings: { width: 'full' } })
    expect(toPayload(doc)).toEqual(layout)
  })

  it('the schema is one root blocks field that names no allowlist; the host offers the field blocks', () => {
    expect(layoutSchema()).toEqual([
      expect.objectContaining({ name: 'blocks', type: 'blocks', blockTypes: [] }),
    ])
    const { host, unmount } = mountHost()
    expect(host.host.allowLayoutOnly).toBe(true)
    unmount()
  })
})

describe('the save baseline, Save and Remove', () => {
  it('hydrates from the first session; a later mint never replaces the baseline', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    expect(editor.fields.value.blocks).toEqual(BLOCKS)
    expect(host.baseline.value).toBe(3)

    q.mint.mockImplementationOnce(async () =>
      session({ layout: { blocks: [], settings: {}, lock_version: 9 } }),
    )
    await host.switchSample('postb0000001')
    await flushPromises()
    expect(host.baseline.value).toBe(3)
    expect(editor.fields.value.blocks).toEqual(BLOCKS)
    unmount()
  })

  it('posts the layout, the version loaded and the accepted pair; marks only the submitted position saved', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    editTitle(editor, 'h2')
    await flushPromises()
    await editor.applyWorking()
    await flushPromises()

    let finish!: (v: unknown) => void
    q.save.mockImplementationOnce(() => new Promise((resolve) => (finish = resolve)))
    const saving = host.save()
    await flushPromises()
    const [surface, target, body] = q.save.mock.calls[0]!
    expect([surface, target]).toEqual(['entry', 'post'])
    expect(body.expected_lock_version).toBe(3)
    expect(body.token).toBe('tok1')
    expect(body.preview_revision).toEqual({ epoch: 'e1', revision: 1 })
    expect(body.layout.blocks[0].data.level).toBe('h2')

    editTitle(editor, 'h3') // while the save is in flight
    await flushPromises()
    finish({
      layout: { blocks: body.layout.blocks, settings: {}, lock_version: 4 },
      previewCleared: false,
    })
    await saving
    await flushPromises()
    expect(host.baseline.value).toBe(4)
    expect(editor.dirty.value).toBe(true) // h3 was not in that save
    unmount()
  })

  it('a version conflict is shown; Reload discards the edits and loads the saved layout and version', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    editTitle(editor, 'h2')
    await flushPromises()
    q.save.mockRejectedValueOnce(
      new ApiError(
        'moved',
        409,
        {},
        { error: { details: { code: 'LAYOUT_VERSION_CONFLICT', current: 4 } } },
      ),
    )
    await host.save()
    expect(host.conflict.value).toBe(true)

    const theirs = [BLOCKS[1]!]
    q.mint.mockImplementationOnce(async () =>
      session({ layout: { blocks: theirs, settings: {}, lock_version: 4 } }),
    )
    await host.reload()
    await flushPromises()
    expect(host.conflict.value).toBe(false)
    expect(editor.fields.value.blocks).toEqual(theirs)
    expect(host.baseline.value).toBe(4)
    expect(editor.dirty.value).toBe(false)
    unmount()
  })

  it('a removed layout’s session is shown as removed', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    editTitle(editor, 'h2')
    await flushPromises()
    q.save.mockRejectedValueOnce(
      new ApiError('gone', 410, {}, { error: { details: { code: 'LAYOUT_SESSION_RETIRED' } } }),
    )
    await host.save()
    expect(host.retired.value).toBe(true)
    expect(notify.error).not.toHaveBeenCalled()
    unmount()
  })

  it('an apply answered LAYOUT_SESSION_RETIRED shows the layout as removed and mints nothing new', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    editTitle(editor, 'h2')
    await flushPromises()
    q.apply.mockRejectedValueOnce(
      new ApiError('gone', 410, {}, { error: { details: { code: 'LAYOUT_SESSION_RETIRED' } } }),
    )
    await editor.applyWorking()
    await flushPromises()
    expect(host.retired.value).toBe(true)
    expect(q.mint).toHaveBeenCalledTimes(1) // no renewal resurrects a removed layout's session
    unmount()
  })

  it('Remove sends the session token and the version loaded', async () => {
    const { host, unmount } = mountHost()
    await flushPromises()
    q.remove.mockResolvedValueOnce({ lockVersion: 4 })
    expect(await host.remove()).toBe(true)
    expect(q.remove).toHaveBeenCalledWith('entry', 'post', {
      token: 'tok1',
      expected_lock_version: 3,
    })
    unmount()
  })
})

describe('whether a saved layout exists', () => {
  it('a starter session has none; its first save makes one; a remove ends it', async () => {
    q.mint.mockImplementation(async () =>
      session({
        starter: true,
        layout: { blocks: structuredClone(BLOCKS), settings: {}, lock_version: 0 },
      }),
    )
    const { host, unmount } = mountHost()
    await flushPromises()
    expect(host.live.value).toBe(false)
    q.save.mockResolvedValueOnce({
      layout: { blocks: BLOCKS, settings: {}, lock_version: 1 },
      previewCleared: false,
    })
    await host.save()
    expect(host.live.value).toBe(true)
    q.remove.mockResolvedValueOnce({ lockVersion: 2 })
    await host.remove()
    expect(host.live.value).toBe(false)
    unmount()
  })

  it('a session on a saved layout has one', async () => {
    const { host, unmount } = mountHost()
    await flushPromises()
    expect(host.live.value).toBe(true)
    unmount()
  })
})

describe('the restore sequence', () => {
  it('renew mints, applies the whole document with a null pair, and keeps the saved version', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    editTitle(editor, 'h4')
    await flushPromises()
    const renewal = await host.host.renew(editor.snapshotFields())
    expect(q.mint).toHaveBeenCalledTimes(2)
    const [token, layout, options] = q.apply.mock.calls[q.apply.mock.calls.length - 1]!
    expect(token).toBe('tok2')
    expect(layout.blocks[0].data.level).toBe('h4')
    expect(options).toEqual({ epoch: null, base_revision: null, operations: [] })
    expect(renewal.token).toBe('tok2')
    expect(host.baseline.value).toBe(3)
    unmount()
  })

  it('a sample switch re-mints for that sample and carries the unsaved layout over', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    editTitle(editor, 'h2')
    await flushPromises()
    await host.switchSample('postb0000001')
    await flushPromises()
    expect(q.mint).toHaveBeenLastCalledWith('entry', 'post', 'postb0000001')
    const [, layout] = q.apply.mock.calls[q.apply.mock.calls.length - 1]!
    expect(layout.blocks[0].data.level).toBe('h2')
    expect(host.sample.value).toBe('postb0000001')
    expect(editor.dirty.value).toBe(true)
    unmount()
  })

  it('a newer switch abandons an older one still in flight: its answer is ignored', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    let finishFirst!: (s: LayoutSession) => void
    q.mint.mockImplementationOnce(() => new Promise((resolve) => (finishFirst = resolve)))
    const first = host.switchSample('postb0000001')
    await flushPromises()
    await host.switchSample('postc0000001')
    await flushPromises()
    finishFirst(session({ token: 'stale' }))
    await first
    await flushPromises()
    expect(editor.previewToken.value).not.toBe('stale')
    expect(host.sample.value).toBe('postc0000001')
    unmount()
  })
})
