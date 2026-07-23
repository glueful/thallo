<script setup lang="ts">
// Task 10d: this single component covers BOTH "Categories" tabs the design calls for (dual-mode
// on whether a `product` prop is given), rather than splitting into two files:
//   - products/index.vue's "Categories" tab (`product` omitted): full category CRUD — the
//     taxonomy management surface for the whole tenant.
//   - the product detail's "Categories" tab (`product` given): the SAME category list, read-only,
//     plus an assignment section that sets which of those categories this one product belongs to
//     via the wholesale PUT set-list endpoint. CRUD controls are always hidden in this mode —
//     editing/deleting shared taxonomy from inside a single product's view would be surprising.
import { computed, inject, onUnmounted, reactive, ref, useTemplateRef, watch } from 'vue'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import {
  useCommerceCategories,
  useCommerceCategoryMutations,
  useCommerceProductMutations,
  type CommerceCategory,
  type CommerceProduct,
} from '@/queries/commerceCatalog'
import {
  useProductCategories,
  type AssignedCategory,
  type SectionEnvelope,
} from '@/queries/commerceProductSections'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { useSectionState, type SectionState } from '@/composables/useSectionState'
import { ProductRevisionCoordinatorKey } from '@/composables/useProductRevisionCoordinator'
import { rebaseSet } from '@/utils/sectionRebase'
import SectionStateChip from './SectionStateChip.vue'

const props = defineProps<{ canManage: boolean; product?: CommerceProduct }>()
const emit = defineEmits<{ state: [SectionState] }>()

const { success, error: notifyError } = useNotify()
const { data: categoriesData, status } = useCommerceCategories()
const { create, update, remove } = useCommerceCategoryMutations()
const { setCategories } = useCommerceProductMutations()

const rows = computed<CommerceCategory[]>(() => categoriesData.value ?? [])

/** Whether CRUD controls (add/edit/delete) render — never in product-assignment mode, see the
 * file-level comment above. */
const managementMode = computed(() => props.canManage && !props.product)

function parentName(uuid: string | null): string | null {
  if (uuid === null) return null
  return rows.value.find((c) => c.uuid === uuid)?.name ?? uuid
}

// ── Create / edit (shared form) ─────────────────────────────────────────────────────────────

// USelect/reka-ui reserve the empty string as "no selection" and reject a SelectItem with an
// empty `value` — so "no parent" uses a non-empty sentinel, translated to `null` at the payload
// boundary (mirrors products/index.vue's `ALL` status/type filter sentinel).
const ROOT = '__root__'

const schema = z.object({
  name: z.string().min(1, 'Name is required.'),
  slug: z.string().min(1, 'Slug is required.'),
  description: z.string().optional(),
  parent_uuid: z.string().optional(),
  position: z.number().int().optional(),
})
type Schema = z.output<typeof schema>

function blankState() {
  return { name: '', slug: '', description: '', parent_uuid: ROOT, position: 0 }
}

const formOpen = ref(false)
const editingUuid = ref<string | null>(null)
const state = reactive(blankState())
const formError = ref<string | null>(null)
const formRef = useTemplateRef<Form<Schema>>('formRef')

// A category can't be its own parent — exclude it from its own parent picker while editing.
const parentItems = computed(() => [
  { label: 'No parent (root)', value: ROOT },
  ...rows.value
    .filter((c) => c.uuid !== editingUuid.value)
    .map((c) => ({ label: c.name, value: c.uuid })),
])

function openCreate() {
  editingUuid.value = null
  Object.assign(state, blankState())
  formError.value = null
  formOpen.value = true
}

function openEdit(category: CommerceCategory) {
  editingUuid.value = category.uuid
  state.name = category.name
  state.slug = category.slug
  state.description = category.description ?? ''
  state.parent_uuid = category.parent_uuid ?? ROOT
  state.position = category.position
  formError.value = null
  formOpen.value = true
}

function cancelForm() {
  formOpen.value = false
}

async function submitForm(event: FormSubmitEvent<Schema>) {
  const input = {
    name: event.data.name,
    slug: event.data.slug,
    description: event.data.description || null,
    parent_uuid:
      event.data.parent_uuid && event.data.parent_uuid !== ROOT ? event.data.parent_uuid : null,
    position: event.data.position ?? 0,
  }
  try {
    if (editingUuid.value) {
      await update.mutateAsync({ uuid: editingUuid.value, input })
      success('Category updated', `“${input.name}” was saved.`)
    } else {
      await create.mutateAsync(input)
      success('Category created', `“${input.name}” is ready.`)
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
    notifyError(err, editingUuid.value ? 'Couldn’t update category' : 'Couldn’t create category')
  }
}

// ── Delete ───────────────────────────────────────────────────────────────────────────────────

const pendingDelete = ref<CommerceCategory | null>(null)
async function confirmDelete() {
  const category = pendingDelete.value
  if (!category) return
  try {
    await remove.mutateAsync(category.uuid)
    success('Category deleted', `“${category.name}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete category')
  }
}

// ── Product assignment (only rendered when `product` is given) ─────────────────────────────
//
// Task C6: hydrated from the real `products.categories.index` read (Task C1) instead of
// session-only tracking — the "existing assignments can't be shown" warning is GONE. `useSectionState()`
// throws without an ancestor `DirtyRegistry`, which management mode (`products/index.vue`'s
// Categories tab, no `product` prop) never provides — so it (and the coordinator registration
// below) is gated on `props.product`, stable for a mounted instance: every caller keys this
// component on the product's own uuid (see `[uuid]/index.vue`), so a single instance never
// toggles between the two modes.
const sectionState = props.product ? useSectionState('categories', 'Categories') : null
if (sectionState) emit('state', sectionState)
// No `markClean()` here: unlike the attributes subsection, categories has no distinct explicit
// "Use latest" action — a genuine remote divergence auto-merges (see `reconcileRemote` below).
const { phase, dirty, markDirty, beginSave, saveSucceeded, saveFailed } = sectionState ?? {
  phase: ref('idle' as const),
  dirty: ref(false),
  markDirty: () => {},
  beginSave: () => {},
  saveSucceeded: () => {},
  saveFailed: () => {},
}
const coordinator = inject(ProductRevisionCoordinatorKey, null)

// The section's own baseline/draft (Task C3's `SectionRegistration<T>` contract): `baseRevision`/
// `baselineUuids` are what the NEXT reconciliation compares a freshly-refetched remote envelope
// against; `selectedUuids` is the actual draft the checkboxes render — identical to the server's
// assignment whenever this section is clean, and the user's in-progress selection while `dirty`.
const baseRevision = ref<number | null>(null)
const baselineUuids = ref<string[]>([])
const selectedUuids = ref<string[]>([])
const assignError = ref<string | null>(null)
/** Set only right after a 409/refresh applied a `rebaseSet` 'merged' verdict — spec §5.2: "REPLACE
 * the draft with the merged result" and show a review banner, but never auto-resubmit. */
const mergeBanner = ref(false)

function syncFromRemote(envelope: SectionEnvelope<AssignedCategory>): void {
  baseRevision.value = envelope.revision
  baselineUuids.value = envelope.items.map((c) => c.uuid)
  selectedUuids.value = envelope.items.map((c) => c.uuid)
  mergeBanner.value = false
}

// `enabled: () => !!toValue(uuid)` (Task C1) makes this harmless to call in management mode too
// (uuid resolves to `''`, the query never fires) — only the ancestor-context-dependent calls
// above are actually gated on `props.product`.
const categoriesSection = useProductCategories(() => props.product?.uuid ?? '')

watch(
  () => categoriesSection.data.value,
  (envelope) => {
    if (!envelope || dirty.value) return
    syncFromRemote(envelope)
  },
  { immediate: true },
)

async function refetchCategoriesSection(): Promise<SectionEnvelope<AssignedCategory>> {
  const result = await categoriesSection.refetch(true)
  if (result.status !== 'success') {
    throw result.error ?? new Error('Failed to refresh categories.')
  }
  return result.data
}

if (props.product && coordinator) {
  const deregister = coordinator.register<AssignedCategory>('categories', {
    baseRevision,
    dirty,
    refetch: refetchCategoriesSection,
    // Only ever called while clean — no local draft to preserve.
    adoptRemote: (remote) => {
      syncFromRemote(remote)
    },
    // Only ever called while dirty — three-way SET rebase (Task C3's `rebaseSet`) against the
    // UUID arrays only (never whole envelopes).
    reconcileRemote: (remote) => {
      const remoteUuids = remote.items.map((c) => c.uuid)
      const verdict = rebaseSet(baselineUuids.value, selectedUuids.value, remoteUuids)
      baseRevision.value = remote.revision
      baselineUuids.value = remoteUuids
      if (verdict.kind === 'merged') {
        selectedUuids.value = verdict.result
        mergeBanner.value = true
      } else {
        // The remote set hasn't actually changed since our baseline — only the shared product
        // revision advanced elsewhere. Keep the local draft, adopt the fresh revision, no banner.
        mergeBanner.value = false
      }
      // Clears any lingering 'error' chip back to 'idle' without touching `dirty` (already true).
      markDirty()
    },
  })
  onUnmounted(deregister)
}

function toggleAssignment(uuid: string) {
  selectedUuids.value = selectedUuids.value.includes(uuid)
    ? selectedUuids.value.filter((u) => u !== uuid)
    : [...selectedUuids.value, uuid]
  markDirty()
}

async function saveAssignment() {
  const product = props.product
  if (!product || baseRevision.value === null) return
  assignError.value = null
  beginSave()
  try {
    await setCategories.mutateAsync({
      productUuid: product.uuid,
      categoryUuids: selectedUuids.value,
      expectedRevision: baseRevision.value,
    })
    saveSucceeded()
    success(
      'Categories updated',
      `${selectedUuids.value.length} categor${selectedUuids.value.length === 1 ? 'y' : 'ies'} set.`,
    )
    await coordinator?.afterMutation()
  } catch (e) {
    const err = toApiError(e)
    if (err.status === 409) {
      // Stale `expected_revision` — never blindly resubmit. Refresh this section FIRST; the
      // merge (or silent-rebase) verdict runs from inside that refresh's `reconcileRemote`.
      saveFailed()
      await coordinator?.refresh('categories')
    } else {
      saveFailed()
      assignError.value = Object.values(err.fieldErrors)[0] ?? err.message
      notifyError(err, 'Couldn’t set categories')
    }
  }
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
    <!-- Category list (management CRUD when no product is given; read-only reference otherwise) -->
    <section class="space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-medium text-default">Categories</h3>
        <UButton
          v-if="managementMode"
          size="xs"
          icon="i-lucide-plus"
          label="New category"
          data-test="category-add"
          @click="openCreate"
        />
      </div>

      <div
        v-if="status === 'pending'"
        class="flex justify-center py-6"
        data-test="categories-loading"
      >
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
      </div>
      <UAlert
        v-else-if="status === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load categories"
        data-test="categories-error"
      />
      <UAlert
        v-else-if="rows.length === 0"
        color="neutral"
        variant="subtle"
        icon="i-lucide-folder-tree"
        title="No categories yet"
        data-test="categories-empty"
      />

      <div
        v-for="category in rows"
        :key="category.uuid"
        data-test="category-row"
        :data-uuid="category.uuid"
        class="flex flex-wrap items-center gap-3 rounded-md border border-default p-3"
      >
        <span class="font-medium text-default">{{ category.name }}</span>
        <span class="text-xs text-muted">{{ category.slug }}</span>
        <UBadge
          v-if="parentName(category.parent_uuid)"
          color="neutral"
          variant="subtle"
          size="sm"
          data-test="category-parent"
        >
          under {{ parentName(category.parent_uuid) }}
        </UBadge>

        <div v-if="managementMode" class="ml-auto flex gap-1">
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-pencil"
            aria-label="Edit category"
            data-test="category-edit"
            @click="openEdit(category)"
          />
          <UButton
            size="xs"
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            aria-label="Delete category"
            data-test="category-delete"
            @click="
              () => {
                pendingDelete = category
              }
            "
          />
        </div>
      </div>
    </section>

    <!-- Create/edit form -------------------------------------------------------------------- -->
    <template v-if="managementMode">
      <UAlert
        v-if="formError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="category-form-error"
        :title="formError"
      />

      <UForm
        v-if="formOpen"
        id="category-form"
        ref="formRef"
        :schema="schema"
        :state="state"
        class="grid grid-cols-2 gap-3 rounded-md border border-default p-3"
        @submit="submitForm"
      >
        <UFormField label="Name" name="name" required>
          <UInput v-model="state.name" class="w-full" data-test="category-name-input" />
        </UFormField>
        <UFormField label="Slug" name="slug" required>
          <UInput v-model="state.slug" class="w-full" data-test="category-slug-input" />
        </UFormField>
        <UFormField label="Description" name="description" class="col-span-2">
          <UTextarea
            v-model="state.description"
            class="w-full"
            :rows="2"
            data-test="category-description-input"
          />
        </UFormField>
        <UFormField label="Parent" name="parent_uuid">
          <USelect
            v-model="state.parent_uuid"
            :items="parentItems"
            class="w-full"
            data-test="category-parent-input"
          />
        </UFormField>
        <UFormField label="Position" name="position">
          <UInput
            v-model.number="state.position"
            type="number"
            class="w-full"
            data-test="category-position-input"
          />
        </UFormField>
        <div class="col-span-2 flex gap-2">
          <UButton
            type="submit"
            size="xs"
            :loading="create.isLoading.value || update.isLoading.value"
            :label="editingUuid ? 'Save' : 'Create'"
            data-test="category-form-submit"
          />
          <UButton size="xs" color="neutral" variant="ghost" label="Cancel" @click="cancelForm" />
        </div>
      </UForm>
    </template>

    <!-- Product category assignment ---------------------------------------------------------- -->
    <section
      v-if="product"
      data-test="category-assignment-section"
      class="space-y-3 border-t border-default pt-6"
    >
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-medium text-default">Assigned categories</h3>
        <SectionStateChip :phase="phase" :dirty="dirty" data-test="categories-state-chip" />
      </div>
      <p class="text-xs text-muted">
        Saving replaces the entire category assignment for this product.
      </p>

      <UAlert
        v-if="categoriesSection.status.value === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load current assignments. Try again."
        data-test="categories-section-error"
      />

      <UAlert
        v-if="assignError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="category-assignment-error"
        :title="assignError"
      />

      <UAlert
        v-if="mergeBanner"
        color="warning"
        variant="subtle"
        icon="i-lucide-git-merge"
        title="Categories merged with remote changes — review and save"
        data-test="category-merge-banner"
      />

      <div
        v-for="category in rows"
        :key="category.uuid"
        data-test="category-assign-row"
        :data-uuid="category.uuid"
        class="flex items-center gap-2"
      >
        <UCheckbox
          :model-value="selectedUuids.includes(category.uuid)"
          :disabled="!canManage"
          :label="category.name"
          data-test="category-assign-checkbox"
          @update:model-value="() => toggleAssignment(category.uuid)"
        />
      </div>

      <UButton
        v-if="canManage"
        size="xs"
        :loading="phase === 'saving'"
        :disabled="saveDisabled"
        label="Save categories"
        data-test="category-assignment-save"
        @click="saveAssignment"
      />
    </section>
  </div>

  <UModal
    :open="pendingDelete !== null"
    title="Delete category"
    @update:open="
      (v: boolean) => {
        if (!v) pendingDelete = null
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.name }}”</span>? This can’t be undone.
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
          data-test="category-delete-confirm"
          :loading="remove.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>
