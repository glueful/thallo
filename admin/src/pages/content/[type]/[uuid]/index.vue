<script setup lang="ts">
import { computed, ref, watch, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import { useContentTypes } from '@/queries/contentTypes'
import { useDraft, useSaveDraft } from '@/queries/drafts'
import { useEntryLocales, useCreateLocaleDraft } from '@/queries/entries'
import { usePublish } from '@/queries/publish'
import { localeStatus } from './components/localeStatus'
import { useLocales } from '@/queries/locales'
import { runtimeConfig } from '@/runtime/config'
import type { FieldDef } from '@/fields/types'
import FieldEditor from '@/components/FieldEditor.vue'
import { ApiError, apiErrorCode, apiErrorDetails } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import PublishPanel from './components/PublishPanel.vue'
import SeoPanel from './components/SeoPanel.vue'
import VersionsPanel from './components/VersionsPanel.vue'
import WorkflowPanel from './components/WorkflowPanel.vue'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { slugify } from '@/utils/slugify'
import LocaleSwitcher from './components/LocaleSwitcher.vue'
import LocaleRoutesModal from './components/LocaleRoutesModal.vue'
import BulkLocaleMenu from './components/BulkLocaleMenu.vue'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const type = computed(() => String(route.params.type))
const uuid = computed(() => String(route.params.uuid))

const caps = useCapabilitiesStore()
const seoEnabled = computed(() => caps.isEnabled('lemma.seo'))
const workflowEnabled = computed(() => caps.isEnabled('lemma.workflow'))

// Sidebar tabs: Publishing is home; SEO joins when its pack is enabled. v-show keeps
// panel state (dirty slug, open schedule) alive across tab switches.
const sideTab = ref('publishing')
const sideTabItems = computed(() => [
  { label: 'Publishing', value: 'publishing' },
  ...(seoEnabled.value ? [{ label: 'SEO', value: 'seo' }] : []),
  { label: 'Versions', value: 'versions' },
])

const { success, warning, error: notifyError } = useNotify()

// The locale being edited. Starts at the default but is driven by the header switcher; the draft,
// save mutation, and PublishPanel all follow it.
const locale = ref(String(route.query.locale ?? runtimeConfig.defaultLocale))

// The content-type schema drives the field editor.
const { data: contentTypes } = useContentTypes()
const contentType = computed(() => contentTypes.value?.find((c) => c.slug === type.value))
const schema = computed<FieldDef[]>(() =>
  (contentType.value?.schema ?? []).map((f) => ({
    name: String(f.name ?? ''),
    type: (f.type ?? 'string') as FieldDef['type'],
    required: f.required ?? undefined,
    enum: f.enum ?? undefined,
    // Carry the widget hint through so `text` + `rich` renders the RichText (UEditor) editor,
    // matching the content-type preview — without this it falls back to a plain textarea.
    format: (f.format ?? undefined) as FieldDef['format'],
    // Target type for a reference field — drives the searchable picker.
    referenceType: f.reference_type ?? undefined,
    multiple: f.multiple ?? undefined,
    maxItems: f.max_items ?? undefined,
    referenceSlugField: f.reference_slug_field ?? undefined,
  })),
)

// ── Locales ───────────────────────────────────────────────────────────────────
const { data: allLocales } = useLocales()
const enabledLocales = computed(() => (allLocales.value ?? []).filter((l) => l.enabled))
const multiLocale = computed(() => enabledLocales.value.length > 1)
function localeLabel(code: string): string {
  const match = enabledLocales.value.find((l) => l.code === code)
  return match ? `${match.name} (${code})` : code
}

const { data: entryLocales } = useEntryLocales(uuid)
const entryLocaleCodes = computed(() => (entryLocales.value ?? []).map((l) => l.locale))
const localeCopyItems = computed(() =>
  entryLocaleCodes.value.map((code) => ({ label: localeLabel(code), value: code })),
)
const addableLocales = computed(() =>
  enabledLocales.value.filter((l) => !entryLocaleCodes.value.includes(l.code)),
)

// Land on a locale the entry actually has (it may have been authored in a non-default locale). Runs
// once on first load, then leaves the switcher under the user's control.
const localeInitialized = ref(false)
watchEffect(() => {
  if (localeInitialized.value) return
  const codes = entryLocaleCodes.value
  if (!codes.length) return
  if (!codes.includes(locale.value)) {
    locale.value = codes.includes(runtimeConfig.defaultLocale)
      ? runtimeConfig.defaultLocale
      : codes[0]!
  }
  localeInitialized.value = true
})

// Fields shared across locales (not per-locale) — editing them affects every locale.
const sharedFields = computed(() =>
  (contentType.value?.schema ?? []).filter((f) => !f.localized).map((f) => String(f.name)),
)
const showSharedNote = computed(
  () => sharedFields.value.length > 0 && locale.value !== runtimeConfig.defaultLocale,
)

// ── Create a locale version ─────────────────────────────────────────────────────
const createLocale = useCreateLocaleDraft()
const pendingLocale = ref('')
const copyEnabled = ref(true)
const copyFrom = ref('')

function openCreate(code: string) {
  pendingLocale.value = code
  copyEnabled.value = entryLocaleCodes.value.length > 0
  copyFrom.value = locale.value || entryLocaleCodes.value[0] || ''
}

async function confirmCreate() {
  const target = pendingLocale.value
  if (!target) return
  try {
    await createLocale.mutateAsync({
      uuid: uuid.value,
      locale: target,
      sourceLocale: copyEnabled.value ? copyFrom.value || undefined : undefined,
    })
    pendingLocale.value = ''
    locale.value = target
    success(`${localeLabel(target)} version created`)
  } catch (e) {
    notifyError(e, 'Couldn’t create the locale version')
  }
}

// ── Copy content into the current locale (overwrite) ───────────────────────────
const copySource = ref('')
const copySourceOptions = computed(() =>
  entryLocaleCodes.value
    .filter((c) => c !== locale.value)
    .map((c) => ({ label: localeLabel(c), value: c })),
)
function openCopyInto(source: string) {
  copySource.value = source
}
async function confirmCopyInto() {
  const source = copySource.value
  if (!source) return
  try {
    await createLocale.mutateAsync({
      uuid: uuid.value,
      locale: locale.value,
      sourceLocale: source,
      overwrite: true,
    })
    success(`Copied ${localeLabel(source)} content into ${localeLabel(locale.value)}`)
    copySource.value = ''
  } catch (e) {
    notifyError(e, 'Couldn’t copy content')
  }
}

// ── Draft ─────────────────────────────────────────────────────────────────────
const { data: draft, status: draftStatus } = useDraft(uuid, () => locale.value)

// Local editable copy, seeded from the loaded draft. lock_version is echoed back on save for
// optimistic concurrency. Re-seeds whenever the loaded draft changes (including on locale switch).
const fields = ref<Record<string, unknown>>({})
const lockVersion = ref(0)
watch(
  draft,
  (d) => {
    if (d) {
      fields.value = { ...d.fields }
      lockVersion.value = d.lock_version
    }
  },
  { immediate: true },
)

const showRoutes = ref(false)

const save = useSaveDraft(uuid.value, () => locale.value, type.value)

// Navbar publish (moved from the panel — the panel keeps unpublish/schedule):
// publishing PINS THE SAVED DRAFT, so a dirty form saves first.
const publish = usePublish(uuid.value, () => locale.value, type.value)
const isPublished = computed(() => {
  const summary = (entryLocales.value ?? []).find((s) => s.locale === locale.value)
  return summary ? localeStatus(summary).key === 'published' : false
})
async function onPublish() {
  if (!(await onSave())) return // never publish past a failed save
  try {
    await publish.mutateAsync('publish')
    success(isPublished.value ? 'Updated' : 'Published')
  } catch (e) {
    notifyError(e, 'Couldn’t publish')
  }
}

async function onSave(): Promise<boolean> {
  try {
    await save.mutateAsync({ fields: fields.value, lock_version: lockVersion.value })
    success('Draft saved')
    return true
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 409) {
      if (apiErrorCode(e) === 'BLOCK_MIGRATION_IN_PROGRESS') {
        const blockType = String(apiErrorDetails(e)?.block_type ?? 'a block type')
        warning(
          `Block type “${blockType}” is being migrated`,
          'Saving is blocked until the migration completes — try again shortly.',
        )
      } else {
        warning(
          'This draft changed elsewhere',
          'Reload to get the latest version before saving again.',
        )
      }
    } else {
      notifyError(e, 'Couldn’t save draft')
    }
    return false
  }
}
</script>

<template>
  <UDashboardPanel id="entry-editor">
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            :to="`/content/${type}`"
            :aria-label="`Back to ${type}`"
          />
        </template>
        <template #title
          ><span class="capitalize">{{ type }}</span></template
        >
        <template #right>
          <LocaleSwitcher
            v-if="multiLocale"
            v-model="locale"
            :summaries="entryLocales ?? []"
            :enabled="enabledLocales"
            :addable="addableLocales"
            @create="openCreate"
          />
          <UDropdownMenu
            v-if="multiLocale && copySourceOptions.length"
            :items="
              copySourceOptions.map((o) => ({
                label: `From ${o.label}`,
                onSelect: () => openCopyInto(o.value),
              }))
            "
            :content="{ align: 'end' }"
          >
            <UButton
              icon="i-lucide-copy"
              color="neutral"
              variant="ghost"
              size="sm"
              aria-label="Copy content into this locale"
            />
          </UDropdownMenu>
          <UButton
            v-if="multiLocale"
            variant="ghost"
            color="neutral"
            icon="i-lucide-signpost"
            aria-label="Manage routes by locale"
            @click="
              () => {
                showRoutes = true
              }
            "
          />
          <BulkLocaleMenu
            v-if="multiLocale"
            :uuid="uuid"
            :type="type"
            :current-locale="locale"
            :summaries="entryLocales ?? []"
            :addable="addableLocales"
          />
          <UButton
            variant="outline"
            color="neutral"
            icon="i-lucide-layout-template"
            :to="`/content/${type}/${uuid}/design/${locale}`"
            data-test="design-link"
          >
            Design
          </UButton>
          <UButton
            variant="outline"
            color="neutral"
            icon="i-lucide-save"
            square
            aria-label="Save draft"
            title="Save draft"
            :loading="save.isLoading.value"
            data-test="save-draft"
            @click="
              () => {
                void onSave()
              }
            "
          />
          <UButton
            :loading="publish.isLoading.value"
            data-test="navbar-publish"
            @click="onPublish"
          >
            {{ isPublished ? 'Update' : 'Publish' }}
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!-- On large screens the body fills the panel height and each pane scrolls on its own, so the
           page chrome stays put. On small screens it stacks and scrolls normally. -->
      <div class="flex w-full flex-col gap-6 lg:h-full lg:min-h-0 lg:flex-row">
        <!-- Entry content — the primary, wider pane -->
        <div class="min-w-0 lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:pe-1">
          <UCard :ui="{ root: 'ring-0' }">
            <UAlert
              v-if="showSharedNote"
              class="mb-4"
              color="neutral"
              variant="subtle"
              icon="i-lucide-link"
              title="Some fields are shared across locales"
              :description="`Editing these applies to every locale: ${sharedFields.join(', ')}.`"
            />
            <div v-if="draftStatus === 'pending'" class="space-y-3">
              <USkeleton v-for="n in 4" :key="n" class="h-10" />
            </div>
            <FieldEditor v-else v-model="fields" :schema="schema" />
          </UCard>
        </div>

        <!-- Publishing — the narrower sidebar, its own scroll section. p-1 gives the card's ring room
             on every side: the scroll container would otherwise clip the outline (overflow-y-auto
             makes overflow-x compute to auto, and the top/bottom edges clip at the scroll extremes).
             Keyed by locale so the panel re-seeds its slug/publish state on a locale switch. -->
        <div class="lg:min-h-0 lg:w-96 lg:shrink-0 lg:overflow-y-auto lg:p-1">
          <UCard>
            <UTabs
              v-model="sideTab"
              variant="link"
              :items="sideTabItems"
              :content="false"
              class="mb-4"
              data-test="editor-side-tabs"
            />
            <PublishPanel
              v-show="sideTab === 'publishing'"
              :key="`${uuid}-${locale}`"
              :uuid="uuid"
              :locale="locale"
              :type="type"
              :suggested-slug="typeof fields.title === 'string' ? slugify(fields.title) : ''"
            >
              <!-- Review state shares the Publishing tab — one editorial surface. -->
              <WorkflowPanel
                v-if="workflowEnabled"
                :key="`wf-${uuid}-${locale}`"
                :uuid="uuid"
                :locale="locale"
                :enabled="workflowEnabled"
              />
            </PublishPanel>
            <template v-if="seoEnabled">
              <SeoPanel
                v-show="sideTab === 'seo'"
                :key="`seo-${uuid}-${locale}`"
                :uuid="uuid"
                :locale="locale"
                :enabled="seoEnabled"
              />
            </template>
            <VersionsPanel
              v-show="sideTab === 'versions'"
              :key="`versions-${uuid}-${locale}`"
              :uuid="uuid"
              :locale="locale"
              :type="type"
            />
          </UCard>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <UModal
    :open="pendingLocale !== ''"
    :title="`Create ${localeLabel(pendingLocale)} version`"
    @update:open="
      (v: boolean) => {
        if (!v) pendingLocale = ''
      }
    "
  >
    <template #body>
      <div class="space-y-4">
        <p class="text-sm text-muted">
          Add a <span class="text-default">{{ localeLabel(pendingLocale) }}</span> draft for this
          entry.
        </p>
        <UCheckbox
          v-if="entryLocaleCodes.length"
          v-model="copyEnabled"
          label="Copy content from an existing locale"
        />
        <UFormField v-if="copyEnabled && entryLocaleCodes.length" label="Copy from">
          <USelect v-model="copyFrom" :items="localeCopyItems" class="w-full" />
        </UFormField>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="createLocale.isLoading.value"
          @click="
            () => {
              pendingLocale = ''
            }
          "
        />
        <UButton
          icon="i-lucide-plus"
          label="Create version"
          :loading="createLocale.isLoading.value"
          @click="confirmCreate"
        />
      </div>
    </template>
  </UModal>

  <UModal
    :open="copySource !== ''"
    title="Copy content into this locale"
    @update:open="
      (v: boolean) => {
        if (!v) copySource = ''
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">
        Replace the <span class="text-default">{{ localeLabel(locale) }}</span> draft with content
        from <span class="text-default">{{ localeLabel(copySource) }}</span
        >? Shared (non-localized) fields are copied from the source; the target's localized fields
        are cleared for re-translation. This overwrites the current draft.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="createLocale.isLoading.value"
          @click="
            () => {
              copySource = ''
            }
          "
        />
        <UButton
          icon="i-lucide-copy"
          label="Copy content"
          :loading="createLocale.isLoading.value"
          @click="confirmCopyInto"
        />
      </div>
    </template>
  </UModal>

  <LocaleRoutesModal v-model:open="showRoutes" :uuid="uuid" :enabled="enabledLocales" />
</template>
