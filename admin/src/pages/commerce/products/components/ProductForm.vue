<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import {
  useCommerceProductMutations,
  PRODUCT_STATUSES,
  PRODUCT_TYPES,
  type CommerceProduct,
} from '@/queries/commerceCatalog'
import { useMoney } from '@/composables/useMoney'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ product: CommerceProduct; canManage: boolean }>()
const emit = defineEmits<{ saved: [] }>()

const { success, error: notifyError } = useNotify()
const { update } = useCommerceProductMutations()
const { format } = useMoney()

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
})
type Schema = z.output<typeof schema>

function fromProduct(p: CommerceProduct) {
  return {
    name: p.name,
    slug: p.slug,
    description: p.description ?? '',
    type: p.type as (typeof PRODUCT_TYPES)[number],
    status: p.status as (typeof PRODUCT_STATUSES)[number],
    taxClass: p.tax_class ?? '',
  }
}

const state = reactive(fromProduct(props.product))
watch(
  () => props.product,
  (p) => Object.assign(state, fromProduct(p)),
)

const baseVariant = computed(() => props.product.variants[0] ?? null)
// useMoney().format() throws until /commerce/meta resolves — guard so an unsettled meta query
// (still pending on first paint) never crashes the form render.
const basePriceText = computed(() => {
  const variant = baseVariant.value
  if (!variant) return null
  try {
    return format(variant.price)
  } catch {
    return null
  }
})

async function onSubmit(event: FormSubmitEvent<Schema>) {
  try {
    await update.mutateAsync({
      uuid: props.product.uuid,
      input: {
        name: event.data.name,
        slug: event.data.slug,
        description: event.data.description || null,
        type: event.data.type,
        status: event.data.status,
        tax_class: event.data.taxClass || null,
      },
    })
    success('Product saved', `“${event.data.name}” was updated.`)
    emit('saved')
  } catch (e) {
    notifyError(e, 'Couldn’t save product')
  }
}
</script>

<template>
  <UForm :schema="schema" :state="state" class="space-y-6" @submit="onSubmit">
    <div
      v-if="basePriceText"
      class="rounded-md border border-default p-3 text-sm"
      data-test="product-base-price"
    >
      <span class="text-muted">Base price</span>
      <span class="ml-2 font-medium text-default">{{ basePriceText }}</span>
      <span class="ml-1 text-xs text-muted">({{ baseVariant?.sku }})</span>
      <!-- Read-only: the Variants tab (Task 10b) is the single place variant pricing is edited,
           so this display never doubles as an editable base-price field. -->
      <span class="ml-2 text-xs text-muted">— edit pricing in the Variants tab.</span>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <UFormField label="Name" name="name" required class="col-span-2">
        <UInput v-model="state.name" class="w-full" :disabled="!canManage" />
      </UFormField>

      <UFormField label="Slug" name="slug" required class="col-span-2">
        <UInput v-model="state.slug" class="w-full" :disabled="!canManage" />
      </UFormField>

      <UFormField label="Description" name="description" class="col-span-2">
        <UTextarea v-model="state.description" class="w-full" :rows="3" :disabled="!canManage" />
      </UFormField>

      <UFormField label="Type" name="type">
        <USelect v-model="state.type" :items="typeItems" class="w-full" :disabled="!canManage" />
      </UFormField>

      <UFormField label="Status" name="status">
        <USelect v-model="state.status" :items="statusItems" class="w-full" :disabled="!canManage" />
      </UFormField>

      <UFormField label="Tax class" name="taxClass" class="col-span-2">
        <UInput v-model="state.taxClass" placeholder="Optional" class="w-full" :disabled="!canManage" />
      </UFormField>
    </div>

    <UButton
      v-if="canManage"
      type="submit"
      data-test="product-form-save"
      :loading="update.isLoading.value"
      label="Save changes"
    />
  </UForm>
</template>
