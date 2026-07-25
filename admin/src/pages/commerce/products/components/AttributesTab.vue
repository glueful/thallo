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
import { computed, inject, onUnmounted, reactive, ref, useTemplateRef, watch } from 'vue'
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
  type ProductAttributeAssignmentInput,
} from '@/queries/commerceCatalog'
import {
  useProductAttributes,
  type ProductAttributeAssignment,
  type SectionEnvelope,
} from '@/queries/commerceProductSections'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { useSectionState, type SectionState } from '@/composables/useSectionState'
import { ProductRevisionCoordinatorKey } from '@/composables/useProductRevisionCoordinator'
import { rebaseStructured } from '@/utils/sectionRebase'
import TablePagination from '@/components/TablePagination.vue'
import SectionStateChip from './SectionStateChip.vue'

const props = defineProps<{ canManage: boolean; product?: CommerceProduct }>()
const emit = defineEmits<{ state: [SectionState] }>()

const { success, error: notifyError } = useNotify()

// ── List: search + pagination (shared by both modes — same tenant-wide attribute set) ──────────
const search = ref('')
const debouncedSearch = refDebounced(search, 300)
const page = ref(1)
const perPage = ref(25)
const filters = computed(() => ({
  q: debouncedSearch.value || undefined,
  page: page.value,
  perPage: perPage.value,
}))

const { data: attributesData, status } = useCommerceAttributes(filters)
const { create, update, remove, createValue, updateValue, removeValue } =
  useCommerceAttributeMutations()
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
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
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
  const input = {
    slug: event.data.slug,
    value: event.data.value,
    position: event.data.position ?? 0,
  }
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
//
// Task C6: hydrated from the real `products.attributes.index` read (Task C1) instead of
// session-only tracking — the "existing assignments can't be shown" warning is GONE.
// `useSectionState()` throws without an ancestor `DirtyRegistry`, which management mode
// (`products/index.vue`'s Attributes tab, no `product` prop) never provides — so it (and the
// coordinator registration below) is gated on `props.product`, stable for a mounted instance:
// every caller keys this component on the product's own uuid (see `[uuid]/index.vue`), so a
// single instance never toggles between the two modes.

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

const sectionState = props.product ? useSectionState('attributes', 'Attributes') : null
if (sectionState) emit('state', sectionState)
const { phase, dirty, markDirty, beginSave, saveSucceeded, saveFailed, markClean } =
  sectionState ?? {
    phase: ref('idle' as const),
    dirty: ref(false),
    markDirty: () => {},
    beginSave: () => {},
    saveSucceeded: () => {},
    saveFailed: () => {},
    markClean: () => {},
  }
const coordinator = inject(ProductRevisionCoordinatorKey, null)

const assignState = reactive<Record<string, AttributeAssignEntry>>({})
const customRows = reactive<CustomAttributeRow[]>([])
const assignError = ref<string | null>(null)
let customRowKeySeq = 0

// The section's own baseline/draft (Task C3's `SectionRegistration<T>` contract): `baseRevision`/
// `baselineRows` are what the NEXT reconciliation compares a freshly-refetched remote envelope
// against — `assignState`/`customRows` above are the actual draft the form renders.
const baseRevision = ref<number | null>(null)
const baselineRows = ref<ProductAttributeAssignment[]>([])
/** Set only while a conflict review is showing (`reconcileRemote` verdict was `'conflict'`, or a
 * 409 recovery landed on one) — see `useLatestConflict`/`replaceWithMineConflict` below for the
 * two explicit actions (never an automatic resubmit). */
const conflictRemote = ref<SectionEnvelope<ProductAttributeAssignment> | null>(null)

/** Rebuilds `assignState`/`customRows` from a server (or merged/reviewed) row set — the honest
 * "what the product actually carries" reality, not an echo of whatever was last submitted. Every
 * attribute the shared (paginated) list ever surfaces ALSO gets a default entry so its checkbox
 * has somewhere to read/write, but — unlike the old session-only version — an attribute assigned
 * server-side that was never visited this session (off-page) still gets its entry from
 * `serverRows` here, not from `rows.value`, which is exactly what keeps a save from wiping it. */
function applyRowsToDraft(serverRows: readonly ProductAttributeAssignment[]): void {
  for (const uuid of Object.keys(assignState)) delete assignState[uuid]
  customRows.splice(0, customRows.length)

  for (const row of serverRows) {
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
  for (const attr of rows.value) {
    if (!(attr.uuid in assignState)) {
      assignState[attr.uuid] = {
        included: false,
        values: [],
        used_for_variants: false,
        visible: true,
      }
    }
  }
}

function syncFromRemote(envelope: SectionEnvelope<ProductAttributeAssignment>): void {
  baseRevision.value = envelope.revision
  baselineRows.value = envelope.items
  applyRowsToDraft(envelope.items)
}

// Every attribute the shared (paginated) list surfaces — INCLUDING on a later page/search visited
// after the initial hydration — gets a default assignment entry so its checkbox always has
// somewhere to read/write; never overwrites an entry `applyRowsToDraft` already seeded.
watch(
  rows,
  (newRows) => {
    if (!props.product) return
    for (const attr of newRows) {
      if (!(attr.uuid in assignState)) {
        assignState[attr.uuid] = {
          included: false,
          values: [],
          used_for_variants: false,
          visible: true,
        }
      }
    }
  },
  { immediate: true },
)

// Harmless to call in management mode too — `enabled: () => !!toValue(uuid)` (Task C1) means the
// query never fires when there's no product.
const attributesSection = useProductAttributes(() => props.product?.uuid ?? '')

watch(
  () => attributesSection.data.value,
  (envelope) => {
    if (!envelope || dirty.value) return
    syncFromRemote(envelope)
  },
  { immediate: true },
)

async function refetchAttributesSection(): Promise<SectionEnvelope<ProductAttributeAssignment>> {
  const result = await attributesSection.refetch(true)
  if (result.status !== 'success') {
    throw result.error ?? new Error('Failed to refresh attributes.')
  }
  return result.data
}

function toggleIncluded(uuid: string) {
  const entry = assignState[uuid]
  if (entry) entry.included = !entry.included
  markDirty()
}

function toggleValue(uuid: string, slug: string) {
  const entry = assignState[uuid]
  if (!entry) return
  entry.values = entry.values.includes(slug)
    ? entry.values.filter((s) => s !== slug)
    : [...entry.values, slug]
  markDirty()
}

function setCustomRowField(
  row: CustomAttributeRow,
  patch: Partial<Pick<CustomAttributeRow, 'name' | 'valuesText' | 'used_for_variants' | 'visible'>>,
): void {
  Object.assign(row, patch)
  markDirty()
}

function addCustomRow() {
  customRows.push({
    key: `custom-${customRowKeySeq++}`,
    name: '',
    valuesText: '',
    used_for_variants: false,
    visible: true,
  })
  markDirty()
}

function removeCustomRow(key: string) {
  const index = customRows.findIndex((r) => r.key === key)
  if (index !== -1) customRows.splice(index, 1)
  markDirty()
}

function buildPayloadRows(): ProductAttributeAssignmentInput[] {
  // Build from assignState — NEVER from rows.value: the attribute list is paginated and
  // searchable, so a checked attribute can be off-page at Save time. The PUT is a
  // wholesale replace; filtering by the visible page would silently drop (and therefore
  // WIPE) every included assignment not currently in view.
  const attributeRows: ProductAttributeAssignmentInput[] = Object.entries(assignState)
    .filter(([, entry]) => entry.included)
    .map(([uuid, entry]) => ({
      attribute_uuid: uuid,
      values: entry.values,
      used_for_variants: entry.used_for_variants,
      visible: entry.visible,
    }))

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

/** The current draft, shaped as `ProductAttributeAssignment[]` (the same item shape `B`/`R` are)
 * so it can be passed as `rebaseStructured`'s `L` parameter — accepted for interface symmetry only
 * (see that function's own docblock: `L` never affects the verdict), `position` is a filler 0
 * since the draft doesn't track it. */
function currentDraftRows(): ProductAttributeAssignment[] {
  return buildPayloadRows().map((row) => ({
    attribute_uuid: row.attribute_uuid ?? null,
    name: row.name ?? null,
    values: row.values ?? [],
    used_for_variants: row.used_for_variants ?? false,
    visible: row.visible ?? true,
    position: 0,
  }))
}

if (props.product && coordinator) {
  const deregister = coordinator.register<ProductAttributeAssignment>('attributes', {
    baseRevision,
    dirty,
    refetch: refetchAttributesSection,
    // Only ever called while clean — no local draft to preserve.
    adoptRemote: (remote) => {
      syncFromRemote(remote)
      conflictRemote.value = null
    },
    // Only ever called while dirty — structured (non-set) rebase (Task C3's `rebaseStructured`):
    // silent when the remote genuinely didn't change the assignment set, otherwise an explicit
    // review ("Use latest" / "Replace with mine"), never an automatic retry.
    reconcileRemote: (remote) => {
      const verdict = rebaseStructured(baselineRows.value, currentDraftRows(), remote.items)
      if (verdict === 'silent') {
        baseRevision.value = remote.revision
        baselineRows.value = remote.items
        markDirty()
        conflictRemote.value = null
      } else {
        conflictRemote.value = remote
      }
    },
  })
  onUnmounted(deregister)
}

async function saveAssignment() {
  const product = props.product
  if (!product || baseRevision.value === null) return
  assignError.value = null
  beginSave()
  try {
    await setAttributes.mutateAsync({
      productUuid: product.uuid,
      rows: buildPayloadRows(),
      expectedRevision: baseRevision.value,
    })
    saveSucceeded()
    success('Attributes updated', 'Attribute assignment saved.')
    await coordinator?.afterMutation()
  } catch (e) {
    const err = toApiError(e)
    if (err.status === 409) {
      // Stale `expected_revision` — never blindly resubmit. Refresh this section FIRST; the
      // conflict (or silent-rebase) verdict runs from inside that refresh's `reconcileRemote`.
      saveFailed()
      await coordinator?.refresh('attributes')
    } else {
      saveFailed()
      assignError.value = Object.values(err.fieldErrors)[0] ?? err.message
      notifyError(err, 'Couldn’t set attributes')
    }
  }
}

function useLatestConflict(): void {
  const remote = conflictRemote.value
  if (!remote) return
  syncFromRemote(remote)
  conflictRemote.value = null
  markClean()
}

async function replaceWithMineConflict(): Promise<void> {
  const remote = conflictRemote.value
  if (!remote) return
  baseRevision.value = remote.revision
  baselineRows.value = remote.items
  conflictRemote.value = null
  await saveAssignment()
}

const saveDisabled = computed(
  () =>
    baseRevision.value === null ||
    phase.value === 'saving' ||
    (coordinator?.refreshing.value ?? false),
)
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

      <div
        v-if="status === 'pending'"
        class="flex justify-center py-6"
        data-test="attributes-loading"
      >
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
                @click="
                  () => {
                    pendingDelete = attr
                  }
                "
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
                @click="
                  () => {
                    pendingDeleteValue = val
                  }
                "
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
                <UInput
                  v-model="valueState.value"
                  class="w-full"
                  data-test="attribute-value-value-input"
                />
              </UFormField>
              <UFormField label="Slug" name="slug" required>
                <UInput
                  v-model="valueState.slug"
                  class="w-full"
                  data-test="attribute-value-slug-input"
                />
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
                <UButton
                  size="xs"
                  color="neutral"
                  variant="ghost"
                  label="Cancel"
                  @click="cancelValueForm"
                />
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
          <UInput
            v-model.number="state.position"
            type="number"
            class="w-full"
            data-test="attribute-position-input"
          />
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
    <section
      v-if="product"
      data-test="attribute-assignment-section"
      class="space-y-4 border-t border-default pt-6"
    >
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-medium text-default">Assigned attributes</h3>
        <SectionStateChip :phase="phase" :dirty="dirty" data-test="attributes-state-chip" />
      </div>
      <p class="text-xs text-muted">
        Saving replaces the entire attribute assignment for this product.
      </p>

      <UAlert
        v-if="attributesSection.status.value === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load current assignments. Try again."
        data-test="attributes-section-error"
      />

      <UAlert
        v-if="assignError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="attribute-assignment-error"
        :title="assignError"
      />

      <UAlert
        v-if="conflictRemote"
        color="warning"
        variant="subtle"
        icon="i-lucide-git-merge"
        title="Attributes changed elsewhere — review and save again"
        data-test="attribute-conflict"
      >
        <template #description>
          <div class="mt-2 flex gap-2">
            <UButton
              size="xs"
              label="Use latest"
              data-test="attribute-use-latest"
              @click="useLatestConflict"
            />
            <UButton
              size="xs"
              color="neutral"
              variant="subtle"
              label="Replace with mine"
              data-test="attribute-replace-mine"
              @click="replaceWithMineConflict"
            />
          </div>
        </template>
      </UAlert>

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
              @update:model-value="
                (v: boolean | 'indeterminate') => {
                  assignState[attr.uuid]!.used_for_variants = v === true
                  markDirty()
                }
              "
            />
            <UCheckbox
              :model-value="assignState[attr.uuid]!.visible"
              :disabled="!canManage"
              label="Visible"
              data-test="attribute-assign-visible"
              @update:model-value="
                (v: boolean | 'indeterminate') => {
                  assignState[attr.uuid]!.visible = v === true
                  markDirty()
                }
              "
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
              :model-value="row.name"
              placeholder="Name"
              :disabled="!canManage"
              data-test="attribute-assign-custom-name"
              @update:model-value="
                (v: string | number) => setCustomRowField(row, { name: String(v) })
              "
            />
            <UInput
              :model-value="row.valuesText"
              placeholder="Values, comma-separated"
              :disabled="!canManage"
              data-test="attribute-assign-custom-values"
              @update:model-value="
                (v: string | number) => setCustomRowField(row, { valuesText: String(v) })
              "
            />
          </div>
          <div class="flex flex-wrap items-center gap-4">
            <UCheckbox
              :model-value="row.used_for_variants"
              :disabled="!canManage"
              label="Used for variants"
              data-test="attribute-assign-custom-variants"
              @update:model-value="
                (v: boolean | 'indeterminate') =>
                  setCustomRowField(row, { used_for_variants: v === true })
              "
            />
            <UCheckbox
              :model-value="row.visible"
              :disabled="!canManage"
              label="Visible"
              data-test="attribute-assign-custom-visible"
              @update:model-value="
                (v: boolean | 'indeterminate') => setCustomRowField(row, { visible: v === true })
              "
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
        :loading="phase === 'saving'"
        :disabled="saveDisabled"
        label="Save attributes"
        data-test="attribute-assignment-save"
        @click="saveAssignment"
      />
    </section>
  </div>

  <UModal
    :open="pendingDelete !== null"
    title="Delete attribute"
    @update:open="
      (v: boolean) => {
        if (!v) pendingDelete = null
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.name }}”</span>? This removes all of
        its values and detaches it from every product. This can’t be undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="remove.isLoading.value"
          @click="
            () => {
              pendingDelete = null
            }
          "
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
    @update:open="
      (v: boolean) => {
        if (!v) pendingDeleteValue = null
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDeleteValue?.value }}”</span>? This can’t be
        undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="removeValue.isLoading.value"
          @click="
            () => {
              pendingDeleteValue = null
            }
          "
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
