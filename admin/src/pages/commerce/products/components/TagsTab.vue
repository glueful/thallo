<script setup lang="ts">
// Task 19a: mirrors CategoriesTab.vue's dual-mode design (management mode: no `product` prop —
// tag CRUD; assignment mode: `product` given — read-only reference list + a set-list assignment
// section), one component covering BOTH "Tags" surfaces the design calls for:
//   - products/index.vue's "Tags" tab (`product` omitted): full tag CRUD for the whole tenant.
//   - the product detail's "Tags" tab (`product` given): the same tag list, read-only, plus an
//     assignment section that sets which of those tags this one product carries via the
//     wholesale PUT set-list endpoint. CRUD controls are always hidden in this mode.
//
// Unlike categories, tags are FLAT (no parent/tree, no description/position) and `GET
// /commerce/tags` IS paginated with a `q` search filter (`TagRepository::paginatedFor()`) — so
// this component also owns search + pagination state, absent from CategoriesTab.
import { computed, inject, onUnmounted, reactive, ref, useTemplateRef, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import {
  useCommerceTags,
  useCommerceTagMutations,
  useCommerceProductMutations,
  type CommerceTag,
  type CommerceProduct,
} from '@/queries/commerceCatalog'
import {
  useProductTags,
  type AssignedTag,
  type SectionEnvelope,
} from '@/queries/commerceProductSections'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { useSectionState, type SectionState } from '@/composables/useSectionState'
import { ProductRevisionCoordinatorKey } from '@/composables/useProductRevisionCoordinator'
import { rebaseSet } from '@/utils/sectionRebase'
import TablePagination from '@/components/TablePagination.vue'
import SectionStateChip from './SectionStateChip.vue'

const props = defineProps<{ canManage: boolean; product?: CommerceProduct }>()
const emit = defineEmits<{ state: [SectionState] }>()

const { success, error: notifyError } = useNotify()

// ── List: search + pagination (shared by both modes — same tenant-wide tag set) ────────────
const search = ref('')
const debouncedSearch = refDebounced(search, 300)
const page = ref(1)
const perPage = ref(24)
const filters = computed(() => ({
  q: debouncedSearch.value || undefined,
  page: page.value,
  perPage: perPage.value,
}))

const { data: tagsData, status } = useCommerceTags(filters)
const { create, update, remove } = useCommerceTagMutations()
const { setTags } = useCommerceProductMutations()

const rows = computed<CommerceTag[]>(() => tagsData.value?.tags ?? [])

/** Whether CRUD controls (add/edit/delete) render — never in product-assignment mode, see the
 * file-level comment above. */
const managementMode = computed(() => props.canManage && !props.product)

// ── Create / edit (shared form) ─────────────────────────────────────────────────────────────

const schema = z.object({
  name: z.string().min(1, 'Name is required.'),
  slug: z.string().min(1, 'Slug is required.'),
})
type Schema = z.output<typeof schema>

function blankState() {
  return { name: '', slug: '' }
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

function openEdit(tag: CommerceTag) {
  editingUuid.value = tag.uuid
  state.name = tag.name
  state.slug = tag.slug
  formError.value = null
  formOpen.value = true
}

function cancelForm() {
  formOpen.value = false
}

async function submitForm(event: FormSubmitEvent<Schema>) {
  try {
    if (editingUuid.value) {
      // Slug is immutable (TagService::rename()) — the key's mere PRESENCE in the request
      // throws a 422, so the update payload NEVER includes it, regardless of the disabled
      // slug field's current value.
      await update.mutateAsync({ uuid: editingUuid.value, input: { name: event.data.name } })
      success('Tag updated', `“${event.data.name}” was saved.`)
    } else {
      await create.mutateAsync({ slug: event.data.slug, name: event.data.name })
      success('Tag created', `“${event.data.name}” is ready.`)
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
    notifyError(err, editingUuid.value ? 'Couldn’t update tag' : 'Couldn’t create tag')
  }
}

// ── Delete ───────────────────────────────────────────────────────────────────────────────────

const pendingDelete = ref<CommerceTag | null>(null)
async function confirmDelete() {
  const tag = pendingDelete.value
  if (!tag) return
  try {
    await remove.mutateAsync(tag.uuid)
    success('Tag deleted', `“${tag.name}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete tag')
  }
}

// ── Product assignment (only rendered when `product` is given) ─────────────────────────────
//
// Task C6: hydrated from the real `products.tags.index` read (Task C1) instead of session-only
// tracking — the "existing assignments can't be shown" warning is GONE. `useSectionState()`
// throws without an ancestor `DirtyRegistry`, which management mode (`products/index.vue`'s Tags
// tab, no `product` prop) never provides — so it (and the coordinator registration below) is
// gated on `props.product`, stable for a mounted instance: every caller keys this component on
// the product's own uuid (see `[uuid]/index.vue`), so a single instance never toggles between
// the two modes.
//
// `selectedUuids` is a bare uuid array, independent of the CURRENT paginated/searched page of
// `rows` — a tag assigned server-side that isn't on the visible page still lives in this array
// (from hydration) and is still submitted on save, which is what keeps a save from wiping an
// off-page assignment (the wipe-class regression this task pins).
const sectionState = props.product ? useSectionState('tags', 'Tags') : null
if (sectionState) emit('state', sectionState)
// No `markClean()` here: unlike the attributes subsection, tags has no distinct explicit "Use
// latest" action — a genuine remote divergence auto-merges (see `reconcileRemote` below).
const { phase, dirty, markDirty, beginSave, saveSucceeded, saveFailed } = sectionState ?? {
  phase: ref('idle' as const),
  dirty: ref(false),
  markDirty: () => {},
  beginSave: () => {},
  saveSucceeded: () => {},
  saveFailed: () => {},
}
const coordinator = inject(ProductRevisionCoordinatorKey, null)

const baseRevision = ref<number | null>(null)
const baselineUuids = ref<string[]>([])
const selectedUuids = ref<string[]>([])
const assignError = ref<string | null>(null)
/** Set only right after a 409/refresh applied a `rebaseSet` 'merged' verdict — spec §5.2: "REPLACE
 * the draft with the merged result" and show a review banner, but never auto-resubmit. */
const mergeBanner = ref(false)

function syncFromRemote(envelope: SectionEnvelope<AssignedTag>): void {
  baseRevision.value = envelope.revision
  baselineUuids.value = envelope.items.map((t) => t.uuid)
  selectedUuids.value = envelope.items.map((t) => t.uuid)
  mergeBanner.value = false
}

// Harmless to call in management mode too — `enabled: () => !!toValue(uuid)` (Task C1) means the
// query never fires when there's no product.
const tagsSection = useProductTags(() => props.product?.uuid ?? '')

watch(
  () => tagsSection.data.value,
  (envelope) => {
    if (!envelope || dirty.value) return
    syncFromRemote(envelope)
  },
  { immediate: true },
)

async function refetchTagsSection(): Promise<SectionEnvelope<AssignedTag>> {
  const result = await tagsSection.refetch(true)
  if (result.status !== 'success') {
    throw result.error ?? new Error('Failed to refresh tags.')
  }
  return result.data
}

if (props.product && coordinator) {
  const deregister = coordinator.register<AssignedTag>('tags', {
    baseRevision,
    dirty,
    refetch: refetchTagsSection,
    adoptRemote: (remote) => {
      syncFromRemote(remote)
    },
    reconcileRemote: (remote) => {
      const remoteUuids = remote.items.map((t) => t.uuid)
      const verdict = rebaseSet(baselineUuids.value, selectedUuids.value, remoteUuids)
      baseRevision.value = remote.revision
      baselineUuids.value = remoteUuids
      if (verdict.kind === 'merged') {
        selectedUuids.value = verdict.result
        mergeBanner.value = true
      } else {
        mergeBanner.value = false
      }
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
    await setTags.mutateAsync({
      productUuid: product.uuid,
      tagUuids: selectedUuids.value,
      expectedRevision: baseRevision.value,
    })
    saveSucceeded()
    success(
      'Tags updated',
      `${selectedUuids.value.length} tag${selectedUuids.value.length === 1 ? '' : 's'} set.`,
    )
    await coordinator?.afterMutation()
  } catch (e) {
    const err = toApiError(e)
    if (err.status === 409) {
      // Stale `expected_revision` — never blindly resubmit. Refresh this section FIRST; the
      // merge (or silent-rebase) verdict runs from inside that refresh's `reconcileRemote`.
      saveFailed()
      await coordinator?.refresh('tags')
    } else {
      saveFailed()
      assignError.value = Object.values(err.fieldErrors)[0] ?? err.message
      notifyError(err, 'Couldn’t set tags')
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
    <!-- Tag list (management CRUD when no product is given; read-only reference otherwise) -->
    <section class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-sm font-medium text-default">Tags</h3>
        <div class="flex items-center gap-2">
          <UInput
            v-model="search"
            icon="i-lucide-search"
            placeholder="Search tags…"
            class="w-56"
            data-test="tag-search"
          />
          <UButton
            v-if="managementMode"
            size="xs"
            icon="i-lucide-plus"
            label="New tag"
            data-test="tag-add"
            @click="openCreate"
          />
        </div>
      </div>

      <div v-if="status === 'pending'" class="flex justify-center py-6" data-test="tags-loading">
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
      </div>
      <UAlert
        v-else-if="status === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load tags"
        data-test="tags-error"
      />
      <UAlert
        v-else-if="rows.length === 0"
        color="neutral"
        variant="subtle"
        icon="i-lucide-tag"
        title="No tags yet"
        data-test="tags-empty"
      />

      <div
        v-for="tagRow in rows"
        :key="tagRow.uuid"
        data-test="tag-row"
        :data-uuid="tagRow.uuid"
        class="flex flex-wrap items-center gap-3 rounded-md border border-default p-3"
      >
        <span class="font-medium text-default">{{ tagRow.name }}</span>
        <span class="text-xs text-muted">{{ tagRow.slug }}</span>

        <div v-if="managementMode" class="ml-auto flex gap-1">
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-pencil"
            aria-label="Edit tag"
            data-test="tag-edit"
            @click="openEdit(tagRow)"
          />
          <UButton
            size="xs"
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            aria-label="Delete tag"
            data-test="tag-delete"
            @click="
              () => {
                pendingDelete = tagRow
              }
            "
          />
        </div>
      </div>

      <TablePagination
        v-if="(tagsData?.total ?? 0) > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="tagsData?.total ?? 0"
        label="tags"
      />
    </section>

    <!-- Create/edit form -------------------------------------------------------------------- -->
    <template v-if="managementMode">
      <UAlert
        v-if="formError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="tag-form-error"
        :title="formError"
      />

      <UForm
        v-if="formOpen"
        id="tag-form"
        ref="formRef"
        :schema="schema"
        :state="state"
        class="grid grid-cols-2 gap-3 rounded-md border border-default p-3"
        @submit="submitForm"
      >
        <UFormField label="Name" name="name" required>
          <UInput v-model="state.name" class="w-full" data-test="tag-name-input" />
        </UFormField>
        <UFormField label="Slug" name="slug" required>
          <!-- Immutable once created — shown for reference while editing, never submitted
               (see submitForm's docblock). -->
          <UInput
            v-model="state.slug"
            :disabled="editingUuid !== null"
            class="w-full"
            data-test="tag-slug-input"
          />
        </UFormField>
        <div class="col-span-2 flex gap-2">
          <UButton
            type="submit"
            size="xs"
            :loading="create.isLoading.value || update.isLoading.value"
            :label="editingUuid ? 'Save' : 'Create'"
            data-test="tag-form-submit"
          />
          <UButton size="xs" color="neutral" variant="ghost" label="Cancel" @click="cancelForm" />
        </div>
      </UForm>
    </template>

    <!-- Product tag assignment ---------------------------------------------------------------- -->
    <section
      v-if="product"
      data-test="tag-assignment-section"
      class="space-y-3 border-t border-default pt-6"
    >
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-medium text-default">Assigned tags</h3>
        <SectionStateChip :phase="phase" :dirty="dirty" data-test="tags-state-chip" />
      </div>
      <p class="text-xs text-muted">Saving replaces the entire tag assignment for this product.</p>

      <UAlert
        v-if="tagsSection.status.value === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load current assignments. Try again."
        data-test="tags-section-error"
      />

      <UAlert
        v-if="assignError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="tag-assignment-error"
        :title="assignError"
      />

      <UAlert
        v-if="mergeBanner"
        color="warning"
        variant="subtle"
        icon="i-lucide-git-merge"
        title="Tags merged with remote changes — review and save"
        data-test="tag-merge-banner"
      />

      <div
        v-for="tagRow in rows"
        :key="tagRow.uuid"
        data-test="tag-assign-row"
        :data-uuid="tagRow.uuid"
        class="flex items-center gap-2"
      >
        <UCheckbox
          :model-value="selectedUuids.includes(tagRow.uuid)"
          :disabled="!canManage"
          :label="tagRow.name"
          data-test="tag-assign-checkbox"
          @update:model-value="() => toggleAssignment(tagRow.uuid)"
        />
      </div>

      <UButton
        v-if="canManage"
        size="xs"
        :loading="phase === 'saving'"
        :disabled="saveDisabled"
        label="Save tags"
        data-test="tag-assignment-save"
        @click="saveAssignment"
      />
    </section>
  </div>

  <UModal
    :open="pendingDelete !== null"
    title="Delete tag"
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
          data-test="tag-delete-confirm"
          :loading="remove.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>
