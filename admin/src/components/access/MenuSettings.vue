<script setup lang="ts">
// A role's or user's menus and landing page (Users & Access): each sidebar item shown or hidden,
// and where signing in lands. For a user, each item can also follow their roles, which is where
// every item starts. Tidying only — the note says so, since a hidden page still opens by address.
import { computed, onMounted, ref } from 'vue'
import { useVisibleNav } from '@/navigation/sidebar'
import { menuCatalog, withContentTypes, type MenuEntry } from '@/navigation/hiddenMenus'
import { useContentTypes } from '@/queries/contentTypes'
import {
  fetchUiSettings,
  useSaveUiSettings,
  type MenuState,
  type UiSubject,
} from '@/queries/uiSettings'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ subject: UiSubject; uuid: string }>()

const { success, error: notifyError } = useNotify()
const save = useSaveUiSettings()
const nav = useVisibleNav()
const { data: contentTypes } = useContentTypes()

/** Every item that can be hidden, in sidebar order, main then utilities. */
const catalog = computed<MenuEntry[]>(() => [
  ...menuCatalog(withContentTypes(nav.value[0], contentTypes.value ?? [])),
  ...menuCatalog(nav.value[1]),
])
const groups = computed(() => {
  const out: { label: string | null; items: MenuEntry[] }[] = []
  for (const entry of catalog.value) {
    const last = out[out.length - 1]
    if (last && last.label === entry.group) last.items.push(entry)
    else out.push({ label: entry.group, items: [entry] })
  }
  return out
})

// A role only hides; a user's item can also follow their roles (`inherit`: no setting of its own).
type Choice = MenuState | 'inherit'
const CHOICES = computed<{ value: Choice; label: string }[]>(() =>
  props.subject === 'users'
    ? [
        { value: 'inherit', label: 'As roles' },
        { value: 'shown', label: 'Show' },
        { value: 'hidden', label: 'Hide' },
      ]
    : [
        { value: 'shown', label: 'Show' },
        { value: 'hidden', label: 'Hide' },
      ],
)
const unset: Choice = props.subject === 'users' ? 'inherit' : 'shown'

const menus = ref<Record<string, MenuState>>({})
const HOME = '__home'
const landing = ref<string>(HOME)
const loading = ref(true)
const saving = ref(false)

const landingItems = computed(() => [
  { label: 'Home (the default)', value: HOME },
  ...catalog.value
    .filter((e) => e.path !== '/')
    .map((e) => ({ label: e.group ? `${e.group} › ${e.label}` : e.label, value: e.path })),
])

function choiceOf(path: string): Choice {
  return menus.value[path] ?? unset
}
function choose(path: string, choice: Choice): void {
  const next = { ...menus.value }
  // A role's "shown" and a user's "as roles" are the absence of a setting.
  if (choice === 'inherit' || (props.subject === 'roles' && choice === 'shown')) delete next[path]
  else next[path] = choice
  menus.value = next
}

onMounted(async () => {
  try {
    const settings = await fetchUiSettings(props.subject, props.uuid)
    menus.value = { ...settings.menus }
    landing.value = settings.landing ?? HOME
  } catch (e) {
    notifyError(e, 'Couldn’t load the menu settings')
  } finally {
    loading.value = false
  }
})

async function onSave(): Promise<void> {
  saving.value = true
  try {
    await save(props.subject, props.uuid, {
      menus: menus.value,
      landing: landing.value === HOME ? null : landing.value,
    })
    success('Menus saved', 'They apply the next time the sidebar loads.')
  } catch (e) {
    notifyError(e, 'Couldn’t save the menu settings')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-5" data-test="menu-settings">
    <p class="text-sm text-muted" data-test="menu-settings-note">
      {{
        subject === 'roles'
          ? 'Choose what everyone with this role sees in the sidebar, and where signing in takes them.'
          : 'Choose what this user sees in the sidebar, and where signing in takes them. Their own choice beats their roles’.'
      }}
      Hiding a menu only tidies the sidebar: the page still opens for anyone whose permissions allow
      it. To take access away, remove the permission.
    </p>

    <USkeleton v-if="loading" class="h-40" />
    <template v-else>
      <UFormField label="After signing in, go to">
        <USelect v-model="landing" :items="landingItems" class="w-full max-w-sm" />
      </UFormField>

      <div class="divide-y divide-default rounded-md border border-default">
        <section v-for="group in groups" :key="group.label ?? group.items[0]!.path" class="p-3">
          <h4
            v-if="group.label"
            class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted"
          >
            {{ group.label }}
          </h4>
          <div
            v-for="item in group.items"
            :key="item.path"
            class="flex items-center justify-between gap-3 py-1"
            :data-test="`menu-row-${item.path}`"
          >
            <span
              class="text-sm"
              :class="choiceOf(item.path) === 'hidden' ? 'text-muted line-through' : ''"
            >
              {{ item.label }}
            </span>
            <span class="inline-flex rounded-md bg-elevated p-0.5">
              <button
                v-for="c in CHOICES"
                :key="c.value"
                type="button"
                class="rounded px-2 py-0.5 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                :class="
                  choiceOf(item.path) === c.value
                    ? 'bg-default text-default shadow-sm'
                    : 'text-muted hover:text-default'
                "
                :aria-pressed="choiceOf(item.path) === c.value ? 'true' : 'false'"
                :aria-label="`${c.label}: ${item.label}`"
                :data-test="`menu-${c.value}-${item.path}`"
                @click="choose(item.path, c.value)"
              >
                {{ c.label }}
              </button>
            </span>
          </div>
        </section>
      </div>

      <div class="flex justify-end">
        <UButton :loading="saving" data-test="menu-settings-save" @click="onSave"
          >Save menus</UButton
        >
      </div>
    </template>
  </div>
</template>
