<script setup lang="ts">
// Task 14 (admin-order-creation): mode toggle (in_store default) — delivery reveals a shipping
// address form plus a "quoted" shipping-method select. There is no dedicated admin endpoint that
// lists a LIVE per-address shipping quote (only the storefront checkout has one); the closest
// server-verified universe is the store's own configured, enabled zone methods
// (`useCommerceShippingZones()`, already fetching each zone's full `methods` projection in one
// call) — the SAME method uuids `DbShippingRateProvider::quote()` mints its own quotes from
// (`ShippingQuote::$id` is the method row's `uuid`). This is UX guidance only: the server's own
// live-quote check at PATCH/finalize time stays authoritative and is surfaced verbatim on
// rejection, same precedent as `RefundSlideover`'s client-computed ceiling.
import { ref, computed, watch } from 'vue'
import { useCommerceDraftMutations, type CommerceDraft } from '@/queries/commerceDrafts'
import { useCommerceShippingZones } from '@/queries/commerceSettings'
import { toApiError } from '@/api/errors'

const props = defineProps<{
  draft: CommerceDraft
}>()

const { update } = useCommerceDraftMutations()

// USelect/reka-ui reserve the empty string as "no selection" and reject a SelectItem with an
// empty `value` — so the "Choose a method…" placeholder option uses a non-empty sentinel,
// translated to `null` (no shipping method chosen) at the query/save boundary, same idiom as
// commerce/products/index.vue's `ALL` sentinel.
const NO_SHIPPING_METHOD = '__none__'

const mode = ref<'in_store' | 'delivery'>('in_store')
const name = ref('')
const line1 = ref('')
const line2 = ref('')
const city = ref('')
const region = ref('')
const postcode = ref('')
const country = ref('')
const phone = ref('')
const shippingMethod = ref('')
const fieldErrors = ref<Record<string, string>>({})
// Review fix (round 1): mirrors DraftCustomerCard's own saveError — a stale_revision/currency
// conflict or a bare 5xx has no field to attach to, so without this the card just stopped loading
// with no visible failure at all.
const saveError = ref<string | null>(null)
const saved = ref(false)

function syncFromDraft() {
  mode.value = props.draft.fulfillment_mode
  const shipping = (props.draft.addresses?.shipping ?? {}) as Record<string, unknown>
  const str = (v: unknown) => (typeof v === 'string' ? v : '')
  name.value = str(shipping.name)
  line1.value = str(shipping.line1)
  line2.value = str(shipping.line2)
  city.value = str(shipping.city)
  region.value = str(shipping.region)
  postcode.value = str(shipping.postcode)
  country.value = str(shipping.country)
  phone.value = str(shipping.phone)
  shippingMethod.value = props.draft.shipping_method ?? NO_SHIPPING_METHOD
  fieldErrors.value = {}
  saveError.value = null
  saved.value = false
}

watch(() => props.draft.uuid, syncFromDraft, { immediate: true })

const { data: zonesPage } = useCommerceShippingZones({ page: 1, perPage: 100 })
const shippingMethodItems = computed(() => {
  const zones = zonesPage.value?.zones ?? []
  return zones.flatMap((zone) =>
    zone.methods
      .filter((m) => m.enabled)
      .map((m) => ({ label: `${zone.name} — ${m.label}`, value: m.uuid })),
  )
})

async function save() {
  fieldErrors.value = {}
  saveError.value = null
  saved.value = false
  try {
    if (mode.value === 'in_store') {
      await update.mutateAsync({
        uuid: props.draft.uuid,
        input: { fulfillment_mode: 'in_store', expected_revision: props.draft.draft_revision },
      })
    } else {
      await update.mutateAsync({
        uuid: props.draft.uuid,
        input: {
          fulfillment_mode: 'delivery',
          // Unlike the top-level PATCH body (where an ABSENT key means "leave untouched" and an
          // explicit `null` clears it), `addresses.shipping` itself is a single PATCH field whose
          // value the server writes as a whole object — there is no per-sub-field presence
          // semantics inside it to preserve. So a blank sub-field is simply omitted from the
          // object (`|| undefined`, dropped by `JSON.stringify`) rather than sent as an explicit
          // `null`: the two are equivalent from the server's point of view here, and omitting
          // reads more naturally as "this line wasn't filled in" than "clear this address field".
          addresses: {
            shipping: {
              name: name.value.trim() || undefined,
              line1: line1.value.trim() || undefined,
              line2: line2.value.trim() || undefined,
              city: city.value.trim() || undefined,
              region: region.value.trim() || undefined,
              postcode: postcode.value.trim() || undefined,
              country: country.value.trim() || undefined,
              phone: phone.value.trim() || undefined,
            },
          },
          shipping_method: shippingMethod.value === NO_SHIPPING_METHOD ? null : shippingMethod.value,
          expected_revision: props.draft.draft_revision,
        },
      })
    }
    saved.value = true
  } catch (e) {
    const err = toApiError(e)
    fieldErrors.value = err.fieldErrors
    saveError.value = Object.keys(err.fieldErrors).length === 0 ? err.message : null
  }
}
</script>

<template>
  <UCard data-test="draft-fulfillment-card">
    <template #header>
      <h3 class="text-sm font-medium">Fulfillment</h3>
    </template>

    <div class="flex flex-col gap-4">
      <div class="flex gap-2">
        <UButton
          :color="mode === 'in_store' ? 'primary' : 'neutral'"
          :variant="mode === 'in_store' ? 'solid' : 'outline'"
          data-test="draft-mode-in-store"
          @click="mode = 'in_store'"
        >
          In-store
        </UButton>
        <UButton
          :color="mode === 'delivery' ? 'primary' : 'neutral'"
          :variant="mode === 'delivery' ? 'solid' : 'outline'"
          data-test="draft-mode-delivery"
          @click="mode = 'delivery'"
        >
          Delivery
        </UButton>
      </div>

      <div v-if="mode === 'delivery'" class="flex flex-col gap-3" data-test="draft-delivery-fields">
        <UFormField label="Recipient name" :error="fieldErrors.addresses">
          <UInput v-model="name" class="w-full" data-test="draft-address-name" />
        </UFormField>
        <UFormField label="Address line 1">
          <UInput v-model="line1" class="w-full" data-test="draft-address-line1" />
        </UFormField>
        <UFormField label="Address line 2">
          <UInput v-model="line2" class="w-full" data-test="draft-address-line2" />
        </UFormField>
        <div class="grid grid-cols-2 gap-3">
          <UFormField label="City">
            <UInput v-model="city" class="w-full" data-test="draft-address-city" />
          </UFormField>
          <UFormField label="Region">
            <UInput v-model="region" class="w-full" data-test="draft-address-region" />
          </UFormField>
          <UFormField label="Postal code">
            <UInput v-model="postcode" class="w-full" data-test="draft-address-postcode" />
          </UFormField>
          <UFormField label="Country" required>
            <UInput v-model="country" class="w-full" data-test="draft-address-country" />
          </UFormField>
        </div>
        <UFormField label="Phone">
          <UInput v-model="phone" class="w-full" data-test="draft-address-phone" />
        </UFormField>

        <UFormField label="Shipping method" :error="fieldErrors.shipping_method">
          <USelect
            v-model="shippingMethod"
            :items="[{ label: 'Choose a method…', value: NO_SHIPPING_METHOD }, ...shippingMethodItems]"
            class="w-full"
            data-test="draft-shipping-method"
          />
        </UFormField>
      </div>

      <UAlert
        v-if="saveError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :title="saveError"
        data-test="draft-fulfillment-save-error"
      />

      <div class="flex items-center gap-2">
        <UButton :loading="update.isLoading.value" data-test="draft-fulfillment-save" @click="save">
          Save fulfillment
        </UButton>
        <span v-if="saved" class="text-sm text-success" data-test="draft-fulfillment-saved">Saved</span>
      </div>
    </div>
  </UCard>
</template>
