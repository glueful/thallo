import { describe, expect, it, vi } from 'vitest'
import { createEditorHistory } from '@/editor/ops/history'
import { present } from '@/editor/ops/types'
import {
  createPaletteReconciler,
  createReplacementLedger,
  type ReplacementRecord,
} from '@/editor/paletteReplacements'
import { restoreOps, restoredFields, restoreVersionThroughServer } from '@/editor/restoreVersion'

// Restoring a version into the draft: the draft takes the version's fields, all of them, except
// the settings-schema stamp — that describes the draft's own representation, not its content.
const schema = { settings: 10, conversions: ['a'] }
const current = {
  _schema: schema,
  title: 'Edited',
  body: [{ id: 'b1', type: 'heading', data: { text: 'New' }, settings: {} }],
  subtitle: 'Only in the draft',
}
const version = {
  _schema: { settings: 9, conversions: [] },
  title: 'Original',
  body: [{ id: 'b0', type: 'heading', data: { text: 'Old' }, settings: {} }],
}

describe('restoredFields', () => {
  it('is the version, with the draft’s own schema stamp', () => {
    expect(restoredFields(current, version)).toEqual({
      _schema: schema,
      title: 'Original',
      body: version.body,
    })
  })
})

describe('restoreOps', () => {
  it('sets each field that differs, and removes one the version did not have', () => {
    const ops = restoreOps(current, version)
    expect(ops).toEqual([
      {
        type: 'SetPageSettings',
        field: 'title',
        from: { present: true, value: 'Edited' },
        to: { present: true, value: 'Original' },
      },
      {
        type: 'SetPageSettings',
        field: 'body',
        from: { present: true, value: current.body },
        to: { present: true, value: version.body },
      },
      {
        type: 'SetPageSettings',
        field: 'subtitle',
        from: { present: true, value: 'Only in the draft' },
        to: { present: false },
      },
    ])
  })

  it('is nothing when the draft already matches the version', () => {
    expect(restoreOps(restoredFields(current, version), version)).toEqual([])
  })
})

// Restore to draft on the server (custom palette spec §4.5): the page names the version; the
// response's records reach existing history first, the ledger moves to the restore's generation,
// and the restore lands as one undoable transaction already persisted — undo and redo are then
// ordinary edits, saved as ordinary saves.
describe('restoreVersionThroughServer', () => {
  const tok = (v: string) => ({ type: 'token' as const, value: v })
  const heading = (id: string, colour: string, text = 'x') => ({
    id,
    type: 'heading',
    data: { text },
    settings: { style: { colors: { text: tok(colour) } } },
  })
  const record: ReplacementRecord = {
    id: 'jobA',
    slot: 1,
    map: { 'color.brand-1': 'color.accent' },
    completed_generation: 3,
  }

  function setup() {
    const history = createEditorHistory(
      { fields: { title: 'Now', body: [heading('a', 'color.text', 'Now')] } },
      { session: 's1', regionsOf: () => [], blockFields: () => ['body'] },
    )
    // an unsaved colour edit made before the job completed
    history.record({
      type: 'SetSetting',
      block: 'a',
      path: 'colors.text',
      breakpoint: null,
      from: present(tok('color.text') as never),
      to: present(tok('color.brand-1') as never),
    })
    history.commit()
    const ledger = createReplacementLedger(2)
    const reconciler = createPaletteReconciler({
      history,
      currentFields: () => history.document.fields,
      replaceFields: (next) => history.adopt({ ...history.document, fields: next }),
      ledger,
      fetchRange: async () => Promise.reject(new Error('no gap fetch expected')),
    })
    return { history, ledger, reconciler }
  }

  it('asks the server with the ledger boundary, reconciles history first, installs one persisted transaction', async () => {
    const { history, ledger, reconciler } = setup()
    const restored = { title: 'Then', body: [heading('a', 'color.brand-1', 'Then')] }
    const request = vi.fn(async (_through: number) => ({
      draft: { fields: restored, lock_version: 7 },
      palette_generation: 4,
      palette_replacements: { after: 2, through: 4, records: [record] },
    }))
    const res = await restoreVersionThroughServer({
      current: () => history.document.fields,
      request,
      paletteThrough: () => ledger.through,
      restoreFromResponse: reconciler.restoreFromResponse,
      applyOps: async (ops) => {
        history.beginTransaction()
        for (const op of ops) history.record(op)
        history.commit()
      },
      markPersisted: () => history.markSaved(),
    })
    expect(request).toHaveBeenCalledWith(2)
    expect(res.draft.lock_version).toBe(7)
    expect(ledger.through).toBe(4)
    const fields = history.document.fields as { title: string; body: ReturnType<typeof heading>[] }
    expect(fields.title).toBe('Then')
    expect(fields.body[0]!.settings.style.colors.text.value).toBe('color.brand-1') // restored at 4: not mapped
    const entries = history.entries()
    expect(entries[entries.length - 1]!.ops.every((op) => op.type === 'SetPageSettings')).toBe(true)
    expect(history.isDirty).toBe(false) // the server has it
    history.undo()
    const before = history.document.fields as { title: string; body: ReturnType<typeof heading>[] }
    expect(before.title).toBe('Now')
    expect(before.body[0]!.settings.style.colors.text.value).toBe('color.accent') // reconciled history
    expect(history.isDirty).toBe(true) // an ordinary save takes it
  })

  it('installs nothing when the palette history expired on the way', async () => {
    const { history, ledger, reconciler } = setup()
    const res = await restoreVersionThroughServer({
      current: () => history.document.fields,
      request: async () => ({
        draft: { fields: { title: 'Then' }, lock_version: 7 },
        palette_generation: 4,
        palette_replacements: { expired: true as const },
      }),
      paletteThrough: () => ledger.through,
      restoreFromResponse: reconciler.restoreFromResponse,
      applyOps: async () => {
        throw new Error('must not install')
      },
      markPersisted: () => history.markSaved(),
    })
    expect(res.draft.lock_version).toBe(7)
    expect(ledger.expired).toBe(true)
    expect((history.document.fields as { title: string }).title).toBe('Now')
  })
})
