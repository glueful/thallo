<script setup lang="ts">
// Shared copy-to-clipboard affordance (orders-invoices-receipts plan, Task 9): a single small
// button any detail surface can drop next to a value it wants copyable verbatim — order number,
// customer email, a payment reference/gateway transaction id, a formatted address, etc. Deliberately
// dumb: it copies exactly the `value` prop it was given, never re-derives or re-formats anything
// itself — the caller (e.g. `formatAddress()`) owns producing the SAME string it already displays,
// so what's copied can never drift from what's shown.
import { ref } from 'vue'

const props = defineProps<{
  value: string
  /** Used as the button's aria-label (e.g. "Copy order number") — never rendered as visible text. */
  label: string
}>()

// Transient "copied" affordance only — no toast. A toast per click would be noisy for something a
// user may click several times in a row (order number, email, EACH address); the icon swap is
// feedback enough.
const copied = ref(false)
let resetTimer: ReturnType<typeof setTimeout> | undefined

async function copy() {
  try {
    await navigator.clipboard.writeText(props.value)
    copied.value = true
    clearTimeout(resetTimer)
    resetTimer = setTimeout(() => {
      copied.value = false
    }, 1500)
  } catch {
    // Clipboard unavailable (permissions/insecure context) — mirrors settings/payments.vue's
    // established pattern: the value stays visible/selectable, so this degrades silently.
  }
}
</script>

<template>
  <UButton
    :icon="copied ? 'i-lucide-check' : 'i-lucide-copy'"
    color="neutral"
    variant="ghost"
    size="xs"
    :aria-label="label"
    @click="copy"
  />
</template>
