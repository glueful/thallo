<script setup lang="ts">
// Bulk-create users from a CSV via the csv.users import adapter. Upload → map the fixed user/profile
// /role fields to columns → dry-run or commit. The job runs in the background (Settings →
// Import/Export shows progress); this modal just queues it.
import { computed, ref, useTemplateRef, watch } from 'vue'
import { uploadImportFile, createImport } from '@/queries/importExport'
import { useNotify } from '@/composables/useNotify'

const open = defineModel<boolean>('open', { required: true })
const { success, error: notifyError } = useNotify()

const SKIP = '__skip__'
const FIELDS = [
  { key: 'username', label: 'Username', required: true },
  { key: 'email', label: 'Email', required: true },
  { key: 'password', label: 'Password', required: false },
  { key: 'status', label: 'Status', required: false },
  { key: 'first_name', label: 'First name', required: false },
  { key: 'last_name', label: 'Last name', required: false },
  { key: 'roles', label: 'Roles (slugs, comma-separated)', required: false },
]
const modeItems = [
  { label: 'Dry run (preview, no writes)', value: 'dry_run' },
  { label: 'Commit (create users)', value: 'commit' },
]

const fileInput = useTemplateRef<HTMLInputElement>('fileInput')
const selectedFile = ref<File | null>(null)
const columns = ref<string[]>([])
const mapping = ref<Record<string, string>>({})
const mode = ref<'dry_run' | 'commit'>('dry_run')
const submitting = ref(false)

const columnItems = computed(() => [
  { label: '— skip —', value: SKIP },
  ...columns.value.map((c) => ({ label: c, value: c })),
])
const ready = computed(
  () =>
    !!selectedFile.value &&
    columns.value.length > 0 &&
    FIELDS.every((f) => !f.required || (mapping.value[f.key] ?? SKIP) !== SKIP),
)

function parseHeader(text: string): string[] {
  const firstLine = text.split(/\r?\n/).find((l) => l.trim() !== '') ?? ''
  return firstLine.split(',').map((c) => c.trim().replace(/^"(.*)"$/, '$1'))
}

async function onFilePicked(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  selectedFile.value = file
  columns.value = file ? parseHeader(await file.text()) : []
  const next: Record<string, string> = {}
  for (const f of FIELDS) {
    const want = f.key.replace(/_/g, '')
    const match = columns.value.find((c) => c.toLowerCase().replace(/[\s_-]/g, '') === want)
    next[f.key] = match ?? SKIP
  }
  mapping.value = next
}

function reset() {
  selectedFile.value = null
  columns.value = []
  mapping.value = {}
  if (fileInput.value) fileInput.value.value = ''
}

async function submit() {
  const file = selectedFile.value
  if (!file || !ready.value) return
  submitting.value = true
  try {
    const uploaded = await uploadImportFile(file)
    const map: Record<string, string> = {}
    for (const [field, col] of Object.entries(mapping.value)) {
      if (col !== SKIP) map[field] = col
    }
    await createImport({
      adapter: 'csv.users',
      disk: uploaded.disk,
      path: uploaded.path,
      mode: mode.value,
      options: { mapping: map },
    })
    success(
      mode.value === 'dry_run' ? 'Dry run queued' : 'Import queued',
      'Track progress in Settings → Import / Export.',
    )
    open.value = false
  } catch (e) {
    notifyError(e, 'Could not start the import')
  } finally {
    submitting.value = false
  }
}

watch(open, (v) => {
  if (!v) reset()
})
</script>

<template>
  <UModal v-model:open="open" title="Bulk import users" :ui="{ content: 'sm:max-w-lg' }">
    <template #body>
      <div class="space-y-4">
        <p class="text-sm text-muted">
          Upload a CSV with a header row and map its columns to user fields. Username and email are
          required; a missing password is generated (the user resets it). The import runs in the
          background.
        </p>

        <UFormField label="CSV file">
          <div class="flex items-center gap-2">
            <UButton
              icon="i-lucide-paperclip"
              color="neutral"
              variant="subtle"
              label="Choose file…"
              @click="fileInput?.click()"
            />
            <span class="truncate text-sm text-muted">{{ selectedFile?.name ?? 'No file' }}</span>
          </div>
        </UFormField>

        <UFormField
          v-if="columns.length"
          label="Map fields to columns"
          hint="Required fields (*) must be mapped"
        >
          <div class="space-y-2">
            <div v-for="f in FIELDS" :key="f.key" class="flex items-center gap-2">
              <span class="w-44 shrink-0 truncate text-sm text-default">
                {{ f.label }}<span v-if="f.required" class="text-error">*</span>
              </span>
              <USelect v-model="mapping[f.key]" :items="columnItems" class="flex-1" />
            </div>
          </div>
        </UFormField>

        <UFormField label="Mode">
          <USelect v-model="mode" :items="modeItems" class="w-full" />
        </UFormField>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="submitting"
          @click="
            () => {
              open = false
            }
          "
        />
        <UButton
          icon="i-lucide-upload"
          :label="mode === 'dry_run' ? 'Run dry run' : 'Import'"
          :loading="submitting"
          :disabled="!ready"
          @click="submit"
        />
      </div>
    </template>
  </UModal>

  <input ref="fileInput" type="file" accept=".csv" class="hidden" @change="onFilePicked" />
</template>
