<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import {
  cloneTheme,
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
const themes = ref<string[]>([])
const templates = ref<TemplateRow[]>([])
const query = ref('')
const openFolders = reactive<Record<string, boolean>>({})
const selectedPath = ref<string | null>(null)
const source = ref('')
const origin = ref<string>('')
const isReadOnly = ref(false)
const readonlyReason = ref<string | null>(null)
const violations = ref<PolicyViolation[]>([])
const saving = ref(false)
const historyOpen = ref(false)

const groups = computed(() => {
  const q = query.value.trim().toLowerCase()
  const byFamily = new Map<string, TemplateRow[]>()
  for (const t of templates.value) {
    if (t.path === 'custom.css') continue // the pinned Site entry owns it
    if (q !== '' && !t.path.toLowerCase().includes(q)) continue
    const family = t.path.includes('/') ? t.path.split('/')[0]! : 'root'
    if (!byFamily.has(family)) byFamily.set(family, [])
    byFamily.get(family)!.push(t)
  }
  return [...byFamily.entries()].sort(([a], [b]) => a.localeCompare(b))
})

/** The saved custom.css row, when one exists (drives the pinned entry's badge). */
const customCssRow = computed(() => templates.value.find((t) => t.path === 'custom.css'))

/** Open custom.css: a 404 (never saved) is the EMPTY state, not an error. */
async function openCustomCss() {
  try {
    const detail = await fetchTemplate('custom.css', theme.value)
    selectedPath.value = 'custom.css'
    source.value = detail.source
    origin.value = detail.origin
    isReadOnly.value = false
    readonlyReason.value = null
    violations.value = []
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) {
      selectedPath.value = 'custom.css'
      source.value = ''
      origin.value = 'empty'
      isReadOnly.value = false
      readonlyReason.value = null
      violations.value = []
      return
    }
    notify.error(err, "Couldn't load custom.css")
  }
}

/** Searching force-opens matching folders; otherwise the user's toggle state rules. */
const searching = computed(() => query.value.trim() !== '')

/** The list label inside a folder: the path minus the folder prefix. */
function fileName(path: string, family: string): string {
  return path.startsWith(`${family}/`) ? path.slice(family.length + 1) : path
}

/** Switch the theme being edited: reload the listing, drop the selection. */
async function switchTheme(next: string) {
  if (next === theme.value) return
  theme.value = next
  selectedPath.value = null
  source.value = ''
  await loadList()
}

// Clone-theme: server-side copy of the CURRENT theme into themes/{name}/.
const cloneOpen = ref(false)
const cloneName = ref('')
const cloning = ref(false)

async function submitClone() {
  const name = cloneName.value.trim()
  if (name === '') return
  cloning.value = true
  try {
    const result = await cloneTheme(name, theme.value)
    themes.value = result.themes
    cloneOpen.value = false
    cloneName.value = ''
    notify.success(`Theme “${result.theme}” created`, 'You are now editing the new theme.')
    await switchTheme(result.theme)
  } catch (err) {
    notify.error(err, "Couldn't create the theme")
  } finally {
    cloning.value = false
  }
}

async function loadList() {
  try {
    const result = await fetchTemplates(theme.value)
    theme.value = result.theme
    themes.value = result.themes ?? []
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
    isReadOnly.value = detail.readonly === true
    readonlyReason.value = detail.readonly_reason ?? null
    violations.value = []
  } catch (err) {
    notify.error(err, "Couldn't load template")
  }
}

/**
 * Switcher items: never an empty string (SelectItem throws on '') — before the
 * first listing resolves there are simply no items yet.
 */
const themeItems = computed(() => {
  if (themes.value.length > 0) return themes.value
  return theme.value !== '' ? [theme.value] : []
})

/** Syntax mode by extension; twig is the default for everything else. */
const editorLanguage = computed<'twig' | 'css' | 'json' | 'javascript'>(() => {
  const p = selectedPath.value ?? ''
  if (p.endsWith('.css')) return 'css'
  if (p.endsWith('.json')) return 'json'
  if (p.endsWith('.js')) return 'javascript'
  return 'twig'
})

async function save() {
  if (!selectedPath.value || isReadOnly.value) return
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

// Void handler for UButton's typed onClick — an inline assignment returns a value.
function openHistory(): void {
  historyOpen.value = true
}

onMounted(loadList)
</script>

<template>
  <UDashboardPanel id="templates">
    <template #header>
      <UDashboardNavbar title="Theme editor" />
    </template>

    <template #body>
      <div class="flex h-full min-h-0 gap-4" data-test="templates-page">
        <aside class="flex w-80 shrink-0 flex-col gap-2">
          <div class="flex items-center gap-2">
            <UIcon name="i-lucide-palette" class="size-4 shrink-0 text-muted" />
            <!-- Always rendered — with one theme it still shows WHERE switching
                 lives; more themes appear here as soon as themes/{name}/ exists. -->
            <USelect
              :model-value="theme || undefined"
              :items="themeItems"
              placeholder="Theme"
              class="w-full"
              size="sm"
              data-test="theme-select"
              @update:model-value="switchTheme($event as string)"
            />
            <UButton
              size="sm"
              variant="ghost"
              color="neutral"
              icon="i-lucide-copy-plus"
              aria-label="Duplicate this theme"
              title="Duplicate this theme"
              data-test="clone-theme-open"
              @click="cloneOpen = true"
            />
          </div>
          <UInput
            v-model="query"
            icon="i-lucide-search"
            placeholder="Filter templates…"
            size="sm"
            data-test="template-search"
          />
          <div class="min-h-0 flex-1 space-y-1 overflow-y-auto pr-1">
            <!-- Site custom CSS (custom-css spec §5): pinned, always visible. -->
            <div class="border-b border-default pb-1">
              <p class="px-2 pb-0.5 text-xs font-semibold text-muted">Site</p>
              <button
                class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-elevated"
                :class="{ 'bg-elevated': selectedPath === 'custom.css' }"
                data-test="template-item-custom.css"
                @click="openCustomCss()"
              >
                <UIcon name="i-lucide-paintbrush" class="size-4 shrink-0 text-muted" />
                <span class="min-w-0 flex-1 truncate">custom.css</span>
                <UBadge size="xs" :color="customCssRow ? 'primary' : 'neutral'" variant="subtle">
                  {{ customCssRow ? 'db' : 'empty' }}
                </UBadge>
              </button>
            </div>
            <UCollapsible
              v-for="[family, rows] in groups"
              :key="family"
              :open="searching ? true : (openFolders[family] ?? family === 'root')"
              :unmount-on-hide="false"
              @update:open="openFolders[family] = $event"
            >
              <UButton
                class="w-full justify-between"
                color="neutral"
                variant="ghost"
                size="sm"
                :data-test="`template-group-${family}`"
              >
                <span class="flex min-w-0 items-center gap-2">
                  <UIcon
                    name="i-lucide-folder"
                    class="size-4 shrink-0 text-muted group-data-[state=open]:hidden"
                  />
                  <UIcon
                    name="i-lucide-folder-open"
                    class="hidden size-4 shrink-0 text-muted group-data-[state=open]:inline-block"
                  />
                  <span class="truncate font-medium">{{ family }}</span>
                  <span class="text-xs text-muted">{{ rows.length }}</span>
                </span>
                <UIcon
                  name="i-lucide-chevron-right"
                  class="size-4 shrink-0 text-muted transition-transform group-data-[state=open]:rotate-90"
                />
              </UButton>
              <template #content>
                <ul class="mt-0.5 space-y-0.5 pl-3">
                  <li v-for="t in rows" :key="t.path">
                    <button
                      class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm hover:bg-elevated"
                      :class="{ 'bg-elevated': t.path === selectedPath }"
                      :data-test="`template-item-${t.path}`"
                      :title="t.path"
                      @click="open(t.path)"
                    >
                      <UIcon name="i-lucide-file-code" class="size-4 shrink-0 text-muted" />
                      <span class="min-w-0 flex-1 truncate">{{ fileName(t.path, family) }}</span>
                      <UBadge
                        size="xs"
                        :color="t.origin === 'db' ? 'primary' : 'neutral'"
                        variant="subtle"
                      >
                        {{ t.readonly ? 'read-only' : t.origin }}
                      </UBadge>
                    </button>
                  </li>
                </ul>
              </template>
            </UCollapsible>
            <p v-if="groups.length === 0" class="px-2 py-4 text-sm text-muted">
              No templates match “{{ query }}”.
            </p>
          </div>
        </aside>

        <USeparator orientation="vertical" class="h-full" />

        <main
          v-if="selectedPath"
          class="flex min-w-0 flex-1 flex-col gap-3"
          data-test="template-detail"
        >
          <div class="flex items-center gap-2">
            <h2 class="min-w-0 flex-1 truncate font-mono text-sm">{{ selectedPath }}</h2>
            <UBadge
              v-if="isReadOnly"
              color="neutral"
              variant="subtle"
              icon="i-lucide-lock"
              data-test="readonly-badge"
            >
              Read-only
            </UBadge>
            <UButton
              v-if="!isReadOnly && origin === 'db'"
              data-test="history-button"
              variant="ghost"
              color="neutral"
              icon="i-lucide-history"
              label="History"
              @click="openHistory()"
            />
            <UButton
              v-if="!isReadOnly && origin === 'db'"
              data-test="delete-override"
              color="error"
              variant="ghost"
              icon="i-lucide-trash-2"
              label="Delete override"
              @click="removeOverride"
            />
            <UButton
              v-if="!isReadOnly"
              data-test="save-template"
              :loading="saving"
              icon="i-lucide-save"
              label="Save"
              @click="save"
            />
          </div>

          <p
            v-if="isReadOnly && readonlyReason"
            class="text-xs text-muted"
            data-test="readonly-note"
          >
            {{ readonlyReason }}
          </p>
          <p v-else-if="isReadOnly" class="text-xs text-muted" data-test="readonly-note">
            Read-only theme file — browse it for class names, then override them in
            <code>custom.css</code>.
          </p>
          <p
            v-else-if="selectedPath === 'custom.css'"
            class="text-xs text-muted"
            data-test="custom-css-note"
          >
            Loaded after the theme stylesheets on every page — target blocks via their
            <code>thallo-block-*</code> classes. Site styling for trusted operators; this is not a
            content-editing surface.
          </p>
          <p v-else-if="origin !== 'db'" class="text-xs text-muted" data-test="fs-origin-note">
            Filesystem template ({{ origin }}) — saving creates a database override that shadows it.
          </p>

          <!-- Only THIS region scrolls: the file header and notes above stay fixed. -->
          <div class="min-h-0 flex-1 space-y-3 overflow-y-auto">
            <!-- Keyed by language+mode: the editor builds its CodeMirror extensions
                 once at mount, so language/readonly switches must remount it. -->
            <TemplateEditor
              :key="`${editorLanguage}-${isReadOnly ? 'ro' : 'rw'}`"
              v-model="source"
              :language="editorLanguage"
              :readonly="isReadOnly"
            />

            <ul
              v-if="violations.length"
              class="space-y-1 text-sm text-error"
              data-test="violations"
            >
              <li v-for="v in violations" :key="`${v.line}-${v.message}`" data-test="violation">
                Line {{ v.line }}: {{ v.message }}
              </li>
            </ul>
          </div>

          <HistoryPanel
            v-model:open="historyOpen"
            :theme="theme"
            :path="selectedPath"
            @restored="reopenAfterRestore"
          />
        </main>
        <main v-else class="grid flex-1 place-items-center text-sm text-muted">
          Select a template to view or override it.
        </main>

        <UModal v-model:open="cloneOpen" title="Duplicate theme">
          <template #body>
            <div class="space-y-3">
              <p class="text-sm text-muted">
                Copies “{{ theme }}” (templates, assets, theme.json) into a new
                <code>themes/{name}</code> directory you can edit independently.
              </p>
              <UFormField
                label="New theme name"
                description="Lowercase letters/digits, dashes or underscores."
              >
                <UInput
                  v-model="cloneName"
                  placeholder="my-theme"
                  class="w-full"
                  data-test="clone-theme-name"
                  @keyup.enter="submitClone"
                />
              </UFormField>
              <div class="flex justify-end gap-2">
                <UButton
                  variant="ghost"
                  color="neutral"
                  label="Cancel"
                  @click="cloneOpen = false"
                />
                <UButton
                  :loading="cloning"
                  :disabled="cloneName.trim() === ''"
                  icon="i-lucide-copy-plus"
                  label="Create theme"
                  data-test="clone-theme-submit"
                  @click="submitClone"
                />
              </div>
            </div>
          </template>
        </UModal>
      </div>
    </template>
  </UDashboardPanel>
</template>
