// The Appearance page's brand colour rows (custom palette spec §2.3, §5.1). The form keeps the
// rows as JSON (useSettingsForm's one string per key): each row its stable key, its id — null
// until the server gives a new colour one — its name and its hex. Save sends the whole list when it
// differs from the stored one; a new row joins once it is a name and a colour.
import { normalizeHex } from '@/style/contrast'
import type { BrandEntry } from '@/queries/palette'

export interface BrandRow {
  key: string
  id: number | null
  name: string
  hex: string
}
export interface StoredBrandColors {
  /** What a list edited from this one names as its base; every write moves it on. */
  revision: number
  colors: Array<{ id: number; name: string; hex: string }>
  removed: Array<{ id: number; name: string }>
}
/** A list to save, with the row behind each colour it sends, in order: how the response's ids are matched back. */
export interface Submission {
  json: string
  keys: string[]
}

const NAME_MAX = 32
let counter = 0

function parse(json: string | undefined): Record<string, unknown> | null {
  if (!json) return null
  try {
    const value: unknown = JSON.parse(json)
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : null
  } catch {
    return null
  }
}
const isId = (v: unknown): v is number =>
  Number.isInteger(v) && (v as number) >= 1 && (v as number) <= 9999

export function parseStored(json: string | undefined): StoredBrandColors {
  const data = parse(json)
  const list = (v: unknown) => (Array.isArray(v) ? (v as Array<Record<string, unknown>>) : [])
  const revision = data?.revision
  return {
    revision: Number.isInteger(revision) && (revision as number) >= 0 ? (revision as number) : 0,
    colors: list(data?.colors)
      .filter((c) => isId(c.id) && typeof c.name === 'string' && typeof c.hex === 'string')
      .map((c) => ({ id: c.id as number, name: c.name as string, hex: c.hex as string })),
    removed: list(data?.removed)
      .filter((r) => isId(r.id) && typeof r.name === 'string')
      .map((r) => ({ id: r.id as number, name: r.name as string })),
  }
}

/** The form's rows: stored colours (key `b<id>`) or draft rows carrying their own key. */
export function parseDraft(json: string | undefined): BrandRow[] {
  const data = parse(json)
  if (!Array.isArray(data?.colors)) return []
  return (data.colors as Array<Record<string, unknown>>).map((c) => ({
    key: typeof c.key === 'string' ? c.key : `b${String(c.id)}`,
    id: isId(c.id) ? c.id : null,
    name: typeof c.name === 'string' ? c.name : '',
    hex: typeof c.hex === 'string' ? c.hex : '',
  }))
}

export function serializeDraft(rows: BrandRow[]): string {
  return JSON.stringify({ colors: rows })
}

export function newRow(): BrandRow {
  counter += 1
  return { key: `n${counter}`, id: null, name: '', hex: '' }
}

/** A row as the server would store it, or null while it is not a name and a colour. */
export function validRow(row: BrandRow): { name: string; hex: string } | null {
  const name = row.name.trim()
  const hex = normalizeHex(row.hex)
  return name !== '' && name.length <= NAME_MAX && hex !== null ? { name, hex } : null
}

/** The newer of two readings of the stored list (for numbering new rows only: never a base). */
export function newer(a: StoredBrandColors, b: StoredBrandColors): StoredBrandColors {
  return b.revision > a.revision ? b : a
}

/**
 * What Save sends for the rows, or null for nothing: the list and the revision it was edited from.
 * A saved colour mid-edit goes as it was saved, an unfinished new row stays out, and an unchanged
 * list is not sent at all.
 */
export function submission(rows: BrandRow[], stored: StoredBrandColors): Submission | null {
  const saved = new Map(stored.colors.map((c) => [c.id, c]))
  const colors: Array<{ id?: number; name: string; hex: string }> = []
  const keys: string[] = []
  for (const row of rows) {
    const valid = validRow(row)
    if (row.id === null) {
      if (valid) {
        colors.push(valid)
        keys.push(row.key)
      }
      continue
    }
    const kept = valid ?? saved.get(row.id)
    if (kept) {
      colors.push({ id: row.id, name: kept.name, hex: kept.hex })
      keys.push(row.key)
    }
  }
  const same =
    colors.length === stored.colors.length &&
    colors.every((c, i) => {
      const s = stored.colors[i]!
      return c.id === s.id && c.name === s.name && c.hex === s.hex
    })
  return same ? null : { json: JSON.stringify({ base: stored.revision, colors }), keys }
}

/**
 * The rows with the ids a save gave its new colours, read from the save's own response: the
 * response lists the colours in the order they were sent, so the row behind the i-th sent colour
 * takes the i-th id. Edits made since stay; a response that does not line up adopts nothing (the
 * refetch then decides).
 */
export function adoptIds(
  rows: BrandRow[],
  sentKeys: string[],
  saved: StoredBrandColors,
): BrandRow[] {
  if (saved.colors.length !== sentKeys.length) return rows
  const ids = new Map(sentKeys.map((key, i) => [key, saved.colors[i]!.id]))
  return rows.map((row) =>
    row.id === null && ids.has(row.key) ? { ...row, id: ids.get(row.key)! } : row,
  )
}

/** The rows without colours that left the stored list (a Clear, a replacement completing); new rows stay. */
export function dropRemoved(rows: BrandRow[], stored: StoredBrandColors): BrandRow[] {
  const live = new Set(stored.colors.map((c) => c.id))
  return rows.filter((row) => row.id === null || live.has(row.id))
}

/**
 * The rows hold no brand edit: the same colours, in the same order, as `stored` (keys aside), each
 * read as the server stores it — a name typed with a trailing space, or a hex in capitals, is the
 * colour the server saved.
 */
export function matches(rows: BrandRow[], stored: StoredBrandColors): boolean {
  return (
    rows.length === stored.colors.length &&
    rows.every((row, i) => {
      const c = stored.colors[i]!
      const as = validRow(row) ?? row
      return row.id === c.id && as.name === c.name && as.hex === c.hex
    })
  )
}

/**
 * The rows with every colour a save committed: one whose row was removed while the save was in
 * flight is saved now, so it comes back at its place, under the row's key — a saved colour leaves
 * only through Clear.
 */
export function keepCommitted(
  rows: BrandRow[],
  sentKeys: string[],
  committed: StoredBrandColors,
): BrandRow[] {
  const out = [...rows]
  const present = new Set(rows.map((r) => r.id))
  committed.colors.forEach((c, i) => {
    if (present.has(c.id)) return
    const row = { key: sentKeys[i] ?? `b${c.id}`, id: c.id, name: c.name, hex: c.hex }
    out.splice(Math.min(i, out.length), 0, row)
  })
  return out
}

/** The pending list for the preview: new finished rows numbered above every id seen (never stored). */
export function previewBrands(rows: BrandRow[], stored: StoredBrandColors): BrandEntry[] {
  let next = Math.max(
    0,
    ...stored.colors.map((c) => c.id),
    ...stored.removed.map((r) => r.id),
    ...rows.map((r) => r.id ?? 0),
  )
  const out: BrandEntry[] = []
  for (const row of rows) {
    const valid = validRow(row)
    if (valid) out.push({ id: row.id ?? ++next, ...valid })
  }
  return out
}
