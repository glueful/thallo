import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export interface AccountPage {
  label: string
  path: string
}

export interface AccountSettings {
  pages: AccountPage[]
  after_login: string | null
  after_logout: string | null
}

function base(): string {
  return `${runtimeConfig.apiBase}/settings/accounts`
}

function unwrap(json: unknown): AccountSettings {
  const value = (json as { data?: AccountSettings }).data
  if (!value || !Array.isArray(value.pages)) {
    throw new Error('Malformed account settings response.')
  }
  return {
    pages: value.pages,
    after_login: value.after_login ?? null,
    after_logout: value.after_logout ?? null,
  }
}

/**
 * Client mirror of the server's `AccountReturnPath::validate()` (server remains the authority): a
 * safe redirect is an application-relative path with exactly one leading `/`, no host, no scheme,
 * no backslash, no control character, and no percent-encoding. A blank value is "no override".
 */
export function isSafeReturnPath(value: string): boolean {
  if (value === '') return true // blank clears the override
  for (let i = 0; i < value.length; i++) {
    const code = value.charCodeAt(i)
    if (code <= 0x1f || code === 0x7f) return false // control chars
  }
  if (value !== value.trim()) return false // leading/trailing whitespace
  try {
    if (decodeURIComponent(value) !== value) return false // percent-encoded bypass
  } catch {
    return false // malformed encoding
  }
  if (value[0] !== '/') return false // must be rooted at one '/'
  if (value.startsWith('//') || value.includes('\\')) return false // host-bearing tricks
  return true
}

export async function fetchAccountSettings(): Promise<AccountSettings> {
  return unwrap(await authFetch(base()))
}

export async function saveAccountRedirects(
  afterLogin: string | null,
  afterLogout: string | null,
): Promise<AccountSettings> {
  return unwrap(
    await authFetch(base(), {
      method: 'PUT',
      body: JSON.stringify({ after_login: afterLogin, after_logout: afterLogout }),
    }),
  )
}
