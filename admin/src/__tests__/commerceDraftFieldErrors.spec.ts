import { describe, it, expect, vi, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'
import { mount } from '@vue/test-utils'
import type { CommerceDraft } from '@/queries/commerceDrafts'
import type DraftCustomerCardType from '@/pages/commerce/orders/components/DraftCustomerCard.vue'

// Review fix (round 1, CRITICAL): the original covering test for this behavior hand-constructed
// `new ApiError(..., { phone: '…' })` directly — it exercised the CARD's rendering given an
// already-normalized error, but said nothing about whether the real wire response ever gets
// normalized into that shape in the first place. It didn't: `authFetch()` (the draft endpoints'
// ONLY transport before Task 16 regenerated the OpenAPI schema) called `responseError()`, which
// used to read `body.errors` only — but the draft controller's `Response::validation()` renders
// `{ error: { details: { phone: "…" } } }` with NO `errors` key at all. So `toApiError(e)
// .fieldErrors` was ALWAYS `{}` for every draft mutation failure: an invalid phone rendered
// nothing, "Save" looked dead, and the old test was a false green.
//
// This file drives the REAL pipeline instead: `@/queries/commerceDrafts` is NOT mocked, only
// `global.fetch` is stubbed to return the actual backend envelope
// (`DraftConflictException`/`Response::validation()` shapes, verified against
// `extensions/commerce/src/Orders/DraftConflictException.php` and `Http/Response.php`) — so a
// failure travels update.mutateAsync() -> real updateDraft() -> real client (Task 16 regeneration
// moved the draft endpoints onto the typed openapi-fetch `client`) -> real toApiError() -> the
// card's own catch handler, exactly as it does in the browser. Since the typed `client` captures
// `globalThis.fetch` at CREATION time (module load), `DraftCustomerCard` (and everything it
// transitively imports, including `@/api/client`) is dynamic-imported PER TEST, after stubbing
// fetch and resetting the module graph — mirrors commerceOrders.spec.ts's established
// stub-then-dynamic-import pattern; a static top-level import would bind the card to a `client`
// instance created against the ORIGINAL global fetch, before any stub ever ran.
vi.mock('@/stores/session', () => ({ useSessionStore: () => ({ accessToken: null }) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

function draft(overrides: Partial<CommerceDraft> = {}): CommerceDraft {
  return {
    uuid: 'd1',
    order_number: null,
    status: 'draft',
    fulfillment_status: 'unfulfilled',
    email: null,
    user_uuid: null,
    customer_name: null,
    phone_normalized: null,
    phone_display: null,
    fulfillment_mode: 'in_store',
    origin: 'admin',
    currency: 'USD',
    subtotal: 0,
    discount_total: 0,
    shipping_total: 0,
    tax_total: 0,
    grand_total: 0,
    refunded_total: 0,
    discount_code: null,
    shipping_method: null,
    addresses: null,
    placed_at: null,
    created_at: '2026-01-01 00:00:00',
    updated_at: null,
    draft_revision: 0,
    lines: [],
    ...overrides,
  }
}

/** Dynamic-imports `DraftCustomerCard` (and everything it transitively pulls in, including the
 * typed `client`) AFTER the caller has already stubbed `global.fetch`, so the freshly re-created
 * `client` singleton captures the stub rather than the original global. Must be called after
 * `vi.stubGlobal('fetch', ...)` in every test. */
async function mountCard(draftValue: CommerceDraft) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const { default: DraftCustomerCard } = await import(
    '@/pages/commerce/orders/components/DraftCustomerCard.vue'
  )
  return mount(DraftCustomerCard as typeof DraftCustomerCardType, {
    global: { plugins: [pinia, PiniaColada] },
    props: { draft: draftValue, canAttachUser: false },
  })
}

// The typed `client` (and, transitively, `@/stores/tenant`) is imported dynamically per test —
// a genuine dynamic import, not a cached microtask, and `src/__tests__/setup.ts`'s global
// `vi.resetModules()` (needed by every typed-client spec) makes THIS file's dynamic import
// re-transform the module graph from scratch. That easily outlasts a single `flushPromises()`
// tick (one macrotask), so a plain `await flushPromises()` after the click is flaky here
// specifically — `vi.waitFor` polls with real timers until the assertion holds (or its own
// timeout fails the test loudly).
async function waitFor(assertion: () => void): Promise<void> {
  await vi.waitFor(assertion, { timeout: 2000, interval: 20 })
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('DraftCustomerCard: real wire-envelope error normalization (no mocked commerceDrafts/authFetch)', () => {
  it('renders a 422 field error from the REAL Response::validation() envelope shape', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            success: false,
            message: 'Validation failed',
            error: {
              code: 422,
              timestamp: '2026-01-01T00:00:00Z',
              request_id: 'req_1',
              details: {
                phone: 'phone must be a phone number in international format, e.g. +15550109999.',
              },
            },
          }),
          { status: 422 },
        ),
      ),
    )

    const wrapper = await mountCard(draft({ uuid: 'd1', draft_revision: 0 }))
    await wrapper.find('[data-test="draft-customer-phone"]').setValue('not-a-phone')
    await wrapper.find('[data-test="draft-customer-save"]').trigger('click')

    await waitFor(() => {
      expect(wrapper.text()).toContain('phone must be a phone number in international format')
    })
    // No message-level banner when a field error already explains the failure inline.
    expect(wrapper.find('[data-test="draft-customer-save-error"]').exists()).toBe(false)
  })

  it('renders a message-level banner for a REAL non-field 409 (DraftConflictException::staleRevision)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            success: false,
            message: 'This draft changed since you loaded it; reload the draft and retry.',
            error: {
              code: 409,
              timestamp: '2026-01-01T00:00:00Z',
              request_id: 'req_2',
              details: { conflict: 'stale_revision' },
            },
          }),
          { status: 409 },
        ),
      ),
    )

    const wrapper = await mountCard(draft({ uuid: 'd1', draft_revision: 0, email: 'old@example.com' }))
    await wrapper.find('[data-test="draft-customer-save"]').trigger('click')

    await waitFor(() => {
      expect(wrapper.find('[data-test="draft-customer-save-error"]').exists()).toBe(true)
    })
    expect(wrapper.find('[data-test="draft-customer-save-error"]').text()).toBe(
      'This draft changed since you loaded it; reload the draft and retry.',
    )
    // The raw machine discriminator never leaks into the UI as if it were a field name/value (the
    // `conflict` key is never mistaken for a field named "conflict" — see errors.spec.ts) —
    // pinning the CRITICAL fix's other half: previously this had no surface at all, and the card
    // just stopped loading silently with nothing rendered anywhere.
    expect(wrapper.text()).not.toContain('stale_revision')
  })

  it('renders a message-level banner for a bare 500 (non-JSON body)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('<html>Internal Server Error</html>', { status: 500 })),
    )

    const wrapper = await mountCard(draft({ uuid: 'd1', draft_revision: 0 }))
    await wrapper.find('[data-test="draft-customer-save"]').trigger('click')

    await waitFor(() => {
      expect(wrapper.find('[data-test="draft-customer-save-error"]').exists()).toBe(true)
    })
  })
})
