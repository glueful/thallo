<script setup lang="ts">
// A json field with the `link-list` format: a list of links — [{ label, url, icon?, active?,
// new_tab? }] — edited as rows rather than as JSON text. A row shows a link's label and URL and
// whether it opens in a new tab (`new_tab`, as a menu item has); anything else a link carries (an
// icon, `active`) is kept as it is through an edit.
import { computed } from 'vue'
import type { FieldDef } from '../types'

type Link = { label?: string; url?: string; [key: string]: unknown }

defineProps<{ field: FieldDef }>()
const model = defineModel<unknown>()

const links = computed<Link[]>(() =>
  Array.isArray(model.value)
    ? (model.value as unknown[]).map((l) =>
        typeof l === 'object' && l !== null ? (l as Link) : { label: String(l ?? ''), url: '' },
      )
    : [],
)

function write(next: Link[]): void {
  model.value = next
}
function edit(index: number, key: 'label' | 'url', value: string): void {
  write(links.value.map((l, i) => (i === index ? { ...l, [key]: value } : l)))
}
/** On, the link opens in a new tab; off, it carries no flag at all (the default: same tab). */
function toggleNewTab(index: number): void {
  write(
    links.value.map((l, i) => {
      if (i !== index) return l
      const { new_tab: on, ...rest } = l
      return on === true ? rest : { ...rest, new_tab: true }
    }),
  )
}
function add(): void {
  write([...links.value, { label: '', url: '' }])
}
function remove(index: number): void {
  write(links.value.filter((_, i) => i !== index))
}
function move(index: number, by: -1 | 1): void {
  const to = index + by
  if (to < 0 || to >= links.value.length) return
  const next = [...links.value]
  ;[next[index], next[to]] = [next[to]!, next[index]!]
  write(next)
}
</script>

<template>
  <UFormField :label="field.label ?? field.name" :required="field.required" :name="field.name">
    <div class="space-y-2" data-test="link-list">
      <div
        v-for="(link, i) in links"
        :key="i"
        class="flex items-center gap-1.5"
        data-test="link-row"
      >
        <UInput
          :model-value="link.label ?? ''"
          placeholder="Label"
          size="sm"
          class="min-w-0 flex-1"
          :aria-label="`Link ${i + 1} label`"
          @update:model-value="(v: string | number) => edit(i, 'label', String(v))"
        />
        <UInput
          :model-value="link.url ?? ''"
          placeholder="/path or https://…"
          size="sm"
          class="min-w-0 flex-[1.4]"
          :aria-label="`Link ${i + 1} URL`"
          @update:model-value="(v: string | number) => edit(i, 'url', String(v))"
        />
        <UButton
          size="xs"
          :variant="link.new_tab === true ? 'soft' : 'ghost'"
          :color="link.new_tab === true ? 'primary' : 'neutral'"
          icon="i-lucide-external-link"
          :aria-pressed="link.new_tab === true ? 'true' : 'false'"
          :aria-label="`Open link ${i + 1} in a new tab`"
          :title="link.new_tab === true ? 'Opens in a new tab' : 'Opens in the same tab'"
          data-test="link-new-tab"
          @click="toggleNewTab(i)"
        />
        <UButton
          size="xs"
          variant="ghost"
          color="neutral"
          icon="i-lucide-chevron-up"
          :disabled="i === 0"
          :aria-label="`Move link ${i + 1} up`"
          data-test="link-up"
          @click="move(i, -1)"
        />
        <UButton
          size="xs"
          variant="ghost"
          color="error"
          icon="i-lucide-trash-2"
          :aria-label="`Remove link ${i + 1}`"
          data-test="link-remove"
          @click="remove(i)"
        />
      </div>
      <UButton size="xs" variant="ghost" icon="i-lucide-plus" data-test="link-add" @click="add">
        Add link
      </UButton>
    </div>
  </UFormField>
</template>
