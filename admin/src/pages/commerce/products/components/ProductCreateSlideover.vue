<script setup lang="ts">
import { computed, reactive, ref, useTemplateRef, watch } from 'vue'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import {
  useCommerceProductMutations,
  PRODUCT_STATUSES,
  PRODUCT_TYPES,
} from '@/queries/commerceCatalog'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { formatMoney } from '@/composables/useMoney'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { slugify } from '@/utils/slugify'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; created: [uuid: string] }>()

const { success, error: notifyError } = useNotify()
const { create } = useCommerceProductMutations()
const { data: meta } = useCommerceMeta()

const typeItems = PRODUCT_TYPES.map((t) => ({ label: t, value: t }))
const statusItems = PRODUCT_STATUSES.map((s) => ({ label: s, value: s }))

const schema = z.object({
  name: z.string().min(1, 'Name is required.'),
  slug: z
    .string()
    .min(1, 'Slug is required.')
    .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'Lowercase letters, numbers and hyphens only.'),
  description: z.string().optional(),
  type: z.enum(PRODUCT_TYPES),
  status: z.enum(PRODUCT_STATUSES),
  taxClass: z.string().optional(),
  sku: z.string().min(1, 'SKU is required.'),
  price: z
    .number({ message: 'Price is required.' })
    .int('Price must be a whole number of minor units.')
    .nonnegative('Price cannot be negative.'),
  currency: z
    .string()
    .length(3, 'Currency must be a 3-letter code.')
    .transform((v) => v.toUpperCase()),
})
type Schema = z.output<typeof schema>

function blankState() {
  return {
    name: '',
    slug: '',
    description: '',
    type: 'physical' as const,
    status: 'draft' as const,
    taxClass: '',
    sku: '',
    price: 0,
    currency: meta.value?.currency ?? 'USD',
  }
}

const state = reactive(blankState())
const slugTouched = ref(false)

watch(
  () => state.name,
  (name) => {
    if (!slugTouched.value) state.slug = slugify(name)
  },
)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    Object.assign(state, blankState())
    slugTouched.value = false
  },
)

const pricePreview = computed(() => {
  const exponent = meta.value?.currency_exponent ?? 2
  if (!Number.isInteger(state.price) || state.price < 0) return null
  try {
    return formatMoney(state.price, { currency: state.currency || 'USD', currency_exponent: exponent })
  } catch {
    return null
  }
})

const createForm = useTemplateRef<Form<Schema>>('createForm')

async function onSubmit(event: FormSubmitEvent<Schema>) {
  try {
    const created = await create.mutateAsync({
      slug: event.data.slug,
      name: event.data.name,
      description: event.data.description || null,
      type: event.data.type,
      status: event.data.status,
      tax_class: event.data.taxClass || null,
      variants: [
        {
          sku: event.data.sku,
          price: event.data.price,
          currency: event.data.currency,
        },
      ],
    })
    success('Product created', `“${event.data.name}” is ready.`)
    emit('created', created.uuid)
    emit('update:open', false)
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
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
        <div class="grid grid-cols-2 gap-4">
          <UFormField label="Name" name="name" required class="col-span-2">
            <UInput
              v-model="state.name"
              placeholder="e.g. Wireless mouse"
              class="w-full"
              data-test="product-name-input"
            />
          </UFormField>

          <UFormField
            label="Slug"
            name="slug"
            required
            class="col-span-2"
            help="Lowercase, hyphenated identifier — drives the storefront URL."
          >
            <UInput
              v-model="state.slug"
              placeholder="e.g. wireless-mouse"
              class="w-full"
              data-test="product-slug-input"
              @update:model-value="slugTouched = true"
            />
          </UFormField>

          <UFormField label="Description" name="description" class="col-span-2">
            <UTextarea v-model="state.description" class="w-full" :rows="3" />
          </UFormField>

          <UFormField label="Type" name="type">
            <USelect v-model="state.type" :items="typeItems" class="w-full" />
          </UFormField>

          <UFormField label="Status" name="status">
            <USelect v-model="state.status" :items="statusItems" class="w-full" />
          </UFormField>

          <UFormField label="Tax class" name="taxClass" class="col-span-2">
            <UInput v-model="state.taxClass" placeholder="Optional" class="w-full" />
          </UFormField>
        </div>

        <div class="space-y-3 border-t border-default pt-4">
          <p class="text-xs font-medium uppercase tracking-wide text-muted">Default variant</p>
          <div class="grid grid-cols-2 gap-4">
            <UFormField label="SKU" name="sku" required class="col-span-2">
              <UInput
                v-model="state.sku"
                placeholder="e.g. WM-001"
                class="w-full"
                data-test="product-sku-input"
              />
            </UFormField>

            <UFormField
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

            <UFormField label="Currency" name="currency" required>
              <UInput
                v-model="state.currency"
                placeholder="USD"
                class="w-full uppercase"
                data-test="product-currency-input"
              />
            </UFormField>

            <p v-if="pricePreview" class="col-span-2 text-xs text-muted" data-test="price-preview">
              Preview: {{ pricePreview }}
            </p>
          </div>
        </div>
      </UForm>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between">
        <UButton color="neutral" variant="ghost" label="Close" @click="emit('update:open', false)" />
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
