<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import {
  deleteTemplate,
  fetchTemplate,
  fetchTemplates,
  saveTemplate,
  violationsFrom,
  type PolicyViolation,
  type TemplateRow,
} from '@/queries/templates'
import TemplateEditor from './components/TemplateEditor.vue'
import HistoryPanel from './components/HistoryPanel.vue'

const notify = useNotify()
const theme = ref('')
const templates = ref<TemplateRow[]>([])
const selectedPath = ref<string | null>(null)
const source = ref('')
const origin = ref<string>('')
const violations = ref<PolicyViolation[]>([])
const saving = ref(false)
const historyOpen = ref(false)

const groups = computed(() => {
  const byFamily = new Map<string, TemplateRow[]>()
  for (const t of templates.value) {
    const family = t.path.includes('/') ? t.path.split('/')[0]! : 'root'
    if (!byFamily.has(family)) byFamily.set(family, [])
    byFamily.get(family)!.push(t)
  }
  return [...byFamily.entries()].sort(([a], [b]) => a.localeCompare(b))
})

async function loadList() {
  try {
    const result = await fetchTemplates(theme.value)
    theme.value = result.theme
    templates.value = result.templates
  } catch (err) {
    notify.error(err, "Couldn't load templates")
  }
}

async function open(path: string) {
  try {
    const detail = await fetchTemplate(path, theme.value)
    selectedPath.value = path
    source.value = detail.source
    origin.value = detail.origin
    violations.value = []
  } catch (err) {
    notify.error(err, "Couldn't load template")
  }
}

async function save() {
  if (!selectedPath.value) return
  saving.value = true
  violations.value = []
  try {
    await saveTemplate(selectedPath.value, source.value, theme.value)
    notify.success('Template saved — live now')
    origin.value = 'db'
    await loadList()
  } catch (err) {
    const errs = violationsFrom(err)
    if (err instanceof ApiError && err.status === 422 && errs.length > 0) {
      violations.value = errs
    } else {
      notify.error(err, "Couldn't save template")
    }
  } finally {
    saving.value = false
  }
}

async function removeOverride() {
  if (!selectedPath.value) return
  try {
    await deleteTemplate(selectedPath.value, theme.value)
    notify.success('Override removed — filesystem template is live')
    await loadList()
    await open(selectedPath.value)
  } catch (err) {
    notify.error(err, "Couldn't delete the override")
  }
}

async function reopenAfterRestore() {
  await loadList()
  if (selectedPath.value) await open(selectedPath.value)
}

onMounted(loadList)
</script>

<template>
  <div class="flex gap-6 p-6" data-test="templates-page">
    <aside class="w-80 shrink-0 space-y-4 overflow-y-auto">
      <div v-for="[family, rows] in groups" :key="family">
        <h3 class="text-sm font-semibold text-muted mb-1">{{ family }}</h3>
        <ul>
          <li v-for="t in rows" :key="t.path">
            <button
              class="w-full text-left px-2 py-1 rounded hover:bg-elevated text-sm flex items-center gap-1"
              :class="{ 'bg-elevated': t.path === selectedPath }"
              :data-test="`template-item-${t.path}`"
              @click="open(t.path)"
            >
              <span class="truncate flex-1">{{ t.path }}</span>
              <UBadge size="xs" :color="t.origin === 'db' ? 'primary' : 'neutral'" variant="subtle">
                {{ t.origin }}
              </UBadge>
            </button>
          </li>
        </ul>
      </div>
    </aside>

    <main v-if="selectedPath" class="flex-1 min-w-0 space-y-3" data-test="template-detail">
      <div class="flex items-center gap-2">
        <h2 class="font-mono text-sm flex-1 truncate">{{ selectedPath }}</h2>
        <UButton
          v-if="origin === 'db'"
          data-test="history-button"
          variant="ghost"
          color="neutral"
          icon="i-lucide-history"
          label="History"
          @click="historyOpen = true"
        />
        <UButton
          v-if="origin === 'db'"
          data-test="delete-override"
          color="error"
          variant="ghost"
          icon="i-lucide-trash-2"
          label="Delete override"
          @click="removeOverride"
        />
        <UButton
          data-test="save-template"
          :loading="saving"
          icon="i-lucide-save"
          label="Save"
          @click="save"
        />
      </div>

      <p v-if="origin !== 'db'" class="text-xs text-muted" data-test="fs-origin-note">
        Filesystem template ({{ origin }}) — saving creates a database override that shadows it.
      </p>

      <TemplateEditor v-model="source" />

      <ul v-if="violations.length" class="text-sm text-error space-y-1" data-test="violations">
        <li v-for="v in violations" :key="`${v.line}-${v.message}`" data-test="violation">
          Line {{ v.line }}: {{ v.message }}
        </li>
      </ul>

      <HistoryPanel
        v-model:open="historyOpen"
        :theme="theme"
        :path="selectedPath"
        @restored="reopenAfterRestore"
      />
    </main>
    <main v-else class="flex-1 grid place-items-center text-muted text-sm">
      Select a template to view or override it.
    </main>
  </div>
</template>
