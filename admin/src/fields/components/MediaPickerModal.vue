<script setup lang="ts">
// The asset fields' choose-or-upload modal: Upload (default tab, mirroring
// the Media page's upload modal) and Library (search + thumb grid over the
// same query the Media page uses). Every acquired blob — uploaded or picked —
// leaves through the one `select` emit.
import { computed, ref, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useMediaList, useUploadMedia, type MediaItem } from '@/queries/media'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{
  /** Multi-asset fields upload several files at once; single fields take one. */
  multiple?: boolean
  /** Which tab to land on when opened — the dropzone opens on Upload, the library button on Library. */
  initialTab?: 'upload' | 'library'
  /** Visibility for files uploaded through this picker's Upload tab. Defaults to 'public' — every
   * pre-existing caller (content asset fields, product media) needs a publicly servable blob. A
   * caller with a private-only backend contract (e.g. digital-download definitions —
   * `DownloadService::assertBlobAttachable()` requires the referenced blob to be PRIVATE) overrides
   * this to 'private'. The Library tab needs no matching override: `MediaAdminController::index()`
   * lists every blob regardless of visibility already. */
  visibility?: 'public' | 'private'
  /** Media-library type filter for the Library tab (mirrors `MediaAdminController::applyTypeFilter`:
   * image|video|audio|doc). Defaults to 'image' — every pre-existing caller only ever picks images.
   * Pass '' for no filter (e.g. a digital-download deliverable can be any file type). */
  mediaType?: string
}>()
const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ select: [uuid: string] }>()
const { error: notifyError } = useNotify()

const tab = ref('upload')
const tabs = [
  { label: 'Upload', value: 'upload', slot: 'upload' as const },
  { label: 'Media library', value: 'library', slot: 'library' as const },
]

// ── Upload tab ──────────────────────────────────────────────────────────────
const files = ref<File[] | null>(null)
const singleFile = ref<File | null>(null)
const upload = useUploadMedia()
const uploading = ref(false)
const fileCount = computed(() => (props.multiple ? (files.value?.length ?? 0) : singleFile.value ? 1 : 0))

async function uploadSelected(): Promise<void> {
  const list = props.multiple ? (files.value ?? []) : singleFile.value ? [singleFile.value] : []
  if (!list.length) return
  uploading.value = true
  try {
    for (const file of list) {
      // Content assets must be public: the admin preview (/blobs/{uuid}) and
      // the live-site MediaUrlResolver both serve public blobs only. A caller
      // with a private-only contract overrides this via the `visibility` prop
      // (see its own docblock).
      const asset = await upload.mutateAsync({ file, visibility: props.visibility ?? 'public' })
      if (asset.blob_uuid) emit('select', asset.blob_uuid)
    }
    open.value = false
  } catch (e) {
    notifyError(e, 'Upload failed')
  } finally {
    uploading.value = false
  }
}

// ── Library tab ─────────────────────────────────────────────────────────────
const search = ref('')
const debounced = refDebounced(search, 250)
const page = ref(1)
watch(debounced, () => {
  page.value = 1
})

const perPage = 24
const { data, status } = useMediaList(
  () => page.value,
  () => perPage,
  () => props.mediaType ?? 'image',
  () => debounced.value || undefined,
)

function pick(item: MediaItem): void {
  emit('select', item.uuid)
  open.value = false
}

// Fresh state each open: land on the caller's tab, selections/search reset.
watch(open, (o) => {
  if (o) {
    tab.value = props.initialTab ?? 'upload'
    files.value = null
    singleFile.value = null
    search.value = ''
    page.value = 1
  }
})
</script>

<template>
  <UModal
    v-model:open="open"
    title="Add media"
    description="Upload from your device or pick from the library."
    :ui="{ content: 'sm:max-w-2xl' }"
  >
    <template #body>
      <!-- Both panes stay mounted so library search/paging and picked-but-not-
           uploaded files survive tab switches. -->
      <UTabs
        v-model="tab"
        :items="tabs"
        size="xs"
        variant="link"
        :unmount-on-hide="false"
        data-test="media-picker-tabs"
      >
        <template #upload>
          <div class="space-y-3 pt-2">
            <UFileUpload
              v-if="multiple"
              v-model="files"
              multiple
              icon="i-lucide-upload"
              label="Drop files here or click to browse"
              class="h-44 w-full"
            />
            <UFileUpload
              v-else
              v-model="singleFile"
              icon="i-lucide-upload"
              label="Drop a file here or click to browse"
              class="h-44 w-full"
            />
            <div class="flex justify-end">
              <UButton
                :label="fileCount > 1 ? `Upload ${fileCount} files` : 'Upload'"
                icon="i-lucide-upload"
                :disabled="!fileCount"
                :loading="uploading"
                data-test="media-picker-upload"
                @click="uploadSelected"
              />
            </div>
          </div>
        </template>

        <template #library>
          <div class="space-y-3 pt-2">
            <UInput
              v-model="search"
              icon="i-lucide-search"
              placeholder="Search media…"
              class="w-full"
              data-test="media-library-search"
            />

            <div v-if="status === 'pending'" class="grid grid-cols-4 gap-2">
              <USkeleton v-for="n in 8" :key="n" class="aspect-square" />
            </div>
            <UEmpty
              v-else-if="!data?.media.length"
              icon="i-lucide-image-off"
              title="No media found"
              :description="debounced ? 'Try a different search.' : 'Upload something first.'"
            />
            <div v-else class="grid max-h-96 grid-cols-4 gap-2 overflow-y-auto overscroll-contain">
              <button
                v-for="m in data.media"
                :key="m.uuid"
                type="button"
                class="group relative aspect-square overflow-hidden rounded-md border border-default hover:border-primary focus-visible:border-primary"
                :title="m.name"
                :data-test="`media-library-item-${m.uuid}`"
                @click="pick(m)"
              >
                <img :src="m.thumb_url" :alt="m.name" class="h-full w-full object-cover" />
                <span
                  class="absolute inset-x-0 bottom-0 truncate bg-default/85 px-1.5 py-0.5 text-start text-[11px] text-muted opacity-0 transition-opacity group-hover:opacity-100"
                >
                  {{ m.name }}
                </span>
              </button>
            </div>

            <div
              v-if="data && data.total > perPage"
              class="flex items-center justify-between text-sm text-muted"
            >
              <UButton
                size="xs"
                variant="ghost"
                color="neutral"
                icon="i-lucide-chevron-left"
                :disabled="page <= 1"
                aria-label="Previous page"
                @click="page = Math.max(1, page - 1)"
              />
              <span>Page {{ page }} / {{ Math.max(1, Math.ceil(data.total / perPage)) }}</span>
              <UButton
                size="xs"
                variant="ghost"
                color="neutral"
                icon="i-lucide-chevron-right"
                :disabled="page >= Math.ceil(data.total / perPage)"
                aria-label="Next page"
                @click="page = page + 1"
              />
            </div>
          </div>
        </template>
      </UTabs>
    </template>
  </UModal>
</template>
