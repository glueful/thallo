<script setup lang="ts">
// Pure presentation (site-identity spec §4): the WP site-icon pattern — a
// rounded app-icon tile next to a fake browser tab so editors see the favicon
// in context before it ships. No logic beyond props.
defineProps<{
  src: string
  siteName?: string
}>()
</script>

<template>
  <div class="flex items-center gap-4" data-test="favicon-preview">
    <!-- App-icon tile -->
    <img :src="src" alt="" class="size-12 shrink-0 rounded-xl border border-default object-cover" />
    <!-- Browser-tab mock -->
    <div class="min-w-0 flex-1 overflow-hidden rounded-lg border border-default bg-elevated">
      <div class="flex items-center gap-1.5 px-2.5 pt-2">
        <span class="size-2 rounded-full bg-red-400" />
        <span class="size-2 rounded-full bg-amber-400" />
        <span class="size-2 rounded-full bg-green-400" />
      </div>
      <div class="px-2 pb-0 pt-1.5">
        <div class="flex max-w-48 items-center gap-2 rounded-t-lg bg-default px-3 py-1.5">
          <img :src="src" alt="" class="size-4 shrink-0 rounded-sm object-cover" />
          <span class="truncate text-xs">{{ siteName || 'Site' }}</span>
          <span class="ml-auto shrink-0 text-xs text-muted">×</span>
        </div>
      </div>
    </div>
  </div>
</template>
