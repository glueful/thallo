<script setup lang="ts">
// Single-page product editor plan, Task C5: the Details card. `type` is CONDITIONALLY editable —
// Commerce rejects a change only while the product carries "strandable references" (variants,
// grouped-children membership in either direction, or cart/order lines; CatalogService::
// applyProductPatch). Variants are client-visible, so a variant-carrying product renders the
// honest locked state up front and the payload never includes `type`; a variant-free product gets
// a real select, with the rarer child-membership lock arriving as the server's own field-mapped
// 422. `type` is sent ONLY when actually changed — an always-present key would 422 every
// unrelated Details save on locked products.
import { computed, inject, nextTick, reactive, ref, useTemplateRef, watch } from 'vue'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import {
  useCommerceProductMutations,
  PRODUCT_STATUSES,
  PRODUCT_TYPES,
  type CommerceProduct,
} from '@/queries/commerceCatalog'
import { useMoney } from '@/composables/useMoney'
import { useNotify } from '@/composables/useNotify'
import { toApiError } from '@/api/errors'
import { useSectionState, type SectionState } from '@/composables/useSectionState'
import { ProductRevisionCoordinatorKey } from '@/composables/useProductRevisionCoordinator'
import RichText from '@/components/RichText.vue'
import { isEmptyHtml } from '@/fields/components/blocks/useBlockListOps'

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
const typeItems = PRODUCT_TYPES.map((t) => ({ label: t, value: t }))

/** Variants are the client-visible strandable reference — lock the type field honestly up front
 * rather than offering a select that always 422s. (Child-membership locks stay server-detected.) */
const typeLocked = computed(() => props.product.variants.length > 0)

// External products carry their outbound link in `metadata.external_url` (API-required — spec
// §5.4 gap fix 2: without this fieldset the link was uneditable anywhere in the SPA). Driven by
// the DRAFT type (not the saved product) so switching to external reveals the required link field
// in the SAME save that changes the type — the server validates external metadata against the
// incoming effective type.
const isExternal = computed(() => state.type === 'external')

const schema = z
  .object({
    name: z.string().min(1, 'Name is required.'),
    slug: z
      .string()
      .min(1, 'Slug is required.')
      .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'Lowercase letters, numbers and hyphens only.'),
    description: z.string().optional(),
    type: z.enum(PRODUCT_TYPES),
    status: z.enum(PRODUCT_STATUSES),
    taxClass: z.string().optional(),
    externalUrl: z.string().optional(),
    buttonLabel: z.string().optional(),
  })
  .superRefine((data, ctx) => {
    if (isExternal.value && !/^https?:\/\/.+\..+/.test((data.externalUrl ?? '').trim())) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['externalUrl'],
        message: 'A valid http(s) link is required for external products.',
      })
    }
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
    externalUrl: typeof p.metadata.external_url === 'string' ? p.metadata.external_url : '',
    buttonLabel: typeof p.metadata.button_label === 'string' ? p.metadata.button_label : '',
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
  const description = event.data.description ?? ''
  beginSave()
  try {
    // Metadata is included ONLY for external products, and always as the MERGED object — the
    // server wholesale-replaces the column, so a fragment would wipe unrelated metadata keys.
    let metadata: Record<string, unknown> | undefined
    if (isExternal.value) {
      metadata = { ...props.product.metadata, external_url: (event.data.externalUrl ?? '').trim() }
      const label = (event.data.buttonLabel ?? '').trim()
      if (label !== '') metadata.button_label = label
      else delete metadata.button_label
    }
    await update.mutateAsync({
      uuid: props.product.uuid,
      input: {
        name: event.data.name,
        slug: event.data.slug,
        // "Blank" for rich text includes Tiptap's empty document ('<p></p>') — send null, never
        // an empty-markup string, matching the plain-textarea era's `|| null` semantics.
        description: isEmptyHtml(description) ? null : description,
        // Sent ONLY when actually changed — an ever-present key would 422 every unrelated save
        // once the product carries variants/children/orders (server-side strandable-refs guard).
        ...(event.data.type !== props.product.type ? { type: event.data.type } : {}),
        status: event.data.status,
        tax_class: event.data.taxClass || null,
        ...(metadata !== undefined ? { metadata } : {}),
      },
    })
    saveSucceeded()
    await coordinator?.afterMutation()
    success('Product saved', `“${event.data.name}” was updated.`)
    emit('saved')
  } catch (e) {
    saveFailed()
    const err = toApiError(e)
    // Server metadata.* keys map onto the local field names that render them.
    const FIELD_ALIAS: Record<string, string> = {
      'metadata.external_url': 'externalUrl',
      'metadata.button_label': 'buttonLabel',
    }
    const fieldErrs = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name: FIELD_ALIAS[name] ?? name,
      message,
    }))
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

      <!-- The CMS's own RichText editor (Tiptap/UEditor, HTML-string model — dogfooding the
           content editor, user request 2026-07-24): bold/italic/lists/links etc. The storefront
           renders this through the render pack's fail-closed `safe_html` sanitizer, so markup
           here can never smuggle scripts onto the shop page. -->
      <UFormField label="Description" name="description" class="col-span-2">
        <div
          class="rounded-md border border-default px-3"
          data-test="product-description-input"
        >
          <RichText
            v-model="state.description"
            :editable="canManage"
            placeholder="Describe the product…"
            content-class="max-h-96 overflow-y-auto !min-h-32"
          />
        </div>
      </UFormField>

      <UFormField v-if="typeLocked" label="Type">
        <p class="text-sm text-default" data-test="product-type-value">{{ product.type }}</p>
        <p class="mt-1 text-xs text-muted" data-test="product-type-note">
          Locked — products with variants can’t change type.
        </p>
      </UFormField>
      <UFormField
        v-else
        label="Type"
        name="type"
        help="Switching type changes which sections apply below."
      >
        <USelect
          v-model="state.type"
          :items="typeItems"
          class="w-full"
          :disabled="!canManage"
          data-test="product-type-input"
        />
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

      <template v-if="isExternal">
        <UFormField
          label="External link"
          name="externalUrl"
          required
          class="col-span-2"
          help="Where “Add to cart” sends the customer."
        >
          <UInput
            v-model="state.externalUrl"
            type="url"
            class="w-full"
            :disabled="!canManage"
            data-test="product-external-url-input"
          />
        </UFormField>

        <UFormField label="Button label" name="buttonLabel" class="col-span-2" help="Optional — e.g. “Buy at Partner Store”.">
          <UInput
            v-model="state.buttonLabel"
            class="w-full"
            :disabled="!canManage"
            data-test="product-button-label-input"
          />
        </UFormField>
      </template>
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
