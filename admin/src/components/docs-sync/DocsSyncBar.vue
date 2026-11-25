<script setup lang="ts">
// Documentation from GitHub, where the pages are (docs sync design §9): a slim bar above a content
// type's pages list. It appears on the type the docs source imports into, saying what the pages
// were synced from and whether the repo has moved, with Sync now. History, Preview and the source
// settings open in a side panel (DocsSyncCard). A docs-shaped type with no source offers to set one
// up. Someone who may not read imports (the API refuses) sees nothing.
import { computed, ref } from 'vue'
import {
  noteText,
  progressText,
  useDocsSync,
  useStartDocsSync,
  type DocsRun,
} from '@/queries/docsSync'
import { useNotify } from '@/composables/useNotify'
import type { TypeLike } from '@/pages/settings/import-export/markdownFolder'
import DocsSyncCard from './DocsSyncCard.vue'

const props = defineProps<{ type: string; contentTypes: TypeLike[] }>()

const { data, error } = useDocsSync()
const start = useStartDocsSync()
const { error: notifyError } = useNotify()
const open = ref(false)

const source = computed(() => data.value?.source ?? null)
const ours = computed(() => !error.value && source.value?.content_type === props.type)
/** A type made for Markdown pages (thallo:docs:setup gives it source_path) can take a source. */
const docsShaped = computed(() =>
  (props.contentTypes.find((t) => t.slug === props.type)?.schema ?? []).some(
    (f) => String(f.name ?? '') === 'source_path',
  ),
)
const offerSetup = computed(() => !error.value && !!data.value && !source.value && docsShaped.value)
const active = computed<DocsRun | null>(
  () => data.value?.recent.find((r) => r.id === data.value?.active_run) ?? null,
)
const busy = computed(() => !!data.value?.active_run)
const syncedRef = computed(() => data.value?.synced?.ref ?? null)

async function onSync() {
  try {
    await start.mutateAsync('commit')
  } catch (e) {
    notifyError(e, 'Could not start the sync')
  }
}
</script>

<template>
  <div
    v-if="ours || offerSetup"
    class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-default px-4 py-3 text-sm"
    data-test="docs-sync-bar"
  >
    <UIcon name="i-lucide-refresh-cw" class="size-4 text-muted" />
    <template v-if="ours">
      <span class="text-default">
        Synced from {{ source!.repo }}<template v-if="syncedRef"> · {{ syncedRef }}</template>
      </span>
      <span class="text-muted">{{
        active ? progressText(active) : noteText(data!.note, source)
      }}</span>
      <span class="grow" />
      <UButton
        size="sm"
        :disabled="busy"
        :loading="start.isLoading.value"
        data-test="docs-sync-bar-now"
        @click="onSync"
      >
        Sync now
      </UButton>
      <UButton
        size="sm"
        color="neutral"
        variant="ghost"
        icon="i-lucide-ellipsis"
        aria-label="Sync details: history, Preview and the source"
        data-test="docs-sync-bar-details"
        @click="open = true"
      />
    </template>
    <template v-else>
      <span class="text-muted">Keep these pages in step with a public GitHub repository.</span>
      <span class="grow" />
      <UButton
        size="sm"
        color="neutral"
        variant="outline"
        data-test="docs-sync-bar-setup"
        @click="open = true"
      >
        Sync from GitHub
      </UButton>
    </template>

    <USlideover
      v-model:open="open"
      title="Documentation from GitHub"
      :ui="{ content: 'max-w-2xl' }"
    >
      <template #body>
        <DocsSyncCard :content-types="contentTypes" />
      </template>
    </USlideover>
  </div>
</template>
