<script setup lang="ts">
// Read-only inventory of the default store pages (account-form-blocks plan Task 1) — the
// Settings → Accounts "Account pages" card, adopted for commerce. The list is a fixed server-side
// allowlist whose paths follow the live shop prefix; nothing here is editable. Shares
// useStoreSettings() with StorePanel (same query key → one request).
import { computed } from 'vue'
import { useStoreSettings } from '@/queries/commerceSettings'

const { data: settings } = useStoreSettings()
const pages = computed(() => settings.value?.pages ?? [])
</script>

<template>
  <!-- max-w-2xl matches StorePanel below, so the two sections align. -->
  <section
    v-if="pages.length > 0"
    class="mb-6 max-w-2xl rounded-lg border border-default"
    data-testid="store-pages"
  >
    <div class="border-b border-default px-5 py-3">
      <h2 class="text-sm font-semibold text-highlighted">Store pages</h2>
    </div>
    <ul class="divide-y divide-default">
      <li
        v-for="page in pages"
        :key="page.path"
        class="flex items-center justify-between gap-3 px-5 py-3"
      >
        <span class="text-sm text-highlighted">{{ page.label }}</span>
        <a
          :href="page.path"
          target="_blank"
          rel="noopener"
          class="text-sm text-primary hover:underline"
          data-testid="store-page-link"
          >{{ page.path }}</a
        >
      </li>
    </ul>
  </section>
</template>
