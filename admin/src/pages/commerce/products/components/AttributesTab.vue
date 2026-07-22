<script setup lang="ts">
// Task 19b: mirrors TagsTab.vue's dual-mode design (management mode: no `product` prop —
// attribute + value CRUD; assignment mode: `product` given — read-only reference list + a
// set-list assignment section), one component covering BOTH "Attributes" surfaces the design
// calls for:
//   - products/index.vue's "Attributes" tab (`product` omitted): full attribute + value CRUD for
//     the whole tenant.
//   - the product detail's "Attributes" tab (`product` given): the same attribute list, read-only,
//     plus an assignment section that sets which attributes (and which of their values) this one
//     product carries via the wholesale PUT set-list endpoint. CRUD controls are always hidden in
//     this mode.
//
// Unlike tags/categories, attributes carry a VALUES sub-collection (embedded, batch-loaded by
// `AttributeService::list()` — see `CommerceAttribute`'s docblock) — each row is expandable to
// add/edit/delete its own values inline. And unlike tags, BOTH slug and name stay editable after
// creation (no immutability trap), so the edit form always submits the full {slug, name, position}.
//
// The product assignment shape is far richer than tags/categories' bare uuid list
// (`SetProductAttributesData`: each row is `{attribute_uuid?, name?, values?, used_for_variants?,
// visible?, position?}`) — exactly ONE of `attribute_uuid` (an existing tenant attribute; `values`
// must be that attribute's existing value SLUGS) or a non-empty `name` (a one-off custom row;
// `values` is free text) is given. The assignment section below builds that shape from the SAME
// paginated attribute list rendered above (there is no unpaginated attribute fetch, unlike
// categories) plus a separate list of custom rows the admin builds up by hand.
import { computed, reactive, ref, useTemplateRef, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import {
  useCommerceAttributes,
  useCommerceAttributeMutations,
  useCommerceProductMutations,
  type CommerceAttribute,
  type CommerceAttributeValue,
  type CommerceProduct,
  type CommerceProductAttribute,
  type ProductAttributeAssignmentInput,
} from '@/queries/commerceCatalog'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'

const props = defineProps<{ canManage: boolean; product?: CommerceProduct }>()

const { success, error: notifyError } = useNotify()

// ── List: search + pagination (shared by both modes — same tenant-wide attribute set) ──────────
const search = ref('')
const debouncedSearch = refDebounced(search, 300)
const page = ref(1)
const perPage = ref(24)
const filters = computed(() => ({
  q: debouncedSearch.value || undefined,
  page: page.value,
  perPage: perPage.value,
}))

const { data: attributesData, status } = useCommerceAttributes(filters)
const { create, update, remove, createValue, updateValue, removeValue } = useCommerceAttributeMutations()
const { setAttributes } = useCommerceProductMutations()

const rows = computed<CommerceAttribute[]>(() => attributesData.value?.attributes ?? [])

/** Whether CRUD controls (attribute AND value add/edit/delete) render — never in
 * product-assignment mode, see the file-level comment above. */
const managementMode = computed(() => props.canManage && !props.product)

// ── Create / edit attribute (shared form) ───────────────────────────────────────────────────

const schema = z.object({
  name: z.string().min(1, 'Name is required.'),
  slug: z.string().min(1, 'Slug is required.'),
  position: z.number().int().optional(),
})
type Schema = z.output<typeof schema>

function blankState() {
  return { name: '', slug: '', position: 0 }
}

const formOpen = ref(false)
const editingUuid = ref<string | null>(null)
const state = reactive(blankState())
const formError = ref<string | null>(null)
const formRef = useTemplateRef<Form<Schema>>('formRef')

function openCreate() {
  editingUuid.value = null
  Object.assign(state, blankState())
  formError.value = null
  formOpen.value = true
}

function openEdit(attr: CommerceAttribute) {
  editingUuid.value = attr.uuid
  state.name = attr.name
  state.slug = attr.slug
  state.position = attr.position
  formError.value = null
  formOpen.value = true
}

function cancelForm() {
  formOpen.value = false
}

async function submitForm(event: FormSubmitEvent<Schema>) {
  const input = { name: event.data.name, slug: event.data.slug, position: event.data.position ?? 0 }
  try {
    if (editingUuid.value) {
      await update.mutateAsync({ uuid: editingUuid.value, input })
      success('Attribute updated', `“${input.name}” was saved.`)
    } else {
      await create.mutateAsync(input)
      success('Attribute created', `“${input.name}” is ready.`)
    }
    formOpen.value = false
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({ name, message }))
    if (fieldErrors.length > 0) formRef.value?.setErrors(fieldErrors)
    formError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, editingUuid.value ? 'Couldn’t update attribute' : 'Couldn’t create attribute')
  }
}

// ── Delete attribute ─────────────────────────────────────────────────────────────────────────

const pendingDelete = ref<CommerceAttribute | null>(null)
async function confirmDelete() {
  const attr = pendingDelete.value
  if (!attr) return
  try {
    await remove.mutateAsync(attr.uuid)
    success('Attribute deleted', `“${attr.name}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete attribute')
  }
}

// ── Nested values editor (expand a row to add/edit/delete its values) ──────────────────────────
// No named template ref for the value form: it lives inside the attribute `v-for`, so a shared
// ref name would resolve to an array of Form instances (mirrors VariantsPanel's edit-form note) —
// field errors surface only through the plain-text banner below, never setErrors() highlighting.

const expandedUuid = ref<string | null>(null)
function toggleExpand(uuid: string) {
  expandedUuid.value = expandedUuid.value === uuid ? null : uuid
}

const valueSchema = z.object({
  value: z.string().min(1, 'Value is required.'),
  slug: z.string().min(1, 'Slug is required.'),
  position: z.number().int().optional(),
})
type ValueSchema = z.output<typeof valueSchema>

const valueFormOpen = ref(false)
const valueFormAttributeUuid = ref<string | null>(null)
const editingValueUuid = ref<string | null>(null)
const valueState = reactive({ slug: '', value: '', position: 0 })
const valueFormError = ref<string | null>(null)

function openCreateValue(attributeUuid: string) {
  expandedUuid.value = attributeUuid
  valueFormAttributeUuid.value = attributeUuid
  editingValueUuid.value = null
  Object.assign(valueState, { slug: '', value: '', position: 0 })
  valueFormError.value = null
  valueFormOpen.value = true
}

function openEditValue(attributeUuid: string, val: CommerceAttributeValue) {
  expandedUuid.value = attributeUuid
  valueFormAttributeUuid.value = attributeUuid
  editingValueUuid.value = val.uuid
  valueState.slug = val.slug
  valueState.value = val.value
  valueState.position = val.position
  valueFormError.value = null
  valueFormOpen.value = true
}

function cancelValueForm() {
  valueFormOpen.value = false
}

async function submitValueForm(event: FormSubmitEvent<ValueSchema>) {
  const attributeUuid = valueFormAttributeUuid.value
  if (!attributeUuid) return
  const input = { slug: event.data.slug, value: event.data.value, position: event.data.position ?? 0 }
  try {
    if (editingValueUuid.value) {
      await updateValue.mutateAsync({ uuid: editingValueUuid.value, input })
      success('Value updated', `“${input.value}” was saved.`)
    } else {
      await createValue.mutateAsync({ attributeUuid, input })
      success('Value added', `“${input.value}” is ready.`)
    }
    valueFormOpen.value = false
  } catch (e) {
    const err = toApiError(e)
    valueFormError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, editingValueUuid.value ? 'Couldn’t update value' : 'Couldn’t add value')
  }
}

const pendingDeleteValue = ref<CommerceAttributeValue | null>(null)
async function confirmDeleteValue() {
  const val = pendingDeleteValue.value
  if (!val) return
  try {
    await removeValue.mutateAsync(val.uuid)
    success('Value deleted', `“${val.value}” was removed.`)
    pendingDeleteValue.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete value')
  }
}

// ── Product assignment (only rendered when `product` is given) ─────────────────────────────────
// There is no admin GET for a product's current attribute assignment (only the set-list PUT,
// which returns the fresh attached rows) — exactly like TagsTab's `knownTags`. `null` = never
// observed this session; a non-null array is only reached after a successful set call positively
// established the assignment. Never claim "none assigned" for the unknown state, and never
// pre-check anything from a guess.

interface AttributeAssignEntry {
  included: boolean
  values: string[]
  used_for_variants: boolean
  visible: boolean
}

interface CustomAttributeRow {
  key: string
  name: string
  valuesText: string
  used_for_variants: boolean
  visible: boolean
}

const knownRows = ref<CommerceProductAttribute[] | null>(null)
const assignState = reactive<Record<string, AttributeAssignEntry>>({})
const customRows = reactive<CustomAttributeRow[]>([])
const assignError = ref<string | null>(null)
let customRowKeySeq = 0

// Every attribute the shared (paginated) list ever surfaces gets a default assignment entry, so
// checkboxes always have somewhere to read/write — mirrors the list-driven checkbox pattern in
// TagsTab/CategoriesTab, with the same "only the current page/search is assignable" limitation.
watch(
  rows,
  (newRows) => {
    if (!props.product) return
    for (const attr of newRows) {
      if (!(attr.uuid in assignState)) {
        assignState[attr.uuid] = { included: false, values: [], used_for_variants: false, visible: true }
      }
    }
  },
  { immediate: true },
)

function toggleIncluded(uuid: string) {
  const entry = assignState[uuid]
  if (entry) entry.included = !entry.included
}

function toggleValue(uuid: string, slug: string) {
  const entry = assignState[uuid]
  if (!entry) return
  entry.values = entry.values.includes(slug) ? entry.values.filter((s) => s !== slug) : [...entry.values, slug]
}

function addCustomRow() {
  customRows.push({
    key: `custom-${customRowKeySeq++}`,
    name: '',
    valuesText: '',
    used_for_variants: false,
    visible: true,
  })
}

function removeCustomRow(key: string) {
  const index = customRows.findIndex((r) => r.key === key)
  if (index !== -1) customRows.splice(index, 1)
}

function buildPayloadRows(): ProductAttributeAssignmentInput[] {
  const attributeRows: ProductAttributeAssignmentInput[] = rows.value
    .filter((attr) => assignState[attr.uuid]?.included)
    .map((attr) => {
      const entry = assignState[attr.uuid]!
      return {
        attribute_uuid: attr.uuid,
        values: entry.values,
        used_for_variants: entry.used_for_variants,
        visible: entry.visible,
      }
    })

  const custom: ProductAttributeAssignmentInput[] = customRows
    .filter((row) => row.name.trim() !== '')
    .map((row) => ({
      name: row.name.trim(),
      values: row.valuesText
        .split(',')
        .map((v) => v.trim())
        .filter((v) => v !== ''),
      used_for_variants: row.used_for_variants,
      visible: row.visible,
    }))

  return [...attributeRows, ...custom]
}

/** Re-hydrates local assignment state from a successful set-list response — the honest
 * "what actually got saved" reality, not just an echo of what was submitted (mirrors
 * TagsTab's `selectedUuids.value = assigned.map(...)`). */
function applyKnownRows(resultRows: CommerceProductAttribute[]) {
  for (const uuid of Object.keys(assignState)) {
    assignState[uuid] = { included: false, values: [], used_for_variants: false, visible: true }
  }
  customRows.splice(0, customRows.length)

  for (const row of resultRows) {
    if (row.attribute_uuid) {
      assignState[row.attribute_uuid] = {
        included: true,
        values: row.values,
        used_for_variants: row.used_for_variants,
        visible: row.visible,
      }
    } else {
      customRows.push({
        key: `custom-${customRowKeySeq++}`,
        name: row.name ?? '',
        valuesText: row.values.join(', '),
        used_for_variants: row.used_for_variants,
        visible: row.visible,
      })
    }
  }
}

async function saveAssignment() {
  const product = props.product
  if (!product) return
  assignError.value = null
  try {
    const result = await setAttributes.mutateAsync({ productUuid: product.uuid, rows: buildPayloadRows() })
    knownRows.value = result
    applyKnownRows(result)
    success('Attributes updated', `${result.length} attribute row${result.length === 1 ? '' : 's'} set.`)
  } catch (e) {
    const err = toApiError(e)
    assignError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t set attributes')
  }
}
</script>

<template>
  <div class="space-y-8">
    <!-- Attribute list (management CRUD when no product is given; read-only reference otherwise) -->
    <section class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-sm font-medium text-default">Attributes</h3>
        <div class="flex items-center gap-2">
          <UInput
            v-model="search"
            icon="i-lucide-search"
            placeholder="Search attributes…"
            class="w-56"
            data-test="attribute-search"
          />
          <UButton
            v-if="managementMode"
            size="xs"
            icon="i-lucide-plus"
            label="New attribute"
            data-test="attribute-add"
            @click="openCreate"
          />
        </div>
      </div>

      <div v-if="status === 'pending'" class="flex justify-center py-6" data-test="attributes-loading">
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
      </div>
      <UAlert
        v-else-if="status === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load attributes"
        data-test="attributes-error"
      />
      <UAlert
        v-else-if="rows.length === 0"
        color="neutral"
        variant="subtle"
        icon="i-lucide-list-tree"
        title="No attributes yet"
        data-test="attributes-empty"
      />

      <div
        v-for="attr in rows"
        :key="attr.uuid"
        data-test="attribute-row"
        :data-uuid="attr.uuid"
        class="rounded-md border border-default p-3"
      >
        <div class="flex flex-wrap items-center gap-3">
          <span class="font-medium text-default">{{ attr.name }}</span>
          <span class="text-xs text-muted">{{ attr.slug }}</span>
          <UBadge color="neutral" variant="subtle" size="sm">
            {{ attr.values.length }} value{{ attr.values.length === 1 ? '' : 's' }}
          </UBadge>

          <div class="ml-auto flex gap-1">
            <UButton
              size="xs"
              color="neutral"
              variant="ghost"
              :icon="expandedUuid === attr.uuid ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              :label="expandedUuid === attr.uuid ? 'Hide values' : 'Values'"
              data-test="attribute-values-toggle"
              @click="toggleExpand(attr.uuid)"
            />
            <template v-if="managementMode">
              <UButton
                size="xs"
                color="neutral"
                variant="ghost"
                icon="i-lucide-pencil"
                aria-label="Edit attribute"
                data-test="attribute-edit"
                @click="openEdit(attr)"
              />
              <UButton
                size="xs"
                color="error"
                variant="ghost"
                icon="i-lucide-trash-2"
                aria-label="Delete attribute"
                data-test="attribute-delete"
                @click="() => { pendingDelete = attr }"
              />
            </template>
          </div>
        </div>

        <div
          v-if="expandedUuid === attr.uuid"
          data-test="attribute-values-panel"
          class="mt-3 space-y-2 border-t border-default pt-3"
        >
          <UAlert
            v-if="attr.values.length === 0"
            color="neutral"
            variant="subtle"
            icon="i-lucide-list"
            title="No values yet"
            data-test="attribute-values-empty"
          />

          <div
            v-for="val in attr.values"
            :key="val.uuid"
            data-test="attribute-value-row"
            :data-uuid="val.uuid"
            class="flex flex-wrap items-center gap-3 rounded-md border border-default p-2"
          >
            <span class="text-default">{{ val.value }}</span>
            <span class="text-xs text-muted">{{ val.slug }}</span>

            <div v-if="managementMode" class="ml-auto flex gap-1">
              <UButton
                size="xs"
                color="neutral"
                variant="ghost"
                icon="i-lucide-pencil"
                aria-label="Edit value"
                data-test="attribute-value-edit"
                @click="openEditValue(attr.uuid, val)"
              />
              <UButton
                size="xs"
                color="error"
                variant="ghost"
                icon="i-lucide-trash-2"
                aria-label="Delete value"
                data-test="attribute-value-delete"
                @click="() => { pendingDeleteValue = val }"
              />
            </div>
          </div>

          <template v-if="managementMode">
            <UButton
              size="xs"
              icon="i-lucide-plus"
              label="Add value"
              data-test="attribute-value-add"
              @click="openCreateValue(attr.uuid)"
            />

            <UAlert
              v-if="valueFormOpen && valueFormAttributeUuid === attr.uuid && valueFormError"
              color="error"
              variant="subtle"
              icon="i-lucide-triangle-alert"
              data-test="attribute-value-form-error"
              :title="valueFormError"
            />

            <UForm
              v-if="valueFormOpen && valueFormAttributeUuid === attr.uuid"
              id="attribute-value-form"
              :schema="valueSchema"
              :state="valueState"
              class="grid grid-cols-2 gap-3 rounded-md border border-default p-3 sm:grid-cols-3"
              @submit="submitValueForm"
            >
              <UFormField label="Value" name="value" required>
                <UInput v-model="valueState.value" class="w-full" data-test="attribute-value-value-input" />
              </UFormField>
              <UFormField label="Slug" name="slug" required>
                <UInput v-model="valueState.slug" class="w-full" data-test="attribute-value-slug-input" />
              </UFormField>
              <UFormField label="Position" name="position">
                <UInput
                  v-model.number="valueState.position"
                  type="number"
                  class="w-full"
                  data-test="attribute-value-position-input"
                />
              </UFormField>
              <div class="col-span-2 flex gap-2 sm:col-span-3">
                <UButton
                  type="submit"
                  size="xs"
                  :loading="createValue.isLoading.value || updateValue.isLoading.value"
                  :label="editingValueUuid ? 'Save' : 'Add'"
                  data-test="attribute-value-form-submit"
                />
                <UButton size="xs" color="neutral" variant="ghost" label="Cancel" @click="cancelValueForm" />
              </div>
            </UForm>
          </template>
        </div>
      </div>

      <TablePagination
        v-if="(attributesData?.total ?? 0) > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="attributesData?.total ?? 0"
        label="attributes"
      />
    </section>

    <!-- Create/edit attribute form --------------------------------------------------------- -->
    <template v-if="managementMode">
      <UAlert
        v-if="formError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="attribute-form-error"
        :title="formError"
      />

      <UForm
        v-if="formOpen"
        id="attribute-form"
        ref="formRef"
        :schema="schema"
        :state="state"
        class="grid grid-cols-2 gap-3 rounded-md border border-default p-3 sm:grid-cols-3"
        @submit="submitForm"
      >
        <UFormField label="Name" name="name" required>
          <UInput v-model="state.name" class="w-full" data-test="attribute-name-input" />
        </UFormField>
        <UFormField label="Slug" name="slug" required>
          <UInput v-model="state.slug" class="w-full" data-test="attribute-slug-input" />
        </UFormField>
        <UFormField label="Position" name="position">
          <UInput v-model.number="state.position" type="number" class="w-full" data-test="attribute-position-input" />
        </UFormField>
        <div class="col-span-2 flex gap-2 sm:col-span-3">
          <UButton
            type="submit"
            size="xs"
            :loading="create.isLoading.value || update.isLoading.value"
            :label="editingUuid ? 'Save' : 'Create'"
            data-test="attribute-form-submit"
          />
          <UButton size="xs" color="neutral" variant="ghost" label="Cancel" @click="cancelForm" />
        </div>
      </UForm>
    </template>

    <!-- Product attribute assignment -------------------------------------------------------- -->
    <section v-if="product" data-test="attribute-assignment-section" class="space-y-4 border-t border-default pt-6">
      <h3 class="text-sm font-medium text-default">Assigned attributes</h3>
      <p class="text-xs text-muted">Saving replaces the entire attribute assignment for this product.</p>

      <UAlert
        v-if="knownRows === null"
        color="neutral"
        variant="subtle"
        icon="i-lucide-list-tree"
        title="Assignment not loaded"
        description="Existing attribute assignments aren't shown here yet — saving refreshes them for this session."
        data-test="attribute-assignment-unknown"
      />

      <UAlert
        v-if="assignError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="attribute-assignment-error"
        :title="assignError"
      />

      <div
        v-for="attr in rows"
        :key="attr.uuid"
        data-test="attribute-assign-row"
        :data-uuid="attr.uuid"
        class="space-y-3 rounded-md border border-default p-3"
      >
        <UCheckbox
          :model-value="assignState[attr.uuid]?.included ?? false"
          :disabled="!canManage"
          :label="attr.name"
          data-test="attribute-assign-include"
          @update:model-value="() => toggleIncluded(attr.uuid)"
        />

        <div v-if="assignState[attr.uuid]?.included" class="ml-6 space-y-3">
          <div class="flex flex-wrap gap-3">
            <UCheckbox
              v-for="val in attr.values"
              :key="val.uuid"
              :model-value="assignState[attr.uuid]!.values.includes(val.slug)"
              :disabled="!canManage"
              :label="val.value"
              data-test="attribute-assign-value-checkbox"
              @update:model-value="() => toggleValue(attr.uuid, val.slug)"
            />
          </div>
          <div class="flex flex-wrap gap-4">
            <UCheckbox
              :model-value="assignState[attr.uuid]!.used_for_variants"
              :disabled="!canManage"
              label="Used for variants"
              data-test="attribute-assign-variants"
              @update:model-value="(v: boolean | 'indeterminate') => { assignState[attr.uuid]!.used_for_variants = v === true }"
            />
            <UCheckbox
              :model-value="assignState[attr.uuid]!.visible"
              :disabled="!canManage"
              label="Visible"
              data-test="attribute-assign-visible"
              @update:model-value="(v: boolean | 'indeterminate') => { assignState[attr.uuid]!.visible = v === true }"
            />
          </div>
        </div>
      </div>

      <!-- Custom (non-global) attribute rows -->
      <div class="space-y-3">
        <div
          v-for="row in customRows"
          :key="row.key"
          data-test="attribute-assign-custom-row"
          class="space-y-2 rounded-md border border-default p-3"
        >
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <UInput
              v-model="row.name"
              placeholder="Name"
              :disabled="!canManage"
              data-test="attribute-assign-custom-name"
            />
            <UInput
              v-model="row.valuesText"
              placeholder="Values, comma-separated"
              :disabled="!canManage"
              data-test="attribute-assign-custom-values"
            />
          </div>
          <div class="flex flex-wrap items-center gap-4">
            <UCheckbox
              v-model="row.used_for_variants"
              :disabled="!canManage"
              label="Used for variants"
              data-test="attribute-assign-custom-variants"
            />
            <UCheckbox
              v-model="row.visible"
              :disabled="!canManage"
              label="Visible"
              data-test="attribute-assign-custom-visible"
            />
            <UButton
              v-if="canManage"
              size="xs"
              color="error"
              variant="ghost"
              icon="i-lucide-trash-2"
              aria-label="Remove custom attribute row"
              data-test="attribute-assign-custom-remove"
              @click="removeCustomRow(row.key)"
            />
          </div>
        </div>

        <UButton
          v-if="canManage"
          size="xs"
          color="neutral"
          variant="ghost"
          icon="i-lucide-plus"
          label="Add custom attribute"
          data-test="attribute-assign-custom-add"
          @click="addCustomRow"
        />
      </div>

      <UButton
        v-if="canManage"
        size="xs"
        :loading="setAttributes.isLoading.value"
        label="Save attributes"
        data-test="attribute-assignment-save"
        @click="saveAssignment"
      />
    </section>
  </div>

  <UModal
    :open="pendingDelete !== null"
    title="Delete attribute"
    @update:open="(v: boolean) => { if (!v) pendingDelete = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.name }}”</span>? This removes all of its values and
        detaches it from every product. This can’t be undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="remove.isLoading.value"
          @click="() => { pendingDelete = null }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          data-test="attribute-delete-confirm"
          :loading="remove.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>

  <UModal
    :open="pendingDeleteValue !== null"
    title="Delete value"
    @update:open="(v: boolean) => { if (!v) pendingDeleteValue = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDeleteValue?.value }}”</span>? This can’t be undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="removeValue.isLoading.value"
          @click="() => { pendingDeleteValue = null }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          data-test="attribute-value-delete-confirm"
          :loading="removeValue.isLoading.value"
          @click="confirmDeleteValue"
        />
      </div>
    </template>
  </UModal>
</template>
