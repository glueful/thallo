import { useMutation } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { runtimeConfig } from '@/runtime/config'

// Mints a short-lived preview token for the entry's current draft (in the given locale).
export async function mintPreview(uuid: string, locale: string): Promise<string> {
  const { data, error, response } = await client.POST('/entries/{uuid}/preview/{locale}', {
    params: { path: { uuid, locale } },
  })
  if (error) throw toApiError(error, response)
  return data?.data?.token ?? ''
}

// Builds the frontend preview URL from the configured template + the minted token.
export function buildPreviewUrl(token: string): string {
  const base = runtimeConfig.sitePreviewUrl
  if (!base || !token) return ''
  const sep = base.includes('?') ? '&' : '?'
  return `${base}${sep}token=${encodeURIComponent(token)}`
}

export function usePreview(uuid: string, locale: string) {
  return useMutation({
    mutation: () => mintPreview(uuid, locale),
  })
}

/** The accepted working-copy pair (visual builder spec §3.5). */
export interface RevisionPair {
  epoch: string
  revision: number
}

export interface PreviewMintResult {
  token: string
  themeUrl: string | null
  /** The pair already accepted for this entry+locale, so a second editor starts from it. */
  accepted: RevisionPair | null
}

// Mints a preview token; theme_url is server-decided (null = rendered delivery off).
export async function mintPreviewData(uuid: string, locale: string): Promise<PreviewMintResult> {
  const { data, error, response } = await client.POST('/entries/{uuid}/preview/{locale}', {
    params: { path: { uuid, locale } },
  })
  if (error) throw toApiError(error, response)
  const epoch = data?.data?.epoch
  const revision = data?.data?.revision
  return {
    token: data?.data?.token ?? '',
    themeUrl: data?.data?.theme_url ?? null,
    accepted:
      typeof epoch === 'string' && typeof revision === 'number' ? { epoch, revision } : null,
  }
}

export function useThemePreview(uuid: string, locale: string) {
  return useMutation({
    mutation: () => mintPreviewData(uuid, locale),
  })
}

export interface ApplyPreviewOptions {
  /** The pair the client last accepted; both null before its first apply. */
  epoch: string | null
  base_revision: number | null
  /** The committed operations since `base_revision` (intent for the fragment path). */
  operations: unknown[]
}

export interface ApplyPreviewResult extends RevisionPair {
  /** The revision the stage showed before this one: what an in-place patch expects. */
  baseline: number
  style_generation: number
  applied_at: string
}

// Apply the CURRENT working fields as the next working-copy revision (visual builder spec
// §3.5) — nothing persisted; the stage's /_preview/{token} URL then renders the accepted copy.
// A stale pair is a 409 PREVIEW_REVISION_STALE carrying the current pair.
export async function applyPreview(
  uuid: string,
  locale: string,
  token: string,
  fields: Record<string, unknown>,
  options: ApplyPreviewOptions,
): Promise<ApplyPreviewResult> {
  const { data, error, response } = await client.POST('/entries/{uuid}/preview/{locale}/apply', {
    params: { path: { uuid, locale } },
    // The spec types `fields` as unknown[]; the backend expects a keyed object —
    // cast through (same convention as drafts.ts saveDraft).
    body: {
      token,
      fields: fields as unknown as unknown[],
      epoch: options.epoch,
      base_revision: options.base_revision,
      operations: options.operations as unknown as never,
    },
  })
  if (error) throw toApiError(error, response)
  const d = (data?.data ?? {}) as Partial<ApplyPreviewResult>
  return {
    epoch: String(d.epoch ?? ''),
    revision: Number(d.revision ?? 0),
    baseline: Number(d.baseline ?? 0),
    style_generation: Number(d.style_generation ?? 0),
    applied_at: String(d.applied_at ?? ''),
  }
}
