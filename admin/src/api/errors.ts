// Normalized error surface for the whole SPA.
//
// The framework renders EVERY error as a JSON body of the shape
//   { success: false, message: string, errors?: { <field>: string[] } }
// (see framework src/Http/Exceptions/Handler.php + Validation/ValidationException.php).
//
// Two callers feed into here:
//   - openapi-fetch (the typed `client`) hands back a parsed `error` body plus the raw `response`.
//   - raw fetch() calls (auth + multipart upload, which live outside the typed surface) hand back a
//     Response we parse ourselves.
// Both funnel into ApiError so every page sees a consistent { status, message, fieldErrors }.

export interface ApiErrorBody {
  success?: boolean
  message?: string
  errors?: Record<string, string[] | string>
}

const DEFAULT_MESSAGE = 'Something went wrong. Please try again.'

/** Error thrown by the query layer and surfaced by useNotify(). */
export class ApiError extends Error {
  /** HTTP status (0 when unknown, e.g. a network failure). */
  readonly status: number
  /** First message per field, ready to feed UForm / UFormField error state. */
  readonly fieldErrors: Record<string, string>
  /** The raw parsed body, for callers that need more than message/fieldErrors. */
  readonly body: unknown

  constructor(message: string, status: number, fieldErrors: Record<string, string>, body: unknown) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.fieldErrors = fieldErrors
    this.body = body
  }
}

function isErrorBody(value: unknown): value is ApiErrorBody {
  return typeof value === 'object' && value !== null
}

/** Collapse `{ field: string[] }` (or `{ field: string }`) to the first message per field. */
function flattenFieldErrors(errors: ApiErrorBody['errors']): Record<string, string> {
  const out: Record<string, string> = {}
  if (!errors) return out
  for (const [field, messages] of Object.entries(errors)) {
    const first = Array.isArray(messages) ? messages[0] : messages
    if (typeof first === 'string' && first.trim() !== '') out[field] = first
  }
  return out
}

/**
 * `Response::validation()` (framework `Http/Response.php`) renders a controller-caught
 * `ValidationException` as `{ error: { code, timestamp, request_id, details: { field: message } } }`
 * — a DIFFERENT envelope from the global exception handler's `{ errors: { field: [message] } }`
 * (`Http/Exceptions/Handler.php::renderValidationException()`, used only when a ValidationException
 * escapes uncaught, e.g. a DTO hydration failure). Every commerce catalog mutation that manually
 * catches its own ValidationException (variant/children/stock business-rule 422s — see
 * commerceCatalog.ts) uses the former, so `errors` alone misses them entirely and the specific
 * constraint message (e.g. "Only grouped products can have children.") would silently vanish.
 *
 * `error.details` is also reused for machine-readable failure codes (STALE_DRAFT,
 * BLOCK_MIGRATION_IN_PROGRESS — see canvas-page.spec.ts), always shaped `{ code, ...}`. Those are
 * consumed via `apiErrorCode()`/`apiErrorDetails()`, never as field messages, so this only treats
 * `details` as a field-error map when every value is a non-empty string AND there's no `code` key.
 */
function fieldErrorsFromDetails(details: unknown): Record<string, string> {
  if (typeof details !== 'object' || details === null || Array.isArray(details)) return {}
  const entries = Object.entries(details as Record<string, unknown>)
  if (entries.length === 0 || 'code' in (details as Record<string, unknown>)) return {}
  const out: Record<string, string> = {}
  for (const [field, message] of entries) {
    if (typeof message !== 'string' || message.trim() === '') return {}
    out[field] = message
  }
  return out
}

function messageFromBody(body: unknown, fallback: string): string {
  if (isErrorBody(body) && typeof body.message === 'string' && body.message.trim() !== '') {
    return body.message
  }
  return fallback
}

/**
 * The machine-readable detail code from a framework error body — Response::error()
 * puts caller details under `error.details`, so a coded failure looks like
 * `{ error: { details: { code: 'STALE_DRAFT' | 'BLOCK_MIGRATION_IN_PROGRESS', ... } } }`.
 * Null when absent — callers branch on the code instead of parsing messages.
 */
export function apiErrorCode(e: unknown): string | null {
  const details = apiErrorDetails(e)
  const code = details?.code
  return typeof code === 'string' ? code : null
}

/** The framework error body's `error.details` object, if any. */
export function apiErrorDetails(e: unknown): Record<string, unknown> | null {
  if (!(e instanceof ApiError)) return null
  const body = e.body as { error?: { details?: unknown } } | null
  const details = body?.error?.details
  return typeof details === 'object' && details !== null
    ? (details as Record<string, unknown>)
    : null
}

/**
 * Normalize an openapi-fetch failure (its `error` body + `response`) — or any thrown value — into
 * an ApiError. Pass the destructured `response` so the resulting error carries the HTTP status.
 */
export function toApiError(
  error: unknown,
  response?: Response,
  fallback = DEFAULT_MESSAGE,
): ApiError {
  if (error instanceof ApiError) return error
  const status = response?.status ?? 0
  const fallbackMessage = error instanceof Error ? error.message : fallback
  const message = messageFromBody(error, fallbackMessage)
  let fieldErrors = flattenFieldErrors(isErrorBody(error) ? error.errors : undefined)
  if (Object.keys(fieldErrors).length === 0 && isErrorBody(error)) {
    const details = (error as { error?: { details?: unknown } }).error?.details
    fieldErrors = fieldErrorsFromDetails(details)
  }
  return new ApiError(message, status, fieldErrors, error ?? null)
}

/**
 * Parse a raw fetch() Response (auth + upload endpoints, outside the typed client) into an
 * ApiError. Call only on a non-ok response.
 */
export async function responseError(res: Response, fallback = DEFAULT_MESSAGE): Promise<ApiError> {
  let body: unknown = null
  try {
    body = await res.clone().json()
  } catch {
    // Non-JSON error body (e.g. an HTML 500 / proxy error) — keep the generic fallback message.
  }
  const message = messageFromBody(body, fallback)
  const fieldErrors = flattenFieldErrors(isErrorBody(body) ? body.errors : undefined)
  return new ApiError(message, res.status, fieldErrors, body)
}
