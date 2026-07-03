<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoutes, useSaveRoute } from '@/queries/routes'
import { usePublish } from '@/queries/publish'
import { usePreview, useThemePreview, buildPreviewUrl } from '@/queries/preview'
import { useSchedules, useScheduleMutations } from '@/queries/schedules'
import { useEntryLocales } from '@/queries/entries'
import { useNotify } from '@/composables/useNotify'
import { localeStatus } from './localeStatus'

const props = defineProps<{ uuid: string; locale: string; type: string }>()
const { success, warning, error: notifyError } = useNotify()

// ── Publication status (the panel's headline state) ─────────────────────────
// Same query key the LocaleSwitcher uses — Pinia Colada dedupes, no extra request.
const { data: entryLocales } = useEntryLocales(() => props.uuid)
const status = computed(() => {
  const summary = (entryLocales.value ?? []).find((s) => s.locale === props.locale)
  return summary ? localeStatus(summary) : null
})
const isPublished = computed(() => status.value?.key === 'published')

// ── Route / slug ──────────────────────────────────────────────────────────
const { data: routes } = useRoutes(() => props.uuid)
const slug = ref('')
const savedSlug = ref('')
watch(
  routes,
  (r) => {
    const match = r?.find((x) => x.locale === props.locale)
    if (match) {
      slug.value = match.slug
      savedSlug.value = match.slug
    }
  },
  { immediate: true },
)
// The save affordance only appears when the slug is actually dirty — routes are
// otherwise not a separate "workflow" competing with Publish.
const slugDirty = computed(() => slug.value !== savedSlug.value)
const saveRoute = useSaveRoute(props.uuid, props.locale)
async function onSaveRoute() {
  try {
    await saveRoute.mutateAsync(slug.value)
    savedSlug.value = slug.value
    success('Route saved')
  } catch (e) {
    notifyError(e, 'Couldn’t save route')
  }
}

// ── Publish / unpublish (state-aware: one primary action at a time) ────────
const publish = usePublish(props.uuid, props.locale, props.type)
async function onPublish(action: 'publish' | 'unpublish') {
  try {
    await publish.mutateAsync(action)
    success(action === 'publish' ? 'Published' : 'Unpublished')
  } catch (e) {
    notifyError(e, action === 'publish' ? 'Couldn’t publish' : 'Couldn’t unpublish')
  }
}

// ── Preview ─────────────────────────────────────────────────────────────────
const preview = usePreview(props.uuid, props.locale)
async function onPreview() {
  try {
    const url = buildPreviewUrl(await preview.mutateAsync())
    if (url) window.open(url, '_blank', 'noopener')
    else warning('No preview URL is configured')
  } catch (e) {
    notifyError(e, 'Preview failed')
  }
}

// ── Preview in theme ─────────────────────────────────────────────────────────
// Always shown; mints on click. theme_url is SERVER-decided (null = rendered
// delivery off) — the SPA never builds theme URLs or consults capability state.
const themePreview = useThemePreview(props.uuid, props.locale)
async function onThemePreview() {
  try {
    const { themeUrl } = await themePreview.mutateAsync()
    if (themeUrl) window.open(themeUrl, '_blank', 'noopener')
    else warning('Theme preview unavailable — rendered delivery is disabled')
  } catch (e) {
    notifyError(e, 'Preview failed')
  }
}

// ── Schedule (behind a disclosure — it's the rare path) ─────────────────────
const { data: schedules } = useSchedules(() => props.uuid)
const scheduleOpen = ref(false)
const runAt = ref('')
const { create: createSchedule, cancel: cancelSchedule } = useScheduleMutations(
  props.uuid,
  props.locale,
)
async function onSchedule() {
  if (!runAt.value) return
  try {
    await createSchedule.mutateAsync({
      action: 'publish',
      run_at: new Date(runAt.value).toISOString(),
    })
    runAt.value = ''
    scheduleOpen.value = false
    success('Scheduled')
  } catch (e) {
    notifyError(e, 'Couldn’t schedule')
  }
}
async function onCancelSchedule(scheduleUuid: string) {
  try {
    await cancelSchedule.mutateAsync(scheduleUuid)
    success('Schedule canceled')
  } catch (e) {
    notifyError(e, 'Couldn’t cancel schedule')
  }
}
const localeSchedules = computed(() =>
  (schedules.value ?? []).filter((s) => !s.locale || s.locale === props.locale),
)

// Void handler for UButton's typed onClick — an inline toggle returns a value.
function toggleSchedule(): void {
  scheduleOpen.value = !scheduleOpen.value
}
</script>

<template>
  <!-- A tab SECTION, not a card: the editor sidebar's tabbed card provides the chrome;
       the tab label provides the "Publishing" title. -->
  <div data-test="publish-panel">
    <div class="mb-5 flex items-center gap-2">
      <UBadge
        v-if="status"
        :color="status.color"
        :icon="status.icon"
        variant="subtle"
        data-test="publish-status"
      >
        {{ status.label }}
      </UBadge>
      <span class="flex-1" />
      <UTooltip text="Preview draft">
        <UButton
          color="neutral"
          variant="ghost"
          icon="i-lucide-eye"
          aria-label="Preview draft"
          data-test="preview"
          :loading="preview.isLoading.value"
          @click="onPreview"
        />
      </UTooltip>
      <UTooltip text="Preview in theme">
        <UButton
          color="neutral"
          variant="ghost"
          icon="i-lucide-panels-top-left"
          aria-label="Preview in theme"
          data-testid="theme-preview"
          :loading="themePreview.isLoading.value"
          @click="onThemePreview"
        />
      </UTooltip>
    </div>

    <div class="space-y-5">
      <UFormField label="Slug">
        <div class="flex items-center gap-2">
          <UInput v-model="slug" placeholder="my-page" class="flex-1" />
          <UButton
            v-if="slugDirty"
            variant="subtle"
            data-test="save-route"
            :loading="saveRoute.isLoading.value"
            @click="onSaveRoute"
          >
            Save route
          </UButton>
        </div>
      </UFormField>

      <div class="flex items-center gap-2">
        <UButton
          v-if="!isPublished"
          data-test="publish"
          :loading="publish.isLoading.value"
          @click="onPublish('publish')"
        >
          Publish
        </UButton>
        <UButton
          v-else
          color="neutral"
          variant="subtle"
          data-test="unpublish"
          :loading="publish.isLoading.value"
          @click="onPublish('unpublish')"
        >
          Unpublish
        </UButton>
        <UButton
          color="neutral"
          variant="ghost"
          icon="i-lucide-clock"
          data-test="schedule-toggle"
          @click="toggleSchedule()"
        >
          Schedule…
        </UButton>
      </div>

      <div v-if="scheduleOpen || localeSchedules.length" class="space-y-2">
        <div v-if="scheduleOpen" class="flex items-end gap-2">
          <UFormField label="Publish at" class="flex-1">
            <UInput v-model="runAt" type="datetime-local" class="w-full" />
          </UFormField>
          <UButton
            variant="subtle"
            data-test="schedule-confirm"
            :disabled="!runAt"
            :loading="createSchedule.isLoading.value"
            @click="onSchedule"
          >
            Schedule
          </UButton>
        </div>
        <ul v-if="localeSchedules.length" class="space-y-1">
          <li
            v-for="s in localeSchedules"
            :key="s.uuid"
            class="flex items-center justify-between text-sm"
          >
            <span class="text-muted">
              {{ s.action }} · {{ s.run_at }}
              <UBadge size="sm" variant="subtle">{{ s.status ?? 'pending' }}</UBadge>
            </span>
            <UButton
              color="error"
              variant="ghost"
              size="xs"
              icon="i-lucide-x"
              @click="onCancelSchedule(s.uuid)"
            />
          </li>
        </ul>
      </div>

      <!-- Review/workflow lives INSIDE the publishing tab (one editorial surface, not
           two) but stays its own component — the parent slots it in when the pack is
           enabled. -->
      <template v-if="$slots.default">
        <USeparator />
        <slot />
      </template>
    </div>
  </div>
</template>
