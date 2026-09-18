<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ApiError, apiErrorCode, apiErrorDetails } from '@/api/errors'
import {
  useStyleClasses,
  useStyleClassJob,
  useStyleClassMutations,
  useStyleClassUsage,
  type StyleClassJobKind,
} from '@/queries/styleClasses'
import { useNotify } from '@/composables/useNotify'
import StyleClassEditor from './components/StyleClassEditor.vue'
import StyleClassSaveDialog from './components/StyleClassSaveDialog.vue'
import StyleClassSaveErrors from './components/StyleClassSaveErrors.vue'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const { success, error: notifyError } = useNotify()
const id = computed(() => String(route.params.id))

const { data: list, status, refetch } = useStyleClasses()
const styleClass = computed(() => (list.value?.classes ?? []).find((c) => c.id === id.value))
const { update, archive, queueJob } = useStyleClassMutations()
const { data: usage, refetch: refetchUsage } = useStyleClassUsage(() => id.value)

const name = ref('')
const description = ref('')
const style = ref<Record<string, unknown>>({})
/** The version the form loaded: a save names it, a conflict reloads it (spec §4.3). */
const loadedVersion = ref(0)

// Hydrate ONCE per load (background refetches must not clobber in-progress edits).
let hydrated = false
watch(
  styleClass,
  (c) => {
    if (hydrated || !c) return
    name.value = c.name
    description.value = c.description ?? ''
    style.value = JSON.parse(JSON.stringify(c.style)) as Record<string, unknown>
    loadedVersion.value = c.version
    hydrated = true
  },
  { immediate: true },
)

/**
 * The everywhere-jobs (spec §4.5): the class locks until the job completes. `detach` keeps how
 * every block looks; `remove` changes how pages look. Progress is polled while it runs.
 */
const jobKind = ref<StyleClassJobKind | null>(null)
const jobId = ref<string | null>(null)
const locked = computed(() => (styleClass.value?.locked_by_job ?? null) !== null)
const { data: job, refetch: refetchJob } = useStyleClassJob(
  () => id.value,
  () => jobId.value ?? styleClass.value?.locked_by_job ?? null,
)
let poll: ReturnType<typeof setInterval> | null = null
function stopPolling() {
  if (poll !== null) clearInterval(poll)
  poll = null
}
watch(
  () => job.value?.status,
  (status) => {
    if (status === 'running' && poll === null) {
      poll = setInterval(() => {
        void refetchJob()
        void refetch()
      }, 1500)
    }
    if (status !== undefined && status !== 'running') {
      stopPolling()
      void refetch()
    }
  },
  { immediate: true },
)
onBeforeUnmount(stopPolling)

async function runEverywhere() {
  const kind = jobKind.value
  if (kind === null) return
  try {
    const queued = await queueJob.mutateAsync({ id: id.value, kind })
    jobId.value = queued.id
    jobKind.value = null
    success(
      kind === 'detach' ? 'Detaching everywhere' : 'Removing everywhere',
      'The class is locked until the job completes.',
    )
  } catch (e) {
    notifyError(e, 'Couldn’t queue the job')
  }
}

async function onArchive() {
  try {
    await archive.mutateAsync(id.value)
    success('Style class archived', 'Old revisions that reference it still restore.')
  } catch (e) {
    notifyError(e, 'Couldn’t archive the style class')
  }
}

const confirming = ref(false)
async function askToSave() {
  confirming.value = true
  await refetchUsage()
}

/**
 * The fields the server refused on the last save (spec §12.5). A class still holding a value the
 * contract does not offer cannot be saved until it is repaired, however unrelated the edit being
 * made — so a refusal keeps the draft exactly as it is and says which field stands in the way.
 */
const saveErrors = ref<Record<string, string>>({})

async function onSave() {
  saveErrors.value = {} // a refusal is not shown over the attempt that follows it
  try {
    const saved = await update.mutateAsync({
      id: id.value,
      version: loadedVersion.value,
      name: name.value.trim(),
      description: description.value.trim() || null,
      style: style.value,
    })
    loadedVersion.value = saved.version
    confirming.value = false
    success('Style class saved', 'Published pages pick it up on their next request.')
  } catch (e) {
    if (e instanceof ApiError && apiErrorCode(e) === 'STYLE_CLASS_VERSION_CONFLICT') {
      // Someone saved first: the form keeps these edits, the version moves to the current
      // one so the next save applies on top of what they saved.
      const current = Number(apiErrorDetails(e)?.current_version ?? loadedVersion.value)
      loadedVersion.value = current
      await refetch()
      notifyError(e, 'Someone else saved this style class first — review and save again')
      return
    }
    if (e instanceof ApiError && Object.keys(e.fieldErrors).length > 0) {
      // Nothing of the draft is touched: not the name, not the style, not the loaded version.
      saveErrors.value = e.fieldErrors
      confirming.value = false
    }
    notifyError(e, 'Couldn’t save the style class')
  }
}
</script>

<template>
  <UDashboardPanel id="style-class-edit">
    <template #header>
      <UDashboardNavbar :title="styleClass ? styleClass.name : 'Style class'">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/settings/style-classes"
            aria-label="Back to style classes"
          />
        </template>
        <template #right>
          <UButton
            v-if="styleClass && !styleClass.archived"
            variant="ghost"
            color="warning"
            :disabled="locked"
            data-test="style-class-archive"
            @click="onArchive"
          >
            Archive
          </UButton>
          <UButton
            :disabled="!styleClass || locked"
            data-test="style-class-save"
            @click="askToSave"
          >
            Save
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto w-full max-w-6xl pb-5">
        <div v-if="status === 'pending'" class="space-y-2">
          <USkeleton v-for="n in 4" :key="n" class="h-12" />
        </div>
        <UEmpty
          v-else-if="!styleClass"
          icon="i-lucide-paintbrush"
          title="Unknown style class"
          :description="`No style class has the id “${id}”.`"
        />
        <div v-else class="grid gap-6 lg:grid-cols-3">
          <UCard class="lg:self-start">
            <template #header>
              <div class="flex items-center gap-2">
                <h2 class="flex-1 font-semibold text-default">Details</h2>
                <UBadge v-if="styleClass.archived" size="xs" color="warning" variant="subtle"
                  >archived</UBadge
                >
                <UBadge v-if="styleClass.locked_by_job" size="xs" color="info" variant="subtle">
                  job running
                </UBadge>
              </div>
            </template>
            <div class="space-y-4">
              <UFormField label="Name" description="Unique on this site, in any letter case">
                <UInput v-model="name" class="w-full" data-test="style-class-name" />
              </UFormField>
              <UFormField label="Description">
                <UTextarea v-model="description" class="w-full" :rows="2" />
              </UFormField>
              <p class="text-xs text-muted">Version {{ loadedVersion }}</p>
            </div>
          </UCard>
          <UCard class="lg:col-span-3">
            <template #header><h2 class="font-semibold text-default">Everywhere</h2></template>
            <div class="space-y-3 text-sm">
              <p class="text-muted">
                Archiving keeps the definition so old revisions still restore. These jobs walk every
                draft, published entry, retained revision and region; the class is locked until they
                complete.
              </p>
              <div class="flex flex-wrap gap-2">
                <UButton
                  variant="outline"
                  color="neutral"
                  :disabled="locked"
                  data-test="style-class-detach-everywhere"
                  @click="jobKind = 'detach'"
                >
                  Detach everywhere
                </UButton>
                <UButton
                  variant="outline"
                  color="warning"
                  :disabled="locked"
                  data-test="style-class-remove-everywhere"
                  @click="jobKind = 'remove'"
                >
                  Remove everywhere — changes how pages look
                </UButton>
              </div>
              <div v-if="job" class="rounded border border-default p-3" data-test="style-class-job">
                <p class="font-medium text-default">
                  {{ job.kind === 'detach' ? 'Detach everywhere' : 'Remove everywhere' }} —
                  {{ job.status }}
                </p>
                <p class="text-xs text-muted">
                  Pass {{ job.passes }}: {{ job.work_items_done }} of
                  {{ job.work_items_total }} documents done, {{ job.work_items_failed }} refused
                </p>
                <ul v-if="job.failure_report.length" class="mt-1 space-y-0.5 text-xs text-muted">
                  <li v-for="(f, i) in job.failure_report" :key="i">
                    {{ f.source }} {{ f.id }}: {{ f.reason }}
                  </li>
                </ul>
              </div>
            </div>
          </UCard>
          <UCard class="lg:col-span-2">
            <template #header><h2 class="font-semibold text-default">Style</h2></template>
            <StyleClassSaveErrors :errors="saveErrors" class="mb-4" />
            <StyleClassEditor v-model="style" />
          </UCard>
        </div>
      </div>
      <UModal
        :open="jobKind !== null"
        :title="jobKind === 'detach' ? 'Detach everywhere?' : 'Remove everywhere?'"
        data-test="style-class-job-dialog"
        @update:open="(v: boolean) => (jobKind = v ? jobKind : null)"
      >
        <template #body>
          <p class="text-sm text-default">
            {{
              jobKind === 'detach'
                ? 'Every block keeps how it looks: what this class contributes is written into each block, and the reference is removed.'
                : 'The reference is removed from every block and nothing is written in its place: pages change how they look.'
            }}
            The class is locked until the job completes.
          </p>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton variant="ghost" color="neutral" @click="jobKind = null">Cancel</UButton>
            <UButton
              :color="jobKind === 'remove' ? 'warning' : 'primary'"
              :loading="queueJob.isLoading.value"
              data-test="style-class-job-confirm"
              @click="runEverywhere"
            >
              {{ jobKind === 'detach' ? 'Detach everywhere' : 'Remove everywhere' }}
            </UButton>
          </div>
        </template>
      </UModal>
      <StyleClassSaveDialog
        v-model:open="confirming"
        :name="name"
        :usage="usage"
        :saving="update.isLoading.value"
        @confirm="onSave"
      />
    </template>
  </UDashboardPanel>
</template>
