import { describe, expect, it } from 'vitest'
import { restoreOps, restoredFields } from '@/editor/restoreVersion'

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
