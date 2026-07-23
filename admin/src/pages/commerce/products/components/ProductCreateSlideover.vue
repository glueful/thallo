<script setup lang="ts">
import { computed, reactive, useTemplateRef, watch } from 'vue'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { useCommerceProductMutations, PRODUCT_TYPES } from '@/queries/commerceCatalog'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { formatMoney } from '@/composables/useMoney'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { slugify } from '@/utils/slugify'

/**
 * Draft-first create (Woo-style): collect only what the API can't derive —
 * a name, the structural type, and (for purchasable types) the starting
 * price, since `POST /commerce/products` requires >=1 variant for
 * physical/digital. Everything else is derived (slug from name, SKU from
 * slug, currency from tenant meta, status always `draft`) and refined in
 * the product editor the index navigates into on `created`.
 */

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; created: [uuid: string] }>()

const { success, error: notifyError } = useNotify()
const { create } = useCommerceProductMutations()
const { data: meta } = useCommerceMeta()

const typeItems = PRODUCT_TYPES.map((t) => ({ label: t, value: t }))

/** external/grouped products reject variants server-side — no price to ask for. */
const PURCHASABLE_TYPES = ['physical', 'digital'] as const

const schema = z.object({
  name: z
    .string()
    .min(1, 'Name is required.')
    .refine((v) => slugify(v).length > 0, 'Name must contain letters or numbers.'),
  type: z.enum(PRODUCT_TYPES),
  price: z
    .number({ message: 'Price is required.' })
    .int('Price must be a whole number of minor units.')
    .nonnegative('Price cannot be negative.'),
  // Optional: a plain digit string, blank allowed (no compare-at price set). Never coerced
  // through Number() until submit, and only after this regex confirms a clean non-negative
  // integer — mirrors VariantsPanel's own compare-at field for this same task.
  compareAtPriceInput: z
    .string()
    .regex(/^\d*$/, 'Compare-at price must be a whole, non-negative number.'),
})
type Schema = z.output<typeof schema>

function blankState() {
  return {
    name: '',
    type: 'physical' as const,
    price: 0,
    compareAtPriceInput: '',
  }
}

const state = reactive(blankState())

watch(
  () => props.open,
  (open) => {
    if (open) Object.assign(state, blankState())
  },
)

const purchasable = computed(() => (PURCHASABLE_TYPES as readonly string[]).includes(state.type))
const derivedSlug = computed(() => slugify(state.name))
const currency = computed(() => meta.value?.currency ?? 'USD')

const pricePreview = computed(() => {
  const exponent = meta.value?.currency_exponent ?? 2
  if (!Number.isInteger(state.price) || state.price < 0) return null
  try {
    return formatMoney(state.price, { currency: currency.value, currency_exponent: exponent })
  } catch {
    return null
  }
})

const createForm = useTemplateRef<Form<Schema>>('createForm')

async function onSubmit(event: FormSubmitEvent<Schema>) {
  const slug = slugify(event.data.name)
  const isPurchasable = (PURCHASABLE_TYPES as readonly string[]).includes(event.data.type)
  const compareAt =
    event.data.compareAtPriceInput === '' ? null : Number(event.data.compareAtPriceInput)
  try {
    const created = await create.mutateAsync({
      slug,
      name: event.data.name,
      type: event.data.type,
      status: 'draft',
      // SKU defaults to the (unique) slug; refined on the Variants tab. `compare_at_price` is
      // OMITTED entirely when blank — never sent as an explicit null on this brand-new variant.
      variants: isPurchasable
        ? [
            {
              sku: slug,
              price: event.data.price,
              currency: currency.value,
              ...(compareAt !== null ? { compare_at_price: compareAt } : {}),
            },
          ]
        : [],
    })
    success('Draft created', `Finish setting up “${event.data.name}” in the editor.`)
    emit('created', created.uuid)
    emit('update:open', false)
  } catch (e) {
    const err = toApiError(e)
    // The form has no slug/variant fields — surface those server errors on
    // the fields the user CAN act on.
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name: name === 'slug' ? 'name' : name === 'variants' ? 'price' : name,
      message,
    }))
    if (fieldErrors.length > 0) createForm.value?.setErrors(fieldErrors)
    notifyError(err, 'Couldn’t create product')
  }
}
</script>

<template>
  <USlideover
    :open="open"
    title="Create product"
    :ui="{ content: 'sm:max-w-xl' }"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <UForm
        id="product-create-form"
        ref="createForm"
        :schema="schema"
        :state="state"
        class="space-y-6"
        @submit="onSubmit"
      >
        <p class="text-sm text-muted">
          Starts as a draft — add images, stock, categories and everything else in the editor right
          after creating.
        </p>

        <div class="grid grid-cols-2 gap-4">
          <UFormField label="Name" name="name" required class="col-span-2">
            <UInput
              v-model="state.name"
              placeholder="e.g. Wireless mouse"
              class="w-full"
              data-test="product-name-input"
            />
          </UFormField>

          <UFormField label="Type" name="type">
            <USelect
              v-model="state.type"
              :items="typeItems"
              class="w-full"
              data-test="product-type-select"
            />
          </UFormField>

          <UFormField
            v-if="purchasable"
            label="Price"
            name="price"
            required
            help="Minor units (e.g. cents) — the smallest unit of the currency."
          >
            <UInput
              v-model.number="state.price"
              type="number"
              :min="0"
              class="w-full"
              data-test="product-price-input"
            />
          </UFormField>

          <UFormField
            v-if="purchasable"
            label="Compare-at price"
            name="compareAtPriceInput"
            help="Optional, minor units"
          >
            <UInput
              v-model="state.compareAtPriceInput"
              class="w-full"
              placeholder="Optional"
              data-test="product-compare-at-input"
            />
          </UFormField>
        </div>

        <div
          v-if="derivedSlug"
          class="rounded-md bg-elevated/50 px-3 py-2 text-xs text-muted"
          data-test="derived-preview"
        >
          Slug <span class="font-medium text-default">{{ derivedSlug }}</span>
          <template v-if="purchasable">
            · SKU <span class="font-medium text-default">{{ derivedSlug }}</span>
            <template v-if="pricePreview">
              · <span data-test="price-preview">{{ pricePreview }}</span>
            </template>
          </template>
          · created as draft
        </div>
      </UForm>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between">
        <UButton
          color="neutral"
          variant="ghost"
          label="Close"
          @click="emit('update:open', false)"
        />
        <UButton
          type="submit"
          form="product-create-form"
          data-test="product-create-submit"
          :loading="create.isLoading.value"
          label="Create"
        />
      </div>
    </template>
  </USlideover>
</template>
