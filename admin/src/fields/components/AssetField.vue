<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { FieldDef } from '../types'
import { useUploadMedia, blobDisplayUrl } from '@/queries/media'
import { useNotify } from '@/composables/useNotify'
import MediaPickerModal from './MediaPickerModal.vue'

const props = withDefaults(
  defineProps<{
    field: FieldDef
    /** The dropzone already opens the picker (with a Library tab); hosts that
        find the extra button redundant can hide it. Defaults to shown. */
    libraryButton?: boolean
  }>(),
  { libraryButton: true },
)
// Stores blob uuid(s) — the backend FieldValidator::assetExistsOnMediaDisk validates by uuid.
// Single: string | undefined. Multiple: string[].
const model = defineModel<string | string[]>()
const file = ref<File | null>(null)
const upload = useUploadMedia()
const { error: notifyError } = useNotify()

const isMultiple = computed(() => props.field.multiple === true)

const singleUuid = computed<string | undefined>({
  get: () => (typeof model.value === 'string' ? model.value : undefined),
  set: (v) => (model.value = v),
})

const multiUuids = computed<string[]>({
  get: () => (Array.isArray(model.value) ? model.value : []),
  set: (v) => (model.value = v),
})

watch(file, async (f) => {
  if (!f) return
  // Check cap BEFORE uploading to avoid orphaned blobs
  if (isMultiple.value) {
    const cap = props.field.maxItems
    if (cap != null && multiUuids.value.length >= cap) {
      file.value = null
      return
    }
  }
  try {
    // Content assets must be public: the admin preview (/blobs/{uuid}) and the
    // live-site MediaUrlResolver both serve public blobs only — a private
    // upload would render as a broken image in both places.
    const asset = await upload.mutateAsync({ file: f, visibility: 'public' })
    if (!asset.blob_uuid) return // guard: skip if uuid absent (should not happen)
    if (isMultiple.value) {
      multiUuids.value = [...multiUuids.value, asset.blob_uuid]
    } else {
      singleUuid.value = asset.blob_uuid
    }
  } catch (e) {
    notifyError(e, 'Upload failed')
  } finally {
    file.value = null
  }
})

function removeUuid(uuid: string) {
  multiUuids.value = multiUuids.value.filter((u) => u !== uuid)
}

// Choose-or-upload: the picker modal (tabbed: upload / library) emits a blob
// uuid either way. Clicking the dropzone opens it on Upload; the library
// button opens it on Library. Dropping files on the dropzone still uploads
// directly (the click is intercepted, the drop is not).
const libraryOpen = ref(false)
const pickerTab = ref<'upload' | 'library'>('upload')
function openPicker(tab: 'upload' | 'library') {
  pickerTab.value = tab
  libraryOpen.value = true
}
function onLibraryPick(uuid: string) {
  if (isMultiple.value) {
    const cap = props.field.maxItems
    if (cap != null && multiUuids.value.length >= cap) return
    if (!multiUuids.value.includes(uuid)) multiUuids.value = [...multiUuids.value, uuid]
  } else {
    singleUuid.value = uuid
  }
}
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <!-- Multiple asset chips -->
    <div v-if="isMultiple" class="space-y-2">
      <div v-if="multiUuids.length" class="flex flex-wrap gap-1">
        <UBadge
          v-for="uuid in multiUuids"
          :key="uuid"
          color="neutral"
          variant="subtle"
          class="gap-1"
        >
          <img :src="blobDisplayUrl(uuid)" alt="" class="h-6 w-6 rounded object-cover" />
          <UButton
            icon="i-lucide-x"
            color="neutral"
            variant="ghost"
            size="xs"
            :aria-label="`Remove ${uuid}`"
            @click="removeUuid(uuid)"
          />
        </UBadge>
      </div>
      <div data-test="asset-dropzone-open" @click.capture.prevent.stop="openPicker('upload')">
        <UFileUpload v-model="file" />
      </div>
      <div class="flex items-center justify-between">
        <UButton
          v-if="libraryButton"
          size="xs"
          variant="ghost"
          color="neutral"
          icon="i-lucide-library"
          data-test="asset-library-open"
          @click="openPicker('library')"
        >
          Choose from library
        </UButton>
        <p v-if="upload.isLoading.value" class="text-xs text-muted">Uploading…</p>
      </div>
    </div>
    <!-- Single asset -->
    <template v-else>
      <div data-test="asset-dropzone-open" @click.capture.prevent.stop="openPicker('upload')">
        <UFileUpload v-model="file" />
      </div>
      <div class="mt-1 flex items-center justify-between gap-2">
        <UButton
          v-if="libraryButton"
          size="xs"
          variant="ghost"
          color="neutral"
          icon="i-lucide-library"
          data-test="asset-library-open"
          @click="openPicker('library')"
        >
          Choose from library
        </UButton>
        <p v-if="upload.isLoading.value" class="text-xs text-muted">Uploading…</p>
        <div v-else-if="singleUuid" class="flex min-w-0 items-center gap-2">
          <img :src="blobDisplayUrl(singleUuid)" alt="" class="h-10 w-10 shrink-0 rounded object-cover" />
          <span class="truncate text-xs text-muted">{{ singleUuid }}</span>
        </div>
      </div>
    </template>

    <MediaPickerModal
      v-model:open="libraryOpen"
      :multiple="isMultiple"
      :initial-tab="pickerTab"
      @select="onLibraryPick"
    />
  </UFormField>
</template>
