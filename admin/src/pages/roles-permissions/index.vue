<script setup lang="ts">
import { computed, reactive, ref, useTemplateRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { useRoles, useRoleMutations, type Role } from '@/queries/rbac'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import RolesListPane from './components/RolesListPane.vue'
import RoleDetailPane from './components/RoleDetailPane.vue'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const router = useRouter()
const { success, error: notifyError } = useNotify()
const { data: roles, status: rolesStatus } = useRoles()
const { create, update, remove } = useRoleMutations()

const selectedUuid = computed(() => (route.query.role as string | undefined) || undefined)
const selectedRole = computed(() => (roles.value ?? []).find((r) => r.uuid === selectedUuid.value))

function selectRole(role: Role) {
  router.replace({ query: { ...route.query, role: role.uuid } })
}
function clearSelection() {
  const q = { ...route.query }
  delete q.role
  router.replace({ query: q })
}

// ── Role create/edit (modals) ──
const showRoleForm = ref(false)
const editingRole = ref<Role | null>(null)
const schema = z.object({
  name: z.string().min(1, 'Name is required.'),
  slug: z
    .string()
    .min(1, 'Slug is required.')
    .regex(/^[a-z0-9_-]+$/, 'Lowercase letters, numbers, “-” and “_” only.'),
  description: z.string().optional(),
})
type Schema = z.output<typeof schema>
const roleForm = reactive({ name: '', slug: '', description: '' })
const slugTouched = ref(false)
const roleFormRef = useTemplateRef<Form<Schema>>('roleFormRef')

function slugify(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}
watch(
  () => roleForm.name,
  (name) => {
    // Slug is immutable on edit; only auto-derive when creating and untouched.
    if (editingRole.value === null && !slugTouched.value) roleForm.slug = slugify(name)
  },
)

function openCreate() {
  editingRole.value = null
  slugTouched.value = false
  Object.assign(roleForm, { name: '', slug: '', description: '' })
  showRoleForm.value = true
}
function openEdit(role: Role) {
  editingRole.value = role
  slugTouched.value = true
  Object.assign(roleForm, { name: role.name, slug: role.slug, description: role.description ?? '' })
  showRoleForm.value = true
}

async function onSubmitRole(event: FormSubmitEvent<Schema>) {
  try {
    if (editingRole.value !== null) {
      // slug is immutable; only name/description are editable.
      await update.mutateAsync({
        uuid: editingRole.value.uuid,
        input: { name: event.data.name, description: event.data.description || undefined },
      })
      success('Role updated')
    } else {
      const res = await create.mutateAsync({
        name: event.data.name,
        slug: event.data.slug,
        description: event.data.description || undefined,
      })
      success('Role created')
      // Select the new role so its permission editor opens.
      const uuid = (res as { data?: { uuid?: string } })?.data?.uuid
      if (uuid) router.replace({ query: { ...route.query, role: uuid } })
    }
    showRoleForm.value = false
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) roleFormRef.value?.setErrors(fieldErrors)
    notifyError(err, 'Couldn’t save role')
  }
}

// ── Delete role ──
const pendingDelete = ref<Role | null>(null)
async function confirmDelete() {
  if (pendingDelete.value === null) return
  const deletedUuid = pendingDelete.value.uuid
  try {
    await remove.mutateAsync(deletedUuid)
    success('Role deleted', `“${pendingDelete.value.name}” was removed.`)
    pendingDelete.value = null
    if (selectedUuid.value === deletedUuid) clearSelection()
  } catch (e) {
    notifyError(e, 'Couldn’t delete role')
  }
}
</script>

<template>
  <UDashboardPanel id="roles-permissions" :ui="{ body: 'overflow-hidden' }">
    <template #header>
      <UDashboardNavbar title="Roles &amp; Permissions" />
    </template>

    <template #body>
      <div class="flex h-full min-h-0 p-1">
        <!-- List pane: visible always on lg+; on mobile only when nothing is selected. -->
        <div
          class="min-h-0 lg:shrink-0 lg:border-e lg:border-default lg:pe-4"
          :class="selectedUuid ? 'hidden lg:block' : 'block'"
        >
          <RolesListPane
            class="h-full"
            :selected-uuid="selectedUuid"
            @select="selectRole"
            @create="openCreate"
          />
        </div>

        <!-- Detail pane: visible always on lg+; on mobile only when a role is selected. -->
        <div
          class="min-w-0 flex-1 flex-col lg:ps-6"
          :class="selectedUuid ? 'flex' : 'hidden lg:flex'"
        >
          <div v-if="!selectedUuid" class="m-auto text-center text-sm text-muted">
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
            <RoleDetailPane
              v-if="selectedRole"
              :key="selectedRole.uuid"
              :role="selectedRole"
              class="min-h-0 flex-1"
              @edit="openEdit(selectedRole)"
              @delete="pendingDelete = selectedRole"
            />
            <div v-else class="flex flex-1 items-center justify-center">
              <UIcon
                v-if="rolesStatus === 'pending'"
                name="i-lucide-loader-circle"
                class="size-6 animate-spin text-muted"
              />
              <p v-else class="text-sm text-muted">Role not found.</p>
            </div>
          </template>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <!-- Role create/edit -->
  <UModal v-model:open="showRoleForm" :title="editingRole ? 'Edit role' : 'New role'">
    <template #body>
      <UForm
        id="role-form"
        ref="roleFormRef"
        :schema="schema"
        :state="roleForm"
        class="space-y-4"
        @submit="onSubmitRole"
      >
        <UFormField label="Name" name="name">
          <UInput v-model="roleForm.name" placeholder="Editor" class="w-full" />
        </UFormField>
        <UFormField
          label="Slug"
          name="slug"
          :hint="editingRole ? 'Immutable' : 'Used in code/checks'"
        >
          <UInput
            v-model="roleForm.slug"
            :disabled="editingRole !== null"
            placeholder="editor"
            class="w-full"
            @update:model-value="slugTouched = true"
          />
        </UFormField>
        <UFormField label="Description" name="description">
          <UTextarea v-model="roleForm.description" :rows="2" class="w-full" />
        </UFormField>
      </UForm>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          @click="
            () => {
              showRoleForm = false
            }
          "
          >Cancel</UButton
        >
        <UButton
          type="submit"
          form="role-form"
          :loading="create.isLoading.value || update.isLoading.value"
        >
          {{ editingRole ? 'Save changes' : 'Create role' }}
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- Delete confirm -->
  <UModal
    :open="pendingDelete !== null"
    title="Delete role"
    @update:open="
      (v: boolean) => {
        if (!v) pendingDelete = null
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.name }}”</span>? Users assigned this
        role will lose it.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="remove.isLoading.value"
          @click="
            () => {
              pendingDelete = null
            }
          "
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          :loading="remove.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>
