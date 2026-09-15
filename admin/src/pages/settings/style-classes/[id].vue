<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ApiError, apiErrorCode, apiErrorDetails } from '@/api/errors'
import { useStyleClasses, useStyleClassMutations, useStyleClassUsage } from '@/queries/styleClasses'
import { useNotify } from '@/composables/useNotify'
import StyleClassEditor from './components/StyleClassEditor.vue'
import StyleClassSaveDialog from './components/StyleClassSaveDialog.vue'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const { success, error: notifyError } = useNotify()
const id = computed(() => String(route.params.id))

const { data: list, status, refetch } = useStyleClasses()
const styleClass = computed(() => (list.value?.classes ?? []).find((c) => c.id === id.value))
const { update } = useStyleClassMutations()
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

const confirming = ref(false)
async function askToSave() {
  confirming.value = true
  await refetchUsage()
}

async function onSave() {
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
            :disabled="!styleClass || styleClass.locked_by_job !== null"
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
          <UCard class="lg:col-span-2">
            <template #header><h2 class="font-semibold text-default">Style</h2></template>
            <StyleClassEditor v-model="style" />
          </UCard>
        </div>
      </div>
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
