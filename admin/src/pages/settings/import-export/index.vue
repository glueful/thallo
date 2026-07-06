<!--
  Route meta via a <route> block instead of definePage(): vue-tsc / the
  unplugin-vue-router Volar plugin deterministically fails to inject the
  definePage macro type for this one route (`/settings/import-export/`) —
  "Cannot find name 'definePage'" — though the build and runtime are fine and
  every other page resolves it. The <route> block (sfc-route-blocks Volar plugin)
  declares the same meta and type-checks cleanly.
-->
<route lang="json">
{ "meta": { "requiresAuth": true } }
</route>

<script setup lang="ts">
import { computed, ref, useTemplateRef, watchEffect } from 'vue'
import { useIntervalFn } from '@vueuse/core'
import {
  useAdapters,
  useJobs,
  useJobErrors,
  useImportExportMutations,
  uploadImportFile,
  downloadExport,
  isJobActive,
  type IeJob,
} from '@/queries/importExport'
import { useContentTypes } from '@/queries/contentTypes'
import { runtimeConfig } from '@/runtime/config'
import { useNotify } from '@/composables/useNotify'
import { useCapabilitiesStore } from '@/stores/capabilities'

const caps = useCapabilitiesStore()
caps.ensureLoaded()

const { success, error: notifyError } = useNotify()

const { data: adapters } = useAdapters()
const { data: jobs, status: jobsStatus, refresh, isLoading } = useJobs()
const { runExport, runImport, cancel, retry } = useImportExportMutations()

// Poll while any job is still progressing (Pinia Colada has no refetchInterval).
const hasActive = computed(() => (jobs.value ?? []).some((j) => isJobActive(j.status)))
useIntervalFn(() => {
  if (hasActive.value) refresh()
}, 4000)

// ── Export ──────────────────────────────────────────────────────────────────
const exportAdapter = ref('')
const exporterItems = computed(() =>
  (adapters.value?.exporters ?? []).map((a) => ({ label: a.label, value: a.key })),
)
watchEffect(() => {
  if (!exportAdapter.value && exporterItems.value.length) {
    exportAdapter.value = exporterItems.value[0]!.value
  }
})

async function onExport() {
  if (!exportAdapter.value) return
  try {
    await runExport.mutateAsync({ adapter: exportAdapter.value })
    success('Export queued', 'It will appear in the jobs list below as it runs.')
  } catch (e) {
    notifyError(e, 'Could not start the export')
  }
}

// ── Import ──────────────────────────────────────────────────────────────────
const importAdapter = ref('')
// Format-adapter keys belong to the thallo.importers pack; the core snapshot
// importer (thallo.content) is always available regardless of the capability.
const FORMAT_ADAPTER_KEYS = ['csv.content', 'markdown.content', 'wordpress.content']
const importerItems = computed(() =>
  (adapters.value?.importers ?? [])
    .filter((a) => caps.isEnabled('thallo.importers') || !FORMAT_ADAPTER_KEYS.includes(a.key))
    .map((a) => ({ label: a.label, value: a.key })),
)
watchEffect(() => {
  if (!importAdapter.value && importerItems.value.length) {
    importAdapter.value = importerItems.value[0]!.value
  }
})

const importMode = ref<'dry_run' | 'commit'>('dry_run')
const modeItems = [
  { label: 'Dry run (preview, no writes)', value: 'dry_run' },
  { label: 'Commit (write to the database)', value: 'commit' },
]

const fileInput = useTemplateRef<HTMLInputElement>('fileInput')
const selectedFile = ref<File | null>(null)
const importing = ref(false)

// ── Import mapping wizard (CSV + Markdown + WordPress) ────────────────────────
// CSV, Markdown and WordPress adapters need an options bag (target type + a field→source mapping);
// other adapters just take a file. CSV maps fields to columns; Markdown maps fields to front-matter
// keys; WordPress maps fields to a fixed set of WXR keys. Markdown/WordPress also route a converted
// HTML body into a chosen field.
const SKIP = '__skip__'
// The scalar WXR keys a WordPress import can map fields to (mirrors WordpressContentImporter::KEYS).
const WXR_KEYS = ['title', 'excerpt', 'slug', 'date', 'status', 'author']
const isCsv = computed(() => importAdapter.value === 'csv.content')
const isMarkdown = computed(() => importAdapter.value === 'markdown.content')
const isWordpress = computed(() => importAdapter.value === 'wordpress.content')
// Markdown and WordPress both route a converted HTML body into a chosen field.
const hasBodyField = computed(() => isMarkdown.value || isWordpress.value)
const needsWizard = computed(() => isCsv.value || isMarkdown.value || isWordpress.value)

const { data: contentTypes } = useContentTypes()
const typeItems = computed(() =>
  (contentTypes.value ?? []).map((t) => ({ label: t.name ?? t.slug, value: t.slug })),
)
const wizardType = ref('')
const wizardFields = computed(() =>
  (contentTypes.value?.find((t) => t.slug === wizardType.value)?.schema ?? []).map((f) => ({
    name: String(f.name ?? ''),
    type: String(f.type ?? ''),
    required: !!f.required,
  })),
)
const wizardPublish = ref(false)
const bodyField = ref('') // Markdown/WordPress: the field that receives the converted HTML body

// The source keys the fields map to: CSV column headers, Markdown front-matter keys, or WXR keys.
const sourceKeys = ref<string[]>([])
const sourceLabel = computed(() =>
  isWordpress.value ? 'WordPress fields' : isMarkdown.value ? 'front-matter keys' : 'columns',
)
// WordPress keys are fixed (not parsed from the file), so the mapping is available immediately.
watchEffect(() => {
  if (isWordpress.value) sourceKeys.value = WXR_KEYS
})
// field name → source key (or SKIP)
const wizardMapping = ref<Record<string, string>>({})
const keyItems = computed(() => [
  { label: '— skip —', value: SKIP },
  ...sourceKeys.value.map((c) => ({ label: c, value: c })),
])
// Markdown body-field options: the type's text fields.
const bodyFieldItems = computed(() =>
  wizardFields.value
    .filter((f) => f.type === 'text')
    .map((f) => ({ label: f.name, value: f.name })),
)

function parseCsvHeader(text: string): string[] {
  const firstLine = text.split(/\r?\n/).find((l) => l.trim() !== '') ?? ''
  return firstLine.split(',').map((c) => c.trim().replace(/^"(.*)"$/, '$1'))
}
function parseFrontMatterKeys(text: string): string[] {
  const body = text.replace(/^﻿/, '').replace(/^\s+/, '')
  if (!body.startsWith('---')) return []
  const lines = body.split(/\r?\n/)
  const keys: string[] = []
  for (let i = 1; i < lines.length; i++) {
    if (lines[i]!.trim() === '---') break
    const m = /^([A-Za-z0-9_-]+)\s*:/.exec(lines[i]!)
    if (m) keys.push(m[1]!)
  }
  return keys
}

// Default each field to a same-name source key, else skip.
watchEffect(() => {
  const keys = sourceKeys.value
  const next: Record<string, string> = {}
  for (const f of wizardFields.value) {
    const match = keys.find((c) => c.toLowerCase() === f.name.toLowerCase())
    next[f.name] = match ?? wizardMapping.value[f.name] ?? SKIP
  }
  wizardMapping.value = next
})
// Default the Markdown/WordPress body field to a "body" field when present.
watchEffect(() => {
  if (hasBodyField.value && !bodyField.value) {
    bodyField.value = bodyFieldItems.value.find((i) => i.value === 'body')?.value ?? ''
  }
})

// Required fields must be satisfied (mapped to a source key, or — Markdown/WordPress — the body field).
const wizardReady = computed(() => {
  if (!needsWizard.value) return true
  if (wizardType.value === '') return false
  if (isCsv.value && sourceKeys.value.length === 0) return false
  return wizardFields.value.every(
    (f) =>
      !f.required ||
      (wizardMapping.value[f.name] ?? SKIP) !== SKIP ||
      (hasBodyField.value && bodyField.value === f.name),
  )
})

async function onFilePicked(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  selectedFile.value = file
  // WordPress keys are fixed (set by the watchEffect above), so don't touch them here.
  if (isWordpress.value) return
  if (!file) {
    sourceKeys.value = []
  } else if (isCsv.value) {
    sourceKeys.value = parseCsvHeader(await file.text())
  } else if (isMarkdown.value) {
    sourceKeys.value = parseFrontMatterKeys(await file.text())
  } else {
    sourceKeys.value = []
  }
}

function wizardOptions(): Record<string, unknown> {
  const mapping: Record<string, string> = {}
  for (const [field, key] of Object.entries(wizardMapping.value)) {
    if (key !== SKIP) mapping[field] = key
  }
  const options: Record<string, unknown> = {
    content_type: wizardType.value,
    mapping,
    locale: runtimeConfig.defaultLocale,
    publish: wizardPublish.value,
  }
  if (hasBodyField.value && bodyField.value) options.body_field = bodyField.value
  return options
}

async function onImport() {
  const file = selectedFile.value
  if (!file || !importAdapter.value || !wizardReady.value) return
  importing.value = true
  try {
    const uploaded = await uploadImportFile(file)
    await runImport.mutateAsync({
      adapter: importAdapter.value,
      disk: uploaded.disk,
      path: uploaded.path,
      mode: importMode.value,
      options: needsWizard.value ? wizardOptions() : undefined,
    })
    success(
      importMode.value === 'dry_run' ? 'Dry run queued' : 'Import queued',
      'It will appear in the jobs list below as it runs.',
    )
    selectedFile.value = null
    sourceKeys.value = []
    if (fileInput.value) fileInput.value.value = ''
  } catch (e) {
    notifyError(e, 'Could not start the import')
  } finally {
    importing.value = false
  }
}

// ── Jobs ──────────────────────────────────────────────────────────────────────
function statusColor(s: string): 'success' | 'error' | 'neutral' | 'info' {
  if (s === 'completed') return 'success'
  if (s === 'failed') return 'error'
  if (s === 'cancelled') return 'neutral'
  return 'info'
}

const acting = ref('') // uuid currently being cancelled/retried/downloaded

async function onDownload(job: IeJob) {
  acting.value = job.uuid
  try {
    await downloadExport(job.uuid)
  } catch (e) {
    notifyError(e, 'Could not download the export')
  } finally {
    acting.value = ''
  }
}

async function onCancel(job: IeJob) {
  acting.value = job.uuid
  try {
    await cancel.mutateAsync(job.uuid)
    success('Job cancelled')
  } catch (e) {
    notifyError(e, 'Could not cancel the job')
  } finally {
    acting.value = ''
  }
}

async function onRetry(job: IeJob) {
  acting.value = job.uuid
  try {
    await retry.mutateAsync(job.uuid)
    success('Job re-queued')
  } catch (e) {
    notifyError(e, 'Could not retry the job')
  } finally {
    acting.value = ''
  }
}

// ── Errors modal ───────────────────────────────────────────────────────────────
const errorsJob = ref<IeJob | null>(null)
const errorsUuid = computed(() => errorsJob.value?.uuid ?? '')
const { data: jobErrors, status: errorsStatus } = useJobErrors(errorsUuid)

function fmtTime(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <UDashboardPanel id="settings-import-export">
    <template #header>
      <UDashboardNavbar title="Import / Export">
        <template #right>
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            :loading="isLoading"
            @click="refresh()"
          >
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-4xl space-y-6">
        <p class="text-sm text-muted">
          Export content as NDJSON; import an NDJSON bundle, a CSV, a Markdown document, or a
          WordPress export (WXR). Jobs run in the background — they progress only while a queue
          worker is running.
        </p>

        <div class="grid gap-6 md:grid-cols-2">
          <!-- Export -->
          <UCard>
            <template #header><h2 class="font-semibold text-default">Export</h2></template>
            <div class="space-y-4">
              <UFormField label="What to export">
                <USelect v-model="exportAdapter" :items="exporterItems" class="w-full" />
              </UFormField>
              <p class="text-xs text-muted">Format: NDJSON</p>
              <UButton
                icon="i-lucide-download"
                :loading="runExport.isLoading.value"
                :disabled="!exportAdapter"
                @click="onExport"
              >
                Start export
              </UButton>
            </div>
          </UCard>

          <!-- Import: core snapshot always visible; format adapters gated by thallo.importers -->
          <UCard>
            <template #header><h2 class="font-semibold text-default">Import</h2></template>
            <div class="space-y-4">
              <UFormField label="Adapter">
                <USelect
                  v-model="importAdapter"
                  :items="importerItems"
                  data-testid="importer-adapter"
                  class="w-full"
                />
              </UFormField>

              <!--
                Format-adapter wizard section: content-type selector, body-field routing,
                field mapping, and publish toggle. Only present when the thallo.importers
                capability is on (format adapters are also filtered from the dropdown above
                when the capability is off, so needsWizard will always be false then too).
              -->
              <div v-if="caps.isEnabled('thallo.importers')" data-test="format-import" class="space-y-4">
                <UFormField
                  v-if="needsWizard"
                  label="Content type"
                  :hint="
                    isWordpress
                      ? 'Each WordPress post/page becomes an entry of this type'
                      : isMarkdown
                        ? 'The Markdown document becomes an entry of this type'
                        : 'Each CSV row becomes an entry of this type'
                  "
                >
                  <USelect
                    v-model="wizardType"
                    :items="typeItems"
                    placeholder="Choose a content type"
                    class="w-full"
                  />
                </UFormField>

                <UFormField
                  v-if="hasBodyField && wizardType"
                  label="Body field"
                  :hint="
                    isWordpress
                      ? 'The post body (content:encoded HTML) is stored here'
                      : 'The Markdown body is converted to HTML and stored here'
                  "
                >
                  <USelect
                    v-model="bodyField"
                    :items="bodyFieldItems"
                    placeholder="Choose a text field"
                    class="w-full"
                  />
                </UFormField>

                <UFormField
                  v-if="needsWizard && wizardType && sourceKeys.length"
                  :label="`Map fields to ${sourceLabel}`"
                  hint="Required fields (*) must be mapped"
                >
                  <div class="space-y-2">
                    <div v-for="f in wizardFields" :key="f.name" class="flex items-center gap-2">
                      <span class="w-28 shrink-0 truncate text-sm text-default">
                        {{ f.name }}<span v-if="f.required" class="text-error">*</span>
                      </span>
                      <USelect v-model="wizardMapping[f.name]" :items="keyItems" class="flex-1" />
                    </div>
                  </div>
                </UFormField>

                <UFormField v-if="needsWizard" label="On commit">
                  <USwitch v-model="wizardPublish" label="Publish imported entries" />
                </UFormField>
              </div>

              <UFormField
                label="File"
                :hint="
                  isWordpress
                    ? 'A WordPress export (.xml / .wxr)'
                    : isMarkdown
                      ? 'A .md / .mdx file with optional front matter'
                      : isCsv
                        ? 'CSV with a header row'
                        : 'NDJSON exported from Thallo'
                "
              >
                <div class="flex items-center gap-2">
                  <UButton
                    icon="i-lucide-paperclip"
                    color="neutral"
                    variant="subtle"
                    label="Choose file…"
                    @click="fileInput?.click()"
                  />
                  <span class="truncate text-sm text-muted">
                    {{ selectedFile?.name ?? 'No file' }}
                  </span>
                </div>
              </UFormField>

              <UFormField label="Mode">
                <USelect v-model="importMode" :items="modeItems" class="w-full" />
              </UFormField>

              <UButton
                icon="i-lucide-upload"
                :loading="importing || runImport.isLoading.value"
                :disabled="!selectedFile || !importAdapter || !wizardReady"
                @click="onImport"
              >
                {{ importMode === 'dry_run' ? 'Run dry run' : 'Import' }}
              </UButton>
            </div>
          </UCard>
        </div>

        <!-- Jobs -->
        <section class="space-y-3">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">Recent jobs</h2>

          <div v-if="jobsStatus === 'pending'" class="space-y-2">
            <USkeleton class="h-16" />
            <USkeleton class="h-16" />
          </div>

          <UEmpty
            v-else-if="!(jobs ?? []).length"
            icon="i-lucide-arrow-down-up"
            title="No jobs yet"
            description="Start an export or import above."
          />

          <div v-else class="flex flex-col gap-2">
            <div
              v-for="job in jobs"
              :key="job.uuid"
              class="flex items-start justify-between gap-4 rounded-xl border border-default p-4"
            >
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <UBadge
                    :label="job.type"
                    :color="job.type === 'export' ? 'primary' : 'neutral'"
                    variant="subtle"
                    size="sm"
                    class="capitalize"
                  />
                  <UBadge
                    :label="job.status"
                    :color="statusColor(job.status)"
                    variant="subtle"
                    size="sm"
                    class="capitalize"
                  />
                  <span v-if="job.mode === 'dry_run'" class="text-xs text-muted">dry run</span>
                </div>
                <p class="mt-1 text-sm text-default">{{ job.adapter }}</p>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                  <span>{{ job.processed_records }} / {{ job.total_records }} records</span>
                  <span v-if="job.failed_records > 0" class="text-error">
                    {{ job.failed_records }} failed
                  </span>
                  <span>{{ fmtTime(job.created_at) }}</span>
                </div>
              </div>

              <div class="flex shrink-0 items-center gap-1">
                <UButton
                  v-if="job.failed_records > 0 || job.error_overflow_count > 0"
                  label="Errors"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-triangle-alert"
                  @click="() => { errorsJob = job }"
                />
                <UButton
                  v-if="job.type === 'export' && job.status === 'completed'"
                  label="Download"
                  color="neutral"
                  variant="subtle"
                  size="xs"
                  icon="i-lucide-download"
                  :loading="acting === job.uuid"
                  @click="onDownload(job)"
                />
                <UButton
                  v-if="job.status === 'failed'"
                  label="Retry"
                  color="neutral"
                  variant="subtle"
                  size="xs"
                  icon="i-lucide-rotate-cw"
                  :loading="acting === job.uuid"
                  @click="onRetry(job)"
                />
                <UButton
                  v-if="isJobActive(job.status)"
                  label="Cancel"
                  color="error"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-x"
                  :loading="acting === job.uuid"
                  @click="onCancel(job)"
                />
              </div>
            </div>
          </div>
        </section>
      </div>
    </template>
  </UDashboardPanel>

  <input
    ref="fileInput"
    type="file"
    :accept="
      isWordpress
        ? '.xml,.wxr'
        : isCsv
          ? '.csv'
          : isMarkdown
            ? '.md,.mdx,.markdown'
            : '.ndjson,.jsonl,.json'
    "
    class="hidden"
    @change="onFilePicked"
  />

  <UModal
    :open="errorsJob !== null"
    title="Job errors"
    @update:open="
      (v: boolean) => {
        if (!v) errorsJob = null
      }
    "
  >
    <template #body>
      <div v-if="errorsStatus === 'pending'" class="space-y-2">
        <USkeleton class="h-10" />
        <USkeleton class="h-10" />
      </div>
      <UEmpty
        v-else-if="!(jobErrors ?? []).length"
        icon="i-lucide-check"
        title="No recorded errors"
        description="This job has no stored error records."
      />
      <ul v-else class="divide-y divide-default">
        <li v-for="err in jobErrors" :key="err.uuid" class="py-2">
          <div class="flex items-center gap-2">
            <UBadge
              :label="err.severity"
              :color="err.severity === 'error' ? 'error' : 'warning'"
              variant="subtle"
              size="xs"
              class="capitalize"
            />
            <code class="text-xs text-muted">{{ err.code }}</code>
            <span v-if="err.record_number !== null" class="text-xs text-dimmed">
              record {{ err.record_number }}
            </span>
          </div>
          <p class="mt-0.5 wrap-break-word text-sm text-default">{{ err.message }}</p>
        </li>
      </ul>
    </template>
  </UModal>
</template>
