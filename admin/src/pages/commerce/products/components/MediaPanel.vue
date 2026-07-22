<script setup lang="ts">
import { ref } from 'vue'
import {
  useCommerceProductMutations,
  MEDIA_ROLES,
  type CommerceProduct,
  type CommerceProductMedia,
  type CommerceMediaRole,
} from '@/queries/commerceCatalog'
import { blobDisplayUrl } from '@/queries/media'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import MediaPickerModal from '@/fields/components/MediaPickerModal.vue'

const props = defineProps<{ product: CommerceProduct; canManage: boolean }>()

const { error: notifyError } = useNotify()
const { attachMedia, updateMedia, detachMedia, reorderMedia } = useCommerceProductMutations()

const roleItems = MEDIA_ROLES.map((r) => ({ label: r, value: r }))

// There is no admin GET for a product's media list — attach returns only the row it created and
// reorder is the one endpoint that ever returns the full set — so, exactly like VariantsPanel's
// `knownChildren`, this panel tracks known rows itself from mutation responses for the lifetime
// of the component.
const knownMedia = ref<CommerceProductMedia[]>([])

// ── Attach ───────────────────────────────────────────────────────────────────────────────────

const pickerOpen = ref(false)
const attachError = ref<string | null>(null)

function openPicker() {
  attachError.value = null
  pickerOpen.value = true
}

async function handlePicked(blobUuid: string) {
  try {
    const row = await attachMedia.mutateAsync({
      productUuid: props.product.uuid,
      input: { blob_uuid: blobUuid, role: 'gallery' },
    })
    knownMedia.value = [...knownMedia.value, row].sort((a, b) => a.position - b.position)
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

function startEdit(row: CommerceProductMedia) {
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
    const updated = await updateMedia.mutateAsync({
      uuid,
      productUuid: props.product.uuid,
      input: { alt: editAlt.value || null, role: editRole.value },
    })
    knownMedia.value = knownMedia.value.map((m) => {
      if (m.uuid === uuid) return updated
      // At most one cover: promoting this row demotes any other locally-known cover row —
      // mirrors ProductMediaService::demoteCover(), which already enforced this server-side, so
      // no extra request is needed to reflect it here.
      if (updated.role === 'cover' && m.role === 'cover') return { ...m, role: 'gallery' }
      return m
    })
    editingUuid.value = null
  } catch (e) {
    const err = toApiError(e)
    editError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t update media')
  }
}

// ── Detach ───────────────────────────────────────────────────────────────────────────────────

async function detach(row: CommerceProductMedia) {
  try {
    await detachMedia.mutateAsync({ uuid: row.uuid, productUuid: props.product.uuid })
    knownMedia.value = knownMedia.value.filter((m) => m.uuid !== row.uuid)
  } catch (e) {
    notifyError(e, 'Couldn’t remove media')
  }
}

// ── Reorder (optimistic, rolls back on a failed mutation) ──────────────────────────────────────

const reorderError = ref<string | null>(null)

async function move(index: number, direction: -1 | 1) {
  const target = index + direction
  if (target < 0 || target >= knownMedia.value.length) return

  const previous = knownMedia.value
  const next = [...previous]
  const [row] = next.splice(index, 1)
  next.splice(target, 0, row as CommerceProductMedia)
  knownMedia.value = next
  reorderError.value = null

  try {
    // Every visible uuid is submitted — the endpoint only repositions entries present in the
    // list, so a partial submission would silently leave the rest unchanged.
    knownMedia.value = await reorderMedia.mutateAsync({
      productUuid: props.product.uuid,
      orderedUuids: next.map((m) => m.uuid),
    })
  } catch (e) {
    // Roll back the optimistic reorder — the UI must reflect the last known-good (server) order,
    // not the attempted one, when the mutation is rejected.
    knownMedia.value = previous
    const err = toApiError(e)
    reorderError.value = err.message
    notifyError(err, 'Couldn’t reorder media')
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-medium text-default">Media</h3>
      <UButton
        v-if="canManage"
        size="xs"
        icon="i-lucide-image-plus"
        label="Add media"
        data-test="media-add"
        @click="openPicker"
      />
    </div>

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
      v-if="knownMedia.length === 0"
      color="neutral"
      variant="subtle"
      icon="i-lucide-image-off"
      title="No media yet"
      data-test="media-empty"
    />

    <div
      v-for="(row, index) in knownMedia"
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
        <span v-if="row.alt" class="text-sm text-muted" data-test="media-alt">{{ row.alt }}</span>

        <div v-if="canManage" class="ml-auto flex gap-1">
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-chevron-up"
            aria-label="Move up"
            data-test="media-move-up"
            :disabled="index === 0"
            @click="move(index, -1)"
          />
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-chevron-down"
            aria-label="Move down"
            data-test="media-move-down"
            :disabled="index === knownMedia.length - 1"
            @click="move(index, 1)"
          />
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-pencil"
            aria-label="Edit media"
            data-test="media-edit"
            @click="startEdit(row)"
          />
          <UButton
            size="xs"
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            aria-label="Detach media"
            data-test="media-detach"
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
          <USelect v-model="editRole" :items="roleItems" class="w-full" data-test="media-edit-role-input" />
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
