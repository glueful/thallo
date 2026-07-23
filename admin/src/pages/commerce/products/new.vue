<script setup lang="ts">
// Spec §5.4 (2026-07-23 revision): the full-page create route. "New product" lands HERE — the
// same editor chrome (`EditorSectionCard` + `SectionNav`) the edit page uses, in CREATE mode.
// Name, type, initial price, and the derived-but-editable slug/SKU form ONE page-level atomic
// "Create draft" action (not "the first save" — independent section saves exist only in edit
// mode); every other section renders visible but disabled until the draft exists. No database
// row exists until the atomic create succeeds. On success the router REPLACES to the real
// product uuid so Back never reopens a stale creation form.
//
// unplugin-vue-router ranks the static `new` segment above the `[uuid]` param sibling, so this
// route always wins over the dynamic product page.
import { computed, reactive, ref, useTemplateRef, watch } from 'vue'
import { useRouter } from 'vue-router'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { useCommerceProductMutations, PRODUCT_TYPES } from '@/queries/commerceCatalog'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { formatMoney } from '@/composables/useMoney'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { slugify } from '@/utils/slugify'
import { createDirtyRegistry, useUnsavedGuard } from '@/composables/useSectionState'
import EditorSectionCard from './components/EditorSectionCard.vue'
import SectionNav, { type SectionNavItem } from './components/SectionNav.vue'

const router = useRouter()
const { success, error: notifyError } = useNotify()
const { create } = useCommerceProductMutations()
const { data: meta } = useCommerceMeta()

const typeItems = PRODUCT_TYPES.map((t) => ({ label: t, value: t }))

/** external/grouped products reject variants server-side — no SKU/price to collect. */
const PURCHASABLE_TYPES = ['physical', 'digital'] as const

const schema = z
  .object({
    name: z
      .string()
      .min(1, 'Name is required.')
      .refine((v) => slugify(v).length > 0, 'Name must contain letters or numbers.'),
    type: z.enum(PRODUCT_TYPES),
    slug: z
      .string()
      .min(1, 'Slug is required.')
      .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'Lowercase letters, numbers and hyphens only.'),
    sku: z.string(),
    price: z
      .number({ message: 'Price is required.' })
      .int('Price must be a whole number of minor units.')
      .nonnegative('Price cannot be negative.'),
  })
  .superRefine((data, ctx) => {
    if ((PURCHASABLE_TYPES as readonly string[]).includes(data.type) && data.sku.trim() === '') {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['sku'], message: 'SKU is required.' })
    }
  })
type Schema = z.output<typeof schema>

function blankState() {
  return { name: '', type: 'physical' as (typeof PRODUCT_TYPES)[number], slug: '', sku: '', price: 0 }
}
const state = reactive(blankState())

// Slug derives from the name until the author edits it directly; SKU derives from the slug the
// same way. Both stay fully editable (spec §5.4: "derived-but-editable slug/SKU").
const slugTouched = ref(false)
const skuTouched = ref(false)
watch(
  () => state.name,
  (name) => {
    if (!slugTouched.value) state.slug = slugify(name)
  },
)
watch(
  () => state.slug,
  (slug) => {
    if (!skuTouched.value) state.sku = slug
  },
)

const purchasable = computed(() => (PURCHASABLE_TYPES as readonly string[]).includes(state.type))
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

// ── Dirty-navigation guard (spec §5.4: the create route participates) ────────────────────────
//
// The page registers ONE synthetic section directly on its own registry (it cannot
// `useSectionState()` — that injects `DirtyRegistryKey`, and a component's own `provide()` is
// visible only to descendants). Dirty = any field differs from the blank state and the draft
// hasn't been created yet; `creating` also blocks so an in-flight create can't be navigated away
// from silently (mirrors the C2 `dirty || saving` rule).
const registry = createDirtyRegistry()
useUnsavedGuard(registry)
const created = ref(false)
const creating = ref(false)
const touched = computed(
  () =>
    state.name !== '' ||
    state.slug !== '' ||
    state.sku !== '' ||
    state.price !== 0 ||
    state.type !== 'physical',
)
registry.register({
  id: 'create',
  label: 'New product',
  blocked: computed(() => (touched.value && !created.value) || creating.value),
})

// ── Section nav + dormant cards ─────────────────────────────────────────────────────────────

const DORMANT_BASE = [
  { id: 'media', label: 'Images' },
  { id: 'organization', label: 'Organization' },
  { id: 'addons', label: 'Add-ons' },
] as const

const dormantSections = computed(() => {
  const items: { id: string; label: string }[] = [...DORMANT_BASE]
  if (state.type === 'digital') items.push({ id: 'downloads', label: 'Downloads' })
  items.push({ id: 'content', label: 'Linked content' })
  if (state.type === 'grouped') items.push({ id: 'children', label: 'Grouped products' })
  return items
})

const navSections = computed<SectionNavItem[]>(() => {
  const items: SectionNavItem[] = [{ id: 'details', label: 'Details', indicator: null }]
  if (purchasable.value) items.push({ id: 'pricing', label: 'Pricing', indicator: null })
  for (const s of dormantSections.value) items.push({ id: s.id, label: s.label, indicator: null })
  return items
})

// ── The atomic Create draft action (single-flight, no automatic retry) ──────────────────────

/** Which section card owns each server/client field — a validation failure scrolls the FIRST
 * failing field's section into view with every entered value retained (spec §5.4). */
const FIELD_SECTION: Record<string, string> = {
  name: 'details',
  slug: 'details',
  type: 'details',
  sku: 'pricing',
  price: 'pricing',
  variants: 'pricing',
}

function focusSectionOf(fieldNames: string[]): void {
  const section = FIELD_SECTION[fieldNames[0] ?? ''] ?? 'details'
  document
    .getElementById(`section-${section}`)
    ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const createForm = useTemplateRef<Form<Schema>>('createForm')
const formError = ref<string | null>(null)

async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (creating.value) return // single-flight; never auto-retried
  creating.value = true
  formError.value = null
  try {
    const product = await create.mutateAsync({
      slug: event.data.slug,
      name: event.data.name,
      type: event.data.type,
      status: 'draft',
      variants: purchasable.value
        ? [{ sku: event.data.sku, price: event.data.price, currency: currency.value }]
        : [],
    })
    created.value = true // release the guard BEFORE navigating
    success('Draft created', `Finish setting up “${event.data.name}” in the editor.`)
    router.replace(`/commerce/products/${product.uuid}`)
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      // `variants` errors have no input of their own — surface on the price field.
      name: name === 'variants' ? 'price' : name,
      message,
    }))
    if (fieldErrors.length > 0) {
      createForm.value?.setErrors(fieldErrors)
      focusSectionOf(Object.keys(err.fieldErrors))
    }
    formError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t create product')
  } finally {
    creating.value = false
  }
}

/** UForm's client-side zod failure path: retain values, focus the owning section. */
function onValidationError(event: { errors: { name?: string }[] }): void {
  focusSectionOf(event.errors.map((e) => e.name ?? ''))
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
        <template #right>
          <UButton
            type="submit"
            form="product-create-form"
            label="Create draft"
            data-test="product-create-submit"
            :loading="creating"
            :disabled="creating"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-6 xl:flex-row xl:items-start">
        <div class="min-w-0 flex-1 space-y-6">
          <UForm
            id="product-create-form"
            ref="createForm"
            :schema="schema"
            :state="state"
            class="space-y-6"
            @submit="onSubmit"
            @error="onValidationError"
          >
            <EditorSectionCard section-id="details" title="Details">
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
                  help="Derived from the name — edit to override. Drives the storefront URL."
                >
                  <UInput
                    v-model="state.slug"
                    class="w-full"
                    data-test="product-slug-input"
                    @update:model-value="slugTouched = true"
                  />
                </UFormField>

                <UFormField
                  label="Type"
                  name="type"
                  help="Editable only before creation — read-only afterward."
                >
                  <USelect
                    v-model="state.type"
                    :items="typeItems"
                    class="w-full"
                    data-test="product-type-select"
                  />
                </UFormField>
              </div>
            </EditorSectionCard>

            <EditorSectionCard v-if="purchasable" section-id="pricing" title="Pricing">
              <div class="grid grid-cols-2 gap-4">
                <UFormField
                  label="SKU"
                  name="sku"
                  required
                  help="Derived from the slug — edit to override."
                >
                  <UInput
                    v-model="state.sku"
                    class="w-full"
                    data-test="product-sku-input"
                    @update:model-value="skuTouched = true"
                  />
                </UFormField>

                <UFormField
                  label="Price"
                  name="price"
                  required
                  :help="`Minor units (e.g. cents) — ${currency}.`"
                >
                  <UInput
                    v-model.number="state.price"
                    type="number"
                    :min="0"
                    class="w-full"
                    data-test="product-price-input"
                  />
                </UFormField>

                <p v-if="pricePreview" class="col-span-2 text-xs text-muted" data-test="price-preview">
                  Preview: {{ pricePreview }} · created as draft
                </p>
              </div>
            </EditorSectionCard>

            <UAlert
              v-if="formError"
              color="error"
              variant="subtle"
              icon="i-lucide-triangle-alert"
              :description="formError"
              data-test="product-create-error"
            />
          </UForm>

          <!-- Dormant sections (spec §5.4): visible so the authoring surface is honest about
               what exists, disabled so nothing implies it can save before the draft does. -->
          <EditorSectionCard
            v-for="section in dormantSections"
            :key="section.id"
            :section-id="section.id"
            :title="section.label"
          >
            <p class="text-sm text-muted" :data-test="`create-dormant-${section.id}`">
              Available once the draft is created.
            </p>
          </EditorSectionCard>
        </div>

        <div class="w-full shrink-0 xl:w-56">
          <SectionNav :sections="navSections" />
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
