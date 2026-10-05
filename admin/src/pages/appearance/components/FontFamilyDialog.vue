<script setup lang="ts">
// Add a family, or edit one (block typeface spec §4.6; plan Task 11): a name, the generic it falls
// back to, and — when adding — its .woff2 files. Each file goes to the media library first; the
// family is then made from them, and a file the server cannot read is named with the reason.
import { computed, ref, watch } from 'vue'
import { ApiError } from '@/api/errors'
import { uploadBlob } from '@/queries/media'
import { useFontLibraryMutations, type FontFamily } from '@/queries/fontLibrary'
import { fallbackStack } from '@/fonts/stacks'

const props = defineProps<{
  open: boolean
  /** The family being edited; null adds a new one. */
  family?: FontFamily | null
}>()
const emit = defineEmits<{ 'update:open': [open: boolean]; saved: [id: string] }>()

const FALLBACKS = [
  { value: 'sans-serif', label: 'Sans-serif' },
  { value: 'serif', label: 'Serif' },
  { value: 'monospace', label: 'Monospace' },
  { value: 'system-ui', label: 'System' },
  { value: 'cursive', label: 'Cursive' },
]

const mutations = useFontLibraryMutations()
const name = ref('')
const fallback = ref('sans-serif')
const files = ref<File[]>([])
const refused = ref<string[]>([])
const error = ref<string | null>(null)
const saving = ref(false)
const editing = computed(() => props.family != null)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    name.value = props.family?.name ?? ''
    fallback.value = props.family?.fallback ?? 'sans-serif'
    files.value = []
    refused.value = []
    error.value = null
  },
  { immediate: true },
)

/** Names chosen more than once: each file is one face, and the same file twice is one face. */
const duplicates = computed(() => {
  const seen = new Set<string>()
  const twice = new Set<string>()
  for (const f of files.value) {
    if (seen.has(f.name)) twice.add(f.name)
    seen.add(f.name)
  }
  return [...twice]
})
/** One of each file, in the order chosen. */
const unique = computed(() => {
  const seen = new Set<string>()
  return files.value.filter((f) => (seen.has(f.name) ? false : (seen.add(f.name), true)))
})

function take(list: FileList | File[] | null | undefined): void {
  const chosen = Array.from(list ?? [])
  refused.value = chosen.filter((f) => !/\.woff2$/i.test(f.name)).map((f) => f.name)
  files.value = [...files.value, ...chosen.filter((f) => /\.woff2$/i.test(f.name))]
  error.value = null
}

function onDrop(event: DragEvent): void {
  take(event.dataTransfer?.files)
}

const canSave = computed(
  () =>
    !saving.value &&
    name.value.trim() !== '' &&
    refused.value.length === 0 &&
    (editing.value || unique.value.length > 0),
)

async function save(): Promise<void> {
  if (!canSave.value) return
  saving.value = true
  error.value = null
  try {
    let id: string
    if (props.family) {
      id = await mutations.update.mutateAsync({
        id: props.family.id,
        name: name.value.trim(),
        fallback: fallback.value,
      })
    } else {
      const blobs: string[] = []
      for (const file of unique.value) {
        const uploaded = await uploadBlob(file)
        if (!uploaded.blob_uuid) throw new Error(`${file.name}: the upload returned no file`)
        blobs.push(uploaded.blob_uuid)
      }
      id = await mutations.create.mutateAsync({
        name: name.value.trim(),
        fallback: fallback.value,
        blob_uuids: blobs,
      })
    }
    emit('saved', id)
    emit('update:open', false)
  } catch (e) {
    error.value =
      e instanceof ApiError || e instanceof Error ? e.message : 'Couldn’t save the family'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UModal
    :open="open"
    :title="editing ? 'Edit family' : 'Add a family'"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <div class="space-y-4" data-test="font-family-dialog">
        <UFormField label="Name">
          <UInput v-model="name" class="w-full" data-test="font-family-name" />
        </UFormField>
        <UFormField
          label="Fallback"
          description="What shows while the font loads, and for a weight or style it lacks."
        >
          <div class="flex flex-wrap gap-1" role="group" aria-label="Fallback">
            <button
              v-for="item in FALLBACKS"
              :key="item.value"
              type="button"
              class="rounded-md border px-2 py-1 text-sm"
              :class="
                item.value === fallback
                  ? 'border-primary bg-primary/10 text-primary'
                  : 'border-default text-muted hover:text-default'
              "
              :style="{ fontFamily: fallbackStack(item.value) }"
              :aria-pressed="item.value === fallback ? 'true' : 'false'"
              :data-test="`font-family-fallback-${item.value}`"
              @click="fallback = item.value"
            >
              {{ item.label }}
            </button>
          </div>
        </UFormField>
        <UFormField
          v-if="!editing"
          label="Files"
          description="One .woff2 file per weight and style, or one variable file."
        >
          <label
            class="flex cursor-pointer flex-col items-center gap-1 rounded-md border border-dashed border-default p-4 text-sm text-muted hover:text-default"
            data-test="font-drop"
            @dragover.prevent
            @drop.prevent="onDrop"
          >
            <span>Drop .woff2 files here, or choose them</span>
            <input
              type="file"
              accept=".woff2,font/woff2"
              multiple
              class="sr-only"
              data-test="font-files"
              @change="take(($event.target as HTMLInputElement).files)"
            />
          </label>
          <ul v-if="unique.length > 0" class="mt-2 space-y-0.5 text-xs" data-test="font-file-list">
            <li v-for="file in unique" :key="file.name">{{ file.name }}</li>
          </ul>
          <p
            v-if="duplicates.length > 0"
            class="mt-1 text-xs text-warning"
            data-test="font-file-duplicate"
          >
            <template v-for="(file, i) in duplicates" :key="file">
              {{ i > 0 ? ' ' : '' }}{{ file }} is chosen twice; it is added once.
            </template>
          </p>
          <p
            v-if="refused.length > 0"
            class="mt-1 text-xs text-error"
            data-test="font-file-refused"
          >
            <template v-for="(file, i) in refused" :key="file">
              {{ i > 0 ? ' ' : '' }}{{ file }} isn’t a .woff2 file.
            </template>
          </p>
        </UFormField>
        <p v-if="error" class="text-sm text-error" role="alert" data-test="font-family-error">
          {{ error }}
        </p>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" @click="emit('update:open', false)"
          >Cancel</UButton
        >
        <UButton :loading="saving" :disabled="!canSave" data-test="font-family-save" @click="save">
          {{ editing ? 'Save' : 'Add family' }}
        </UButton>
      </div>
    </template>
  </UModal>
</template>
