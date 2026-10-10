// The Appearance page's brand colour rows (custom palette spec §2.3, §5.1): the stored list read
// into rows, what Save sends, and the list a preview frames.
import { describe, it, expect } from 'vitest'
import {
  adoptIds,
  dropRemoved,
  matches,
  newer,
  newRow,
  parseDraft,
  parseStored,
  previewBrands,
  serializeDraft,
  submission,
} from '@/pages/appearance/brandColors'

const STORED =
  '{"revision":3,"colors":[{"id":4,"name":"Gold","hex":"#8a6a2a"},{"id":1,"name":"Rose","hex":"#c98a8a"}],' +
  '"removed":[{"id":7,"name":"Teal"}]}'

describe('brand colour rows', () => {
  it('reads the stored list into rows in order, and ignores junk', () => {
    expect(parseDraft(STORED).map((r) => [r.id, r.name])).toEqual([
      [4, 'Gold'],
      [1, 'Rose'],
    ])
    expect(parseStored('nope')).toEqual({ revision: 0, colors: [], removed: [] })
    expect(parseStored(STORED).revision).toBe(3)
    expect(parseDraft('')).toEqual([])
  })

  it('keeps a row’s key across a round trip, so a drag or an edit never remounts it', () => {
    const rows = [...parseDraft(STORED), newRow()]
    expect(parseDraft(serializeDraft(rows)).map((r) => r.key)).toEqual(rows.map((r) => r.key))
  })

  it('sends nothing when nothing changed, and the whole list when something did', () => {
    const stored = parseStored(STORED)
    expect(submission(parseDraft(STORED), stored)).toBeNull()
    const moved = parseDraft(STORED).reverse()
    expect(JSON.parse(submission(moved, stored)!.json)).toEqual({
      base: 3,
      colors: [
        { id: 1, name: 'Rose', hex: '#c98a8a' },
        { id: 4, name: 'Gold', hex: '#8a6a2a' },
      ],
    })
  })

  it('sends a new row without an id once it is a name and a colour, and leaves an unfinished one out', () => {
    const stored = parseStored(STORED)
    const rows = [...parseDraft(STORED), { ...newRow(), name: 'Sky', hex: '#38BDF8' }, newRow()]
    const sent = submission(rows, stored)!
    expect(JSON.parse(sent.json).colors.at(-1)).toEqual({ name: 'Sky', hex: '#38bdf8' })
    expect(JSON.parse(sent.json).colors).toHaveLength(3)
    expect(sent.keys).toEqual([rows[0]!.key, rows[1]!.key, rows[2]!.key])
  })

  it('takes the ids a save gave from its response, keeping edits made since', () => {
    const stored = parseStored(STORED)
    const sky = { ...newRow(), name: 'Sky', hex: '#38bdf8' }
    const rows = [...parseDraft(STORED), sky]
    const sent = submission(rows, stored)!
    const saved = parseStored(
      '{"revision":4,"colors":[{"id":4,"name":"Gold","hex":"#8a6a2a"},{"id":1,"name":"Rose","hex":"#c98a8a"},' +
        '{"id":8,"name":"Sky","hex":"#38bdf8"}],"removed":[{"id":7,"name":"Teal"}]}',
    )
    // edited while the save was in flight: Sky renamed, and another row added
    const now = [...rows.slice(0, 2), { ...sky, name: 'Sky blue' }, newRow()]
    const adopted = adoptIds(now, sent.keys, saved)
    expect(adopted.map((r) => [r.id, r.name])).toEqual([
      [4, 'Gold'],
      [1, 'Rose'],
      [8, 'Sky blue'],
      [null, ''],
    ])
    expect(adopted[2]!.key).toBe(sky.key) // never remounted
    // the next save edits from the saved revision and names Sky by its id
    expect(JSON.parse(submission(adopted, saved)!.json)).toMatchObject({
      base: 4,
      colors: [{ id: 4 }, { id: 1 }, { id: 8, name: 'Sky blue' }],
    })
  })

  it('adopts nothing when the response does not line up with what was sent', () => {
    const stored = parseStored(STORED)
    const rows = [...parseDraft(STORED), { ...newRow(), name: 'Sky', hex: '#38bdf8' }]
    const sent = submission(rows, stored)!
    expect(adoptIds(rows, sent.keys, stored)).toEqual(rows)
  })

  it('drops a colour that left the stored list, and keeps new rows', () => {
    const cleared = parseStored(
      '{"revision":4,"colors":[{"id":4,"name":"Gold","hex":"#8a6a2a"}],"removed":[{"id":1,"name":"Rose"},{"id":7,"name":"Teal"}]}',
    )
    const rows = [...parseDraft(STORED), newRow()]
    expect(dropRemoved(rows, cleared).map((r) => r.id)).toEqual([4, null])
  })

  it('tells rows that hold no brand edit from rows that do', () => {
    const stored = parseStored(STORED)
    expect(matches(parseDraft(STORED), stored)).toBe(true)
    const renamed = parseDraft(STORED)
    renamed[1]!.name = 'Blush'
    expect(matches(renamed, stored)).toBe(false)
    expect(matches([...parseDraft(STORED), newRow()], stored)).toBe(false) // an unfinished new row is an edit
    expect(matches(parseDraft(STORED).reverse(), stored)).toBe(false)
  })

  it('prefers the newer of two readings of the stored list', () => {
    const older = parseStored(STORED)
    const later = { ...older, revision: 4 }
    expect(newer(older, later)).toBe(later)
    expect(newer(later, older)).toBe(later)
  })

  it('keeps a saved colour whose fields are mid-edit as it was saved', () => {
    const stored = parseStored(STORED)
    const rows = parseDraft(STORED)
    rows[0]!.name = ''
    expect(submission(rows, stored)).toBeNull()
  })

  it('numbers new rows above every id seen, for the preview only', () => {
    const stored = parseStored(STORED)
    const rows = [...parseDraft(STORED), { ...newRow(), name: 'Sky', hex: '#38bdf8' }]
    expect(previewBrands(rows, stored).map((b) => b.id)).toEqual([4, 1, 8])
  })
})
