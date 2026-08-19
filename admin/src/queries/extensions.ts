import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { reactive, toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'
import { useCapabilitiesStore } from '@/stores/capabilities'

// Extensions admin API (App\Http\Controllers\ExtensionAdminController, under /v1/admin/extensions).
// Installed data is local (PackageManifest + the enabled allow-list); Browse proxies Packagist
// filtered to type=glueful-extension. Enable/disable rewrites config/extensions.php (dev only).

export type SchemaState = 'ready' | 'pending' | 'divergent' | 'none' | 'undeclared'

export interface InstalledExtension {
  name: string
  provider: string
  version?: string | null
  description?: string | null
  author?: string | null
  requires_extensions: string[]
  enabled: boolean
  /** Closed aggregation over the package's migration descriptors (spec B6). */
  schema_state: SchemaState
  /** Per-source explanations when the state is not ready (or authoring guidance). */
  schema_reasons: string[]
  /** The CLI equivalent an operator can run for this row's state. */
  cli_command: string | null
}

/** Chip color for a schema state (ready is calm, divergent demands attention). */
export function schemaChipColor(state: SchemaState): 'success' | 'warning' | 'error' | 'neutral' {
  if (state === 'divergent') return 'error'
  if (state === 'pending') return 'warning'
  if (state === 'ready') return 'success'
  return 'neutral'
}

/**
 * Why the enable/disable toggle is blocked, or null when it isn't. A divergent schema must be
 * repaired on the CLI first — toggling would either replay or paper over the divergence.
 */
export function toggleBlockedReason(ext: InstalledExtension): string | null {
  if (ext.schema_state !== 'divergent') return null
  const reasons = ext.schema_reasons.join(' · ')
  const cli = ext.cli_command ? ` Run: ${ext.cli_command}` : ''
  return `Schema divergent — repair before toggling. ${reasons}${cli}`.trim()
}

/**
 * The failed migration named by a toggle error response (409 carries the persisted operation
 * record under error.details), or null when the failure has no migration to blame.
 */
export function failedMigrationOf(e: unknown): string | null {
  const body = (e as { body?: unknown } | null)?.body
  if (typeof body !== 'object' || body === null) return null
  const details = (body as { error?: { details?: { operation?: { failed_migration?: unknown } } } })
    .error?.details
  const failed = details?.operation?.failed_migration
  return typeof failed === 'string' && failed !== '' ? failed : null
}

export interface CatalogExtension {
  name: string
  description?: string | null
  url?: string | null
  repository?: string | null
  downloads: number
  favers: number
  installed: boolean
  enabled: boolean
}

const base = () => `${runtimeConfig.apiBase}/extensions`

export async function fetchInstalledExtensions(): Promise<InstalledExtension[]> {
  const json = await authFetch(base())
  const data = (json.data ?? json) as Record<string, unknown>
  return Array.isArray(data.extensions) ? (data.extensions as InstalledExtension[]) : []
}

export function useInstalledExtensions() {
  return useQuery({
    key: () => ['extensions', 'installed'],
    query: fetchInstalledExtensions,
  })
}

export async function fetchExtensionCatalog(
  q?: string,
): Promise<{ results: CatalogExtension[]; available: boolean }> {
  const qs = q ? `?q=${encodeURIComponent(q)}` : ''
  const json = await authFetch(`${base()}/registry${qs}`)
  const data = (json.data ?? json) as Record<string, unknown>
  return {
    results: Array.isArray(data.results) ? (data.results as CatalogExtension[]) : [],
    available: data.available !== false,
  }
}

export function useExtensionCatalog(q: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['extensions', 'catalog', toValue(q) ?? ''],
    query: () => fetchExtensionCatalog(toValue(q)),
  })
}

export interface ExtensionReadme {
  found: boolean
  html: string | null
  source: string | null
}

export async function fetchExtensionReadme(name: string): Promise<ExtensionReadme> {
  const [vendor, pkg] = name.split('/')
  const json = await authFetch(
    `${base()}/${encodeURIComponent(vendor ?? '')}/${encodeURIComponent(pkg ?? '')}/readme`,
  )
  const d = (json.data ?? json) as Record<string, unknown>
  return {
    found: d.found === true,
    // Server-rendered + sanitized (CommonMark, raw HTML escaped, unsafe links blocked, images
    // stripped), so it is safe to render with v-html in the detail pane.
    html: typeof d.html === 'string' ? d.html : null,
    source: typeof d.source === 'string' ? d.source : null,
  }
}

export function useExtensionReadme(name: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['extensions', 'readme', toValue(name) ?? ''],
    query: () => fetchExtensionReadme(toValue(name) as string),
    enabled: () => !!toValue(name),
  })
}

export function useExtensionMutations() {
  const cache = useQueryCache()
  const caps = useCapabilitiesStore()
  const invalidate = () => cache.invalidateQueries({ key: ['extensions'] })
  // A toggle changes the capability list, which drives the gated nav/panels — but the
  // backend serves the previous list for a few seconds (dev extension-cache TTL), so a
  // single refetch loses the race. Fire-and-forget poll until the set actually changes;
  // the sidebar is a computed over the store and converges the moment it does.
  const converge = () => {
    invalidate()
    void caps.refreshUntilChanged()
  }

  const enable = useMutation({
    mutation: (name: string) =>
      authFetch(`${base()}/enable`, { method: 'POST', body: JSON.stringify({ name }) }),
    onSettled: converge,
  })
  const disable = useMutation({
    mutation: (name: string) =>
      authFetch(`${base()}/disable`, { method: 'POST', body: JSON.stringify({ name }) }),
    onSettled: converge,
  })

  return { enable, disable }
}

// ── Install (composer require, synchronous) ───────────────────────────────────
// POST /install runs `composer require` inline and returns when it finishes — no
// job, no polling. On success the extension is INSTALLED but disabled; the operator
// enables it with the existing toggle (WordPress-style install → activate).

export type InstallStatus = 'installed' | 'failed'

export interface InstallResult {
  status: InstallStatus
  package: string
  error?: string | null
  output?: string
}

export async function installExtension(name: string): Promise<InstallResult> {
  const json = await authFetch(`${base()}/install`, {
    method: 'POST',
    body: JSON.stringify({ name }),
  })
  const d = (json.data ?? json) as Record<string, unknown>
  return {
    status: d.status === 'installed' ? 'installed' : 'failed',
    package: typeof d.package === 'string' ? d.package : name,
    error: typeof d.error === 'string' ? d.error : null,
    output: typeof d.output === 'string' ? d.output : '',
  }
}

/**
 * Per-package install: a single blocking call (composer runs on the server for the
 * duration of the request). `installing(name)` is true while it's in flight. A
 * successful install invalidates the catalog so the card flips to "Installed".
 */
export function useExtensionInstall() {
  const cache = useQueryCache()
  const inflight = reactive<Record<string, boolean>>({})

  async function install(name: string): Promise<InstallResult> {
    inflight[name] = true
    try {
      const result = await installExtension(name)
      if (result.status === 'installed') {
        cache.invalidateQueries({ key: ['extensions'] }) // refresh the installed flag
      }
      return result
    } catch (e) {
      return {
        status: 'failed',
        package: name,
        error: e instanceof Error ? e.message : 'Install failed',
      }
    } finally {
      inflight[name] = false
    }
  }

  const installing = (name: string): boolean => inflight[name] === true

  return { install, installing }
}

/** Short display name: `glueful/audit` → `audit`. */
export function extensionShortName(name: string): string {
  return name.split('/').pop() ?? name
}
