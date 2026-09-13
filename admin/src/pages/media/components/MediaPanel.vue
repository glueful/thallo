<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  useMediaUsage,
  useMediaMutations,
  formatBytes,
  isImage,
  type MediaDetail,
} from '@/queries/media'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ item: MediaDetail }>()
const emit = defineEmits<{ deleted: [uuid: string] }>()

const { update, remove, optimize } = useMediaMutations()
const isImageItem = computed(() => isImage(props.item.mime_type))

async function runOptimize() {
  try {
    const res = await optimize.mutateAsync(props.item.uuid)
    const d = (res.data ?? res ?? {}) as {
      changed?: boolean
      original_size?: number
      new_size?: number
    }
    if (d.changed) {
      success('Image optimized', `Saved ${formatBytes((d.original_size ?? 0) - (d.new_size ?? 0))}`)
    } else {
      success('Already optimal', 'No further size reduction possible.')
    }
  } catch (e) {
    notifyError(e, 'Could not optimize the image')
  }
}
const { success, error: notifyError } = useNotify()
const { data: usage, status: usageStatus } = useMediaUsage(() => props.item.uuid)

const form = ref({ title: '', alt_text: '', caption: '', tags: [] as string[] })
const tagInput = ref('')

watch(
  () => props.item,
  (it) => {
    form.value = {
      title: it.name,
      alt_text: it.alt_text ?? '',
      caption: it.caption ?? '',
      tags: [...(it.tags ?? [])],
    }
  },
  { immediate: true },
)

const dirty = computed(
  () =>
    form.value.title !== props.item.name ||
    form.value.alt_text !== (props.item.alt_text ?? '') ||
    form.value.caption !== (props.item.caption ?? '') ||
    JSON.stringify(form.value.tags) !== JSON.stringify(props.item.tags ?? []),
)

function addTag() {
  const t = tagInput.value.trim()
  if (t && !form.value.tags.includes(t)) form.value.tags.push(t)
  tagInput.value = ''
}
function removeTag(t: string) {
  form.value.tags = form.value.tags.filter((x) => x !== t)
}

async function save() {
  try {
    await update.mutateAsync({
      uuid: props.item.uuid,
      input: {
        title: form.value.title,
        alt_text: form.value.alt_text,
        caption: form.value.caption,
        tags: form.value.tags,
      },
    })
    success('Saved', form.value.title)
  } catch (e) {
    notifyError(e, 'Could not save changes')
  }
}

const pendingDelete = ref(false)
async function confirmDelete() {
  // Capture before navigating away (props.item is gone once the panel deselects).
  const uuid = props.item.uuid
  const name = props.item.name
  // Close the modal and leave the detail view FIRST: deselecting disables this item's detail query,
  // so the delete's `['media']` invalidation can't refetch the now-missing item (which would 404 and
  // reject the mutation, leaving the modal stuck). The 404-tolerant fetchMediaItem backstops it too.
  pendingDelete.value = false
  emit('deleted', uuid)
  try {
    await remove.mutateAsync(uuid)
    success('Deleted', name)
  } catch (e) {
    notifyError(e, 'Could not delete')
  }
}

async function copyUrl() {
  await navigator.clipboard.writeText(props.item.url)
  success('File URL copied')
}

function fmtDate(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(String(v).replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <div class="flex flex-col gap-5">
    <!-- Title -->
    <div>
      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted"
        >Title</label
      >
      <UInput v-model="form.title" class="w-full" />
    </div>

    <!-- Alt text -->
    <div>
      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted"
        >Alt text</label
      >
      <UTextarea
        v-model="form.alt_text"
        :rows="2"
        autoresize
        placeholder="Describe the image for screen readers"
        class="w-full"
      />
    </div>

    <!-- Caption -->
    <div>
      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted"
        >Caption</label
      >
      <UTextarea
        v-model="form.caption"
        :rows="2"
        autoresize
        placeholder="Add a caption"
        class="w-full"
      />
    </div>

    <!-- Tags -->
    <div>
      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted"
        >Tags</label
      >
      <div v-if="form.tags.length" class="mb-2 flex flex-wrap gap-1">
        <UBadge
          v-for="t in form.tags"
          :key="t"
          color="neutral"
          variant="subtle"
          size="sm"
          class="cursor-pointer"
          @click="removeTag(t)"
        >
          {{ t }}
          <UIcon name="i-lucide-x" class="ms-1 size-3" />
        </UBadge>
      </div>
      <UInput
        v-model="tagInput"
        placeholder="Add a tag and press Enter"
        class="w-full"
        @keydown.enter.prevent="addTag"
      />
    </div>

    <!-- File URL -->
    <div>
      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted"
        >File URL</label
      >
      <UInput :model-value="item.url" readonly class="w-full">
        <template #trailing>
          <UButton
            icon="i-lucide-copy"
            color="neutral"
            variant="link"
            size="xs"
            aria-label="Copy URL"
            @click="copyUrl"
          />
        </template>
      </UInput>
    </div>

    <!-- Actions -->
    <div class="flex flex-col gap-2">
      <span class="text-xs font-semibold uppercase tracking-wide text-muted">Actions</span>
      <UButton
        label="Save changes"
        icon="i-lucide-save"
        block
        :disabled="!dirty"
        :loading="update.isLoading.value"
        @click="save"
      />
      <UButton
        v-if="isImageItem"
        label="Optimize image"
        icon="i-lucide-wand-2"
        color="neutral"
        variant="outline"
        block
        :loading="optimize.isLoading.value"
        @click="runOptimize"
      />
      <UButton
        label="Delete"
        icon="i-lucide-trash-2"
        color="error"
        variant="soft"
        block
        @click="
          () => {
            pendingDelete = true
          }
        "
      />
    </div>

    <!-- Details -->
    <div>
      <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Details</h3>
      <dl class="grid grid-cols-3 gap-y-2 text-sm">
        <dt class="text-muted">Filename</dt>
        <dd class="col-span-2 truncate text-default">{{ item.name }}</dd>
        <dt class="text-muted">Type</dt>
        <dd class="col-span-2 text-default">{{ item.mime_type }}</dd>
        <dt class="text-muted">Size</dt>
        <dd class="col-span-2 text-default">{{ formatBytes(item.size) }}</dd>
        <dt class="text-muted">Visibility</dt>
        <dd class="col-span-2 capitalize text-default">{{ item.visibility }}</dd>
        <dt class="text-muted">Uploaded</dt>
        <dd class="col-span-2 text-default">{{ fmtDate(item.created_at) }}</dd>
      </dl>
    </div>

    <!-- Used in -->
    <div>
      <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Used in</h3>
      <div v-if="usageStatus === 'pending'" class="text-sm text-muted">Checking…</div>
      <p v-else-if="!(usage ?? []).length" class="text-sm text-muted">
        This media is not currently used anywhere.
      </p>
      <ul v-else class="flex flex-col gap-1.5">
        <li v-for="u in usage" :key="u.entry_uuid" class="flex items-center gap-2 text-sm">
          <UIcon name="i-lucide-file-text" class="size-4 shrink-0 text-muted" />
          <span class="truncate text-default">
            <code class="text-xs">{{ u.entry_uuid.slice(0, 8) }}</code>
            <span v-if="u.type" class="text-muted"> · {{ u.type }}</span>
          </span>
          <UBadge
            v-if="u.status"
            :label="u.status"
            size="xs"
            variant="subtle"
            color="neutral"
            class="ms-auto"
          />
        </li>
      </ul>
    </div>

    <UModal v-model:open="pendingDelete" title="Delete media">
      <template #body>
        <p class="text-sm text-muted">
          Delete <span class="text-default">“{{ item.name }}”</span>? This soft-deletes the file;
          any content still referencing it will break.
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
                pendingDelete = false
              }
            "
          />
          <UButton
            color="error"
            icon="i-lucide-trash-2"
            label="Delete"
            :loading="remove.isLoading.value"
            @click="confirmDelete"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
