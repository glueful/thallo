<script setup lang="ts">
import { computed } from 'vue'
import { useLayouts, type LayoutRow } from '@/queries/layouts'

// Site › Layouts (type layouts spec §6.1): each page kind that can have a layout — for each content
// type, its single post — and whether it renders through the theme's template or a custom layout.
// Edit opens the layout editor. Remove lives there, where the editing session and version are.
definePage({ meta: { requiresAuth: true } })

const { data, isLoading, error } = useLayouts()
const rows = computed(() => data.value?.rows)
const canEdit = computed(() => data.value?.canEdit === true)

function stateLabel(row: LayoutRow): string {
  return row.state === 'custom' ? 'Custom layout' : 'Theme template'
}

function savedLine(row: LayoutRow): string | null {
  if (row.state !== 'custom' || row.updated_at === null) return null
  const when = new Date(row.updated_at)
  const date = Number.isNaN(when.getTime()) ? row.updated_at : when.toLocaleString()
  return row.updated_by_name ? `Saved ${date} by ${row.updated_by_name}` : `Saved ${date}`
}
</script>

<template>
  <UDashboardPanel id="layouts">
    <template #header>
      <UDashboardNavbar title="Layouts" />
    </template>

    <template #body>
      <div class="mx-auto max-w-3xl space-y-4 py-2">
        <p class="text-sm text-muted">
          A layout designs every page of a kind at once — the title, the date, the cover and where
          the content goes. Pages without one use the theme's template.
        </p>
        <div v-if="isLoading" class="space-y-2" data-test="layouts-loading">
          <USkeleton class="h-14 w-full" />
          <USkeleton class="h-14 w-full" />
        </div>
        <UAlert
          v-else-if="error"
          color="error"
          variant="subtle"
          title="Couldn’t load the layouts"
          :description="error.message"
        />
        <p
          v-else-if="(rows ?? []).length === 0"
          class="text-sm text-muted"
          data-test="layouts-empty"
        >
          No content type is published on the site yet. Layouts appear here for every publicly
          delivered type.
        </p>
        <ul
          v-else
          class="divide-y divide-default rounded-lg border border-default"
          data-test="layouts-list"
        >
          <li
            v-for="row in rows"
            :key="`${row.surface}:${row.target}`"
            class="flex items-center gap-4 p-4"
            :data-test="`layouts-row-${row.surface}-${row.target}`"
          >
            <UIcon name="i-lucide-layout-template" class="size-5 shrink-0 text-muted" />
            <div class="min-w-0 flex-1">
              <p class="font-medium">{{ row.label }}</p>
              <p class="text-xs text-muted">
                <UBadge
                  :color="row.state === 'custom' ? 'primary' : 'neutral'"
                  variant="subtle"
                  size="sm"
                  :data-test="`layouts-state-${row.surface}-${row.target}`"
                >
                  {{ stateLabel(row) }}
                </UBadge>
                <span v-if="savedLine(row)" class="ms-2">{{ savedLine(row) }}</span>
              </p>
              <p
                v-if="!row.enabled && row.reason"
                class="mt-1 text-xs text-muted"
                data-test="layouts-reason"
              >
                {{ row.reason }}
              </p>
            </div>
            <UButton
              v-if="row.enabled && canEdit"
              size="sm"
              variant="outline"
              color="neutral"
              :to="`/layouts/${row.surface}/${row.target}`"
              :data-test="`layouts-edit-${row.surface}-${row.target}`"
            >
              Edit
            </UButton>
          </li>
        </ul>
        <p
          v-if="(rows ?? []).length > 0 && !canEdit"
          class="text-xs text-muted"
          data-test="layouts-no-edit"
        >
          Editing a layout needs the Manage templates permission.
        </p>
      </div>
    </template>
  </UDashboardPanel>
</template>
