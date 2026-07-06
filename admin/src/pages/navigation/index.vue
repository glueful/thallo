<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  useNavMenus,
  useNavMenu,
  useNavigationMutations,
  type NavTreeItem,
} from '@/queries/navigation'
import { useLocales } from '@/queries/locales'
import { useContentTypes } from '@/queries/contentTypes'
import ReferencePicker from '@/fields/components/ReferencePicker.vue'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { useNotify } from '@/composables/useNotify'
import { ApiError } from '@/api/errors'
import MenuTreeEditor from './components/MenuTreeEditor.vue'

definePage({ meta: { requiresAuth: true } })

const caps = useCapabilitiesStore()
const enabled = computed(() => caps.isEnabled('thallo.navigation'))
const { success, error: notifyError } = useNotify()

const { data: menus } = useNavMenus(enabled)
const { data: localeRows } = useLocales()
const locales = computed(() => (localeRows.value ?? []).map((l) => l.code))

const selected = ref('')
const locale = ref('')
watch(locales, (codes) => {
  if (locale.value === '' && codes.length > 0) locale.value = codes[0]!
})

const { data: detail, refetch } = useNavMenu(selected, () => locale.value || 'en', enabled)
const mutations = useNavigationMutations()

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
  <div class="space-y-6 p-6" data-test="nav-page">
    <h1 class="text-xl font-semibold">Navigation</h1>

    <div class="flex flex-col gap-6 lg:flex-row">
      <UCard class="lg:w-80 lg:shrink-0" data-test="nav-menu-list">
        <div class="space-y-2">
          <button
            v-for="menu in menus ?? []"
            :key="menu.slug"
            class="hover:bg-elevated flex w-full items-center justify-between rounded px-3 py-2 text-left"
            :class="{ 'bg-elevated': selected === menu.slug }"
            data-test="nav-menu-row"
            @click="selected = menu.slug"
          >
            <span class="truncate">{{ menu.name }}</span>
            <span class="text-muted text-xs">{{ menu.item_count }}</span>
          </button>
          <p v-if="(menus ?? []).length === 0" class="text-muted px-3 text-sm">No menus yet.</p>
        </div>

        <USeparator class="my-4" />
        <form class="space-y-2" data-test="nav-menu-create" @submit.prevent="createMenu">
          <UInput v-model="newSlug" size="sm" placeholder="slug (e.g. main)" class="w-full" />
          <UInput v-model="newName" size="sm" placeholder="Name" class="w-full" />
          <UButton type="submit" size="sm" :disabled="newSlug.trim() === '' || newName.trim() === ''">
            Create menu
          </UButton>
        </form>
      </UCard>

      <UCard v-if="detail" class="min-w-0 flex-1">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <h2 class="font-medium">{{ detail.name }}</h2>
            <UButton
              size="xs"
              color="error"
              variant="ghost"
              data-test="nav-menu-delete"
              @click="deleteMenu(detail.slug)"
            >
              Delete
            </UButton>
          </div>
          <div class="flex items-center gap-1" role="group" aria-label="Locale">
            <UButton
              v-for="code in locales"
              :key="code"
              size="xs"
              :variant="locale === code ? 'solid' : 'ghost'"
              data-test="nav-locale-tab"
              @click="
                () => {
                  locale = code
                }
              "
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
            @click="
              () => {
                working.push({ kind: 'url', url: '/', labels: {}, children: [] })
                dirty = true
              }
            "
          >
            Add link
          </UButton>
          <UButton
            size="sm"
            variant="outline"
            icon="i-lucide-file-text"
            data-test="tree-add-page"
            @click="
              () => {
                addPageOpen = !addPageOpen
              }
            "
          >
            Add page
          </UButton>
          <span class="grow" />
          <UButton size="sm" :disabled="!dirty" data-test="tree-save" @click="save">Save</UButton>
        </div>

        <div v-if="addPageOpen" class="border-default mt-3 grid gap-3 rounded border p-3 sm:grid-cols-2" data-test="add-page-picker">
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
      </UCard>

      <UCard v-else class="text-muted flex flex-1 items-center justify-center text-sm">
        Select or create a menu.
      </UCard>
    </div>
  </div>
</template>
