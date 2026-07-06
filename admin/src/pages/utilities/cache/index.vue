<script setup lang="ts">
import { computed, ref } from 'vue'
import { useCacheStatus, useCacheMutations } from '@/queries/cache'
import { useContentTypes } from '@/queries/contentTypes'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data, status, refresh, isLoading } = useCacheStatus()
const { clear } = useCacheMutations()
const { data: contentTypes } = useContentTypes()

const cache = computed(() => data.value)
const selectedType = ref('')

// The driver stats come back as a flat list whose nested values are JSON (or `k=v,k=v`) strings.
// Parse each into a labeled block of readable rows so the card shows "Used 1.70M" instead of a
// raw JSON dump. Unknown shapes fall back to the verbatim string.
interface StatRow {
  label: string
  value: string
}
interface StatBlock {
  label: string
  rows: StatRow[]
  text: string | null
}

function humanize(key: string): string {
  return key.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function formatScalar(key: string, value: unknown): string {
  if (typeof value === 'number') {
    if (key === 'hit_rate') return `${value}%`
    if (key === 'fragmentation_ratio') return `${value}×`
    return value.toLocaleString()
  }
  if (typeof value === 'string') {
    // Redis keyspace style: "keys=31,expires=25,avg_ttl=…"
    if (value.includes('=') && /^[\w]+=/.test(value)) {
      return value
        .split(',')
        .map((pair) => {
          const [k, v] = pair.split('=')
          return v === undefined ? humanize(k) : `${humanize(k)}: ${v}`
        })
        .join(' · ')
    }
    return value
  }
  return String(value)
}

const statBlocks = computed<StatBlock[]>(() =>
  (cache.value?.stats ?? []).map((s) => {
    let parsed: unknown = null
    try {
      parsed = JSON.parse(s.value)
    } catch {
      parsed = null
    }
    if (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) {
      const rows = Object.entries(parsed as Record<string, unknown>).map(([k, v]) => ({
        label: humanize(k),
        value: formatScalar(k, v),
      }))
      return { label: humanize(s.key), rows, text: null }
    }
    return { label: humanize(s.key), rows: [], text: s.value }
  }),
)

const typeItems = computed(() =>
  (contentTypes.value ?? []).map((t) => ({ label: t.name, value: t.slug })),
)

const pendingClearAll = ref(false)

async function clearAll() {
  try {
    await clear.mutateAsync(undefined)
    success('Cache cleared', 'All cache entries were flushed.')
    pendingClearAll.value = false
  } catch (e) {
    notifyError(e, 'Could not clear the cache')
  }
}

async function clearType() {
  if (!selectedType.value) return
  try {
    await clear.mutateAsync(selectedType.value)
    success('Cache cleared', `Delivery cache for “${selectedType.value}” was invalidated.`)
    selectedType.value = ''
  } catch (e) {
    notifyError(e, 'Could not clear the cache')
  }
}
</script>

<template>
  <UDashboardPanel id="utilities-cache">
    <template #header>
      <UDashboardNavbar title="Cache">
        <template #right>
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            :loading="isLoading"
            @click="() => { refresh() }"
          >
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-2xl space-y-6">
        <div v-if="status === 'pending'" class="space-y-3">
          <USkeleton class="h-40" />
          <USkeleton class="h-32" />
        </div>

        <template v-else-if="cache">
          <UCard>
            <template #header><h2 class="font-semibold text-default">Status</h2></template>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
              <dt class="text-muted">Driver</dt>
              <dd class="text-default">{{ cache.driver }}</dd>
              <dt class="text-muted">Prefix</dt>
              <dd class="font-mono text-default">{{ cache.prefix || '—' }}</dd>
              <dt class="text-muted">Tag invalidation</dt>
              <dd class="text-default">{{ cache.tags_enabled ? 'Enabled' : 'Disabled' }}</dd>
              <dt class="text-muted">Keys</dt>
              <dd class="text-default">{{ cache.key_count }}</dd>
            </dl>

            <div v-if="statBlocks.length" class="mt-4 space-y-4 border-t border-default pt-4">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">Driver stats</h3>
              <div v-for="b in statBlocks" :key="b.label">
                <p class="mb-1.5 text-xs font-medium uppercase tracking-wide text-dimmed">
                  {{ b.label }}
                </p>
                <dl
                  v-if="b.rows.length"
                  class="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2"
                >
                  <div v-for="r in b.rows" :key="r.label" class="flex justify-between gap-3">
                    <dt class="text-muted">{{ r.label }}</dt>
                    <dd class="break-all text-right text-default">{{ r.value }}</dd>
                  </div>
                </dl>
                <p v-else class="break-all text-sm text-default">{{ b.text }}</p>
              </div>
            </div>
          </UCard>

          <UCard>
            <template #header><h2 class="font-semibold text-default">Clear cache</h2></template>
            <div class="space-y-4">
              <UFormField
                label="Clear one content type"
                hint="Invalidates only that type's delivery cache (the thallo:type tag)."
              >
                <div class="flex gap-2">
                  <USelect
                    v-model="selectedType"
                    :items="typeItems"
                    placeholder="Choose a content type…"
                    class="flex-1"
                  />
                  <UButton
                    label="Clear"
                    color="neutral"
                    variant="subtle"
                    :disabled="!selectedType"
                    :loading="clear.isLoading.value"
                    @click="clearType"
                  />
                </div>
              </UFormField>

              <div class="border-t border-default pt-4">
                <UButton
                  label="Clear all cache"
                  icon="i-lucide-trash-2"
                  color="error"
                  variant="soft"
                  :loading="clear.isLoading.value"
                  @click="() => { pendingClearAll = true }"
                />
                <p class="mt-1 text-xs text-muted">
                  Flushes every cache entry across the instance.
                </p>
              </div>
            </div>
          </UCard>
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="pendingClearAll" title="Clear all cache">
    <template #body>
      <p class="text-sm text-muted">
        Flush every cache entry? Pages and queries will be recomputed on next access — a brief
        performance dip is expected. This cannot be undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="clear.isLoading.value"
          @click="() => { pendingClearAll = false }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Clear all"
          :loading="clear.isLoading.value"
          @click="clearAll"
        />
      </div>
    </template>
  </UModal>
</template>
