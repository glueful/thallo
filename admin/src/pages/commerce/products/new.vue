<script setup lang="ts">
// Spec §5.4 (approved 2026-07-23): the Omnibox Launcher — the create surface for
// /commerce/products/new. One smart input plus a four-card type row, where typing and tapping
// write the same draft state; chips make the parse honest BEFORE anything exists. Interactive
// design reference (authoritative for look & behavior):
// https://claude.ai/code/artifact/9bd944d6-28b2-4309-ba81-943a5a035169 — see the spec for both
// artifact links. Retained from the prior revision: one page-level atomic "Create draft" action,
// no database row until it succeeds, type editable only here, single-flight with no automatic
// retry, router.replace() on success, and dirty-navigation-guard participation. The dormant
// section cards were removed (user decision 2026-07-23): the launcher stands alone, and the
// sections first appear in the editor the create lands in.
//
// unplugin-vue-router ranks the static `new` segment above the `[uuid]` param sibling, so this
// route always wins over the dynamic product page.
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useCommerceProductMutations, PRODUCT_TYPES } from '@/queries/commerceCatalog'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { formatMoney, parseMajorAmountToMinorUnits } from '@/composables/useMoney'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { slugify } from '@/utils/slugify'
import { parseOmnibox } from '@/utils/omniboxParse'
import { createDirtyRegistry, useUnsavedGuard } from '@/composables/useSectionState'
import UnsavedChangesModal from '@/components/UnsavedChangesModal.vue'

const router = useRouter()
const { success, error: notifyError } = useNotify()
const { create } = useCommerceProductMutations()
const { data: meta } = useCommerceMeta()

/** external/grouped products reject variants server-side — no SKU/price to collect. */
const PURCHASABLE_TYPES = ['physical', 'digital'] as const

/** The type row: OUR vocabulary (variants live INSIDE physical/digital — never Woo's
 * "variable product"), each card teaching its type at the one irreversible moment. */
const TYPE_CARDS = [
  { type: 'physical', icon: 'i-lucide-package', label: 'Physical', teach: 'shipped, stocked' },
  { type: 'digital', icon: 'i-lucide-download', label: 'Digital', teach: 'downloads' },
  { type: 'external', icon: 'i-lucide-external-link', label: 'External', teach: 'sold elsewhere' },
  { type: 'grouped', icon: 'i-lucide-boxes', label: 'Grouped', teach: 'a bundle' },
] as const

const state = reactive({
  text: '',
  type: 'physical' as (typeof PRODUCT_TYPES)[number],
  externalUrl: '',
})

const purchasable = computed(() => (PURCHASABLE_TYPES as readonly string[]).includes(state.type))

// ── The conservative parse (utils/omniboxParse) + BigInt money conversion ───────────────────
//
// If the lifted token doesn't survive the tenant's currency exponent (e.g. "89.99" under a
// zero-exponent currency), the WHOLE input stays the name — typed text is never dropped.
const currency = computed(() => meta.value?.currency ?? 'USD')

const parsed = computed(() => {
  const p = parseOmnibox(state.text, currency.value)
  if (p.majorToken === null) return { name: p.name, priceMinor: null as number | null }
  const exponent = meta.value?.currency_exponent ?? 2
  const minor = parseMajorAmountToMinorUnits(p.majorToken, exponent)
  if (minor === null) return { name: state.text.trim().replace(/\s+/g, ' '), priceMinor: null }
  return { name: p.name, priceMinor: Number(minor) }
})

const derivedSlug = computed(() => slugify(parsed.value.name))

/** The hint's price example, honest for the TENANT's currency: decimal currencies show the
 * decimal form; zero-decimal currencies (where "89.99" is not a valid amount) show a
 * whole-number code-marked form. Never "$" — the marker examples stay currency-neutral. */
const hintExample = computed(() => {
  const exponent = meta.value?.currency_exponent ?? 2
  return exponent > 0
    ? `“Aurora Desk Lamp 89.99” or “… 120 ${currency.value}”`
    : `“Aurora Desk Lamp 1200 ${currency.value}”`
})

const priceLabel = computed(() => {
  if (parsed.value.priceMinor === null) return null
  try {
    return formatMoney(parsed.value.priceMinor, {
      currency: currency.value,
      currency_exponent: meta.value?.currency_exponent ?? 2,
    })
  } catch {
    return null
  }
})

const externalUrlValid = computed(() => /^https?:\/\/.+\..+/.test(state.externalUrl.trim()))

const canCreate = computed(
  () =>
    parsed.value.name !== '' &&
    derivedSlug.value !== '' &&
    (state.type !== 'external' || externalUrlValid.value),
)

// ── Dirty-navigation guard (spec §5.4) — one synthetic registration, direct on the page's own
// registry (a component cannot inject its own provide; mirrors the prior revision). ───────────
const registry = createDirtyRegistry()
const { leaveConfirm, resolveLeave } = useUnsavedGuard(registry)
const created = ref(false)
const creating = ref(false)
const touched = computed(
  () => state.text !== '' || state.externalUrl !== '' || state.type !== 'physical',
)
registry.register({
  id: 'create',
  label: 'New product',
  blocked: computed(() => (touched.value && !created.value) || creating.value),
})

// ── Keyboard: 1-4 select a type when focus is outside the inputs (document-level, so it works
// anywhere on the page; cleaned up on unmount) ──────────────────────────────────────────────
function onPageKeydown(event: KeyboardEvent): void {
  const target = event.target as HTMLElement | null
  if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) return
  const idx = ['1', '2', '3', '4'].indexOf(event.key)
  if (idx >= 0) state.type = TYPE_CARDS[idx].type
}
onMounted(() => document.addEventListener('keydown', onPageKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onPageKeydown))

// ── The atomic Create draft action (single-flight, no automatic retry) ──────────────────────
const formError = ref<string | null>(null)
const omniInvalid = ref(false)
const linkError = ref<string | null>(null)
const omniInput = ref<HTMLInputElement | null>(null)

async function createDraft(): Promise<void> {
  if (creating.value || !canCreate.value) return
  creating.value = true
  formError.value = null
  omniInvalid.value = false
  linkError.value = null
  try {
    const product = await create.mutateAsync({
      slug: derivedSlug.value,
      name: parsed.value.name,
      type: state.type,
      status: 'draft',
      // SKU defaults to the (unique) slug; a missing price is an honest $0.00 draft — both
      // refined in the editor this page lands in.
      variants: purchasable.value
        ? [
            {
              sku: derivedSlug.value,
              price: parsed.value.priceMinor ?? 0,
              currency: currency.value,
            },
          ]
        : [],
      ...(state.type === 'external'
        ? { metadata: { external_url: state.externalUrl.trim() } }
        : {}),
    })
    created.value = true // release the guard BEFORE navigating
    success('Draft created', `Finish setting up “${parsed.value.name}” in the editor.`)
    router.replace(`/commerce/products/${product.uuid}`)
  } catch (e) {
    const err = toApiError(e)
    // No slug/SKU inputs exist — those errors land on the omnibox (the name is the source of
    // both) plus the banner; the external link error lands on its own field.
    const fields = Object.keys(err.fieldErrors)
    if (fields.some((f) => f === 'metadata.external_url')) {
      linkError.value = err.fieldErrors['metadata.external_url'] ?? null
    }
    if (fields.some((f) => f === 'slug' || f === 'name' || f === 'sku' || f === 'variants')) {
      omniInvalid.value = true
      omniInput.value?.focus()
    }
    formError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t create product')
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="commerce-product-create">
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/commerce/products"
            aria-label="Back to products"
          />
        </template>
        <template #title>New product</template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!-- min-h-full (not h-full): centers the launcher vertically in the panel while still
           scrolling naturally when the content is taller than the viewport (small screens,
           External's link field open). -->
      <div class="flex min-h-full flex-col justify-center" data-test="launcher-center">
        <div class="mx-auto w-full max-w-xl space-y-4">
          <h2 class="text-center text-lg font-semibold">What are you selling?</h2>

          <!-- focus-within ring: the border is ALWAYS primary, so without this a keyboard user
               gets no visible cue that focus has entered the input (a11y, C-question 2026-07-24). -->
          <div
            class="flex items-center gap-3 rounded-xl border-2 bg-default px-4 py-3 shadow-sm transition focus-within:ring-2 focus-within:ring-primary/40"
            :class="omniInvalid ? 'border-error' : 'border-primary'"
          >
            <span class="font-bold text-primary">›</span>
            <input
              ref="omniInput"
              v-model="state.text"
              type="text"
              autocomplete="off"
              spellcheck="false"
              placeholder="Name it, price it — or just tap a type below"
              aria-label="Describe your product"
              class="w-full min-w-0 border-0 bg-transparent text-base outline-none"
              data-test="omnibox-input"
              @keydown.enter="createDraft"
            />
          </div>

          <!-- The parse rules, taught in one line (spec §5.4): without this, the money-token
               syntax is only discoverable by accident. Currency-neutral: the examples use the
               TENANT's own currency code, never "$". The bare-integer caveat is the one
               genuinely surprising rule ("Lamp 89" keeps the 89 — model numbers are names). -->
          <p v-if="purchasable" class="text-center text-xs text-muted" data-test="omnibox-hint">
            Tip: end with a price and it’s picked up automatically — {{ hintExample }}. Bare whole
            numbers stay in the name; mark them with {{ currency }}.
          </p>

          <div
            class="grid grid-cols-2 gap-2 sm:grid-cols-4"
            role="radiogroup"
            aria-label="Product type"
          >
            <button
              v-for="card in TYPE_CARDS"
              :key="card.type"
              type="button"
              role="radio"
              :aria-checked="state.type === card.type"
              :data-test="`type-card-${card.type}`"
              class="rounded-lg border p-2.5 text-center transition"
              :class="
                state.type === card.type
                  ? 'border-primary shadow-md'
                  : 'border-default hover:border-accented'
              "
              @click="state.type = card.type"
            >
              <UIcon
                :name="card.icon"
                class="mx-auto size-5"
                :class="state.type === card.type ? 'text-primary' : 'text-muted'"
              />
              <div class="mt-1 text-xs font-bold">{{ card.label }}</div>
              <div class="text-[0.65rem] text-muted">{{ card.teach }}</div>
            </button>
          </div>

          <UFormField
            v-if="state.type === 'external'"
            label="External link"
            required
            help="Where “Add to cart” sends the customer. Editable later in Details."
            :error="linkError ?? undefined"
          >
            <UInput
              v-model="state.externalUrl"
              type="url"
              placeholder="https://partner-store.example/standing-desk"
              class="w-full"
              data-test="external-url-input"
              @keydown.enter="createDraft"
            />
          </UFormField>

          <!-- Chips: the parse made honest — everything the Create action will do, visible first. -->
          <div
            class="flex min-h-7 flex-wrap justify-center gap-1.5"
            data-test="omnibox-chips"
            aria-live="polite"
          >
            <template v-if="parsed.name">
              <UBadge color="primary" variant="subtle" data-test="chip-name"
                >✓ {{ parsed.name }}</UBadge
              >
              <UBadge color="neutral" variant="subtle" data-test="chip-slug"
                >slug {{ derivedSlug }}</UBadge
              >
              <UBadge v-if="purchasable" color="neutral" variant="subtle" data-test="chip-sku"
                >SKU {{ derivedSlug }}</UBadge
              >
            </template>
            <template v-if="purchasable">
              <UBadge v-if="priceLabel" color="primary" variant="subtle" data-test="chip-price"
                >✓ {{ priceLabel }} {{ currency }}</UBadge
              >
              <UBadge
                v-else-if="parsed.name"
                color="warning"
                variant="subtle"
                data-test="chip-no-price"
                >no price yet → 0 {{ currency }} draft</UBadge
              >
            </template>
            <template v-else-if="state.type === 'external'">
              <UBadge
                color="neutral"
                variant="subtle"
                icon="i-lucide-external-link"
                data-test="chip-external"
                >external — no price, links out</UBadge
              >
              <UBadge
                v-if="externalUrlValid"
                color="primary"
                variant="subtle"
                data-test="chip-link-ok"
                >✓ link ok</UBadge
              >
              <UBadge
                v-else-if="parsed.name"
                color="warning"
                variant="subtle"
                data-test="chip-link-required"
                >link required</UBadge
              >
            </template>
            <UBadge
              v-else-if="state.type === 'grouped'"
              color="neutral"
              variant="subtle"
              icon="i-lucide-boxes"
              data-test="chip-grouped"
              >bundle — collect products after create</UBadge
            >
          </div>

          <UAlert
            v-if="formError"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :description="formError"
            data-test="product-create-error"
          />

          <div class="text-center">
            <UButton
              size="lg"
              label="Create  ↵"
              data-test="product-create-submit"
              :loading="creating"
              :disabled="creating || !canCreate"
              @click="createDraft"
            />
            <!-- The draft promise moved here from the button label ("Create draft" → "Create"):
                 it must stay READ somewhere — nothing goes live until activation. -->
            <p class="mt-2 text-xs text-muted">
              You’ll land in the full editor to finish up — it stays a draft until you activate it.
            </p>
          </div>

          <div
            class="flex items-center gap-3 rounded-lg border border-default bg-elevated/40 px-4 py-3 opacity-70"
            data-test="import-doorway"
          >
            <UIcon name="i-lucide-import" class="size-5 text-muted" />
            <span class="flex-1 text-xs"
              ><b>Already selling somewhere else?</b>
              <span class="text-muted">CSV import arrives with the product importer.</span></span
            >
            <UBadge color="neutral" variant="subtle" size="sm">soon</UBadge>
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <UnsavedChangesModal :state="leaveConfirm" @resolve="resolveLeave" />
</template>
