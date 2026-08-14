import { describe, it, expect, vi } from 'vitest'
import { ApiError, toApiError, apiErrorCode, apiErrorDetails, responseError } from '@/api/errors'

// Pins toApiError's field-error extraction across BOTH real backend envelope shapes:
//   - the global exception handler's escaped-ValidationException shape: { errors: { field: [msg] } }
//     (Http/Exceptions/Handler.php::renderValidationException — DTO hydration failures)
//   - a controller's manually-caught ValidationException via Response::validation(): the SAME
//     status but shaped { error: { code, details: { field: msg } } } (Http/Response.php)
// Every commerce catalog variant/children/stock mutation's business-rule 422s (see
// commerceCatalog.ts's createProductVariant/setProductChildren/adjustVariantStock) use the SECOND
// shape, so without the `error.details` fallback their constraint messages never reach fieldErrors.
describe('toApiError field-error extraction', () => {
  it('extracts fieldErrors from a top-level `errors` map (array-of-messages form)', () => {
    const err = toApiError(
      { success: false, message: 'Validation failed', errors: { sku: ['SKU is required.'] } },
      new Response(null, { status: 422 }),
    )
    expect(err.fieldErrors).toEqual({ sku: 'SKU is required.' })
  })

  it('falls back to `error.details` as a field-error map when `errors` is absent', () => {
    const err = toApiError(
      {
        success: false,
        message: 'Validation failed',
        error: {
          code: 422,
          timestamp: '2026-01-01T00:00:00Z',
          request_id: 'req_1',
          details: { type: 'Only grouped products can have children.' },
        },
      },
      new Response(null, { status: 422 }),
    )
    expect(err.fieldErrors).toEqual({ type: 'Only grouped products can have children.' })
    expect(err.message).toBe('Validation failed')
  })

  it('extracts the product_uuid constraint message from error.details', () => {
    const err = toApiError(
      {
        success: false,
        message: 'Validation failed',
        error: { code: 422, details: { product_uuid: "Cannot add variants to a 'grouped' product." } },
      },
      new Response(null, { status: 422 }),
    )
    expect(err.fieldErrors).toEqual({ product_uuid: "Cannot add variants to a 'grouped' product." })
  })

  it('never treats a machine-code details payload (carries `code`) as field errors', () => {
    const err = toApiError(
      {
        success: false,
        error: { code: 409, details: { code: 'BLOCK_MIGRATION_IN_PROGRESS', block_type: 'card' } },
      },
      new Response(null, { status: 409 }),
    )
    expect(err.fieldErrors).toEqual({})
    expect(apiErrorCode(err)).toBe('BLOCK_MIGRATION_IN_PROGRESS')
    expect(apiErrorDetails(err)).toEqual({ code: 'BLOCK_MIGRATION_IN_PROGRESS', block_type: 'card' })
  })

  it('prefers the top-level `errors` map over `error.details` when both are present', () => {
    const err = toApiError(
      {
        success: false,
        errors: { sku: ['SKU is required.'] },
        error: { code: 422, details: { type: 'Should be ignored.' } },
      },
      new Response(null, { status: 422 }),
    )
    expect(err.fieldErrors).toEqual({ sku: 'SKU is required.' })
  })

  it('leaves fieldErrors empty when details is present but not a plain field-message map', () => {
    const err = toApiError(
      { success: false, error: { code: 422, details: ['not', 'a', 'map'] } },
      new Response(null, { status: 422 }),
    )
    expect(err.fieldErrors).toEqual({})
  })

  it('passes an already-constructed ApiError through unchanged', () => {
    const original = new ApiError('boom', 500, { x: 'y' }, null)
    expect(toApiError(original)).toBe(original)
  })

  // `DraftConflictException` (commerce v1.10.0, admin-order-creation Task 14) renders every draft
  // 409 as `{ error: { details: { conflict: 'stale_revision', ...extra } } }` — a closed,
  // lowercase discriminator, not a field named "conflict". Without this exclusion,
  // `fieldErrorsFromDetails` would misread it as `{ conflict: 'stale_revision' }` field error.
  it('never treats a draft `conflict` discriminator as a field error', () => {
    const err = toApiError(
      {
        success: false,
        message: 'This draft changed since you loaded it; reload the draft and retry.',
        error: { code: 409, details: { conflict: 'stale_revision' } },
      },
      new Response(null, { status: 409 }),
    )
    expect(err.fieldErrors).toEqual({})
    expect(apiErrorDetails(err)).toEqual({ conflict: 'stale_revision' })
  })

  it('still ignores the whole details object for a line_conflicts payload (has both `conflict` and `lines`)', () => {
    const err = toApiError(
      {
        success: false,
        message: 'Some lines can no longer be ordered as drafted; review them and retry.',
        error: { code: 409, details: { conflict: 'line_conflicts', lines: [{ line_uuid: 'l1' }] } },
      },
      new Response(null, { status: 409 }),
    )
    expect(err.fieldErrors).toEqual({})
  })
})

// CRITICAL fix (admin-order-creation Task 14 review round 1): `responseError()` — the ONLY
// normalizer `authFetch()` uses (every draft mutation, since the draft endpoints are not in the
// generated OpenAPI schema yet) — used to read `body.errors` alone, missing the
// `Response::validation()` `error.details` shape entirely. A draft's phone/customer_name 422 (and
// every other manually-caught ValidationException reached only through authFetch) rendered NO
// field error at all before this fix. Pinned here directly against a real `Response`, the same
// object `authFetch()` hands to `responseError()`.
describe('responseError field-error extraction (raw-fetch path, e.g. authFetch)', () => {
  it('extracts fieldErrors from the `error.details` shape, matching toApiError', async () => {
    const res = new Response(
      JSON.stringify({
        success: false,
        message: 'Validation failed',
        error: {
          code: 422,
          timestamp: '2026-01-01T00:00:00Z',
          request_id: 'req_1',
          details: { phone: 'phone must be a phone number in international format, e.g. +15550109999.' },
        },
      }),
      { status: 422 },
    )
    const err = await responseError(res)
    expect(err.fieldErrors).toEqual({
      phone: 'phone must be a phone number in international format, e.g. +15550109999.',
    })
    expect(err.status).toBe(422)
  })

  it('extracts fieldErrors from the top-level `errors` map shape too', async () => {
    const res = new Response(
      JSON.stringify({ success: false, message: 'Validation failed', errors: { email: ['Invalid email.'] } }),
      { status: 422 },
    )
    const err = await responseError(res)
    expect(err.fieldErrors).toEqual({ email: 'Invalid email.' })
  })

  it('never treats a draft `conflict` discriminator as a field error, and preserves the message', async () => {
    const res = new Response(
      JSON.stringify({
        success: false,
        message: 'This draft changed since you loaded it; reload the draft and retry.',
        error: { code: 409, details: { conflict: 'stale_revision' } },
      }),
      { status: 409 },
    )
    const err = await responseError(res)
    expect(err.fieldErrors).toEqual({})
    expect(err.message).toBe('This draft changed since you loaded it; reload the draft and retry.')
    expect(apiErrorDetails(err)).toEqual({ conflict: 'stale_revision' })
  })
})

// ── `apiErrorDetails()` / `apiErrorCode()` are STRUCTURAL, never identity-based ────────────────
//
// Both helpers used to gate on `e instanceof ApiError`, which silently answers `false` the moment
// the error crosses a DUPLICATED MODULE GRAPH — vitest's `importOriginal()`/`importActual()`
// inside a `vi.mock` factory loads `@/api/errors` a second time, and a split production bundle can
// do the same, so a byte-identical error carries a DIFFERENT constructor. Every consumer that
// branches on a machine-readable code (`STALE_DRAFT`, `BLOCK_MIGRATION_IN_PROGRESS`, the draft
// `conflict` discriminator, the payment-link `reason`) therefore had a latent path that fell
// through to generic error rendering. Caught concretely in the payment-links final wave, where
// `isPaymentSessionRiskRefusal()` was written with `instanceof` first and returned `false` under
// test — which is why that function is structural and why these two now are as well.
//
// The shape below (`status` + `body.error.details`) is the framework's own error envelope, and it
// is what actually distinguishes a coded refusal; `instanceof ApiError` survives only as a TS
// narrowing tool for callers that want the typed fields.
describe('apiErrorDetails/apiErrorCode across a duplicated module graph', () => {
  it('reads an ApiError built by a SECOND copy of this module, whose constructor is not ours', async () => {
    const duplicate = await vi.importActual<typeof import('@/api/errors')>('@/api/errors')
    // The duplication is real: same source, different class identity.
    expect(duplicate.ApiError).not.toBe(ApiError)

    const foreign = new duplicate.ApiError('Conflict.', 409, {}, {
      success: false,
      error: { code: 409, details: { code: 'BLOCK_MIGRATION_IN_PROGRESS', block_type: 'card' } },
    })
    expect(foreign instanceof ApiError).toBe(false)

    expect(apiErrorCode(foreign)).toBe('BLOCK_MIGRATION_IN_PROGRESS')
    expect(apiErrorDetails(foreign)).toEqual({
      code: 'BLOCK_MIGRATION_IN_PROGRESS',
      block_type: 'card',
    })
  })

  it('reads the draft `conflict` discriminator and the payment-link `reason` off a foreign error', async () => {
    const duplicate = await vi.importActual<typeof import('@/api/errors')>('@/api/errors')
    const stale = new duplicate.ApiError('Stale.', 409, {}, {
      error: { details: { conflict: 'stale_revision' } },
    })
    const refusal = new duplicate.ApiError('Risk.', 409, {}, {
      error: { details: { reason: 'payment_session_risk_unacknowledged' } },
    })

    expect(apiErrorDetails(stale)?.conflict).toBe('stale_revision')
    expect(apiErrorDetails(refusal)?.reason).toBe('payment_session_risk_unacknowledged')
  })

  // A split bundle produces exactly this: the SAME fields, a different (or absent) prototype.
  it('reads a structurally-identical error that was never an ApiError at all', () => {
    const bundled = {
      name: 'ApiError',
      message: 'Conflict.',
      status: 409,
      fieldErrors: {},
      body: { error: { details: { reason: 'order_not_deletable', status: 'draft' } } },
    }
    expect(apiErrorDetails(bundled)).toEqual({ reason: 'order_not_deletable', status: 'draft' })
  })

  it('still answers null for everything that is not a framework error envelope', () => {
    expect(apiErrorDetails(null)).toBeNull()
    expect(apiErrorDetails('nope')).toBeNull()
    expect(apiErrorCode(new Error('x'))).toBeNull()
    // An object with a body but no HTTP status is not an error this layer produced.
    expect(apiErrorDetails({ body: { error: { details: { code: 'X' } } } })).toBeNull()
    expect(apiErrorDetails(new ApiError('x', 409, {}, { success: false }))).toBeNull()
    expect(apiErrorCode(new ApiError('x', 409, {}, { error: { details: { block_type: 'card' } } }))).toBeNull()
  })
})
