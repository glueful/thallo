import { useQuery } from '@pinia/colada'
import { client, core } from '@/api/client'
import { toApiError } from '@/api/errors'

// The signed-in user's own account (the Profile and Security pages): Thallo's `/v1/admin/account`
// endpoints read it and change the name, photo and password; two-factor authentication is the
// framework's email-code flow (`/v1/2fa/*`).

export interface Me {
  uuid: string
  email: string
  username: string
  two_factor_enabled: boolean
  /** Whether this install has email two-factor switched on (`TWO_FACTOR_ENABLED`). */
  two_factor_available: boolean
  profile: { first_name: string | null; last_name: string | null; photo_url: string | null }
}

function record(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}
const text = (value: unknown): string | null =>
  typeof value === 'string' && value !== '' ? value : null

function readMe(raw: unknown): Me {
  const d = record(raw)
  const p = record(d.profile)
  return {
    uuid: String(d.uuid ?? ''),
    email: String(d.email ?? ''),
    username: String(d.username ?? ''),
    // The column is a boolean or 0/1 depending on the driver.
    two_factor_enabled:
      d.two_factor_enabled === true || d.two_factor_enabled === 1 || d.two_factor_enabled === '1',
    two_factor_available: d.two_factor_available === true,
    profile: {
      first_name: text(p.first_name),
      last_name: text(p.last_name),
      photo_url: text(p.photo_url),
    },
  }
}

export async function fetchMe(): Promise<Me> {
  const { data, error, response } = await client.GET('/account')
  if (error) throw toApiError(error, response)
  return readMe((data as unknown as { data?: unknown })?.data)
}

export function useMe() {
  return useQuery({ key: ['me'], query: fetchMe })
}

/** Set your own name and photo: an absent field is left as it is, an empty string clears it. */
export async function updateAccount(body: {
  first_name?: string
  last_name?: string
  photo_url?: string
}): Promise<Me> {
  const { data, error, response } = await client.PATCH('/account', { body: body as never })
  if (error) throw toApiError(error, response)
  return readMe((data as unknown as { data?: unknown })?.data)
}

/** Change your own password; every other session of the account is signed out. */
export async function changePassword(body: {
  current_password: string
  password: string
}): Promise<{ other_sessions_signed_out: number }> {
  const { data, error, response } = await client.POST('/account/password', { body: body as never })
  if (error) throw toApiError(error, response)
  const d = record((data as unknown as { data?: unknown })?.data)
  return { other_sessions_signed_out: Number(d.other_sessions_signed_out ?? 0) }
}

/** Begin turning two-factor on: a code is emailed, and the challenge it answers is returned. */
export async function beginTwoFactor(): Promise<{ challenge_token: string }> {
  const { data, error, response } = await core.POST('/v1/2fa/enable', {})
  if (error) throw toApiError(error, response)
  const d = record((data as unknown as { data?: unknown })?.data ?? data)
  return { challenge_token: String(d.challenge_token ?? '') }
}

/** Finish turning two-factor on with the emailed code. */
export async function confirmTwoFactor(challengeToken: string, code: string): Promise<void> {
  const { error, response } = await core.POST('/v1/2fa/verify', {
    body: { challenge_token: challengeToken, code } as never,
  })
  if (error) throw toApiError(error, response)
}

/**
 * Turn two-factor off. The API asks for a sign-in with a code on this session within the last few
 * minutes, and answers 403 otherwise.
 */
export async function disableTwoFactor(): Promise<void> {
  const { error, response } = await core.POST('/v1/2fa/disable', {})
  if (error) throw toApiError(error, response)
}
