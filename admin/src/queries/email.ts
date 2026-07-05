import { authFetch } from '@/api/authFetch'

// Email admin API (glueful/email-notification extension). Extension routes are
// ROOT-MOUNTED (loadRoutesFrom applies no API prefix — the /rbac/* precedent),
// so paths are literal /email/... — NOT under runtimeConfig.apiBase. No OpenAPI
// attributes upstream, so this rides authFetch like queries/rbac.ts.

export interface EmailTransportSettings {
  default: string
  from: { address: string; name: string }
  bcc: string
  logo_url: string
  mailers: Record<
    string,
    { host?: string; port?: number; username?: string; encryption?: string; transport?: string }
  >
}

export interface EmailSettingsPayload {
  settings: EmailTransportSettings
  /** The password is never returned — only whether one is currently stored. */
  password_set: boolean
}

export interface EmailTemplatePlaceholder {
  name: string
  description: string
  sample: string
}

export interface EmailTemplateRow {
  key: string
  label: string
  description: string
  owner: string
  placeholders: EmailTemplatePlaceholder[]
  /** Effective values: the DB override when one exists, else the definition defaults. */
  subject: string
  body: string
  overridden: boolean
}

/** Editable layout furniture: body-only overrides (partial.layout/header/footer/styles). */
export interface EmailPartialRow {
  key: string
  label: string
  description: string
  /** Editor mode: 'html' for layout/header/footer, 'css' for the styles partial. */
  language: 'html' | 'css'
  body: string
  overridden: boolean
}

/** Flat keys per the extension's PUT contract; password only when non-empty. */
export type EmailSettingsInput = Partial<{
  mailer: string
  host: string
  port: string
  username: string
  password: string
  encryption: string
  from: string
  from_name: string
  bcc: string
  logo_url: string
}>

const base = '/email'

export async function fetchEmailSettings(): Promise<EmailSettingsPayload> {
  const json = await authFetch(`${base}/settings`)
  return (json.data ?? json) as EmailSettingsPayload
}

export async function saveEmailSettings(input: EmailSettingsInput): Promise<EmailSettingsPayload> {
  const json = await authFetch(`${base}/settings`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
  return (json.data ?? json) as EmailSettingsPayload
}

/** A REAL send of a plain test message through the stored effective settings. */
export async function testEmailSettings(to: string): Promise<void> {
  await authFetch(`${base}/settings/test`, { method: 'POST', body: JSON.stringify({ to }) })
}

export async function fetchEmailTemplates(): Promise<{
  templates: EmailTemplateRow[]
  partials: EmailPartialRow[]
}> {
  const json = await authFetch(`${base}/templates`)
  const data = json.data as
    | { templates?: EmailTemplateRow[]; partials?: EmailPartialRow[] }
    | undefined
  return { templates: data?.templates ?? [], partials: data?.partials ?? [] }
}

/** Partials are body-only: the subject field is ignored server-side. */
export async function saveEmailPartial(key: string, body: string): Promise<void> {
  await authFetch(`${base}/templates/${encodeURIComponent(key)}`, {
    method: 'PUT',
    body: JSON.stringify({ body }),
  })
}

export async function saveEmailTemplate(
  key: string,
  input: { subject: string; body: string },
): Promise<void> {
  await authFetch(`${base}/templates/${encodeURIComponent(key)}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}

export async function resetEmailTemplate(key: string): Promise<void> {
  await authFetch(`${base}/templates/${encodeURIComponent(key)}`, { method: 'DELETE' })
}

/** A REAL send: the template rendered with its placeholder SAMPLES (incl. any saved override). */
export async function testEmailTemplate(key: string, to: string): Promise<void> {
  await authFetch(`${base}/templates/${encodeURIComponent(key)}/test`, {
    method: 'POST',
    body: JSON.stringify({ to }),
  })
}
