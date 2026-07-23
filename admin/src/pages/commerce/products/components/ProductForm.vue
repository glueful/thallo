<script setup lang="ts">
// Single-page product editor plan, Task C5: the Details card. `type` is READ-ONLY here — Commerce
// rejects a type change with a 422 once a product carries variants/children/carts/orders, and a
// purchasable product has a variant from birth (design spec §5.1 item 1), so an editable type
// select is a control that normally fails validation. It renders as plain text; the update payload
// never includes it at all (not merely disabled).
import { computed, inject, nextTick, reactive, ref, useTemplateRef, watch } from 'vue'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import {
  useCommerceProductMutations,
  PRODUCT_STATUSES,
  type CommerceProduct,
} from '@/queries/commerceCatalog'
import { useMoney } from '@/composables/useMoney'
import { useNotify } from '@/composables/useNotify'
import { toApiError } from '@/api/errors'
import { useSectionState, type SectionState } from '@/composables/useSectionState'
import { ProductRevisionCoordinatorKey } from '@/composables/useProductRevisionCoordinator'

const props = defineProps<{ product: CommerceProduct; canManage: boolean }>()
const emit = defineEmits<{ saved: []; state: [SectionState] }>()

const { success, error: notifyError } = useNotify()
const { update } = useCommerceProductMutations()
const { format } = useMoney()
const coordinator = inject(ProductRevisionCoordinatorKey, null)

// Details is NOT a registered coordinator section (Task C5 brief): it has no `{revision, items}`
// section envelope to reconcile against — product details live on the product show query itself.
// `useSectionState` still drives this card's own phase/dirty chip and the page's dirty-registry
// nav guard; its whole return value is emitted ONCE (refs, not their values, so the shell always
// reads the live state) so the shell can hand it to `EditorSectionCard` and the section nav — see
// the file-level note in `pages/commerce/products/[uuid]/index.vue` for why an emit was chosen
// over hoisting `useSectionState` to the shell (least invasive to this card's own ownership of its
// save flow).
const sectionState = useSectionState('details', 'Details')
const { dirty, markDirty, beginSave, saveSucceeded, saveFailed } = sectionState
emit('state', sectionState)

const statusItems = PRODUCT_STATUSES.map((s) => ({ label: s, value: s }))

const schema = z.object({
  name: z.string().min(1, 'Name is required.'),
  slug: z
    .string()
    .min(1, 'Slug is required.')
    .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'Lowercase letters, numbers and hyphens only.'),
  description: z.string().optional(),
  status: z.enum(PRODUCT_STATUSES),
  taxClass: z.string().optional(),
})
type Schema = z.output<typeof schema>

function fromProduct(p: CommerceProduct) {
  return {
    name: p.name,
    slug: p.slug,
    description: p.description ?? '',
    status: p.status as (typeof PRODUCT_STATUSES)[number],
    taxClass: p.tax_class ?? '',
  }
}

const state = reactive(fromProduct(props.product))
const formError = ref<string | null>(null)
const formRef = useTemplateRef<Form<Schema>>('formRef')

// Guards the server-state reset below from being misread as a user edit by the dirty watcher —
// `syncingFromProduct` stays true for exactly the flush cycle `Object.assign` runs in (the deep
// watcher below is queued in that same flush, so it still observes the flag before it clears).
let syncingFromProduct = false

watch(
  () => props.product,
  (p) => {
    // A dirty Details draft is never silently overwritten by an unrelated product refresh (e.g.
    // another section's successful save invalidating the shared product query) — Global
    // Constraints' "no blind replacement" principle applies here too, even though Details isn't a
    // registered coordinator section (it has no items array to rebase against).
    if (dirty.value) return
    syncingFromProduct = true
    Object.assign(state, fromProduct(p))
    void nextTick(() => {
      syncingFromProduct = false
    })
  },
)

watch(
  state,
  () => {
    if (syncingFromProduct) return
    markDirty()
  },
  { deep: true },
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
  formError.value = null
  beginSave()
  try {
    await update.mutateAsync({
      uuid: props.product.uuid,
      input: {
        name: event.data.name,
        slug: event.data.slug,
        description: event.data.description || null,
        status: event.data.status,
        tax_class: event.data.taxClass || null,
      },
    })
    saveSucceeded()
    await coordinator?.afterMutation()
    success('Product saved', `“${event.data.name}” was updated.`)
    emit('saved')
  } catch (e) {
    saveFailed()
    const err = toApiError(e)
    const fieldErrs = Object.entries(err.fieldErrors).map(([name, message]) => ({ name, message }))
    if (fieldErrs.length > 0) formRef.value?.setErrors(fieldErrs)
    formError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t save product')
  }
}
</script>

<template>
  <UForm ref="formRef" :schema="schema" :state="state" class="space-y-6" @submit="onSubmit">
    <UAlert
      v-if="formError"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      data-test="product-form-error"
      :title="formError"
    />

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
        <UInput
          v-model="state.name"
          class="w-full"
          :disabled="!canManage"
          data-test="product-name-input"
        />
      </UFormField>

      <UFormField label="Slug" name="slug" required class="col-span-2">
        <UInput
          v-model="state.slug"
          class="w-full"
          :disabled="!canManage"
          data-test="product-slug-input"
        />
      </UFormField>

      <UFormField label="Description" name="description" class="col-span-2">
        <UTextarea
          v-model="state.description"
          class="w-full"
          :rows="3"
          :disabled="!canManage"
          data-test="product-description-input"
        />
      </UFormField>

      <UFormField label="Type">
        <p class="text-sm text-default" data-test="product-type-value">{{ product.type }}</p>
        <p class="mt-1 text-xs text-muted" data-test="product-type-note">
          Type is set at creation.
        </p>
      </UFormField>

      <UFormField label="Status" name="status">
        <USelect
          v-model="state.status"
          :items="statusItems"
          class="w-full"
          :disabled="!canManage"
          data-test="product-status-input"
        />
      </UFormField>

      <UFormField label="Tax class" name="taxClass" class="col-span-2">
        <UInput
          v-model="state.taxClass"
          placeholder="Optional"
          class="w-full"
          :disabled="!canManage"
          data-test="product-tax-class-input"
        />
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
