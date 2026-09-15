<script setup lang="ts">
import { computed, ref } from 'vue'
import { useStyleClasses, useStyleClassMutations, type StyleClass } from '@/queries/styleClasses'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data: list, status } = useStyleClasses()
const { archive } = useStyleClassMutations()

const search = ref('')
const classes = computed<StyleClass[]>(() => {
  const term = search.value.trim().toLowerCase()
  const all = list.value?.classes ?? []
  if (term === '') return all
  return all.filter((c) =>
    [c.name, c.description ?? ''].some((s) => s.toLowerCase().includes(term)),
  )
})

/** The §1.3 paths a class declares, for the row's summary. */
function declared(style: Record<string, unknown>): string[] {
  const out: string[] = []
  const walk = (node: unknown, path: string[]): void => {
    if (typeof node !== 'object' || node === null) return
    const record = node as Record<string, unknown>
    if ('type' in record) {
      out.push(path.join('.'))
      return
    }
    for (const [key, child] of Object.entries(record)) {
      if (['base', 'md', 'lg'].includes(key)) {
        out.push(path.join('.'))
        return
      }
      walk(child, [...path, key])
    }
  }
  walk(style, [])
  return [...new Set(out)]
}

const archiving = ref<string | null>(null)
async function onArchive(c: StyleClass) {
  archiving.value = c.id
  try {
    await archive.mutateAsync(c.id)
    success('Style class archived', 'Old revisions that reference it still restore.')
  } catch (e) {
    notifyError(e, 'Couldn’t archive the style class')
  } finally {
    archiving.value = null
  }
}
</script>

<template>
  <UDashboardPanel id="style-classes">
    <template #header>
      <UDashboardNavbar title="Style classes">
        <template #right>
          <UInput
            v-model="search"
            icon="i-lucide-search"
            placeholder="Search style classes…"
            class="w-64"
            data-test="style-class-search"
          />
          <UButton
            icon="i-lucide-plus"
            to="/settings/style-classes/new"
            data-test="new-style-class"
          >
            New style class
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="status === 'pending'" class="space-y-2">
        <USkeleton v-for="n in 4" :key="n" class="h-12" />
      </div>
      <UEmpty
        v-else-if="!list?.classes.length"
        icon="i-lucide-paintbrush"
        title="No style classes yet"
        description="A style class is a reusable set of design settings any block can compose."
      />
      <UEmpty
        v-else-if="!classes.length"
        icon="i-lucide-search-x"
        title="No style classes match"
        :description="`Nothing has “${search.trim()}” in its name or description.`"
      />
      <div
        v-else
        class="mx-auto w-full max-w-4xl divide-y divide-default rounded-lg border border-default"
      >
        <div
          v-for="c in classes"
          :key="c.id"
          class="flex items-center gap-3 px-4 py-3"
          :class="c.archived ? 'opacity-70' : ''"
          :data-test="`style-class-row-${c.id}`"
        >
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <p class="truncate text-sm font-medium text-default">{{ c.name }}</p>
              <UBadge
                v-if="c.archived"
                size="xs"
                color="warning"
                variant="subtle"
                data-test="style-class-archived"
              >
                archived
              </UBadge>
              <UBadge v-if="c.locked_by_job" size="xs" color="info" variant="subtle"
                >job running</UBadge
              >
            </div>
            <p v-if="c.description" class="truncate text-xs text-muted">{{ c.description }}</p>
            <p class="truncate font-mono text-[11px] text-muted">
              {{ declared(c.style).join(', ') || 'declares nothing yet' }}
            </p>
          </div>
          <UButton
            size="xs"
            variant="ghost"
            color="neutral"
            icon="i-lucide-pencil"
            :to="`/settings/style-classes/${c.id}`"
            :aria-label="`Edit ${c.name}`"
          />
          <UButton
            v-if="!c.archived"
            size="xs"
            variant="ghost"
            color="warning"
            icon="i-lucide-archive"
            :loading="archiving === c.id"
            :disabled="c.locked_by_job !== null"
            :aria-label="`Archive ${c.name}`"
            :data-test="`style-class-archive-${c.id}`"
            @click="onArchive(c)"
          />
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
