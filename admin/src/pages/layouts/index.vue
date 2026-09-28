<script setup lang="ts">
import { computed, ref } from 'vue'
import { mintLayoutSession, removeLayout, useLayouts, type LayoutRow } from '@/queries/layouts'
import { useNotify } from '@/composables/useNotify'

// Site › Layouts (type layouts spec §6.1): each page kind that can have a layout — for each content
// type, its single post, its listing pages and its archives — and whether it renders through the
// theme's template or a custom layout. Edit opens the layout editor. Remove lives there, where the
// editing session and version are. A row that cannot have a layout says why, and where to fix it.
definePage({ meta: { requiresAuth: true } })

const { data, isLoading, error, refetch } = useLayouts()
const { success, error: notifyError } = useNotify()
const rows = computed(() => data.value?.rows)
const canEdit = computed(() => data.value?.canEdit === true)

function stateLabel(row: LayoutRow): string {
  return row.state === 'custom' ? 'Custom layout' : 'Theme template'
}

/**
 * A turned-off row that keeps a custom layout — its pages off the site: the editor cannot open it, so
 * it is removed from here, with a session opened only for that (the server refuses to save to it).
 */
function removable(row: LayoutRow): boolean {
  return !row.enabled && row.state === 'custom' && canEdit.value
}
const removing = ref<LayoutRow | null>(null)
const removeBusy = ref(false)
async function confirmRemove(): Promise<void> {
  const row = removing.value
  if (!row) return
  removeBusy.value = true
  try {
    const session = await mintLayoutSession(row.surface, row.target)
    await removeLayout(row.surface, row.target, {
      token: session.token,
      expected_lock_version: row.lock_version,
    })
    success('Layout removed', `${row.label} uses the theme’s design whenever its pages return.`)
    removing.value = null
    await refetch()
  } catch (e) {
    notifyError(e, 'Couldn’t remove the layout')
    await refetch()
  } finally {
    removeBusy.value = false
  }
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
              <p v-if="!row.enabled && row.reason" class="mt-1 text-xs text-muted">
                <span data-test="layouts-reason">{{ row.reason }}</span>
                <!-- The one link a surface gives today: listing pages off in Settings › General. -->
                <RouterLink
                  v-if="row.link"
                  :to="row.link"
                  class="ms-1 font-medium text-primary hover:underline"
                  data-test="layouts-link"
                >
                  Turn on listing pages
                </RouterLink>
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
            <UButton
              v-else-if="removable(row)"
              size="sm"
              variant="outline"
              color="error"
              :data-test="`layouts-remove-${row.surface}-${row.target}`"
              @click="removing = row"
            >
              Remove
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

  <UModal
    :open="removing !== null"
    title="Remove this layout?"
    @update:open="(open: boolean) => !open && (removing = null)"
  >
    <template #body>
      <p class="text-sm" data-test="layouts-remove-dialog">
        {{ removing?.label }}: its pages are not on the site now. Removed, they use the theme’s
        design whenever they return.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton variant="ghost" color="neutral" @click="removing = null">Keep it</UButton>
        <UButton
          color="error"
          :loading="removeBusy"
          data-test="layouts-remove-confirm"
          @click="confirmRemove()"
        >
          Remove layout
        </UButton>
      </div>
    </template>
  </UModal>
</template>
