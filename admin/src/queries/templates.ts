import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

// Templates admin API (glueful/thallo-render pack, /v1/admin/render/templates/*).
// Untyped query params + slash-spanning {path} segments, so this rides on authFetch
// with hand-built URLs like queries/navigation.ts. Template paths are server-pinned to
// slash-separated [A-Za-z0-9._-]+ segments (DB-edited templates spec §5), so embedding
// them raw in the URL path is safe and deterministic.

export interface TemplateRow {
  path: string
  origin: 'db' | 'theme' | 'package' | 'default'
  overridden: boolean
  updated_at: string | null
  /** Browsable theme file (assets/theme.json) — viewable, never editable. */
  readonly?: boolean
}

export interface TemplateDetail {
  path: string
  theme: string
  origin: 'db' | 'theme' | 'package' | 'default'
  source: string
  version_uuid: string | null
  readonly?: boolean
  /** Present only for the pinned disk-only rows (blocks/html.twig, blocks/shortcode.twig). */
  readonly_reason?: string
}

export interface TemplateVersion {
  uuid: string
  created_by: string | null
  created_at: string
  current: boolean
}

/** A save/restore 422 body: `errors` is the linter's {line, message} list. */
export interface PolicyViolation {
  line: number
  message: string
}

const base = () => `${runtimeConfig.apiBase}/render/templates`

const themeQs = (theme: string) => (theme === '' ? '' : `?${new URLSearchParams({ theme })}`)

export async function fetchTemplates(
  theme = '',
): Promise<{ theme: string; themes: string[]; templates: TemplateRow[] }> {
  const json = await authFetch(`${base()}${themeQs(theme)}`)
  return (json.data ?? json) as { theme: string; themes: string[]; templates: TemplateRow[] }
}

export async function fetchTemplate(path: string, theme = ''): Promise<TemplateDetail> {
  const json = await authFetch(`${base()}/${path}${themeQs(theme)}`)
  return (json.data ?? json) as TemplateDetail
}

/** Throws ApiError; a 422's `body.errors` carries the PolicyViolation list. */
export async function saveTemplate(
  path: string,
  source: string,
  theme = '',
): Promise<{ version_uuid: string }> {
  const json = await authFetch(`${base()}/${path}${themeQs(theme)}`, {
    method: 'PUT',
    body: JSON.stringify({ source }),
  })
  return (json.data ?? json) as { version_uuid: string }
}

export async function deleteTemplate(path: string, theme = ''): Promise<void> {
  await authFetch(`${base()}/${path}${themeQs(theme)}`, { method: 'DELETE' })
}

export async function fetchVersions(path: string, theme = ''): Promise<TemplateVersion[]> {
  const json = await authFetch(`${base()}/${path}/versions${themeQs(theme)}`)
  return ((json.data ?? json) as { versions: TemplateVersion[] }).versions
}

export async function fetchVersion(
  path: string,
  uuid: string,
  theme = '',
): Promise<{ source: string }> {
  const json = await authFetch(`${base()}/${path}/versions/${uuid}${themeQs(theme)}`)
  return (json.data ?? json) as { source: string }
}

/** Throws ApiError; a 422's `body.errors` = the version fails TODAY'S policy. */
export async function restoreVersion(
  path: string,
  uuid: string,
  theme = '',
): Promise<{ version_uuid: string }> {
  const json = await authFetch(`${base()}/${path}/versions/${uuid}/restore${themeQs(theme)}`, {
    method: 'POST',
  })
  return (json.data ?? json) as { version_uuid: string }
}

/** Selectable themes + the active one (feeds the Settings → General Theme card). */
export async function fetchRenderThemes(): Promise<{ themes: string[]; active: string }> {
  const json = await authFetch(`${runtimeConfig.apiBase}/render/themes`)
  return (json.data ?? json) as { themes: string[]; active: string }
}

/**
 * Clone a theme into themes/{name}/ (server-side copy; 422 carries the reason
 * — bad name, name taken, or an unwritable themes directory).
 */
export async function cloneTheme(
  name: string,
  from = 'default',
): Promise<{ theme: string; themes: string[] }> {
  const json = await authFetch(`${runtimeConfig.apiBase}/render/themes`, {
    method: 'POST',
    body: JSON.stringify({ name, from }),
  })
  return (json.data ?? json) as { theme: string; themes: string[] }
}

/** Extract the linter violations from a thrown ApiError body (422s), else []. */
export function violationsFrom(err: unknown): PolicyViolation[] {
  const body = (err as { body?: unknown } | null)?.body
  const errors = (body as { errors?: unknown } | null)?.errors
  return Array.isArray(errors) ? (errors as PolicyViolation[]) : []
}
