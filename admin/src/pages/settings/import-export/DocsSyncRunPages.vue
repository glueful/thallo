<script setup lang="ts">
// One run's pages: each file, what happened to it, and why a page failed.
import { useDocsSyncRun } from '@/queries/docsSync'

const props = defineProps<{ runId: string | null; contentType: string }>()
const { data, isLoading } = useDocsSyncRun(() => props.runId)
</script>

<template>
  <div class="space-y-2 text-sm" data-test="docs-sync-pages">
    <p v-if="isLoading" class="text-muted">Loading…</p>
    <p v-else-if="!data?.pages?.length" class="text-muted">No pages were handled.</p>
    <ul v-else class="divide-y divide-default">
      <li v-for="page in data.pages" :key="page.path" class="flex flex-wrap gap-x-3 py-1.5">
        <RouterLink
          v-if="page.entry_uuid"
          :to="`/content/${contentType}/${page.entry_uuid}`"
          class="text-primary hover:underline"
        >
          <code>{{ page.path }}</code>
        </RouterLink>
        <code v-else class="text-default">{{ page.path }}</code>
        <span class="text-muted">{{ page.status }}</span>
        <span v-if="page.message" class="text-muted">{{ page.message }}</span>
      </li>
    </ul>
  </div>
</template>
