<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useQueryCache } from '@pinia/colada'
import { useMediaList, useUploadMedia, formatBytes, isImage, type MediaItem } from '@/queries/media'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ selectedUuid?: string }>()
const emit = defineEmits<{ select: [item: MediaItem] }>()

const page = ref(1)
const perPage = ref(30)
const type = ref('')
const search = ref('')
const debounced = refDebounced(search, 300)

const { data, status } = useMediaList(page, perPage, type, debounced)
const items = computed(() => data.value?.media ?? [])
const total = computed(() => data.value?.total ?? 0)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

watch([type, debounced], () => {
  page.value = 1
})

const filters = [
  { label: 'All', value: '' },
  { label: 'Images', value: 'image' },
  { label: 'Videos', value: 'video' },
  { label: 'Audio', value: 'audio' },
  { label: 'Docs', value: 'doc' },
]

const cache = useQueryCache()
const { success, error: notifyError } = useNotify()
const upload = useUploadMedia()

const showUpload = ref(false)
const files = ref<File[] | null>(null)
const uploading = ref(false)
const fileCount = computed(() => files.value?.length ?? 0)

// Clear the selection whenever the dialog closes so it never re-opens with stale files.
watch(showUpload, (open) => {
  if (!open) files.value = null
})

// Local object-URL thumbnails for the selected (not-yet-uploaded) files, created lazily and
// revoked when a file is removed / the dialog closes — so we never leak blob: URLs.
const previews = ref(new Map<File, string>())
watch(
  files,
  (list) => {
    const next = new Map<File, string>()
    for (const file of list ?? []) {
      const existing = previews.value.get(file)
      next.set(file, existing ?? (file.type.startsWith('image/') ? URL.createObjectURL(file) : ''))
    }
    for (const [file, url] of previews.value) {
      if (!next.has(file) && url) URL.revokeObjectURL(url)
    }
    previews.value = next
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  for (const url of previews.value.values()) if (url) URL.revokeObjectURL(url)
})

function removeFile(file: File) {
  files.value = (files.value ?? []).filter((f) => f !== file)
}

function fileKey(file: File): string {
  return `${file.name}-${file.size}-${file.lastModified}`
}

async function uploadFiles() {
  const list = files.value ?? []
  if (!list.length) return

  uploading.value = true
  let ok = 0
  const failed: string[] = []
  let lastError: unknown = null

  // Sequential — keeps server load predictable and errors attributable per file.
  for (const file of list) {
    try {
      // Library media exists to be embedded in public content: the admin <img> preview
      // (/blobs/{uuid}) and the live-site MediaUrlResolver both serve public blobs only,
      // so a private upload would be unpickable/unrenderable. Upload public like the
      // asset-field paths do (visibility default is otherwise 'private').
      await upload.mutateAsync({ file, visibility: 'public' })
      ok++
    } catch (e) {
      failed.push(file.name)
      lastError = e
    }
  }

  uploading.value = false
  await cache.invalidateQueries({ key: ['media'] })

  if (ok > 0) {
    success(ok === 1 ? 'Uploaded' : `Uploaded ${ok} files`)
  }
  if (failed.length) {
    notifyError(
      lastError,
      `${failed.length} file${failed.length === 1 ? '' : 's'} failed to upload`,
    )
  }
  showUpload.value = false
}

function fmtDate(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(String(v).replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { dateStyle: 'short' })
}
</script>

<template>
  <div class="flex h-full min-h-0 w-full flex-col gap-3 lg:w-85 lg:shrink-0">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-lg font-semibold text-highlighted">Media Library</h2>
      <UButton icon="i-lucide-plus" size="sm" class="rounded-xl px-3" @click="() => { showUpload = true }" />
    </div>

    <UInput v-model="search" icon="i-lucide-search" placeholder="Search media…" />

    <div class="flex flex-wrap gap-1">
      <UButton
        v-for="f in filters"
        :key="f.value"
        :label="f.label"
        size="xs"
        class="rounded-lg"
        :color="type === f.value ? 'primary' : 'neutral'"
        :variant="type === f.value ? 'solid' : 'soft'"
        @click="() => { type = f.value }"
      />
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto">
      <div v-if="status === 'pending'" class="flex justify-center py-10">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
      </div>
      <UEmpty
        v-else-if="!items.length"
        icon="i-lucide-image"
        title="No media"
        description="Upload a file to get started."
      />
      <div v-else class="flex flex-col gap-0.5">
        <button
          v-for="m in items"
          :key="m.uuid"
          type="button"
          class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors"
          :class="m.uuid === props.selectedUuid ? 'bg-elevated' : 'hover:bg-elevated/50'"
          @click="emit('select', m)"
        >
          <div class="size-10 shrink-0 overflow-hidden rounded-md bg-elevated">
            <img
              v-if="isImage(m.mime_type)"
              :src="m.thumb_url"
              :alt="m.name"
              class="size-full object-cover"
              loading="lazy"
            />
            <div v-else class="flex size-full items-center justify-center">
              <UIcon name="i-lucide-file" class="size-5 text-muted" />
            </div>
          </div>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-default">{{ m.name }}</p>
            <p class="truncate text-xs text-muted">
              {{ formatBytes(m.size) }} · {{ fmtDate(m.created_at) }}
            </p>
          </div>
        </button>
      </div>
    </div>

    <div
      v-if="total > 0"
      class="flex items-center justify-between gap-2 border-t border-default py-2 text-muted"
    >
      <span class="text-xs font-medium uppercase tracking-wide">{{ total }} items</span>
      <div class="flex items-center gap-1">
        <span class="text-sm">{{ page }} / {{ totalPages }}</span>
        <UButton
          icon="i-lucide-chevron-left"
          color="neutral"
          variant="ghost"
          size="xs"
          :disabled="page <= 1"
          @click="() => { page-- }"
        />
        <UButton
          icon="i-lucide-chevron-right"
          color="neutral"
          variant="ghost"
          size="xs"
          :disabled="page >= totalPages"
          @click="() => { page++ }"
        />
      </div>
    </div>

    <UModal v-model:open="showUpload" title="Upload media" :ui="{ content: 'sm:max-w-4xl' }">
      <template #body>
        <div class="flex h-[60vh] flex-col gap-4 sm:flex-row">
          <!-- Dropzone (left): preview disabled so this is JUST the drop area; our own grid is on the right. -->
          <UFileUpload
            v-model="files"
            multiple
            :preview="false"
            icon="i-lucide-upload"
            label="Drop files here or click to browse"
            description="Images, videos and documents — multiple files supported"
            class="h-44 w-full shrink-0 sm:h-full sm:w-1/2"
          />

          <!-- Selected files (right): scrollable thumbnail grid. -->
          <div class="min-h-0 flex-1 overflow-y-auto">
            <div
              v-if="!fileCount"
              class="flex h-full items-center justify-center text-center text-sm text-muted"
            >
              <span>Selected files will appear here</span>
            </div>
            <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3">
              <div
                v-for="file in files ?? []"
                :key="fileKey(file)"
                class="group relative flex flex-col overflow-hidden rounded-lg border border-default"
              >
                <div class="flex aspect-square items-center justify-center bg-elevated">
                  <img
                    v-if="previews.get(file)"
                    :src="previews.get(file)"
                    :alt="file.name"
                    class="size-full object-cover"
                  />
                  <UIcon v-else name="i-lucide-file" class="size-8 text-muted" />
                </div>
                <div class="min-w-0 p-2">
                  <p class="truncate text-xs font-medium text-default">{{ file.name }}</p>
                  <p class="text-xs text-muted">{{ formatBytes(file.size) }}</p>
                </div>
                <UButton
                  icon="i-lucide-x"
                  color="neutral"
                  size="xs"
                  class="absolute inset-e-1 top-1 rounded-full opacity-0 transition-opacity group-hover:opacity-100"
                  :aria-label="`Remove ${file.name}`"
                  @click="removeFile(file)"
                />
              </div>
            </div>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            label="Cancel"
            :disabled="uploading"
            @click="() => { showUpload = false }"
          />
          <UButton
            :label="fileCount ? `Upload ${fileCount} file${fileCount === 1 ? '' : 's'}` : 'Upload'"
            icon="i-lucide-upload"
            :disabled="!fileCount"
            :loading="uploading"
            @click="uploadFiles"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
