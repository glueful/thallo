import { useQuery } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { ApplyPreviewOptions, ApplyPreviewResult, RevisionPair } from '@/editor/stage/types'

// Type layouts (type layouts spec §5.2–§5.5, §6.1): the Layouts page's rows, a layout's samples, its
// editing sessions and applies, and Save and Remove. The endpoints declare no response schemas, so
// every payload is read defensively here, once.

/** One row of the Layouts page: a page kind that can have a layout, and whether it has one. */
export interface LayoutRow {
  surface: string
  target: string
  /** "Posts — single post" */
  label: string
  /** "Applies to every post" */
  reach: string
  state: 'theme' | 'custom'
  enabled: boolean
  /** Why a target cannot have a layout, when it cannot. */
  reason: string | null
  lock_version: number
  updated_by: string | null
  updated_at: string | null
}

/** A layout as the editor edits it. */
export interface LayoutContent {
  blocks: BlockInstance[]
  /** The Frame options: width, header, footer. */
  settings: Record<string, unknown>
}

export interface LayoutSample {
  id: string
  label: string
}

export interface LayoutSession {
  token: string
  /** Null when rendered delivery is off. */
  themeUrl: string | null
  /** The session's baseline: the saved layout, or the starter when there is none. */
  layout: LayoutContent & { lock_version: number }
  /** The baseline is the starter, not a saved layout. */
  starter: boolean
  /** The starter itself, whatever the baseline: what Reset to starter puts back. */
  starterLayout: BlockInstance[]
  /** Blocks the layout must hold exactly once. */
  required: { type: string; field?: string }[]
  /** The field blocks this surface adds to the general blocks. */
  palette: string[]
  sample: LayoutSample | null
  /** No published item to preview against: the stage shows a placeholder. */
  placeholder: boolean
  label: string
  reach: string
}

function record(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

const str = (value: unknown): string => (typeof value === 'string' ? value : '')
const strOrNull = (value: unknown): string | null => (typeof value === 'string' ? value : null)

function content(raw: unknown): LayoutContent {
  const r = record(raw)
  return {
    blocks: Array.isArray(r.blocks) ? (r.blocks as BlockInstance[]) : [],
    settings: record(r.settings),
  }
}

function dataOf(data: unknown): Record<string, unknown> {
  return record((data as { data?: unknown } | undefined)?.data)
}

const qk = () => ['layouts'] as const

export async function fetchLayouts(): Promise<LayoutRow[]> {
  const { data, error, response } = await client.GET('/layouts')
  if (error) throw toApiError(error, response)
  const rows = dataOf(data).layouts
  return (Array.isArray(rows) ? rows : []).map((raw) => {
    const r = record(raw)
    return {
      surface: str(r.surface),
      target: str(r.target),
      label: str(r.label),
      reach: str(r.reach),
      state: r.state === 'custom' ? 'custom' : 'theme',
      enabled: r.enabled !== false,
      reason: strOrNull(r.reason),
      lock_version: typeof r.lock_version === 'number' ? r.lock_version : 0,
      updated_by: strOrNull(r.updated_by),
      updated_at: strOrNull(r.updated_at),
    }
  })
}

export function useLayouts() {
  return useQuery({ key: qk(), query: fetchLayouts })
}

/** The published items a layout can be previewed against, newest first; `q` filters by name. */
export async function fetchLayoutSamples(
  surface: string,
  target: string,
  q?: string,
): Promise<{ samples: LayoutSample[]; default: string | null }> {
  const { data, error, response } = await client.GET('/layouts/{surface}/{target}/samples', {
    params: { path: { surface, target }, query: (q ? { q } : {}) as never },
  })
  if (error) throw toApiError(error, response)
  const d = dataOf(data)
  const samples = (Array.isArray(d.samples) ? d.samples : []).map((raw) => {
    const r = record(raw)
    return { id: str(r.id), label: str(r.label) }
  })
  return { samples, default: strOrNull(d.default) }
}

/**
 * Open an editing session (spec §5.2): the saved layout — or the starter — pinned as its baseline,
 * rendered against `sample` when it is published, else the newest item, else a placeholder.
 */
export async function mintLayoutSession(
  surface: string,
  target: string,
  sample?: string,
): Promise<LayoutSession> {
  const { data, error, response } = await client.POST('/layouts/preview/session', {
    body: { surface, target, ...(sample === undefined ? {} : { sample }) } as never,
  })
  if (error) throw toApiError(error, response)
  const d = dataOf(data)
  const layout = record(d.layout)
  const sampleRaw = record(d.sample)
  return {
    token: str(d.token),
    themeUrl: strOrNull(d.theme_url),
    layout: {
      ...content(layout),
      lock_version: typeof layout.lock_version === 'number' ? layout.lock_version : 0,
    },
    starter: d.starter === true,
    starterLayout: Array.isArray(d.starter_layout) ? (d.starter_layout as BlockInstance[]) : [],
    required: (Array.isArray(d.required) ? d.required : []).map((raw) => {
      const r = record(raw)
      return typeof r.field === 'string'
        ? { type: str(r.type), field: r.field }
        : { type: str(r.type) }
    }),
    palette: (Array.isArray(d.palette) ? d.palette : []).filter(
      (s): s is string => typeof s === 'string',
    ),
    sample:
      typeof sampleRaw.id === 'string' ? { id: sampleRaw.id, label: str(sampleRaw.label) } : null,
    placeholder: d.placeholder === true,
    label: str(d.label),
    reach: str(d.reach),
  }
}

/**
 * Apply the layout to its session as the next working-copy revision (spec §5.3) — validated as a
 * save would be, never written. A stale pair is a 409 PREVIEW_REVISION_STALE; a session whose records
 * are gone a 410 LAYOUT_SESSION_EXPIRED; a removed layout's session a 410 LAYOUT_SESSION_RETIRED.
 */
export async function applyLayout(
  token: string,
  layout: LayoutContent,
  options: ApplyPreviewOptions,
): Promise<ApplyPreviewResult> {
  const { data, error, response } = await client.POST('/layouts/preview/apply', {
    body: {
      token,
      layout,
      epoch: options.epoch,
      base_revision: options.base_revision,
      operations: options.operations,
    } as never,
  })
  if (error) throw toApiError(error, response)
  const d = dataOf(data)
  return {
    epoch: String(d.epoch ?? ''),
    revision: Number(d.revision ?? 0),
    baseline: Number(d.baseline ?? 0),
    style_generation: Number(d.style_generation ?? 0),
    applied_at: String(d.applied_at ?? ''),
    // Every accepted apply refreshes the layout stage whole.
    fragments: null,
  }
}

export interface SaveLayoutResult {
  layout: LayoutContent & { lock_version: number }
  /** Whether the save cleared the session's working copy (the accepted pair matched). */
  previewCleared: boolean
}

/**
 * Save (spec §5.5): the layout, the version the editor loaded (a moved one answers 409
 * LAYOUT_VERSION_CONFLICT) and the accepted pair the save was made from.
 */
export async function saveLayout(
  surface: string,
  target: string,
  body: {
    token: string
    layout: LayoutContent
    expected_lock_version: number
    preview_revision: RevisionPair | null
  },
): Promise<SaveLayoutResult> {
  const { data, error, response } = await client.PUT('/layouts/{surface}/{target}', {
    params: { path: { surface, target } },
    body: body as never,
  })
  if (error) throw toApiError(error, response)
  const d = dataOf(data)
  const layout = record(d.layout)
  return {
    layout: {
      ...content(layout),
      lock_version: typeof layout.lock_version === 'number' ? layout.lock_version : 0,
    },
    previewCleared: d.preview_cleared === true,
  }
}

/** Remove (spec §5.5): the pages go back to the theme's template; the session ends. */
export async function removeLayout(
  surface: string,
  target: string,
  body: { token: string; expected_lock_version: number },
): Promise<{ lockVersion: number }> {
  const { data, error, response } = await client.DELETE('/layouts/{surface}/{target}', {
    params: { path: { surface, target } },
    body: body as never,
  })
  if (error) throw toApiError(error, response)
  const d = dataOf(data)
  return { lockVersion: typeof d.lock_version === 'number' ? d.lock_version : 0 }
}
