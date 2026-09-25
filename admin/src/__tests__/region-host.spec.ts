import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import { ApiError } from '@/api/errors'
import type { RegionData, RegionSession } from '@/queries/regions'
import type { FieldEditorExposed } from '@/editor/stage/types'

// The region host (regions stage spec §5.1–§5.3) with the real stage editor: the document mapping,
// the save baseline, Save and its conflict, Reload, and the restore sequence a page switch or an
// expired session runs.

const q = vi.hoisted(() => ({
  mint: vi.fn(),
  apply: vi.fn(),
  save: vi.fn(),
}))
vi.mock('@/queries/regions', () => ({
  mintRegionSession: q.mint,
  applyRegions: q.apply,
  saveRegions: q.save,
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

import { regionSchema, toDocument, toPayload, useRegionHost } from '@/pages/regions/useRegionHost'
import { useStageEditor, type StageEditor } from '@/editor/stage/useStageEditor'

const HEADER = [{ id: 'hdr000000001', type: 'logo', data: {}, settings: {} }]
const FOOTER = [{ id: 'ftr000000001', type: 'rich_text', data: { body: '<p>F</p>' }, settings: {} }]
const META: RegionData[] = [
  {
    slug: 'header',
    blocks: HEADER,
    settings: {},
    palette: ['logo', 'navigation', 'container'],
    settings_keys: ['sticky', 'width'],
    style_capabilities: [],
    lock_version: 3,
  },
  {
    slug: 'footer',
    blocks: FOOTER,
    settings: {},
    palette: ['rich_text', 'links'],
    settings_keys: ['width'],
    style_capabilities: [],
    lock_version: null,
  },
]

let sessions = 0
function session(overrides: Partial<RegionSession> = {}): RegionSession {
  sessions++
  return {
    token: `tok${sessions}`,
    themeUrl: `/_preview/tok${sessions}`,
    regions: {
      header: { blocks: structuredClone(HEADER), settings: { sticky: false }, lock_version: 3 },
      footer: { blocks: structuredClone(FOOTER), settings: {}, lock_version: null },
    },
    hidden: { header: false, footer: false },
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
  let host!: ReturnType<typeof useRegionHost>
  let editor!: StageEditor
  const wrapper = mount(
    defineComponent({
      setup() {
        host = useRegionHost({ regions: ref(META) })
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

/** An edit to the header's first block, as the inspector makes one. */
function editHeader(editor: StageEditor, label: string): void {
  editor.fields.value = {
    ...editor.fields.value,
    header: [{ id: 'hdr000000001', type: 'logo', data: { label }, settings: {} }],
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  sessions = 0
  revision = 0
  q.mint.mockReset().mockImplementation(async () => session())
  q.apply.mockReset().mockImplementation(async () => accepted())
  q.save.mockReset()
  notify.error.mockReset()
  notify.success.mockReset()
  localStorage.clear()
  localStorage.setItem('thallo.canvas.auto_apply', '0')
})

describe('the region document', () => {
  it('maps the server’s regions to one document and back', () => {
    const regions = {
      header: { blocks: HEADER, settings: { sticky: true } },
      footer: { blocks: FOOTER, settings: { width: 'full' } },
    }
    const doc = toDocument(regions)
    expect(doc).toEqual({
      header: HEADER,
      footer: FOOTER,
      _region_header: { sticky: true },
      _region_footer: { width: 'full' },
    })
    expect(toPayload(doc)).toEqual(regions)
  })

  it('the schema carries each region’s palette on its root field, the current region first', () => {
    expect(regionSchema(META)).toEqual([
      expect.objectContaining({
        name: 'header',
        type: 'blocks',
        blockTypes: ['logo', 'navigation', 'container'],
      }),
      expect.objectContaining({
        name: 'footer',
        type: 'blocks',
        blockTypes: ['rich_text', 'links'],
      }),
    ])
    expect(regionSchema(META, 'footer').map((f) => f.name)).toEqual(['footer', 'header'])
  })
})

describe('the save baseline and Save', () => {
  it('hydrates from the first session; a later mint never replaces the baseline', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    expect(editor.fields.value.header).toEqual(HEADER)
    expect(host.baseline.value).toEqual({ header: 3, footer: null })

    // A page switch mints a session whose baseline moved: the save baseline stays.
    q.mint.mockImplementationOnce(async () =>
      session({
        regions: {
          header: { blocks: [], settings: {}, lock_version: 9 },
          footer: { blocks: [], settings: {}, lock_version: 4 },
        },
      }),
    )
    await host.switchPage('pageb0000001')
    await flushPromises()
    expect(host.baseline.value).toEqual({ header: 3, footer: null })
    expect(editor.fields.value.header).toEqual(HEADER) // the document is kept too

    // A plain re-mint (the refresh affordance) keeps them as well.
    editHeader(editor, 'Kept')
    await flushPromises()
    q.mint.mockImplementationOnce(async () =>
      session({
        regions: {
          header: { blocks: [], settings: {}, lock_version: 11 },
          footer: { blocks: [], settings: {}, lock_version: 6 },
        },
      }),
    )
    await editor.remint()
    await flushPromises()
    expect(host.baseline.value).toEqual({ header: 3, footer: null })
    expect((editor.fields.value.header as { data: { label: string } }[])[0]!.data.label).toBe(
      'Kept',
    )
    unmount()
  })

  it('posts only dirty regions, both versions and the accepted pair; marks the submitted position saved', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    editHeader(editor, 'A')
    await flushPromises()
    await editor.applyWorking()
    await flushPromises()
    expect(editor.dirty.value).toBe(true)

    let finish!: (v: unknown) => void
    q.save.mockImplementationOnce(() => new Promise((resolve) => (finish = resolve)))
    const saving = host.save()
    await flushPromises()
    const body = q.save.mock.calls[0]![0]
    expect(Object.keys(body.regions)).toEqual(['header'])
    expect(body.expected).toEqual({ header: 3, footer: null })
    expect(body.token).toBe('tok1')
    expect(body.preview_revision).toEqual({ epoch: 'e1', revision: 1 })

    editHeader(editor, 'B') // while the save is in flight
    await flushPromises()
    finish({
      regions: {
        header: { blocks: [], settings: {}, lock_version: 4 },
        footer: { blocks: FOOTER, settings: {}, lock_version: null },
      },
      previewCleared: false,
    })
    await saving
    await flushPromises()
    expect(host.baseline.value).toEqual({ header: 4, footer: null })
    expect(editor.dirty.value).toBe(true) // B was not in that save
    unmount()
  })

  it('after a save, one undo is dirty again and the next Save sends the advanced versions', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    editHeader(editor, 'A')
    await flushPromises()
    q.save.mockResolvedValue({
      regions: {
        header: { blocks: [], settings: {}, lock_version: 4 },
        footer: { blocks: FOOTER, settings: {}, lock_version: null },
      },
      previewCleared: false,
    })
    await host.save()
    await flushPromises()
    expect(editor.dirty.value).toBe(false)

    await editor.undo()
    await flushPromises()
    expect(editor.dirty.value).toBe(true)
    await host.save()
    const body = q.save.mock.calls[1]![0]
    expect(Object.keys(body.regions)).toEqual(['header'])
    expect(body.regions.header.blocks).toEqual(HEADER)
    expect(body.expected).toEqual({ header: 4, footer: null })
    unmount()
  })

  it('a version conflict is shown; Reload discards the edits and loads the saved regions and versions', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    editHeader(editor, 'A')
    await flushPromises()
    q.save.mockRejectedValueOnce(
      new ApiError('moved', 409, {}, { error: { details: { code: 'REGION_VERSION_CONFLICT' } } }),
    )
    await host.save()
    expect(host.conflict.value).toBe(true)

    const theirs = [{ id: 'theirs000001', type: 'navigation', data: {}, settings: {} }]
    q.mint.mockImplementationOnce(async () =>
      session({
        regions: {
          header: { blocks: theirs, settings: {}, lock_version: 5 },
          footer: { blocks: FOOTER, settings: {}, lock_version: 2 },
        },
      }),
    )
    await host.reload()
    await flushPromises()
    expect(host.conflict.value).toBe(false)
    expect(editor.fields.value.header).toEqual(theirs)
    expect(host.baseline.value).toEqual({ header: 5, footer: 2 })
    expect(editor.dirty.value).toBe(false)
    expect(editor.historyState.value.canUndo).toBe(false)
    expect(editor.iframeSrc.value).toBe('/_preview/tok2?canvas=1')
    unmount()
  })
  it('a save that clears its session’s copy leaves a newer session’s pair alone', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    editHeader(editor, 'A')
    await flushPromises()
    let finish!: (v: unknown) => void
    q.save.mockImplementationOnce(() => new Promise((resolve) => (finish = resolve)))
    const saving = host.save()
    await flushPromises()
    await host.switchPage('pageb0000001') // a new session, with its own accepted pair
    await flushPromises()
    const pair = editor.accepted.value
    expect(pair).not.toBeNull()
    finish({
      regions: {
        header: { blocks: [], settings: {}, lock_version: 4 },
        footer: { blocks: FOOTER, settings: {}, lock_version: null },
      },
      previewCleared: true, // cleared the OLD session's copy
    })
    await saving
    expect(editor.accepted.value).toEqual(pair)
    unmount()
  })
})

describe('the restore sequence', () => {
  it('a page switch mints, applies the whole document with a null pair, then swaps; history and dirty kept', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    editHeader(editor, 'Edited')
    await flushPromises()
    editor.commitNow()

    let accept!: (v: unknown) => void
    q.apply.mockImplementationOnce(() => new Promise((resolve) => (accept = resolve)))
    const switching = host.switchPage('pageb0000001')
    await flushPromises()
    expect(q.mint).toHaveBeenLastCalledWith('pageb0000001')
    const [token, regions, options] = q.apply.mock.calls[0]!
    expect(token).toBe('tok2')
    expect(regions.header.blocks[0].data.label).toBe('Edited')
    expect(options).toEqual({ epoch: null, base_revision: null, operations: [] })
    expect(host.switching.value).toBe(true)
    expect(editor.iframeSrc.value).toBe('/_preview/tok1?canvas=1') // the old stage stays

    accept(accepted())
    await switching
    await flushPromises()
    expect(host.switching.value).toBe(false)
    expect(editor.iframeSrc.value).toBe('/_preview/tok2?canvas=1')
    expect(editor.accepted.value).toEqual({ epoch: 'e1', revision: 1 })
    expect(editor.dirty.value).toBe(true)
    expect(editor.historyState.value.canUndo).toBe(true)
    unmount()
  })

  it('a second switch abandons the first: the first’s responses are ignored', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    let firstMint!: (v: RegionSession) => void
    q.mint.mockImplementationOnce(() => new Promise((resolve) => (firstMint = resolve)))
    const first = host.switchPage('pagea0000001')
    await flushPromises()
    const second = host.switchPage('pageb0000001')
    await second
    await flushPromises()
    expect(editor.iframeSrc.value).toBe('/_preview/tok2?canvas=1')

    firstMint(session({ token: 'late', themeUrl: '/_preview/late' }))
    await first
    await flushPromises()
    expect(editor.iframeSrc.value).toBe('/_preview/tok2?canvas=1')
    expect(q.apply).toHaveBeenCalledTimes(1) // the abandoned one never applied
    expect(notify.error).not.toHaveBeenCalled()
    expect(host.switching.value).toBe(false)
    unmount()
  })

  it('an expired session renews through the editor: one restore, the swap after acceptance, edits made meanwhile applied after', async () => {
    const { editor, unmount } = mountHost()
    await flushPromises()
    editHeader(editor, 'A')
    await flushPromises()
    await editor.applyWorking()
    await flushPromises()
    expect(editor.accepted.value).toEqual({ epoch: 'e1', revision: 1 })

    editHeader(editor, 'B')
    await flushPromises()
    q.apply.mockRejectedValueOnce(new ApiError('expired', 410, {}, null))
    let accept!: (v: unknown) => void
    q.apply.mockImplementationOnce(() => new Promise((resolve) => (accept = resolve)))
    const run = editor.applyWorking()
    await flushPromises()
    expect(q.mint).toHaveBeenCalledTimes(2) // mount, then the restore
    expect(editor.iframeSrc.value).toBe('/_preview/tok1?canvas=1')

    editHeader(editor, 'C')
    await flushPromises()
    editor.commitNow()
    accept({ ...accepted(), epoch: 'e2', revision: 1 })
    await run
    await flushPromises()
    expect(editor.iframeSrc.value).toBe('/_preview/tok2?canvas=1')
    const [token, regions, options] = q.apply.mock.calls[q.apply.mock.calls.length - 1]!
    expect(token).toBe('tok2')
    expect(regions.header.blocks[0].data.label).toBe('C')
    expect(options.epoch).toBe('e2')
    unmount()
  })

  it('Reload during a switch ends the switch', async () => {
    const { host, unmount } = mountHost()
    await flushPromises()
    q.mint.mockImplementationOnce(() => new Promise(() => {})) // the switch's mint never answers
    void host.switchPage('pageb0000001')
    await flushPromises()
    expect(host.switching.value).toBe(true)
    await host.reload()
    await flushPromises()
    expect(host.switching.value).toBe(false)
    unmount()
  })

  it('a switch that fails leaves the picker on the page the stage still shows', async () => {
    const { host, editor, unmount } = mountHost()
    await flushPromises()
    q.mint.mockRejectedValueOnce(new ApiError('down', 500, {}, null))
    await host.switchPage('pageb0000001')
    await flushPromises()
    expect(host.page.value).toBeUndefined()
    expect(host.switching.value).toBe(false)
    expect(editor.iframeSrc.value).toBe('/_preview/tok1?canvas=1')
    unmount()
  })
})
