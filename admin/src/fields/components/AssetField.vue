<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { FieldDef } from '../types'
import { useUploadMedia, blobDisplayUrl } from '@/queries/media'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ field: FieldDef }>()
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
    const asset = await upload.mutateAsync({ file: f })
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
      <UFileUpload v-model="file" />
      <p v-if="upload.isLoading.value" class="mt-1 text-xs text-muted">Uploading…</p>
    </div>
    <!-- Single asset -->
    <template v-else>
      <UFileUpload v-model="file" />
      <p v-if="upload.isLoading.value" class="mt-1 text-xs text-muted">Uploading…</p>
      <div v-else-if="singleUuid" class="mt-1 flex items-center gap-2">
        <img :src="blobDisplayUrl(singleUuid)" alt="" class="h-10 w-10 rounded object-cover" />
        <span class="text-xs text-muted">{{ singleUuid }}</span>
      </div>
    </template>
  </UFormField>
</template>
