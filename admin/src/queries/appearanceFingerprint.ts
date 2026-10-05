import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// What a stage render's head would depend on now (block typeface plan Task 11): the active theme,
// the saved appearance and the fonts stylesheet, as one opaque value. Read-only — it mints nothing
// and touches no preview session — so every stage's host answers its freshness check with it.

export async function fetchAppearanceFingerprint(): Promise<string> {
  const { data, error, response } = await client.GET('/render/appearance-fingerprint')
  if (error) throw toApiError(error, response)
  return (data as unknown as { data: { appearance_fingerprint: string } }).data
    .appearance_fingerprint
}
