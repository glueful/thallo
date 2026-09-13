<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUser, userDisplayName, useUserAdminMutations } from '@/queries/users'
import { useSessionStore } from '@/stores/session'
import { useNotify } from '@/composables/useNotify'
import UserDetailsForm from './UserDetailsForm.vue'
import UserPermissionsTab from './UserPermissionsTab.vue'

const props = defineProps<{ uuid: string }>()

const router = useRouter()
const route = useRoute()
const session = useSessionStore()
const { success, error: notifyError } = useNotify()
const { remove } = useUserAdminMutations()
const { data: user, status } = useUser(() => props.uuid)

const tab = ref<'details' | 'permissions'>('details')
const tabItems = [
  { label: 'Details', value: 'details' },
  { label: 'Permissions', value: 'permissions' },
]

const pendingDelete = ref(false)
const isSelf = computed(() => session.user?.uuid === props.uuid)

async function confirmDelete() {
  try {
    await remove.mutateAsync(props.uuid)
    success(
      'User deleted',
      user.value ? `“${userDisplayName(user.value)}” was removed.` : undefined,
    )
    pendingDelete.value = false
    // Clear only ?user=, preserving any other query params.
    const query = { ...route.query }
    delete query.user
    router.replace({ query })
  } catch (e) {
    notifyError(e, 'Couldn’t delete user')
  }
}

function fmtDate(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { dateStyle: 'medium' })
}
</script>

<template>
  <div class="flex h-full min-h-0 flex-col">
    <div v-if="status === 'pending'" class="flex flex-1 items-center justify-center">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
    </div>

    <template v-else-if="user">
      <header
        class="mb-4 flex items-start justify-between gap-3 rounded-xl border border-default p-4"
      >
        <div class="flex items-center gap-3">
          <UAvatar :text="(user.email || user.username || '?').charAt(0).toUpperCase()" size="lg" />
          <div>
            <h1 class="text-lg font-semibold text-highlighted">{{ userDisplayName(user) }}</h1>
            <p class="text-sm text-muted">{{ user.email }}</p>
          </div>
        </div>
        <div class="text-right text-xs text-muted">
          <p>
            User ID: <code>{{ user.uuid }}</code>
          </p>
          <p>Registered {{ fmtDate(user.created_at) }}</p>
          <UButton
            class="mt-1"
            color="error"
            variant="ghost"
            size="xs"
            icon="i-lucide-trash-2"
            :disabled="isSelf"
            :title="isSelf ? 'You cannot delete your own account' : undefined"
            @click="
              () => {
                pendingDelete = true
              }
            "
          />
        </div>
      </header>

      <UTabs v-model="tab" variant="link" :items="tabItems" :content="false" class="mb-4" />

      <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
        <UserDetailsForm v-if="tab === 'details'" :key="`details-${user.uuid}`" :user="user" />
        <UserPermissionsTab v-else :key="`perms-${user.uuid}`" :user="user" />
      </div>

      <UModal v-model:open="pendingDelete" title="Delete user">
        <template #body>
          <p class="text-sm text-muted">
            Delete <span class="text-default">“{{ userDisplayName(user) }}”</span>? They lose access
            immediately. The account is soft-deleted, so it can be restored later.
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
                  pendingDelete = false
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
  </div>
</template>
