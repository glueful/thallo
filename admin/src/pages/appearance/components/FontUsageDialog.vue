<script setup lang="ts">
// Before a family is removed (block typeface spec §2.6; plan Task 11): where it is used, grouped,
// with a way to each place that has a page, and what happens there once it is gone — blocks inherit
// their parent's font; Appearance falls back. Removal is a soft delete: Restore brings it back whole.
import { computed } from 'vue'
import type { FontUsage } from '@/queries/fontLibrary'

const props = defineProps<{
  open: boolean
  /** The family's name, for the title. */
  name: string
  /** Null while it loads. */
  usage: FontUsage | null
  removing?: boolean
}>()
const emit = defineEmits<{ 'update:open': [open: boolean]; confirm: [] }>()

const REGION_LABELS: Record<string, string> = { header: 'Header', footer: 'Footer' }

/** A layout's id is `surface:target`; its editor is at /layouts/{surface}/{target}. */
function layoutPath(id: string): string | null {
  const [surface, target] = id.split(':')
  return surface && target
    ? `/layouts/${encodeURIComponent(surface)}/${encodeURIComponent(target)}`
    : null
}

function entryState(entry: FontUsage['entries'][number]): string {
  const where = [
    entry.draft && 'draft',
    entry.published && 'published',
    entry.versions && 'earlier versions',
  ]
  return where.filter(Boolean).join(', ')
}

const blocksUse = computed(() => {
  const u = props.usage
  return (
    u !== null &&
    u.entries.length +
      u.regions.length +
      u.layouts.length +
      u.saved_sections.length +
      u.style_classes.length >
      0
  )
})
const appearanceUse = computed(
  () => props.usage?.appearance.text || props.usage?.appearance.headings,
)
const unused = computed(() => props.usage !== null && !blocksUse.value && !appearanceUse.value)
</script>

<template>
  <UModal
    :open="open"
    :title="`Remove ${name}`"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <div class="space-y-4 text-sm" data-test="font-usage">
        <p v-if="usage === null" class="text-muted">Finding where it is used…</p>
        <template v-else>
          <p v-if="unused" class="text-muted">Nothing uses it.</p>
          <section v-if="usage.entries.length > 0" data-test="usage-entries">
            <h4 class="font-medium">Pages and posts</h4>
            <ul class="mt-1 space-y-0.5 text-muted">
              <li v-for="entry in usage.entries" :key="`${entry.uuid}:${entry.locale}`">
                <span class="text-default">{{ entry.title || 'Untitled' }}</span>
                <span v-if="entry.locale"> ({{ entry.locale }})</span> — {{ entryState(entry) }}
              </li>
            </ul>
          </section>
          <section v-if="usage.regions.length > 0" data-test="usage-regions">
            <h4 class="font-medium">Header and footer</h4>
            <ul class="mt-1 space-y-0.5">
              <li v-for="region in usage.regions" :key="region">
                <RouterLink to="/regions" class="text-primary hover:underline">
                  {{ REGION_LABELS[region] ?? region }}
                </RouterLink>
              </li>
            </ul>
          </section>
          <section v-if="usage.layouts.length > 0" data-test="usage-layouts">
            <h4 class="font-medium">Layouts</h4>
            <ul class="mt-1 space-y-0.5">
              <li v-for="layout in usage.layouts" :key="layout.id">
                <RouterLink
                  v-if="layoutPath(layout.id)"
                  :to="layoutPath(layout.id)!"
                  class="text-primary hover:underline"
                >
                  {{ layout.name }}
                </RouterLink>
                <span v-else>{{ layout.name }}</span>
              </li>
            </ul>
          </section>
          <section v-if="usage.saved_sections.length > 0" data-test="usage-saved_sections">
            <h4 class="font-medium">Saved sections</h4>
            <ul class="mt-1 space-y-0.5 text-muted">
              <li v-for="section in usage.saved_sections" :key="section.id">{{ section.name }}</li>
            </ul>
          </section>
          <section v-if="usage.style_classes.length > 0" data-test="usage-style_classes">
            <h4 class="font-medium">Style classes</h4>
            <ul class="mt-1 space-y-0.5">
              <li v-for="cls in usage.style_classes" :key="cls.id">
                <RouterLink
                  :to="`/settings/style-classes/${encodeURIComponent(cls.id)}`"
                  class="text-primary hover:underline"
                >
                  {{ cls.name }}
                </RouterLink>
              </li>
            </ul>
          </section>
          <section v-if="appearanceUse" data-test="usage-appearance">
            <h4 class="font-medium">Appearance</h4>
            <p class="mt-1 text-muted">
              Custom
              {{
                usage.appearance.text && usage.appearance.headings
                  ? 'Text and Headings'
                  : usage.appearance.text
                    ? 'Text'
                    : 'Headings'
              }}
            </p>
          </section>
          <div class="space-y-1 rounded-md bg-elevated p-3 text-muted">
            <p v-if="blocksUse">Blocks using it inherit their parent's font.</p>
            <p v-if="appearanceUse">
              Appearance falls back: Text to the theme's face, Headings to Text.
            </p>
            <p>Removed families can be restored, with everything that names them.</p>
          </div>
        </template>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" @click="emit('update:open', false)"
          >Cancel</UButton
        >
        <UButton
          color="error"
          :loading="removing"
          :disabled="usage === null"
          data-test="font-usage-confirm"
          @click="emit('confirm')"
        >
          Remove
        </UButton>
      </div>
    </template>
  </UModal>
</template>
