<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import {
  useNavMenus,
  useNavMenu,
  useNavigationMutations,
  type NavMenuSummary,
  type NavTreeItem,
} from '@/queries/navigation'
import { useLocales } from '@/queries/locales'
import { useContentTypes } from '@/queries/contentTypes'
import ReferencePicker from '@/fields/components/ReferencePicker.vue'
import CapabilityErrorPanel from '@/components/CapabilityErrorPanel.vue'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { useNotify } from '@/composables/useNotify'
import { ApiError } from '@/api/errors'
import MenuTreeEditor from './components/MenuTreeEditor.vue'

definePage({ meta: { requiresAuth: true } })

// This page gates locally (no `meta.requiresCapability`) because a genuinely disabled
// pack should EXPLAIN itself in place, not redirect home. The states are explicit and
// never conflated: capability loading → skeleton; capability-discovery error → the
// shared Retry panel; ready-but-disabled → the lock message; enabled → the editor
// (whose menu list has its own loading/empty states). `enabled` is true only at
// `ready`, so the menus query never fires on an unknown capability state.
const caps = useCapabilitiesStore()
const enabled = computed(() => caps.status === 'ready' && caps.isEnabled('thallo.navigation'))
const retryingCaps = ref(false)
async function retryCaps(): Promise<void> {
  retryingCaps.value = true
  try {
    await caps.retry()
  } finally {
    retryingCaps.value = false
  }
}
const { success, error: notifyError } = useNotify()

const { data: menus, isLoading: menusLoading } = useNavMenus(enabled)
const { data: localeRows } = useLocales()
const locales = computed(() => (localeRows.value ?? []).map((l) => l.code))

const selected = ref('')

// Reconcile selection ONLY when it's invalid: first load (empty), post-delete, or a
// refetch that dropped the slug. A still-valid selection — including one whose row just
// moved in a reorder — is left untouched (selection follows slug, never index).
watch(
  menus,
  (rows) => {
    const list = rows ?? []
    if (!list.some((m) => m.slug === selected.value)) {
      selected.value = list.length > 0 ? list[0]!.slug : ''
    }
  },
  { immediate: true },
)

// Create-menu modal (replaces the sidebar form).
const createOpen = ref(false)

// Drag mirror for the sidebar list — kept in sync with the fetched list; the reorder
// mutation commits it. VueDraggable binds this so reordering is smooth without touching
// `selected`.
const menuOrder = ref<NavMenuSummary[]>([])
watch(
  menus,
  (rows) => {
    menuOrder.value = [...(rows ?? [])]
  },
  { immediate: true },
)

const locale = ref('')
watch(locales, (codes) => {
  if (locale.value === '' && codes.length > 0) locale.value = codes[0]!
})

const { data: detail, refetch } = useNavMenu(selected, () => locale.value || 'en', enabled)
const mutations = useNavigationMutations()

async function commitOrder(): Promise<void> {
  try {
    await mutations.reorder.mutateAsync(menuOrder.value.map((m) => m.slug))
  } catch (e) {
    notifyError(e, 'Couldn’t reorder menus')
  }
}

// Keyboard fallback (overflow "Move up/down"): swap with the neighbour, then commit.
async function moveMenu(index: number, delta: number): Promise<void> {
  const target = index + delta
  if (target < 0 || target >= menuOrder.value.length) return
  const next = [...menuOrder.value]
  const [row] = next.splice(index, 1)
  next.splice(target, 0, row!)
  menuOrder.value = next
  await commitOrder()
}

// The page owns the WORKING tree (a reactive clone). A locale switch refetches badges for
// the new locale; unsaved edits are preserved by merging fetched target_* into the local
// tree by item uuid instead of replacing it.
const working = ref<NavTreeItem[]>([])
const dirty = ref(false)

// Add-page picker (nav-entry-items design): pick a published public entry,
// push an entry-kind item with EMPTY labels — the label inherits the page
// title until an editor overrides it.
const { data: pageTypes } = useContentTypes()
const addPageOpen = ref(false)
const addPageType = ref('')
const pageTypeOptions = computed(() =>
  // Non-public types stay VISIBLE but disabled with the reason: they can
  // never render a working nav link (the live site 404s them even when
  // published) — silently hiding them just looks like an empty dropdown.
  (pageTypes.value ?? []).map((t) => ({
    label: (t.name ?? t.slug ?? '') + (t.public_delivery ? '' : ' — not publicly delivered'),
    value: t.slug ?? '',
    disabled: !t.public_delivery,
  })),
)
const pickedEntry = ref('')
// The picker's `picked` event carries the display title so the new row's
// label placeholder shows the inherited page title immediately — the server
// only computes target_title on the next tree load.
function onEntryPicked({ uuid, title }: { uuid: string; title: string }) {
  working.value.push({
    kind: 'entry',
    entry_uuid: uuid,
    labels: {},
    descriptions: {},
    children: [],
    target_title: title || null,
  })
  dirty.value = true
  pickedEntry.value = ''
  addPageOpen.value = false
}

function mergeBadges(local: NavTreeItem[], fetched: NavTreeItem[]): void {
  const byUuid = new Map<string, NavTreeItem>()
  const walk = (items: NavTreeItem[]): void => {
    for (const item of items) {
      if (item.uuid) byUuid.set(item.uuid, item)
      walk(item.children)
    }
  }
  walk(fetched)
  const apply = (items: NavTreeItem[]): void => {
    for (const item of items) {
      const source = item.uuid ? byUuid.get(item.uuid) : undefined
      if (source) {
        item.target_status = source.target_status
        item.target_url = source.target_url
        item.target_title = source.target_title
      }
      apply(item.children)
    }
  }
  apply(local)
}

watch(detail, (d) => {
  if (!d) return
  if (dirty.value) {
    mergeBadges(working.value, d.items)
  } else {
    working.value = JSON.parse(JSON.stringify(d.items)) as NavTreeItem[]
  }
})

// New-menu form
const newSlug = ref('')
const newName = ref('')

async function createMenu(): Promise<void> {
  try {
    await mutations.create.mutateAsync({ slug: newSlug.value.trim(), name: newName.value.trim() })
    success('Menu created')
    selected.value = newSlug.value.trim()
    newSlug.value = ''
    newName.value = ''
    createOpen.value = false
  } catch (e) {
    notifyError(e, 'Couldn’t create the menu')
  }
}

async function deleteMenu(slug: string): Promise<void> {
  try {
    await mutations.remove.mutateAsync(slug)
    if (selected.value === slug) selected.value = ''
    success('Menu deleted')
  } catch (e) {
    notifyError(e, 'Couldn’t delete the menu')
  }
}

// Rename modal — targets a specific row (menu.slug), independent of the current
// selection, so any menu can be renamed straight from its overflow menu.
const renameOpen = ref(false)
const renameSlug = ref('')
const renameName = ref('')

function openRename(menu: NavMenuSummary): void {
  renameSlug.value = menu.slug
  renameName.value = menu.name
  renameOpen.value = true
}

async function submitRename(): Promise<void> {
  const name = renameName.value.trim()
  if (name === '') return
  try {
    await mutations.rename.mutateAsync({ slug: renameSlug.value, name })
    success('Menu renamed')
    renameOpen.value = false
  } catch (e) {
    notifyError(e, 'Couldn’t rename the menu')
  }
}

// Delete confirmation — destructive, so it goes through a modal that names the
// target before calling the shared deleteMenu() (which clears the selection).
const deleteOpen = ref(false)
const deleteSlug = ref('')
const deleteName = ref('')

function openDelete(menu: NavMenuSummary): void {
  deleteSlug.value = menu.slug
  deleteName.value = menu.name
  deleteOpen.value = true
}

async function confirmDelete(): Promise<void> {
  await deleteMenu(deleteSlug.value)
  deleteOpen.value = false
}

async function save(): Promise<void> {
  if (!detail.value) return
  try {
    await mutations.save.mutateAsync({
      slug: detail.value.slug,
      lockVersion: detail.value.lock_version,
      items: working.value,
      locale: locale.value || 'en',
    })
    dirty.value = false
    success('Menu saved')
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) {
      // Someone else changed the menu since we loaded it: drop local edits and reload.
      dirty.value = false
      await refetch()
      notifyError(e, 'The menu changed since you loaded it — reloaded the latest version')
      return
    }
    notifyError(e, 'Couldn’t save the menu')
  }
}
</script>

<template>
  <UDashboardPanel id="navigation" data-test="nav-page">
    <template #header>
      <UDashboardNavbar title="Navigation">
        <template #right>
          <UButton
            v-if="enabled"
            icon="i-lucide-plus"
            data-test="nav-menu-new"
            @click="() => { createOpen = true }"
          >
            New menu
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!-- Capability still resolving: neutral skeleton — never the lock message, which
           would claim "disabled" before anyone knows. -->
      <div v-if="!caps.settled" class="flex flex-col gap-3 p-4" data-testid="nav-caps-loading">
        <USkeleton class="h-8 w-64" />
        <USkeleton class="h-6 w-full" />
        <USkeleton class="h-6 w-full" />
        <USkeleton class="h-6 w-2/3" />
      </div>

      <!-- Capability discovery FAILED: recoverable error, not "disabled". -->
      <CapabilityErrorPanel
        v-else-if="caps.status === 'error'"
        :retrying="retryingCaps"
        @retry="retryCaps"
      />

      <!-- Capability genuinely off (discovery succeeded): one clear empty state, no list, no create. -->
      <div
        v-else-if="!enabled"
        class="flex h-full flex-col items-center justify-center gap-2 text-muted"
        data-testid="nav-caps-disabled"
      >
        <UIcon name="i-lucide-lock" class="size-8" />
        <p class="text-sm">Navigation isn’t enabled.</p>
      </div>

      <div v-else class="flex h-full min-h-0 flex-col gap-6 lg:flex-row">
        <aside class="w-full shrink-0 lg:w-80" data-test="nav-menu-list">
          <VueDraggable
            v-model="menuOrder"
            handle="[data-test='nav-menu-drag']"
            :animation="150"
            class="space-y-1"
            @end="commitOrder"
          >
            <div
              v-for="(menu, i) in menuOrder"
              :key="menu.slug"
              class="group flex items-center gap-1 rounded pr-1"
              :class="selected === menu.slug ? 'bg-elevated border-l-2 border-primary' : 'hover:bg-elevated border-l-2 border-transparent'"
            >
              <UButton
                size="xs"
                variant="ghost"
                color="neutral"
                icon="i-lucide-grip-vertical"
                class="cursor-grab opacity-0 group-hover:opacity-100"
                aria-label="Drag to reorder"
                data-test="nav-menu-drag"
                @click.prevent
              />
              <button
                type="button"
                class="flex min-w-0 flex-1 items-center gap-3 px-2 py-2 text-left"
                data-test="nav-menu-row"
                :aria-current="selected === menu.slug ? 'true' : undefined"
                @click="selected = menu.slug"
              >
                <UIcon name="i-lucide-menu" class="text-muted size-4 shrink-0" />
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm font-medium">{{ menu.name }}</span>
                  <span class="text-muted block truncate text-xs">{{ menu.slug }}</span>
                </span>
                <UBadge color="neutral" variant="subtle" size="sm">{{ menu.item_count }}</UBadge>
              </button>
              <UDropdownMenu
                :items="[
                  [{ label: 'Rename', icon: 'i-lucide-pencil', onSelect: () => openRename(menu) }],
                  [
                    { label: 'Move up', icon: 'i-lucide-arrow-up', disabled: i === 0, onSelect: () => moveMenu(i, -1) },
                    { label: 'Move down', icon: 'i-lucide-arrow-down', disabled: i === menuOrder.length - 1, onSelect: () => moveMenu(i, 1) },
                  ],
                  [{ label: 'Delete', icon: 'i-lucide-trash-2', color: 'error', onSelect: () => openDelete(menu) }],
                ]"
                data-test="nav-menu-menu"
              >
                <UButton size="xs" variant="ghost" color="neutral" icon="i-lucide-ellipsis-vertical" aria-label="Menu actions" />
              </UDropdownMenu>
            </div>
          </VueDraggable>

          <!-- Menu list loading vs genuinely empty: "No menus yet" only after the fetch settles. -->
          <div v-if="menusLoading" class="space-y-2 px-1 py-2" data-testid="nav-menus-loading">
            <USkeleton v-for="n in 3" :key="n" class="h-9 w-full" />
          </div>
          <p v-else-if="menuOrder.length === 0" class="text-muted px-3 py-2 text-sm">No menus yet.</p>
        </aside>

        <div class="min-w-0 flex-1">
          <div v-if="detail">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
              <h2 class="font-medium">{{ detail.name }}</h2>
              <div class="flex items-center gap-1" role="group" aria-label="Locale">
                <UButton
                  v-for="code in locales"
                  :key="code"
                  size="xs"
                  :variant="locale === code ? 'solid' : 'ghost'"
                  data-test="nav-locale-tab"
                  @click="() => { locale = code }"
                >
                  {{ code }}
                </UButton>
              </div>
            </div>

            <MenuTreeEditor :items="working" :locale="locale || 'en'" @changed="dirty = true" />

            <div class="mt-4 flex items-center gap-3">
              <UButton
                size="sm"
                variant="outline"
                data-test="tree-add-root"
                @click="() => { working.push({ kind: 'url', url: '/', labels: {}, descriptions: {}, children: [] }); dirty = true }"
              >
                Add link
              </UButton>
              <UButton
                size="sm"
                variant="outline"
                icon="i-lucide-file-text"
                data-test="tree-add-page"
                @click="() => { addPageOpen = !addPageOpen }"
              >
                Add page
              </UButton>
              <span class="grow" />
              <UButton size="sm" :disabled="!dirty" data-test="tree-save" @click="save">Save</UButton>
            </div>

            <div
              v-if="addPageOpen"
              class="border-default mt-3 grid gap-3 rounded border p-3 sm:grid-cols-2"
              data-test="add-page-picker"
            >
              <UFormField label="Content type">
                <USelect
                  v-model="addPageType"
                  :items="pageTypeOptions"
                  placeholder="Pick a type…"
                  class="w-full"
                  data-test="add-page-type"
                />
              </UFormField>
              <UFormField label="Page" hint="The menu label follows the page title until you override it.">
                <ReferencePicker
                  v-if="addPageType"
                  v-model="pickedEntry"
                  :target="addPageType"
                  @picked="onEntryPicked"
                />
                <p v-else class="text-muted pt-1.5 text-sm">Pick a type first.</p>
              </UFormField>
            </div>
          </div>

          <!-- No menu selected: zero-menu empty state with a CTA, else a light hint.
               While the list is still loading, claim nothing (no premature "No menus yet"). -->
          <div v-else class="flex h-full flex-col items-center justify-center gap-3 text-muted">
            <template v-if="!menusLoading">
              <UIcon name="i-lucide-list-tree" class="size-8" />
              <p class="text-sm">{{ menuOrder.length === 0 ? 'No menus yet.' : 'Select a menu.' }}</p>
              <UButton v-if="menuOrder.length === 0" icon="i-lucide-plus" @click="() => { createOpen = true }">
                New menu
              </UButton>
            </template>
          </div>
        </div>
      </div>

      <!-- Create-menu modal (teleports). MUST live inside #body: UDashboardPanel renders
           #header/#body as fallback of its DEFAULT slot, so anything placed in the default
           slot suppresses them. Enter in either input submits (real form); footer buttons remain. -->
      <UModal v-model:open="createOpen" title="New menu">
        <template #body>
          <form id="nav-create-form" data-test="nav-menu-create" class="space-y-3" @submit.prevent="createMenu">
            <UFormField label="Slug">
              <UInput v-model="newSlug" placeholder="slug (e.g. main)" class="w-full" />
            </UFormField>
            <UFormField label="Name">
              <UInput v-model="newName" placeholder="Name" class="w-full" />
            </UFormField>
            <button type="submit" class="sr-only" aria-hidden="true" tabindex="-1">Create</button>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton color="neutral" variant="ghost" @click="() => { createOpen = false }">Cancel</UButton>
            <UButton
              :disabled="newSlug.trim() === '' || newName.trim() === ''"
              @click="createMenu"
            >
              Create
            </UButton>
          </div>
        </template>
      </UModal>

      <!-- Rename-menu modal (same #body rule as the create modal). The slug is
           immutable; only the display name changes. Enter submits (real form). -->
      <UModal v-model:open="renameOpen" title="Rename menu">
        <template #body>
          <form data-test="nav-menu-rename" class="space-y-3" @submit.prevent="submitRename">
            <UFormField label="Name">
              <UInput v-model="renameName" placeholder="Name" class="w-full" />
            </UFormField>
            <button type="submit" class="sr-only" aria-hidden="true" tabindex="-1">Save</button>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton color="neutral" variant="ghost" @click="() => { renameOpen = false }">Cancel</UButton>
            <UButton
              :disabled="renameName.trim() === ''"
              data-test="nav-menu-rename-save"
              @click="submitRename"
            >
              Save
            </UButton>
          </div>
        </template>
      </UModal>

      <!-- Delete confirmation. Names the target and warns before the irreversible
           delete; the confirm button carries the nav-menu-delete hook. -->
      <UModal v-model:open="deleteOpen" title="Delete menu">
        <template #body>
          <p class="text-sm text-muted">
            Delete the menu “<span class="text-default font-medium">{{ deleteName }}</span>”? This removes the
            menu and all of its items. This can’t be undone.
          </p>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton color="neutral" variant="ghost" @click="() => { deleteOpen = false }">Cancel</UButton>
            <UButton color="error" data-test="nav-menu-delete" @click="confirmDelete">Delete</UButton>
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
