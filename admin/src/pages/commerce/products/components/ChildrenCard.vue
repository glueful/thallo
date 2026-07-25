<script setup lang="ts">
// Single-page product editor plan, Task C8: the Grouped-products card — hydrated from the real
// `products.children.index` read (Task C1) instead of a plain comma-separated-uuid textarea plus
// session-only `knownChildren` tracking of the setChildren response (both DELETED from
// VariantsPanel.vue, which used to own this section; see that file's own note).
//
// CRITICAL wire semantics (Global Constraints: "Admin children reads never hide existing
// attachments"): an attached TOMBSTONED child arrives with `deleted: true` — rendered here
// honestly (muted row + "Deleted" badge). It may be RETAINED (left in the list) or REMOVED (taken
// out) by the next replacement save, but the ADD picker below must never offer a tombstoned OR
// non-purchasable product — the backend 422s a genuinely NEW tombstoned attachment
// (`CatalogService::setProductChildren()`'s own docblock: the retention exception only lets an
// ALREADY-attached child survive being tombstoned after the fact, never creates a new one), and
// only physical/digital products are purchasable (`CatalogService::PURCHASABLE_TYPES`).
//
// `setChildren` is a REPLACEMENT mutation, same shape as MediaPanel's reorder: there is no
// separate attach/detach endpoint for children at all, so add/remove/reorder are ALL local edits
// to `draftItems` (never an immediate network call) — only the explicit Save button commits,
// carrying `expected_revision: baseRevision`. Registered with the coordinator under `'children'`;
// `rebaseStructured` (Task C3) decides silent-rebase vs an explicit conflict review ("Use latest" /
// "Replace with mine") exactly like MediaPanel's reorder conflict flow — no automatic retry ever
// (Global Constraints §10).
import { computed, inject, onUnmounted, ref, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import {
  useCommerceProductMutations,
  useProductSearchForChildren,
  type CommerceProduct,
} from '@/queries/commerceCatalog'
import {
  useProductChildren,
  type ProductChildItem,
  type SectionEnvelope,
} from '@/queries/commerceProductSections'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { useSectionState, type SectionState } from '@/composables/useSectionState'
import { ProductRevisionCoordinatorKey } from '@/composables/useProductRevisionCoordinator'
import { rebaseStructured } from '@/utils/sectionRebase'

const props = defineProps<{ product: CommerceProduct; canManage: boolean }>()
const emit = defineEmits<{ state: [SectionState] }>()

const { error: notifyError } = useNotify()
const { setChildren } = useCommerceProductMutations()
const coordinator = inject(ProductRevisionCoordinatorKey, null)

// Same emit-once wiring rationale as ProductForm.vue/MediaPanel.vue — see MediaPanel's own note.
const sectionState = useSectionState('children', 'Grouped products')
const { phase, dirty, markDirty, beginSave, saveSucceeded, saveFailed, markClean } = sectionState
emit('state', sectionState)

const productUuid = computed(() => props.product.uuid)
const childrenQuery = useProductChildren(productUuid)

// The section's own baseline/draft (Task C3's `SectionRegistration<T>` contract) — identical
// division of responsibility to MediaPanel's reorder draft: `baseRevision`/`baselineItems` are
// what the NEXT reconciliation compares a freshly-refetched remote envelope against, `draftItems`
// is what actually renders and what a Save submits (mapped down to an ordered uuid list).
const baseRevision = ref<number | null>(null)
const baselineItems = ref<ProductChildItem[]>([])
const draftItems = ref<ProductChildItem[]>([])

function syncFromRemote(envelope: SectionEnvelope<ProductChildItem>): void {
  baseRevision.value = envelope.revision
  baselineItems.value = envelope.items
  draftItems.value = envelope.items
}

// Seeds the initial hydration AND keeps a CLEAN section synced to any later query update that
// didn't go through the coordinator — a DIRTY section is left alone here; only the coordinator's
// `reconcileRemote` below is allowed to touch a dirty draft (mirrors MediaPanel verbatim).
watch(
  () => childrenQuery.data.value,
  (envelope) => {
    if (!envelope || dirty.value) return
    syncFromRemote(envelope)
  },
  { immediate: true },
)

async function refetchChildrenSection(): Promise<SectionEnvelope<ProductChildItem>> {
  const result = await childrenQuery.refetch(true)
  if (result.status !== 'success') {
    throw result.error ?? new Error('Failed to refresh children.')
  }
  return result.data
}

/** Set only while a replacement's conflict review is showing — see `useLatestConflict`/
 * `replaceWithMineConflict` below (never an automatic resubmit). */
const conflictRemote = ref<SectionEnvelope<ProductChildItem> | null>(null)

if (coordinator) {
  const deregister = coordinator.register<ProductChildItem>('children', {
    baseRevision,
    dirty,
    refetch: refetchChildrenSection,
    // Only ever called while clean — no local draft to preserve.
    adoptRemote: (remote) => {
      syncFromRemote(remote)
      conflictRemote.value = null
    },
    // Only ever called while dirty — decide silent vs conflict against the ITEM ARRAYS only
    // (never whole envelopes — see `rebaseStructured`'s own docblock).
    reconcileRemote: (remote) => {
      const verdict = rebaseStructured(baselineItems.value, draftItems.value, remote.items)
      if (verdict === 'silent') {
        // The remote's children set/order didn't actually change since our baseline — only the
        // shared product revision advanced (an unrelated mutation elsewhere). Keep the local
        // draft, adopt the fresh revision as the new base (spec §5.2: "show no conflict"), and
        // clear any lingering 'error' chip back to 'idle' via markDirty() (dirty is already true).
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

// ── Remove / reorder (local draft only — no network call until Save) ───────────────────────────

function removeChild(row: ProductChildItem): void {
  draftItems.value = draftItems.value.filter((item) => item.uuid !== row.uuid)
  markDirty()
}

function move(index: number, direction: -1 | 1): void {
  const target = index + direction
  if (target < 0 || target >= draftItems.value.length) return
  const next = [...draftItems.value]
  const [row] = next.splice(index, 1)
  next.splice(target, 0, row as ProductChildItem)
  draftItems.value = next
  markDirty()
}

// ── Add (picker: reuses the existing product search, physical/digital only) ────────────────────

const pickerOpen = ref(false)
const pickerSearch = ref('')
const debouncedPickerSearch = refDebounced(pickerSearch, 250)
const { data: pickerResults, status: pickerStatus } = useProductSearchForChildren(debouncedPickerSearch)

/** Never offers a tombstoned or non-purchasable product (see the file-level note): tombstones
 * never appear in `fetchProducts`' results to begin with (the admin list only ever returns live
 * products), so this filter only needs to enforce purchasable-type, exclude the product being
 * edited itself (a product cannot be its own child — `CatalogService::setProductChildren()`), and
 * exclude anything already in the draft (adding it again would be a confusing no-op). */
const pickerCandidates = computed(() => {
  const draftUuids = new Set(draftItems.value.map((item) => item.uuid))
  return (pickerResults.value ?? []).filter(
    (candidate) =>
      candidate.uuid !== props.product.uuid &&
      (candidate.type === 'physical' || candidate.type === 'digital') &&
      !draftUuids.has(candidate.uuid),
  )
})

function togglePicker(): void {
  pickerOpen.value = !pickerOpen.value
  if (!pickerOpen.value) pickerSearch.value = ''
}

function addChild(candidate: CommerceProduct): void {
  draftItems.value = [
    ...draftItems.value,
    {
      uuid: candidate.uuid,
      name: candidate.name,
      slug: candidate.slug,
      status: candidate.status,
      deleted: false,
      position: draftItems.value.length,
    },
  ]
  markDirty()
}

// ── Save (replacement mutation; structured conflict recovery) ──────────────────────────────────

const saveError = ref<string | null>(null)

async function commitReplace(): Promise<void> {
  if (baseRevision.value === null) return
  saveError.value = null
  beginSave()
  try {
    await setChildren.mutateAsync({
      productUuid: props.product.uuid,
      childUuids: draftItems.value.map((item) => item.uuid),
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
      await coordinator?.refresh('children')
    } else {
      saveFailed()
      saveError.value = Object.values(err.fieldErrors)[0] ?? err.message
      notifyError(err, 'Couldn’t save grouped products')
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
  await commitReplace()
}

const saveDisabled = computed(
  () => phase.value === 'saving' || (coordinator?.refreshing.value ?? false),
)
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-medium text-default">Child products</h3>
      <div class="flex items-center gap-2">
        <UButton
          v-if="canManage && dirty"
          size="xs"
          color="primary"
          label="Save"
          data-test="children-save"
          :loading="phase === 'saving'"
          :disabled="saveDisabled"
          @click="commitReplace"
        />
        <UButton
          v-if="canManage"
          size="xs"
          icon="i-lucide-plus"
          :label="pickerOpen ? 'Close' : 'Add child'"
          data-test="children-add"
          @click="togglePicker"
        />
      </div>
    </div>

    <UAlert
      v-if="saveError"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      data-test="children-save-error"
      :title="saveError"
    />

    <UAlert
      v-if="conflictRemote"
      color="warning"
      variant="subtle"
      icon="i-lucide-git-merge"
      title="Grouped products changed elsewhere — review and save again"
      data-test="children-conflict"
    >
      <template #description>
        <div class="mt-2 flex gap-2">
          <UButton
            size="xs"
            label="Use latest"
            data-test="children-use-latest"
            @click="useLatestConflict"
          />
          <UButton
            size="xs"
            color="neutral"
            variant="subtle"
            label="Replace with mine"
            data-test="children-replace-mine"
            @click="replaceWithMineConflict"
          />
        </div>
      </template>
    </UAlert>

    <div v-if="pickerOpen" class="space-y-2 rounded-md border border-default p-3">
      <UInput
        v-model="pickerSearch"
        icon="i-lucide-search"
        placeholder="Search products…"
        class="w-full"
        data-test="children-picker-search"
      />
      <div
        v-if="pickerStatus === 'pending' && debouncedPickerSearch.length >= 2"
        class="text-xs text-muted"
      >
        Searching…
      </div>
      <ul class="space-y-1">
        <li v-for="candidate in pickerCandidates" :key="candidate.uuid">
          <button
            type="button"
            class="w-full rounded-md border border-default p-2 text-left text-sm hover:bg-elevated"
            data-test="children-picker-result"
            :data-uuid="candidate.uuid"
            @click="addChild(candidate)"
          >
            {{ candidate.name }} — {{ candidate.slug }} ({{ candidate.type }})
          </button>
        </li>
      </ul>
    </div>

    <div
      v-if="childrenQuery.status.value === 'pending'"
      class="flex justify-center py-6"
      data-test="children-loading"
    >
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
    </div>
    <UAlert
      v-else-if="childrenQuery.status.value === 'error'"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Couldn’t load grouped products"
      data-test="children-load-error"
    />
    <UAlert
      v-else-if="draftItems.length === 0"
      color="neutral"
      variant="subtle"
      icon="i-lucide-package"
      title="No child products yet"
      data-test="children-empty"
    />

    <div
      v-for="(row, index) in draftItems"
      :key="row.uuid"
      data-test="children-row"
      :data-uuid="row.uuid"
      :data-deleted="row.deleted"
      class="flex flex-wrap items-center gap-3 rounded-md border border-default p-3"
      :class="row.deleted ? 'opacity-60' : undefined"
    >
      <span class="font-medium" :class="row.deleted ? 'text-muted line-through' : 'text-default'">
        {{ row.name }}
      </span>
      <span class="text-xs text-muted">{{ row.slug }}</span>
      <UBadge
        v-if="row.deleted"
        color="error"
        variant="subtle"
        size="sm"
        data-test="children-deleted-badge"
      >
        Deleted
      </UBadge>
      <UBadge v-else color="neutral" variant="subtle" size="sm">{{ row.status }}</UBadge>

      <div v-if="canManage" class="ml-auto flex gap-1">
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          icon="i-lucide-chevron-up"
          aria-label="Move up"
          data-test="children-move-up"
          :disabled="index === 0 || phase === 'saving'"
          @click="move(index, -1)"
        />
        <UButton
          size="xs"
          color="neutral"
          variant="ghost"
          icon="i-lucide-chevron-down"
          aria-label="Move down"
          data-test="children-move-down"
          :disabled="index === draftItems.length - 1 || phase === 'saving'"
          @click="move(index, 1)"
        />
        <UButton
          size="xs"
          color="error"
          variant="ghost"
          icon="i-lucide-trash-2"
          aria-label="Remove from group"
          data-test="children-remove"
          :disabled="phase === 'saving'"
          @click="removeChild(row)"
        />
      </div>
    </div>
  </div>
</template>
