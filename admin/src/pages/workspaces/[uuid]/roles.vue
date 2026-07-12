<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useTenantTarget } from '@/composables/useTenantTarget'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import {
  createWorkspaceRole,
  deleteWorkspaceRole,
  fetchWorkspaceRoles,
  previewRoleOverrides,
  saveRoleOverrides,
  updateWorkspaceRole,
  type RolesPayload,
  type WorkspaceRole,
} from '@/queries/tenantRoles'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const uuid = computed(() => String(route.params.uuid ?? ''))
const access = useTenancyAccessStore()
const { ensureTargetSelected } = useTenantTarget()
const payload = ref<RolesPayload>({ roles: [], catalog: {} })
const selected = ref<string>('owner')
const grants = ref<string[]>([])
const revokes = ref<string[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const preview = ref<{ added: string[]; removed: string[] } | null>(null)
const newSlug = ref('')
const newName = ref('')
const reassignTo = ref('viewer')

const role = computed(() => payload.value.roles.find((item) => item.slug === selected.value) ?? null)
const groups = computed(() => {
  const result: Record<string, Array<[string, RolesPayload['catalog'][string]]>> = {}
  for (const entry of Object.entries(payload.value.catalog)) {
    const group = entry[1].group
    ;(result[group] ??= []).push(entry)
  }
  return result
})
const replacementRoles = computed(() =>
  payload.value.roles.filter((item) => item.slug !== selected.value && item.status === 'active'),
)

function selectRole(next: WorkspaceRole): void {
  selected.value = next.slug
  grants.value = [...next.grants]
  revokes.value = [...next.revokes]
  preview.value = null
}

function effect(capability: string): string {
  if (grants.value.includes(capability)) return 'grant'
  if (revokes.value.includes(capability)) return 'revoke'
  return 'inherit'
}

function setEffect(capability: string, next: string): void {
  grants.value = grants.value.filter((item) => item !== capability)
  revokes.value = revokes.value.filter((item) => item !== capability)
  if (next === 'grant') grants.value.push(capability)
  if (next === 'revoke') revokes.value.push(capability)
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    payload.value = await fetchWorkspaceRoles()
    selectRole(payload.value.roles.find((item) => item.slug === selected.value) ?? payload.value.roles[0])
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Unable to load workspace roles.'
  } finally {
    loading.value = false
  }
}

async function run(operation: () => Promise<unknown>): Promise<void> {
  error.value = null
  try {
    await operation()
    await load()
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Role update failed.'
  }
}

async function showPreview(): Promise<void> {
  if (!role.value) return
  const result = await previewRoleOverrides(role.value.slug, grants.value, revokes.value)
  preview.value = { added: result.preview.added, removed: result.preview.removed }
}

watch(
  uuid,
  async (target) => {
    if (target && access.access.manage_roles && (await ensureTargetSelected(target))) await load()
  },
  { immediate: true },
)
</script>

<template>
  <UDashboardPanel id="workspace-roles">
    <template #header><UDashboardNavbar title="Roles" /></template>
    <template #body>
      <div class="mx-auto grid w-full max-w-6xl gap-6 px-4 py-6 lg:grid-cols-[15rem_1fr] sm:px-6">
        <aside>
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold">Workspace roles</h2>
          </div>
          <div class="space-y-1" data-testid="roles-list">
            <button
              v-for="item in payload.roles"
              :key="item.slug"
              type="button"
              class="flex w-full items-center justify-between rounded px-3 py-2 text-left text-sm hover:bg-elevated"
              :class="selected === item.slug ? 'bg-elevated font-medium' : ''"
              @click="selectRole(item)"
            >
              <span>{{ item.name }}</span>
              <UBadge v-if="item.status === 'disabled'" color="neutral" variant="subtle">Disabled</UBadge>
            </button>
          </div>
          <form
            class="mt-6 space-y-2 border-t border-default pt-4"
            @submit.prevent="run(() => createWorkspaceRole(newSlug, newName))"
          >
            <UInput v-model="newName" placeholder="Role name" aria-label="Role name" />
            <UInput v-model="newSlug" placeholder="role_slug" aria-label="Role slug" />
            <UButton type="submit" icon="i-lucide-plus" size="sm" :disabled="!newName || !newSlug">
              Add role
            </UButton>
          </form>
        </aside>

        <main v-if="role" class="min-w-0" data-testid="role-editor">
          <div class="flex flex-wrap items-center gap-3 border-b border-default pb-4">
            <div class="min-w-0 flex-1">
              <h2 class="truncate text-lg font-semibold">{{ role.name }}</h2>
              <code class="text-xs text-muted">{{ role.slug }}</code>
            </div>
            <template v-if="!role.builtin">
              <UButton
                :icon="role.status === 'active' ? 'i-lucide-pause' : 'i-lucide-play'"
                color="neutral"
                variant="outline"
                @click="run(() => updateWorkspaceRole(role!.slug, { status: role!.status === 'active' ? 'disabled' : 'active' }))"
              >
                {{ role.status === 'active' ? 'Disable' : 'Enable' }}
              </UButton>
              <USelect
                v-model="reassignTo"
                :items="replacementRoles.map((item) => ({ label: item.name, value: item.slug }))"
                value-key="value"
                class="min-w-36"
              />
              <UButton
                icon="i-lucide-trash-2"
                color="error"
                variant="ghost"
                @click="run(() => deleteWorkspaceRole(role!.slug, reassignTo))"
              >Delete</UButton>
            </template>
          </div>

          <p v-if="error" class="mt-4 text-sm text-error" role="alert">{{ error }}</p>
          <div v-if="loading" class="space-y-3 py-6"><USkeleton v-for="i in 5" :key="i" class="h-10" /></div>
          <div v-else class="space-y-6 py-6">
            <section v-for="(capabilities, group) in groups" :key="group">
              <h3 class="mb-2 text-sm font-semibold">{{ group }}</h3>
              <div class="divide-y divide-default border-y border-default">
                <div v-for="[slug, definition] in capabilities" :key="slug" class="flex items-center gap-4 py-3">
                  <div class="min-w-0 flex-1">
                    <p class="text-sm">{{ definition.label }}</p>
                    <code class="text-xs text-muted">{{ slug }}</code>
                  </div>
                  <USelect
                    :model-value="effect(slug)"
                    :items="role.builtin ? ['inherit', 'grant', 'revoke'] : ['inherit', 'grant']"
                    class="w-32"
                    :data-testid="`capability-toggle-${slug}`"
                    @update:model-value="setEffect(slug, String($event))"
                  />
                </div>
              </div>
            </section>
          </div>

          <div class="flex flex-wrap items-center gap-3 border-t border-default pt-4">
            <UButton data-testid="overrides-preview" color="neutral" variant="outline" @click="showPreview">
              Preview
            </UButton>
            <UButton
              data-testid="overrides-save"
              icon="i-lucide-save"
              @click="run(() => saveRoleOverrides(role!.slug, grants, revokes))"
            >Save</UButton>
            <span v-if="preview" class="text-xs text-muted">
              +{{ preview.added.length }} / -{{ preview.removed.length }}
            </span>
          </div>
        </main>
      </div>
    </template>
  </UDashboardPanel>
</template>
