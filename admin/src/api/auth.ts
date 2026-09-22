// Pre-auth password-reset flow: forgot-password → verify-otp → reset-password (+ resend-otp).
//
// These framework AuthController endpoints exist in the OpenAPI spec but carry NO typed
// request/response bodies (`requestBody: never`), so the typed `core` client can't model them.
// We POST them with raw fetch and normalize failures through responseError — the same approach
// setup.vue uses for /admin/setup. Keeping the paths here (one place) rather than inline in each
// page is the equivalent of the typed-client guarantee: a backend path change is fixed once.
import { responseError } from './errors'

export interface Envelope<T> {
  success?: boolean
  message?: string
  data?: T
}

export async function postJson<T>(
  path: string,
  body: unknown,
  fallback: string,
): Promise<Envelope<T>> {
  const res = await fetch(path, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify(body),
  })
  // responseError parses the framework's { success:false, message, errors } envelope into an
  // ApiError (with per-field messages), so callers catch + notify exactly like the login page.
  if (!res.ok) throw await responseError(res, fallback)
  return (await res.json().catch(() => ({}))) as Envelope<T>
}

/** Step 1 — email a one-time code to start a password reset. */
export function forgotPassword(email: string) {
  return postJson<{ email?: string; expires_in?: number }>(
    '/v1/auth/forgot-password',
    { email },
    'Could not send the reset code. Please try again.',
  )
}

/** Re-send the one-time code (same address). */
export function resendOtp(email: string) {
  return postJson<{ email?: string; expires_in?: number }>(
    '/v1/auth/resend-otp',
    { email },
    'Could not resend the code. Please try again.',
  )
}

/**
 * Step 2 — verify the emailed code. With purpose=password_reset the response carries a short-lived,
 * single-use `reset_token` to submit to resetPassword().
 */
export function verifyOtp(email: string, otp: string, purpose = 'password_reset') {
  return postJson<{ reset_token?: string }>(
    '/v1/auth/verify-otp',
    { email, otp, purpose },
    'Could not verify the code. Please try again.',
  )
}

/** Step 3 — set the new password using the reset_token from verifyOtp(). */
export function resetPassword(resetToken: string, password: string) {
  return postJson<Record<string, never>>(
    '/v1/auth/reset-password',
    { reset_token: resetToken, password },
    'Could not reset your password. Please try again.',
  )
}
