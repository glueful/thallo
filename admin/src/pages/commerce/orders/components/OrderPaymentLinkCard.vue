<script setup lang="ts">
// Payment links Task 13 (payment-links spec §2.2/§2.4): the order detail's payment-link card —
// mint, status, revoke, and the two email deliveries. Self-querying (mirrors OrderPaymentCard.vue
// and OrderNotes.vue: a feature card owns its own read, the parent supplies the order).
//
// ## Custody: the URL lives HERE and nowhere else
//
// `visibleUrl` is a plain component-local ref. It is set only from a mint/delivery-failure
// response, is never written to a store or the query cache, is never logged, and cannot be
// re-fetched — the engine keeps a hash, so `GET .../payment-link` (this card's status query)
// carries no token by construction. Navigating away, refreshing, or dismissing the surface drops
// the plaintext for good; the recovery is a regenerate, which is exactly the honest answer.
//
// "Send this link" (`mode=current`) is the one flow that hands a token back, and it derives it
// from the URL THE OPERATOR CAN SEE — via `paymentLinkTokenFromUrl()` (platform URL API + 64-hex
// shape gate), never ad-hoc string splitting. A visible URL that doesn't parse, or whose final
// segment isn't a token, disables current-send with a stated reason rather than posting a guess.
//
// ## Gating
//
// The card exists only for an `origin='admin'` order still `pending_payment` — the exact pair the
// engine's own mint refuses outside of (`order_not_admin_origin` / `order_not_pending_payment`).
// The guard is HERE as well as in the parent's `v-if`, so the card can never render (or fire its
// query) against an order that could not carry a link.
import { computed, ref } from 'vue'
import type { CommerceOrder } from '@/queries/commerceOrders'
import {
  clampPaymentLinkTtl,
  newPaymentLinkIdempotencyKey,
  paymentLinkRefusalReason,
  paymentLinkTokenFromUrl,
  useOrderPaymentLink,
  useOrderPaymentLinkMutations,
  PAYMENT_LINK_TTL_DEFAULT,
  PAYMENT_LINK_TTL_MAX,
  PAYMENT_LINK_TTL_MIN,
  type PaymentLinkSendEnvelope,
} from '@/queries/commercePaymentLinks'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useCommerceEmailSettings } from '@/queries/commerceSettings'
import { toApiError } from '@/api/errors'
import CopyButton from '@/components/CopyButton.vue'

const props = defineProps<{
  order: CommerceOrder
  canManage: boolean
}>()

const eligible = computed(
  () => props.order.origin === 'admin' && props.order.status === 'pending_payment',
)

const { data, status } = useOrderPaymentLink(() => props.order.uuid, eligible)
const { data: meta } = useCommerceMeta()
const { data: emailSettings } = useCommerceEmailSettings()
const { create, revoke, send } = useOrderPaymentLinkMutations()

const link = computed(() => data.value?.link ?? null)

/**
 * Ruling 3's trigger. TWO independent facts say a provider session was exposed and neither is a
 * restatement of the other: the LINK's own `provider_session_issued`, and the ORDER-level
 * exposure decision's `requires_risk_acknowledgement` (which considers every link the order ever
 * had, including revoked predecessors). Either one means cancellation has stopped being
 * automatic, so the warning variant answers to both rather than to the link alone.
 */
const sessionExposed = computed(
  () =>
    link.value?.provider_session_issued === true ||
    data.value?.exposure.requires_risk_acknowledgement === true,
)

// ── One-time URL custody ──────────────────────────────────────────────────────────────────────
const visibleUrl = ref<string | null>(null)
const visibleToken = computed(() =>
  visibleUrl.value === null ? null : paymentLinkTokenFromUrl(visibleUrl.value),
)

// ── TTL (clamped to the engine's own 1..30 window, in the UI and in the request) ───────────────
const ttlDays = ref(String(PAYMENT_LINK_TTL_DEFAULT))
function clampTtlInput() {
  ttlDays.value = String(clampPaymentLinkTtl(ttlDays.value))
}
const effectiveTtl = computed(() => clampPaymentLinkTtl(ttlDays.value))

const actionError = ref<string | null>(null)
const sendEnvelope = ref<PaymentLinkSendEnvelope | null>(null)

const regenerateOpen = ref(false)
const revokeOpen = ref(false)
const sendRegenerateOpen = ref(false)

// ── Send preconditions: three INDEPENDENT gates, each with its own reason ──────────────────────
// (order email present) AND (the `payment_request` switch on) AND (a rich email channel exists).
// Every failing one is listed, so an operator fixes all of them in one pass rather than one
// refusal at a time.
const paymentRequestEnabled = computed(
  () =>
    emailSettings.value?.templates.find((t) => t.template === 'payment_request')?.enabled.value ===
    true,
)
const emailAvailable = computed(() => meta.value?.email_available === true)

const sendReasons = computed<string[]>(() => {
  const reasons: string[] = []
  if (!props.order.email) {
    reasons.push('This order has no email address, so there is nobody to send the link to.')
  }
  if (!emailAvailable.value) {
    reasons.push('This installation has no rich email channel, so nothing can be emailed.')
  }
  if (!paymentRequestEnabled.value) {
    reasons.push(
      'The payment request email is switched off for this store (Settings → Emails).',
    )
  }
  return reasons
})
const canSend = computed(() => props.canManage && sendReasons.value.length === 0)

/** Why current-mode send can't run right now — the URL is the input, and it is one-time. */
const currentSendReason = computed<string | null>(() => {
  if (visibleUrl.value === null) {
    return 'The link is shown once, and this one is no longer on screen. Use “Regenerate and send”.'
  }
  if (visibleToken.value === null) {
    return 'This link’s address can’t be read, so it can’t be emailed. Use “Regenerate and send”.'
  }
  return null
})
const canSendCurrent = computed(() => canSend.value && currentSendReason.value === null)

// ── Idempotency: ONE key per operator intent, reused on retry of that same intent ──────────────
// A double submit therefore cannot become two deliveries — and neither can a retry of a delivery
// that failed, which the server answers as a replay of the recorded outcome. A NEW intent (after
// a success, or a switch between the two modes) gets a fresh key.
const sendKey = ref<string | null>(null)
const sendIntent = ref<'current' | 'regenerate' | null>(null)
const sendInFlight = ref(false)

function keyFor(mode: 'current' | 'regenerate'): string {
  if (sendKey.value === null || sendIntent.value !== mode) {
    sendKey.value = newPaymentLinkIdempotencyKey()
    sendIntent.value = mode
  }
  return sendKey.value
}

const deliveryFailed = computed(() => sendEnvelope.value?.receipt.status === 'failed')

function fmtDateTime(v: string | null): string {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
const expiresDisplay = computed(() => fmtDateTime(link.value?.expires_at ?? null))

/**
 * Every refusal here carries a machine-readable `error.details.reason`. One of them changes what
 * this card may keep showing: `payment_link_changed` is the server saying the address on screen is
 * NOT the order's current link (another tab regenerated it, say). Continuing to display it would
 * be offering a dead credential the operator might paste to a customer, so it is dropped — the
 * status read below stays authoritative and "Regenerate and send" is the way forward.
 */
function reportRefusal(e: unknown) {
  actionError.value = toApiError(e).message
  if (paymentLinkRefusalReason(e) === 'payment_link_changed') visibleUrl.value = null
}

async function mint() {
  actionError.value = null
  try {
    const minted = await create.mutateAsync({ uuid: props.order.uuid, ttlDays: effectiveTtl.value })
    // The ONE assignment of plaintext in this component, straight from the response.
    visibleUrl.value = minted.url
    regenerateOpen.value = false
  } catch (e) {
    reportRefusal(e)
  }
}

async function confirmRevoke() {
  actionError.value = null
  try {
    await revoke.mutateAsync(props.order.uuid)
    visibleUrl.value = null
    sendEnvelope.value = null
    revokeOpen.value = false
  } catch (e) {
    // The server stays authoritative (a since-changed status races this into a 409) — the dialog
    // stays open for retry rather than closing as if the revoke had gone through.
    reportRefusal(e)
  }
}

async function runSend(mode: 'current' | 'regenerate') {
  if (sendInFlight.value) return
  // Current-mode needs a token the VISIBLE url actually yielded. The button is already disabled
  // without one; this is the guard that makes that a fact rather than a UI convention (and keeps
  // the call below free of a non-null cast).
  const token = mode === 'current' ? visibleToken.value : null
  if (mode === 'current' && token === null) return
  sendInFlight.value = true
  actionError.value = null
  sendEnvelope.value = null
  const idempotencyKey = keyFor(mode)
  try {
    const envelope = await send.mutateAsync({
      uuid: props.order.uuid,
      input:
        token !== null
          ? { mode: 'current', token }
          : { mode: 'regenerate', ttl_days: effectiveTtl.value },
      idempotencyKey,
    })
    sendEnvelope.value = envelope
    // A regenerate whose DELIVERY failed hands back the new link's one-time URL — the link stays
    // active and this is its only copy, so it takes over the one-time surface above.
    if (envelope.url !== null) visibleUrl.value = envelope.url
    if (envelope.receipt.status === 'sent') {
      // Intent complete: a later send is a NEW intent and must not replay this one.
      sendKey.value = null
      sendIntent.value = null
    }
  } catch (e) {
    reportRefusal(e)
  } finally {
    sendInFlight.value = false
    sendRegenerateOpen.value = false
  }
}
</script>

<template>
  <UCard v-if="eligible" data-test="payment-link-card">
    <template #header>
      <h3 class="text-sm font-medium">Payment link</h3>
    </template>

    <div v-if="status === 'pending'" class="flex justify-center py-6" data-test="payment-link-loading">
      <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
    </div>

    <UAlert
      v-else-if="status === 'error'"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Couldn’t load the payment link"
      description="Something went wrong loading this order's payment link. Try again."
      data-test="payment-link-error"
    />

    <div v-else class="flex flex-col gap-4">
      <!-- The one-time surface. Rendered exactly once per minted URL, from component state only:
           there is no way to get it back after this. -->
      <div
        v-if="visibleUrl"
        class="flex flex-col gap-2 rounded-md border border-warning bg-warning/5 p-3"
        data-test="payment-link-url-surface"
      >
        <div class="flex items-center gap-1">
          <code class="break-all text-sm text-default" data-test="payment-link-url">{{ visibleUrl }}</code>
          <CopyButton :value="visibleUrl" label="Copy payment link" data-test="payment-link-url-copy" />
        </div>
        <p class="text-xs text-muted" data-test="payment-link-url-once">
          This address is shown once. Copy it now — it can’t be shown again, and the only way to get
          a new one is to regenerate the link.
        </p>
        <div>
          <UButton
            size="xs"
            variant="ghost"
            color="neutral"
            data-test="payment-link-url-dismiss"
            @click="visibleUrl = null"
          >
            Hide
          </UButton>
        </div>
      </div>

      <!-- No active link: mint one, with the TTL clamped to the engine's own 1..30 window. -->
      <div v-if="!link" class="flex flex-col gap-3" data-test="payment-link-empty">
        <p class="text-sm text-muted">
          This order has no payment link. Create one to let the customer pay online — stock stays
          reserved until it expires.
        </p>
        <div v-if="canManage" class="flex flex-wrap items-end gap-2">
          <UFormField label="Expires in (days)" class="w-40">
            <UInput
              v-model="ttlDays"
              type="number"
              :min="PAYMENT_LINK_TTL_MIN"
              :max="PAYMENT_LINK_TTL_MAX"
              class="w-full"
              data-test="payment-link-ttl"
              @blur="clampTtlInput"
            />
          </UFormField>
          <UButton
            color="primary"
            icon="i-lucide-link"
            data-test="payment-link-create"
            :loading="create.isLoading.value"
            @click="mint"
          >
            Create payment link
          </UButton>
        </div>
      </div>

      <!-- Live link. -->
      <div v-else class="flex flex-col gap-3" data-test="payment-link-live">
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <UBadge
            :color="link.status === 'active' ? 'success' : 'neutral'"
            variant="subtle"
            data-test="payment-link-status"
          >
            {{ link.status }}
          </UBadge>
        </div>

        <!-- Ruling 3: before any provider session is exposed the link is an ordinary hold on
             stock. Once one HAS been exposed, cancelling the order stops being automatic — it
             needs the late-payment risk acknowledged — so the copy changes rather than staying
             quietly reassuring. -->
        <p
          v-if="!sessionExposed"
          class="text-sm text-muted"
          data-test="payment-link-reserved"
        >
          Stock reserved until {{ expiresDisplay }}.
        </p>
        <UAlert
          v-else
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          data-test="payment-link-exposed-warning"
          title="A checkout session has been opened"
          :description="`Stock stays reserved until ${expiresDisplay}. Because the customer already reached the payment provider, cancelling this order now requires acknowledging the late-payment risk.`"
        />

        <!-- Honest about what this store can and cannot do at the gateway. -->
        <p class="text-xs text-muted" data-test="payment-link-gateway-note">
          The payment provider owns a checkout session once the customer opens it; nothing here
          reaches into it. If money arrives after you close this order, the recovery is to mark the
          order paid, or to cancel it with the late-payment risk acknowledged.
        </p>

        <div v-if="canManage" class="flex flex-wrap items-center gap-2">
          <UButton
            size="sm"
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            data-test="payment-link-regenerate"
            @click="regenerateOpen = true"
          >
            Regenerate
          </UButton>
          <UButton
            size="sm"
            color="error"
            variant="outline"
            icon="i-lucide-ban"
            data-test="payment-link-revoke"
            @click="revokeOpen = true"
          >
            Revoke
          </UButton>
        </div>

        <div v-if="canManage" class="flex flex-col gap-2" data-test="payment-link-send">
          <div class="flex flex-wrap items-center gap-2">
            <UButton
              size="sm"
              icon="i-lucide-mail"
              data-test="payment-link-send-current"
              :disabled="!canSendCurrent"
              :loading="sendInFlight && sendIntent === 'current'"
              @click="runSend('current')"
            >
              Send this link
            </UButton>
            <UButton
              size="sm"
              color="neutral"
              variant="outline"
              icon="i-lucide-mail-plus"
              data-test="payment-link-send-regenerate"
              :disabled="!canSend"
              @click="sendRegenerateOpen = true"
            >
              Regenerate and send
            </UButton>
          </div>
          <ul v-if="sendReasons.length > 0" class="flex flex-col gap-0.5">
            <li
              v-for="reason in sendReasons"
              :key="reason"
              class="text-xs text-muted"
              data-test="payment-link-send-reason"
            >
              {{ reason }}
            </li>
          </ul>
          <p v-if="currentSendReason" class="text-xs text-muted" data-test="payment-link-current-reason">
            {{ currentSendReason }}
          </p>
        </div>
      </div>

      <!-- Delivery outcome, derived entirely from the server's own receipt. -->
      <div
        v-if="sendEnvelope"
        class="flex flex-col gap-1 rounded-md border border-default p-3 text-sm"
        data-test="payment-link-send-result"
      >
        <p data-test="payment-link-send-message">{{ sendEnvelope.message }}</p>
        <div class="flex flex-wrap items-center gap-2">
          <UBadge
            :color="deliveryFailed ? 'error' : 'success'"
            variant="subtle"
            size="sm"
            data-test="payment-link-send-status"
          >
            {{ sendEnvelope.receipt.status }}
          </UBadge>
          <UBadge
            v-if="sendEnvelope.receipt.replayed"
            color="neutral"
            variant="subtle"
            size="sm"
            data-test="payment-link-send-replayed"
          >
            Replayed
          </UBadge>
        </div>
        <p v-if="deliveryFailed && visibleUrl" class="text-muted" data-test="payment-link-failure-note">
          The link is still active — only the email failed. Copy the address above and send it to
          the customer yourself.
        </p>
        <p v-if="sendEnvelope.recovery" class="text-muted" data-test="payment-link-send-recovery">
          This delivery can’t be repeated under the same key. Start a new send, or regenerate the
          link and send that.
        </p>
        <div>
          <UButton
            size="xs"
            variant="ghost"
            color="neutral"
            data-test="payment-link-send-dismiss"
            @click="sendEnvelope = null"
          >
            Dismiss
          </UButton>
        </div>
      </div>

      <UAlert
        v-if="actionError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :title="actionError"
        data-test="payment-link-action-error"
      />
    </div>

    <!-- Regenerate: destructive to the CURRENT link, so it says so before it mints. -->
    <UModal v-model:open="regenerateOpen" title="Regenerate payment link">
      <template #body>
        <p class="text-sm" data-test="payment-link-regenerate-dialog">
          Regenerating mints a new link and invalidates the existing one immediately — anyone
          holding the old address will find it no longer works. The new address is shown once.
        </p>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            data-test="payment-link-regenerate-dismiss"
            @click="regenerateOpen = false"
          >
            Dismiss
          </UButton>
          <UButton
            color="primary"
            data-test="payment-link-regenerate-confirm"
            :loading="create.isLoading.value"
            @click="mint"
          >
            Regenerate link
          </UButton>
        </div>
      </template>
    </UModal>

    <UModal v-model:open="revokeOpen" title="Revoke payment link">
      <template #body>
        <p class="text-sm" data-test="payment-link-revoke-dialog">
          Revoking this link makes it stop working immediately. The order stays awaiting payment,
          and you can create a new link at any time.
        </p>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            data-test="payment-link-revoke-dismiss"
            @click="revokeOpen = false"
          >
            Dismiss
          </UButton>
          <UButton
            color="error"
            data-test="payment-link-revoke-confirm"
            :loading="revoke.isLoading.value"
            @click="confirmRevoke"
          >
            Revoke link
          </UButton>
        </div>
      </template>
    </UModal>

    <!-- Regenerate AND send: the same invalidation, plus an email. Works with no URL on screen —
         that is the point of it. -->
    <UModal v-model:open="sendRegenerateOpen" title="Regenerate and send">
      <template #body>
        <p class="text-sm" data-test="payment-link-send-regenerate-dialog">
          This mints a new link, invalidates the existing one immediately, and emails the new
          address to {{ order.email }}.
        </p>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            data-test="payment-link-send-regenerate-dismiss"
            @click="sendRegenerateOpen = false"
          >
            Dismiss
          </UButton>
          <UButton
            color="primary"
            data-test="payment-link-send-regenerate-confirm"
            :loading="sendInFlight && sendIntent === 'regenerate'"
            @click="runSend('regenerate')"
          >
            Regenerate and send
          </UButton>
        </div>
      </template>
    </UModal>
  </UCard>
</template>
