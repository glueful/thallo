import { describe, it, expect, vi, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'
import { mount } from '@vue/test-utils'
import type { CommerceDraft } from '@/queries/commerceDrafts'
import DraftCustomerCard from '@/pages/commerce/orders/components/DraftCustomerCard.vue'

// Review fix (round 1, CRITICAL): the original covering test for this behavior hand-constructed
// `new ApiError(..., { phone: '…' })` directly — it exercised the CARD's rendering given an
// already-normalized error, but said nothing about whether the real wire response ever gets
// normalized into that shape in the first place. It didn't: `authFetch()` (the ONLY transport the
// draft endpoints use, since they aren't in the generated OpenAPI schema yet) calls
// `responseError()`, which used to read `body.errors` only — but the draft controller's
// `Response::validation()` renders `{ error: { details: { phone: "…" } } }` with NO `errors` key
// at all. So `toApiError(e).fieldErrors` was ALWAYS `{}` for every draft mutation failure: an
// invalid phone rendered nothing, "Save" looked dead, and the old test was a false green.
//
// This file drives the REAL pipeline instead: `@/queries/commerceDrafts` and `@/api/authFetch` are
// NOT mocked, only `global.fetch` is stubbed to return the actual backend envelope
// (`DraftConflictException`/`Response::validation()` shapes, verified against
// `extensions/commerce/src/Orders/DraftConflictException.php` and `Http/Response.php`) — so a
// failure travels update.mutateAsync() -> real updateDraft() -> real authFetch() -> real
// responseError() -> the card's own catch handler, exactly as it does in the browser.
vi.mock('@/stores/session', () => ({ useSessionStore: () => ({ accessToken: null }) }))

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

function mountCard(draftValue: CommerceDraft) {
  const pinia = createPinia()
  setActivePinia(pinia)
  return mount(DraftCustomerCard, {
    global: { plugins: [pinia, PiniaColada] },
    props: { draft: draftValue, canAttachUser: false },
  })
}

// `authFetch()` does `await import('@/stores/tenant')` on every call — a genuine dynamic import,
// not a cached microtask, and `src/__tests__/setup.ts`'s global `vi.resetModules()` (needed by
// other specs' typed-client caching) makes THIS file's first dynamic import re-transform the
// module graph from scratch. That easily outlasts a single `flushPromises()` tick (one macrotask),
// so a plain `await flushPromises()` after the click is flaky here specifically — `vi.waitFor`
// polls with real timers until the assertion holds (or its own timeout fails the test loudly).
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

    const wrapper = mountCard(draft({ uuid: 'd1', draft_revision: 0 }))
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

    const wrapper = mountCard(draft({ uuid: 'd1', draft_revision: 0, email: 'old@example.com' }))
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

    const wrapper = mountCard(draft({ uuid: 'd1', draft_revision: 0 }))
    await wrapper.find('[data-test="draft-customer-save"]').trigger('click')

    await waitFor(() => {
      expect(wrapper.find('[data-test="draft-customer-save-error"]').exists()).toBe(true)
    })
  })
})
