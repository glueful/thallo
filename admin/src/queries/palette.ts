import { authFetch } from '@/api/authFetch'
import { ApiError, apiErrorDetails } from '@/api/errors'
import { runtimeConfig } from '@/runtime/config'

// The palette's admin endpoints (custom palette spec §3, §4): where a brand colour is used, clearing
// and replacing it, the replace jobs, and the contrast of unsaved values. Not in the generated
// OpenAPI schema yet, so through authFetch; the 409s carry `usage` or `conflict` in error.details.

export type NeutralKey = 'bg' | 'surface' | 'surface_2' | 'ink' | 'muted' | 'line'
export type BrandKey = '1' | '2' | '3'

export interface ContrastRow {
  fg: string
  on: string
  mode: 'light' | 'dark'
  ratio: number
  passes: boolean
}

/** A pending look: the unsaved accent, neutral, ground and palette (absent reads the saved value). */
export interface PaletteLook {
  theme_accent?: string
  theme_neutral?: string
  theme_background?: string
  palette: {
    neutral_custom: Record<NeutralKey, string> | null
    dark_base: string | null
    brands: Record<BrandKey, { name: string; hex: string } | null>
  }
}

export interface PalettePreview {
  rows: ContrastRow[]
  swatches: Record<string, string>
  values: { light: Record<string, string>; dark: Record<string, string> }
}

export interface PaletteUsage {
  slot: number
  blocking: {
    entries: Array<{
      uuid: string
      title: string
      locale: string
      draft: boolean
      published: boolean
    }>
    regions: string[]
    layouts: Array<{ id: string; name: string }>
    saved_sections: Array<{ id: string; name: string }>
    style_classes: Array<{ id: string; name: string }>
    total: number
    contrast_references: boolean
  }
  historical: {
    entries: Array<{ uuid: string; title: string; locale: string; versions: number }>
    total: number
  }
}

export interface PaletteJob {
  id: string
  slot: number
  to: string
  contrast_to: string | null
  status: 'running' | 'interrupted' | 'failed' | 'completed' | 'cancelled'
  passes: number
  work_items_total: number
  work_items_done: number
  work_items_failed: number
  failure_report: Array<{ source: string; id: string; locale: string | null; reason: string }>
  created_at: string | null
  finished_at: string | null
}

/** The colour is still used: the dialog lists where and offers Replace with…. */
export class PaletteInUse extends Error {
  readonly usage: PaletteUsage
  constructor(usage: PaletteUsage) {
    super('The brand colour is still in use.')
    this.usage = usage
  }
}

/** A running replacement forbids the change (409). */
export class PaletteConflict extends Error {}

const base = () => `${runtimeConfig.apiBase}/appearance/palette`

/** Re-throws a 409 as what it means; anything else as it came. */
function conflictOf(e: unknown): never {
  const details = apiErrorDetails(e)
  if (e instanceof ApiError && e.status === 409) {
    if (details?.usage) throw new PaletteInUse(details.usage as PaletteUsage)
    throw new PaletteConflict(String(details?.conflict ?? e.message))
  }
  throw e
}

export async function previewPalette(look: PaletteLook): Promise<PalettePreview> {
  const json = await authFetch(`${base()}/preview`, { method: 'POST', body: JSON.stringify(look) })
  return (json as { data: PalettePreview }).data
}

export async function fetchPaletteUsage(slot: 1 | 2 | 3): Promise<PaletteUsage> {
  const json = await authFetch(`${base()}/brand/${slot}/usage`)
  return (json as { data: { usage: PaletteUsage } }).data.usage
}

export async function clearBrand(slot: 1 | 2 | 3): Promise<void> {
  try {
    await authFetch(`${base()}/brand/${slot}`, { method: 'DELETE' })
  } catch (e) {
    conflictOf(e)
  }
}

export async function replaceBrand(
  slot: 1 | 2 | 3,
  to: string,
  contrastTo?: string,
): Promise<PaletteJob> {
  try {
    const json = await authFetch(`${base()}/brand/${slot}/replace`, {
      method: 'POST',
      body: JSON.stringify(contrastTo === undefined ? { to } : { to, contrast_to: contrastTo }),
    })
    return (json as { data: { job: PaletteJob } }).data.job
  } catch (e) {
    conflictOf(e)
  }
}

export async function fetchPaletteJobs(): Promise<PaletteJob[]> {
  const json = await authFetch(`${base()}/jobs`)
  return (json as { data: { jobs: PaletteJob[] } }).data.jobs
}

export async function fetchPaletteJob(id: string): Promise<PaletteJob> {
  const json = await authFetch(`${base()}/jobs/${encodeURIComponent(id)}`)
  return (json as { data: { job: PaletteJob } }).data.job
}

export async function cancelPaletteJob(id: string): Promise<PaletteJob> {
  try {
    const json = await authFetch(`${base()}/jobs/${encodeURIComponent(id)}/cancel`, {
      method: 'POST',
    })
    return (json as { data: { job: PaletteJob } }).data.job
  } catch (e) {
    conflictOf(e)
  }
}

export async function resumePaletteJob(id: string): Promise<PaletteJob> {
  try {
    const json = await authFetch(`${base()}/jobs/${encodeURIComponent(id)}/resume`, {
      method: 'POST',
    })
    return (json as { data: { job: PaletteJob } }).data.job
  } catch (e) {
    conflictOf(e)
  }
}
