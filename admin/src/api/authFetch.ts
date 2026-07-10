import { responseError } from './errors'
import { useSessionStore } from '@/stores/session'

// Authenticated, same-origin fetch for endpoints that aren't in the generated OpenAPI schema yet
// (or whose responses are untyped in the spec). `path` is the FULL same-origin path — callers
// prefix runtimeConfig.apiBase for /v1/admin routes, or pass a root path like '/i18n/locales'.
// Attaches the Bearer from the session store and normalizes failures into an ApiError. Regenerate
// the spec to move these onto the typed client later.
export async function authFetch(
  path: string,
  init: RequestInit = {},
): Promise<Record<string, unknown>> {
  const token = useSessionStore().accessToken
  let tenant: ReturnType<(typeof import('@/stores/tenant'))['useTenantStore']> | null = null
  try {
    const { useTenantStore } = await import('@/stores/tenant')
    tenant = useTenantStore()
  } catch {
    // Some pre-app/bootstrap callers run before Pinia is installed. They retain the legacy request.
  }
  const headers: Record<string, string> = { 'content-type': 'application/json' }
  if (token) headers.authorization = `Bearer ${token}`
  if (tenant?.selectedUuid) headers['X-Tenant-Id'] = tenant.selectedUuid
  const res = await fetch(path, {
    ...init,
    headers: { ...headers, ...(init.headers as Record<string, string> | undefined) },
  })
  if (!res.ok) {
    if (res.status === 403 && headers['X-Tenant-Id'] && tenant !== null) {
      tenant.clearSelection()
      await tenant.ensureLoaded(true)
      window.dispatchEvent(new CustomEvent('tenant-switch-required'))
    }
    throw await responseError(res, 'Request failed.')
  }
  return (await res.json().catch(() => ({}))) as Record<string, unknown>
}
