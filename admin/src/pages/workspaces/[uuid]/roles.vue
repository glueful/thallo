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
const singleStoreMode = computed(() => route.path.startsWith('/settings/signup/roles'))
const access = useTenancyAccessStore()
const { ensureTargetSelected } = useTenantTarget()
const payload = ref<RolesPayload>({ roles: [], catalog: {} })
const selected = ref('')
const loading = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const preview = ref<{ added: string[]; removed: string[] } | null>(null)
const search = ref('')
const showCreate = ref(false)
const pendingDelete = ref<WorkspaceRole | null>(null)
const newSlug = ref('')
const newName = ref('')
const reassignTo = ref('viewer')

const role = computed(
  () => payload.value.roles.find((item) => item.slug === selected.value) ?? null,
)
const filteredRoles = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return payload.value.roles
  return payload.value.roles.filter(
    (item) => item.name.toLowerCase().includes(term) || item.slug.toLowerCase().includes(term),
  )
})
const replacementRoles = computed(() =>
  payload.value.roles.filter((item) => item.slug !== selected.value && item.status === 'active'),
)

function selectRole(next: WorkspaceRole): void {
  selected.value = next.slug
  preview.value = null
}

function clearSelection(): void {
  selected.value = ''
  preview.value = null
}

async function load(preferred = selected.value): Promise<void> {
  loading.value = true
  error.value = null
  try {
    payload.value = await fetchWorkspaceRoles(singleStoreMode.value)
    const next = payload.value.roles.find((item) => item.slug === preferred)
    if (next) selectRole(next)
    else if (!singleStoreMode.value && payload.value.roles[0]) selectRole(payload.value.roles[0])
    else clearSelection()
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Unable to load workspace roles.'
  } finally {
    loading.value = false
  }
}

async function run(operation: () => Promise<unknown>, preferred = selected.value): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await operation()
    await load(preferred)
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Role update failed.'
  } finally {
    busy.value = false
  }
}

async function createRole(): Promise<void> {
  const slug = newSlug.value
  await run(() => createWorkspaceRole(slug, newName.value, singleStoreMode.value), slug)
  if (error.value === null) {
    showCreate.value = false
    newSlug.value = ''
    newName.value = ''
  }
}

async function removeRole(): Promise<void> {
  if (!pendingDelete.value) return
  const deleting = pendingDelete.value
  await run(() => deleteWorkspaceRole(deleting.slug, reassignTo.value, singleStoreMode.value), '')
  if (error.value === null) pendingDelete.value = null
}

async function showPreview(grants: string[], revokes: string[]): Promise<void> {
  if (!role.value) return
  const result = await previewRoleOverrides(role.value.slug, grants, revokes, singleStoreMode.value)
  preview.value = { added: result.preview.added, removed: result.preview.removed }
}

async function savePermissions(grants: string[], revokes: string[]): Promise<void> {
  if (!role.value) return
  await run(
    () => saveRoleOverrides(role.value!.slug, grants, revokes, singleStoreMode.value),
    role.value.slug,
  )
}

watch(
  [uuid, singleStoreMode],
  async ([target, singleStore]) => {
    if (singleStore) {
      await load()
      return
    }
    if (target && access.access.manage_roles && (await ensureTargetSelected(target))) await load()
  },
  { immediate: true },
)
</script>

<template>
  <UDashboardPanel id="workspace-roles" :ui="{ body: 'overflow-hidden' }">
    <template #header>
      <UDashboardNavbar :title="singleStoreMode ? 'Signup roles' : 'Roles'">
        <template #leading>
          <UButton
            v-if="singleStoreMode"
            to="/settings/signup"
            icon="i-lucide-arrow-left"
            color="neutral"
            variant="ghost"
            aria-label="Back to signup settings"
            data-testid="signup-roles-back"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex h-full min-h-0 p-1">
        <div
          class="min-h-0 lg:shrink-0 lg:border-e lg:border-default lg:pe-4"
          :class="role ? 'hidden lg:block' : 'block'"
        >
          <div class="flex h-full min-h-0 w-full flex-col gap-3 lg:w-85 lg:shrink-0">
            <div class="flex items-center justify-between gap-2">
              <h2 class="text-lg font-semibold text-highlighted">Roles</h2>
              <UButton
                icon="i-lucide-plus"
                class="rounded-xl px-3"
                size="sm"
                aria-label="New role"
                data-testid="create-workspace-role"
                @click="showCreate = true"
              />
            </div>

            <UInput v-model="search" icon="i-lucide-search" placeholder="Search roles…" />

            <div class="min-h-0 flex-1 overflow-y-auto">
              <div v-if="loading" class="flex justify-center py-10">
                <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
              </div>
              <UEmpty
                v-else-if="!filteredRoles.length"
                icon="i-lucide-shield"
                title="No roles"
                :description="
                  search ? 'No roles match your search.' : 'Create a role to get started.'
                "
              />
              <div v-else class="flex flex-col gap-0.5" data-testid="roles-list">
                <button
                  v-for="item in filteredRoles"
                  :key="item.slug"
                  type="button"
                  class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors"
                  :class="selected === item.slug ? 'bg-elevated' : 'hover:bg-elevated/50'"
                  @click="selectRole(item)"
                >
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-default">{{ item.name }}</p>
                    <code class="truncate text-xs text-muted">{{ item.slug }}</code>
                  </div>
                  <UBadge
                    v-if="item.status === 'disabled'"
                    label="Disabled"
                    color="neutral"
                    variant="subtle"
                    size="xs"
                  />
                </button>
              </div>
            </div>

            <div class="border-t border-default pt-3 text-xs font-medium text-muted">
              {{ filteredRoles.length }} roles
            </div>
          </div>
        </div>

        <div class="min-w-0 flex-1 flex-col lg:ps-6" :class="role ? 'flex' : 'hidden lg:flex'">
          <div v-if="!role" class="m-auto text-center text-sm text-muted">
            <UIcon name="i-lucide-shield" class="mx-auto mb-2 size-6" />
            Select a role to view its permissions
          </div>
          <template v-else>
            <UButton
              class="mb-2 self-start lg:hidden"
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-arrow-left"
              label="Back"
              @click="clearSelection"
            />

            <header
              class="mb-4 flex items-start justify-between gap-3 rounded-xl border border-default p-4"
            >
              <div class="min-w-0">
                <div class="flex items-center gap-2">
                  <h1 class="text-lg font-semibold text-highlighted">{{ role.name }}</h1>
                  <UBadge
                    v-if="role.builtin"
                    label="Built-in"
                    color="neutral"
                    variant="subtle"
                    size="xs"
                  />
                  <UBadge
                    v-else-if="role.status === 'disabled'"
                    label="Disabled"
                    color="warning"
                    variant="subtle"
                    size="xs"
                  />
                </div>
                <code class="text-sm text-muted">{{ role.slug }}</code>
              </div>
              <div v-if="!role.builtin" class="flex shrink-0 items-center gap-1">
                <UButton
                  :icon="role.status === 'active' ? 'i-lucide-pause' : 'i-lucide-play'"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  :aria-label="role.status === 'active' ? 'Disable role' : 'Enable role'"
                  :loading="busy"
                  @click="
                    run(() =>
                      updateWorkspaceRole(
                        role!.slug,
                        { status: role!.status === 'active' ? 'disabled' : 'active' },
                        singleStoreMode,
                      ),
                    )
                  "
                />
                <UButton
                  icon="i-lucide-trash-2"
                  color="error"
                  variant="ghost"
                  size="xs"
                  aria-label="Delete role"
                  @click="pendingDelete = role"
                />
              </div>
            </header>

            <p v-if="error" class="mb-3 text-sm text-error" role="alert">{{ error }}</p>

            <div class="flex min-h-0 flex-1 flex-col">
              <div v-if="loading" class="flex flex-1 items-center justify-center py-16">
                <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
              </div>
              <TenantRolePermissionsEditor
                v-else
                :key="role.slug"
                :role="role"
                :catalog="payload.catalog"
                :busy="busy"
                :preview="preview"
                class="min-h-0 flex-1"
                @preview="showPreview"
                @save="savePermissions"
              />
            </div>
          </template>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="showCreate" title="New role">
    <template #body>
      <form id="workspace-role-form" class="space-y-4" @submit.prevent="createRole">
        <UFormField label="Name">
          <UInput v-model="newName" placeholder="Reviewer" class="w-full" />
        </UFormField>
        <UFormField label="Slug" hint="Immutable">
          <UInput v-model="newSlug" placeholder="reviewer" class="w-full" />
        </UFormField>
      </form>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" @click="showCreate = false">Cancel</UButton>
        <UButton
          type="submit"
          form="workspace-role-form"
          :loading="busy"
          :disabled="!newName || !newSlug"
          >Create role</UButton
        >
      </div>
    </template>
  </UModal>

  <UModal
    :open="pendingDelete !== null"
    title="Delete role"
    @update:open="
      (open: boolean) => {
        if (!open) pendingDelete = null
      }
    "
  >
    <template #body>
      <div class="space-y-4">
        <p class="text-sm text-muted">
          Delete <span class="text-default">“{{ pendingDelete?.name }}”</span>?
        </p>
        <UFormField label="Reassign members to">
          <USelect
            v-model="reassignTo"
            :items="replacementRoles.map((item) => ({ label: item.name, value: item.slug }))"
            value-key="value"
            class="w-full"
          />
        </UFormField>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" :disabled="busy" @click="pendingDelete = null">
          Cancel
        </UButton>
        <UButton color="error" icon="i-lucide-trash-2" :loading="busy" @click="removeRole">
          Delete
        </UButton>
      </div>
    </template>
  </UModal>
</template>
