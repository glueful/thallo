import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { reactive, toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'
import { useCapabilitiesStore } from '@/stores/capabilities'

// Extensions admin API (App\Http\Controllers\ExtensionAdminController, under /v1/admin/extensions).
// Installed data is local (PackageManifest + the enabled allow-list); Browse proxies Packagist
// filtered to type=glueful-extension. Enable/disable rewrites config/extensions.php (dev only).

export interface InstalledExtension {
  name: string
  provider: string
  version?: string | null
  description?: string | null
  author?: string | null
  requires_extensions: string[]
  enabled: boolean
}

export interface CatalogExtension {
  name: string
  description?: string | null
  url?: string | null
  repository?: string | null
  downloads: number
  favers: number
  installed: boolean
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

// ── Install (composer require, detached) ──────────────────────────────────────
// POST /install starts a background job (composer require + auto-enable); the client
// polls GET /install/{jobId} until a terminal status. `succeeded` = installed AND
// enabled; `installed_not_enabled` = installed but auto-enable hit a snag (e.g. a
// missing dependency) — not a hard failure.

export type InstallStatus =
  | 'queued'
  | 'running'
  | 'succeeded'
  | 'failed'
  | 'installed_not_enabled'

export interface InstallJob {
  id: string
  package: string
  status: InstallStatus
  output: string
  error: string | null
  enableError: string | null
}

const TERMINAL: InstallStatus[] = ['succeeded', 'failed', 'installed_not_enabled']

export async function startInstall(name: string): Promise<string> {
  const json = await authFetch(`${base()}/install`, {
    method: 'POST',
    body: JSON.stringify({ name }),
  })
  const d = (json.data ?? json) as { jobId?: string }
  return String(d.jobId ?? '')
}

export async function fetchInstallJob(jobId: string): Promise<InstallJob> {
  const json = await authFetch(`${base()}/install/${encodeURIComponent(jobId)}`)
  return (json.data ?? json) as InstallJob
}

export interface InstallState {
  status: 'starting' | InstallStatus
  error?: string
}

/**
 * Per-package install orchestration: start → poll → converge. `pollMs` is injectable
 * so tests can drive it fast. A successful install auto-enables the extension, which
 * changes the capability set, so we converge the gated nav/catalog (same pattern as
 * enable/disable).
 */
export function useExtensionInstall(pollMs = 1500) {
  const cache = useQueryCache()
  const caps = useCapabilitiesStore()
  const state = reactive<Record<string, InstallState>>({})

  const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

  async function install(name: string): Promise<InstallStatus> {
    state[name] = { status: 'starting' }
    try {
      const jobId = await startInstall(name)
      for (;;) {
        await sleep(pollMs)
        const job = await fetchInstallJob(jobId)
        state[name] = { status: job.status, error: job.error ?? job.enableError ?? undefined }
        if (TERMINAL.includes(job.status)) {
          cache.invalidateQueries({ key: ['extensions'] }) // refresh the installed flag
          if (job.status === 'succeeded') void caps.refreshUntilChanged()
          return job.status
        }
      }
    } catch (e) {
      state[name] = { status: 'failed', error: e instanceof Error ? e.message : 'Install failed' }
      cache.invalidateQueries({ key: ['extensions'] })
      return 'failed'
    }
  }

  const installing = (name: string): boolean => {
    const s = state[name]?.status
    return s === 'starting' || s === 'queued' || s === 'running'
  }

  return { state, install, installing }
}

/** Short display name: `glueful/audit` → `audit`. */
export function extensionShortName(name: string): string {
  return name.split('/').pop() ?? name
}
