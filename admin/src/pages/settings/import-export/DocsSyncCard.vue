<script setup lang="ts">
// Documentation from GitHub (docs sync design §9): the saved source, the last sync that landed,
// whether the repo has moved since, Sync now / Preview, and each run's history. A run holds the
// site's single slot; while it does, the card follows its progress and offers Cancel (and Release
// once it has stopped reporting in).
import { computed, ref } from 'vue'
import {
  countsText,
  noteText,
  outcomeText,
  progressText,
  useCancelDocsSync,
  useDocsSync,
  useReleaseDocsSync,
  useStartDocsSync,
  type DocsRun,
} from '@/queries/docsSync'
import { useNotify } from '@/composables/useNotify'
import type { TypeLike } from './markdownFolder'
import DocsSyncForm from './DocsSyncForm.vue'
import DocsSyncRunPages from './DocsSyncRunPages.vue'

const props = defineProps<{ contentTypes: TypeLike[] }>()

const { data } = useDocsSync()
const start = useStartDocsSync()
const cancel = useCancelDocsSync()
const release = useReleaseDocsSync()
const { error: notifyError } = useNotify()

const editing = ref(false)
const openRun = ref<string | null>(null)

const source = computed(() => data.value?.source ?? null)
const active = computed<DocsRun | null>(
  () => data.value?.recent.find((r) => r.id === data.value?.active_run) ?? null,
)
const busy = computed(() => !!data.value?.active_run)

const typeName = (slug: string): string =>
  props.contentTypes.find((t) => t.slug === slug)?.name ??
  slug.charAt(0).toUpperCase() + slug.slice(1)

const summary = computed(() => {
  const s = source.value
  if (!s) return ''
  const follow =
    s.follow === 'release'
      ? `latest release${s.prereleases ? ' (pre-releases included)' : ''}`
      : `branch ${s.branch}`
  return `${s.repo} · ${s.folder ? s.folder + '/' : '(repository root)'} · ${follow} → ${typeName(s.content_type)}, ${s.publish ? 'published' : 'drafts'}`
})

const when = (at: string | null): string => {
  if (!at) return ''
  const d = new Date(at.replace(' ', 'T') + 'Z')
  return Number.isNaN(d.getTime())
    ? at
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

const lastSynced = computed(() => {
  const run = data.value?.synced
  if (!run) return ''
  const parts = [
    `Last synced ${run.ref ?? ''} (${(run.sha ?? '').slice(0, 7)})`,
    when(run.finished_at),
  ]
  const counts = countsText(run)
  if (counts) parts.push(counts)
  if (run.outcome === 'partial') parts.push(outcomeText(run))
  return parts.filter(Boolean).join(' · ')
})

async function onStart(mode: 'commit' | 'dry_run') {
  try {
    await start.mutateAsync(mode)
  } catch (e) {
    notifyError(e, mode === 'commit' ? 'Could not start the sync' : 'Could not start the preview')
  }
}
</script>

<template>
  <UCard data-test="docs-sync-card">
    <template #header>
      <h2 class="font-semibold text-default">Documentation from GitHub</h2>
    </template>

    <DocsSyncForm
      v-if="!source || editing"
      :source="source"
      :content-types="contentTypes"
      @saved="editing = false"
      @cancel="editing = false"
    />

    <div v-else class="space-y-3 text-sm">
      <p class="text-default">{{ summary }}</p>
      <p v-if="lastSynced" class="text-muted">{{ lastSynced }}</p>
      <p v-if="active" class="text-default" data-test="docs-sync-progress">
        {{ progressText(active) }}
      </p>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-default">{{ noteText(data!.note, source) }}</span>
        <span class="grow" />
        <UButton
          v-if="busy"
          color="neutral"
          variant="outline"
          size="sm"
          data-test="docs-sync-cancel"
          @click="cancel.mutateAsync(data!.active_run!)"
        >
          Cancel
        </UButton>
        <UButton
          v-if="busy && data?.releasable"
          color="neutral"
          variant="outline"
          size="sm"
          data-test="docs-sync-release"
          @click="release.mutateAsync(data!.active_run!)"
        >
          Release
        </UButton>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          :disabled="busy"
          data-test="docs-sync-preview"
          @click="onStart('dry_run')"
        >
          Preview
        </UButton>
        <UButton size="sm" :disabled="busy" data-test="docs-sync-now" @click="onStart('commit')">
          Sync now
        </UButton>
        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          :disabled="busy"
          data-test="docs-sync-edit"
          @click="editing = true"
        >
          Edit source
        </UButton>
      </div>

      <div v-if="data?.recent.length" class="space-y-1">
        <h3 class="text-xs font-semibold text-muted uppercase tracking-wide">History</h3>
        <ul class="divide-y divide-default">
          <li
            v-for="run in data.recent"
            :key="run.id"
            class="flex flex-wrap items-center gap-x-3 py-1.5"
            data-test="docs-sync-history-row"
          >
            <span class="text-muted">{{ when(run.created_at) }}</span>
            <span>{{ run.mode === 'dry_run' ? 'Preview' : 'Sync' }}</span>
            <span class="text-muted">{{ run.ref }}</span>
            <span class="text-default">{{ outcomeText(run) }}</span>
            <span class="text-muted">{{ countsText(run) }}</span>
            <UButton
              size="xs"
              color="neutral"
              variant="link"
              @click="openRun = openRun === run.id ? null : run.id"
            >
              pages
            </UButton>
          </li>
        </ul>
        <DocsSyncRunPages v-if="openRun" :run-id="openRun" />
      </div>
    </div>
  </UCard>
</template>
