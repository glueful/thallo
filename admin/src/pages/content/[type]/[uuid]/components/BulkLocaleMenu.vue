<script setup lang="ts">
import { computed, ref } from 'vue'
import { useQueryCache } from '@pinia/colada'
import { createLocaleDraft, type EntryLocaleSummary } from '@/queries/entries'
import { publishEntry } from '@/queries/publish'
import { qk } from '@/queries/keys'
import type { Locale } from '@/queries/locales'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{
  uuid: string
  type: string
  currentLocale: string
  summaries: EntryLocaleSummary[]
  addable: Locale[]
}>()

const { success, warning, error: notifyError } = useNotify()
const cache = useQueryCache()
const running = ref(false)

const publishableCount = computed(() => props.summaries.filter((s) => s.has_draft).length)

const items = computed(() => [
  [
    {
      label: `Create drafts for all locales (copy ${props.currentLocale})`,
      icon: 'i-lucide-copy-plus',
      disabled: running.value || props.addable.length === 0,
      onSelect: () => runCreateAll(),
    },
    {
      label: 'Publish every locale with a draft',
      icon: 'i-lucide-globe',
      disabled: running.value || publishableCount.value === 0,
      onSelect: () => runPublishAll(),
    },
  ],
])

/** Run a batch, returning how many succeeded; never throws (collects failures). */
async function batch<T>(
  targets: T[],
  fn: (t: T) => Promise<unknown>,
): Promise<{ ok: number; fail: number }> {
  const results = await Promise.allSettled(targets.map((t) => fn(t)))
  const ok = results.filter((r) => r.status === 'fulfilled').length
  return { ok, fail: results.length - ok }
}

function refresh() {
  cache.invalidateQueries({ key: ['entry-locales', props.uuid] })
  cache.invalidateQueries({ key: qk.entries(props.type) })
}

async function runCreateAll() {
  if (running.value || props.addable.length === 0) return
  running.value = true
  try {
    const { ok, fail } = await batch(props.addable, (l) =>
      createLocaleDraft(props.uuid, l.code, props.currentLocale, false),
    )
    refresh()
    if (fail === 0) success(`Created ${ok} locale draft(s)`)
    else warning(`Created ${ok}, failed ${fail}`, 'Some locales may already exist.')
  } catch (e) {
    notifyError(e, 'Bulk create failed')
  } finally {
    running.value = false
  }
}

async function runPublishAll() {
  if (running.value) return
  const targets = props.summaries.filter((s) => s.has_draft).map((s) => s.locale)
  if (targets.length === 0) {
    warning('Nothing to publish', 'No locale has a draft.')
    return
  }
  running.value = true
  try {
    const { ok, fail } = await batch(targets, (code) => publishEntry(props.uuid, code))
    refresh()
    if (fail === 0) success(`Published ${ok} locale(s)`)
    else warning(`Published ${ok}, failed ${fail}`, 'Open each language that failed to see why.')
  } catch (e) {
    notifyError(e, 'Bulk publish failed')
  } finally {
    running.value = false
  }
}
</script>

<template>
  <UDropdownMenu :items="items" :content="{ align: 'end' }">
    <UButton
      icon="i-lucide-layers"
      color="neutral"
      variant="ghost"
      size="sm"
      :loading="running"
      aria-label="Bulk locale actions"
    />
  </UDropdownMenu>
</template>
