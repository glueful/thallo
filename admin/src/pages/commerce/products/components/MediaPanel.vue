<script setup lang="ts">
// Single-page product editor plan, Task C5: the Images card, hydrated from the real
// `products.media.index` read (Task C1) instead of session-only `knownMedia` tracking — the
// `media-unknown` alert is GONE: the list renders server truth, and an empty list is genuinely
// empty (spec §5.5 "what gets deleted").
//
// Attach/update/detach stay item-scoped and unguarded (no `expected_revision`) — every success
// just awaits `coordinator.afterMutation()` once so the section's own hydration query (and every
// OTHER registered section) refreshes. Reorder is the one REPLACEMENT mutation here: moving a row
// only edits a local draft order (`draftItems`, marking this section dirty); an explicit "Save
// order" button submits it with `expected_revision: baseRevision`. A stale save (409) or a
// same-page refresh while a reorder draft is dirty runs the same structured-conflict flow
// (`rebaseStructured`, Task C3) — silent when the remote genuinely didn't change the item set,
// otherwise an explicit review ("Use latest" / "Replace with mine"), never an automatic retry
// (Global Constraints §10).
import { computed, inject, onUnmounted, ref, watch } from 'vue'
import {
  useCommerceProductMutations,
  MEDIA_ROLES,
  type CommerceProduct,
  type CommerceMediaRole,
} from '@/queries/commerceCatalog'
import {
  useProductMedia,
  type ProductMediaItem,
  type SectionEnvelope,
} from '@/queries/commerceProductSections'
import { blobDisplayUrl } from '@/queries/media'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { useSectionState, type SectionState } from '@/composables/useSectionState'
import { ProductRevisionCoordinatorKey } from '@/composables/useProductRevisionCoordinator'
import { rebaseStructured } from '@/utils/sectionRebase'
import MediaPickerModal from '@/fields/components/MediaPickerModal.vue'

const props = defineProps<{ product: CommerceProduct; canManage: boolean }>()
const emit = defineEmits<{ state: [SectionState] }>()

const { error: notifyError } = useNotify()
const { attachMedia, updateMedia, detachMedia, reorderMedia } = useCommerceProductMutations()
const coordinator = inject(ProductRevisionCoordinatorKey, null)

// Same emit-once wiring rationale as ProductForm.vue — see that file's file-level note.
const sectionState = useSectionState('media', 'Images')
const { phase, dirty, markDirty, beginSave, saveSucceeded, saveFailed, markClean } = sectionState
emit('state', sectionState)

const roleItems = MEDIA_ROLES.map((r) => ({ label: r, value: r }))

const productUuid = computed(() => props.product.uuid)
const mediaQuery = useProductMedia(productUuid)

// The section's own baseline/draft (Task C3's `SectionRegistration<T>` contract): `baseRevision`/
// `baselineItems` are what the NEXT reconciliation compares a freshly-refetched remote envelope
// against; `draftItems` is what actually renders — identical to the server's items whenever this
// section is clean, and the user's in-progress reorder while `dirty` is true (attach/update/detach
// never set it; only a reorder draft does).
const baseRevision = ref<number | null>(null)
const baselineItems = ref<ProductMediaItem[]>([])
const draftItems = ref<ProductMediaItem[]>([])

function syncFromRemote(envelope: SectionEnvelope<ProductMediaItem>): void {
  baseRevision.value = envelope.revision
  baselineItems.value = envelope.items
  draftItems.value = envelope.items
}

// Seeds the initial hydration AND keeps a CLEAN section synced to any later query update that
// didn't go through the coordinator (e.g. Colada's own refetch-on-focus) — a DIRTY section is left
// alone here; only the coordinator's `reconcileRemote` below is allowed to touch a dirty draft.
watch(
  () => mediaQuery.data.value,
  (envelope) => {
    if (!envelope || dirty.value) return
    syncFromRemote(envelope)
  },
  { immediate: true },
)

async function refetchMediaSection(): Promise<SectionEnvelope<ProductMediaItem>> {
  const result = await mediaQuery.refetch(true)
  if (result.status !== 'success') {
    throw result.error ?? new Error('Failed to refresh media.')
  }
  return result.data
}

/** Set only while a reorder's conflict review is showing (`reconcileRemote` verdict was
 * `'conflict'`, or a 409 recovery landed on one) — see `useLatestConflict`/`replaceWithMineConflict`
 * below for the two explicit actions (never an automatic resubmit). */
const conflictRemote = ref<SectionEnvelope<ProductMediaItem> | null>(null)

if (coordinator) {
  const deregister = coordinator.register<ProductMediaItem>('media', {
    baseRevision,
    dirty,
    refetch: refetchMediaSection,
    // Only ever called while clean — no local draft to preserve.
    adoptRemote: (remote) => {
      syncFromRemote(remote)
      conflictRemote.value = null
    },
    // Only ever called while dirty (a reorder draft is pending) — decide silent vs conflict against
    // the ITEM ARRAYS only (never whole envelopes — see `rebaseStructured`'s own docblock).
    reconcileRemote: (remote) => {
      const verdict = rebaseStructured(baselineItems.value, draftItems.value, remote.items)
      if (verdict === 'silent') {
        // The remote's media order didn't actually change since our baseline — only the shared
        // product revision advanced (an unrelated mutation elsewhere). Keep the local draft order,
        // adopt the fresh revision as the new base (spec §5.2: "show no conflict"), and clear any
        // lingering 'error' chip back to 'idle' — `markDirty()` is the only transition that does
        // that without touching `dirty` (already true).
        baseRevision.value = remote.revision
        baselineItems.value = remote.items
        markDirty()
        conflictRemote.value = null
      } else {
        conflictRemote.value = remote
      }
    },
  })
  onUnmounted(deregister)
}

// ── Attach ───────────────────────────────────────────────────────────────────────────────────

const pickerOpen = ref(false)
const attachError = ref<string | null>(null)

function openPicker() {
  attachError.value = null
  pickerOpen.value = true
}

async function handlePicked(blobUuid: string) {
  try {
    await attachMedia.mutateAsync({
      productUuid: props.product.uuid,
      input: { blob_uuid: blobUuid, role: 'gallery' },
    })
    await coordinator?.afterMutation()
  } catch (e) {
    const err = toApiError(e)
    attachError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t attach media')
  }
}

// ── Edit (alt/role) ──────────────────────────────────────────────────────────────────────────

const editingUuid = ref<string | null>(null)
const editAlt = ref('')
const editRole = ref<CommerceMediaRole>('gallery')
const editError = ref<string | null>(null)

function startEdit(row: ProductMediaItem) {
  editingUuid.value = row.uuid
  editAlt.value = row.alt ?? ''
  editRole.value = (row.role as CommerceMediaRole) || 'gallery'
  editError.value = null
}

function cancelEdit() {
  editingUuid.value = null
}

async function saveEdit() {
  const uuid = editingUuid.value
  if (!uuid) return
  editError.value = null
  try {
    await updateMedia.mutateAsync({
      uuid,
      productUuid: props.product.uuid,
      input: { alt: editAlt.value || null, role: editRole.value },
    })
    await coordinator?.afterMutation()
    editingUuid.value = null
  } catch (e) {
    const err = toApiError(e)
    editError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t update media')
  }
}

// ── Detach ───────────────────────────────────────────────────────────────────────────────────

async function detach(row: ProductMediaItem) {
  try {
    await detachMedia.mutateAsync({ uuid: row.uuid, productUuid: props.product.uuid })
    await coordinator?.afterMutation()
  } catch (e) {
    notifyError(e, 'Couldn’t remove media')
  }
}

// ── Reorder (local draft + explicit save; structured conflict recovery) ────────────────────────

const reorderError = ref<string | null>(null)

function move(index: number, direction: -1 | 1) {
  const target = index + direction
  if (target < 0 || target >= draftItems.value.length) return
  const next = [...draftItems.value]
  const [row] = next.splice(index, 1)
  next.splice(target, 0, row as ProductMediaItem)
  draftItems.value = next
  reorderError.value = null
  markDirty()
}

async function commitReorder(): Promise<void> {
  if (baseRevision.value === null) return
  beginSave()
  try {
    await reorderMedia.mutateAsync({
      productUuid: props.product.uuid,
      orderedUuids: draftItems.value.map((m) => m.uuid),
      expectedRevision: baseRevision.value,
    })
    saveSucceeded()
    await coordinator?.afterMutation()
  } catch (e) {
    const err = toApiError(e)
    if (err.status === 409) {
      // Stale `expected_revision` — never blindly resubmit. Refresh this section FIRST; the
      // conflict (or silent-rebase) verdict runs from inside that refresh's `reconcileRemote`.
      saveFailed()
      await coordinator?.refresh('media')
    } else {
      saveFailed()
      reorderError.value = Object.values(err.fieldErrors)[0] ?? err.message
      notifyError(err, 'Couldn’t reorder media')
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
  baselineItems.value = remote.items
  conflictRemote.value = null
  await commitReorder()
}

const reorderSaveDisabled = computed(
  () => phase.value === 'saving' || (coordinator?.refreshing.value ?? false),
)

// While a reorder draft is dirty, item-scoped mutations (attach/edit/detach) are disabled: they
// don't touch `draftItems`, so firing one mid-draft would leave the stale draft on screen next to
// a conflict banner — and a detach would make a later "Replace with mine" resubmit a uuid the
// server no longer knows (a loud 422, but a dead end for the user). Save or discard the order
// first; the C5 review pinned this gate as the precedent for every later conflict-flow card.
const itemMutationsLocked = computed(() => dirty.value)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-medium text-default">Media</h3>
      <div class="flex items-center gap-2">
        <UButton
          v-if="canManage && dirty"
          size="xs"
          color="primary"
          label="Save order"
          data-test="media-reorder-save"
          :loading="phase === 'saving'"
          :disabled="reorderSaveDisabled"
          @click="commitReorder"
        />
        <UButton
          v-if="canManage"
          size="xs"
          icon="i-lucide-image-plus"
          label="Add media"
          data-test="media-add"
          :disabled="itemMutationsLocked"
          @click="openPicker"
        />
      </div>
    </div>

    <p v-if="itemMutationsLocked" class="text-xs text-muted" data-test="media-item-mutations-locked">
      Save or discard your order changes to add, edit, or remove media.
    </p>

    <UAlert
      v-if="attachError"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      data-test="media-attach-error"
      :title="attachError"
    />
    <UAlert
      v-if="reorderError"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      data-test="media-reorder-error"
      :title="reorderError"
    />

    <UAlert
      v-if="conflictRemote"
      color="warning"
      variant="subtle"
      icon="i-lucide-git-merge"
      title="Images changed elsewhere — review and save again"
      data-test="media-reorder-conflict"
    >
      <template #description>
        <div class="mt-2 flex gap-2">
          <UButton
            size="xs"
            label="Use latest"
            data-test="media-reorder-use-latest"
            @click="useLatestConflict"
          />
          <UButton
            size="xs"
            color="neutral"
            variant="subtle"
            label="Replace with mine"
            data-test="media-reorder-replace-mine"
            @click="replaceWithMineConflict"
          />
        </div>
      </template>
    </UAlert>

    <div
      v-if="mediaQuery.status.value === 'pending'"
      class="flex justify-center py-6"
      data-test="media-loading"
    >
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
    </div>
    <UAlert
      v-else-if="mediaQuery.status.value === 'error'"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Couldn’t load media"
      data-test="media-load-error"
    />
    <UAlert
      v-else-if="draftItems.length === 0"
      color="neutral"
      variant="subtle"
      icon="i-lucide-image-off"
      title="No media yet"
      data-test="media-empty"
    />

    <div
      v-for="(row, index) in draftItems"
      :key="row.uuid"
      data-test="media-row"
      :data-uuid="row.uuid"
      class="space-y-3 rounded-md border border-default p-3"
    >
      <div class="flex flex-wrap items-center gap-3">
        <img
          :src="blobDisplayUrl(row.blob_uuid)"
          :alt="row.alt ?? ''"
          class="h-14 w-14 rounded object-cover"
          data-test="media-thumb"
        />
        <UBadge color="neutral" variant="subtle" size="sm" data-test="media-role-badge">
          {{ row.role }}
        </UBadge>
        <UBadge
          v-if="row.variant_uuid"
          color="primary"
          variant="subtle"
          size="sm"
          data-test="media-variant-badge"
        >
          Variant-specific
        </UBadge>
        <span v-if="row.alt" class="text-sm text-muted" data-test="media-alt">{{ row.alt }}</span>

        <div v-if="canManage" class="ml-auto flex gap-1">
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-chevron-up"
            aria-label="Move up"
            data-test="media-move-up"
            :disabled="index === 0 || phase === 'saving'"
            @click="move(index, -1)"
          />
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-chevron-down"
            aria-label="Move down"
            data-test="media-move-down"
            :disabled="index === draftItems.length - 1 || phase === 'saving'"
            @click="move(index, 1)"
          />
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-pencil"
            aria-label="Edit media"
            data-test="media-edit"
            :disabled="itemMutationsLocked"
            @click="startEdit(row)"
          />
          <UButton
            size="xs"
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            aria-label="Detach media"
            data-test="media-detach"
            :disabled="itemMutationsLocked"
            @click="detach(row)"
          />
        </div>
      </div>

      <UAlert
        v-if="editingUuid === row.uuid && editError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="media-edit-error"
        :title="editError"
      />

      <div v-if="editingUuid === row.uuid" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <UFormField label="Alt text" class="col-span-2 sm:col-span-2">
          <UInput v-model="editAlt" class="w-full" data-test="media-edit-alt-input" />
        </UFormField>
        <UFormField label="Role">
          <USelect
            v-model="editRole"
            :items="roleItems"
            class="w-full"
            data-test="media-edit-role-input"
          />
        </UFormField>
        <div class="col-span-2 flex items-end gap-2 sm:col-span-4">
          <UButton
            size="xs"
            :loading="updateMedia.isLoading.value"
            label="Save"
            data-test="media-edit-save"
            @click="saveEdit"
          />
          <UButton size="xs" color="neutral" variant="ghost" label="Cancel" @click="cancelEdit" />
        </div>
      </div>
    </div>

    <MediaPickerModal v-model:open="pickerOpen" @select="handlePicked" />
  </div>
</template>
