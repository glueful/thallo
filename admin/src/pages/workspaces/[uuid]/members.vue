<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTenantTarget } from '@/composables/useTenantTarget'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import {
  useTenantMembers,
  useTenantMemberMutations,
  type TenantRole,
  fetchAssignableRoles,
  type AssignableTenantRole,
} from '@/queries/tenantMembers'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const router = useRouter()
const uuid = computed(() => String(route.params.uuid ?? ''))
const access = useTenancyAccessStore()
const { ensureTargetSelected, selectedUuid } = useTenantTarget()
const targetReady = ref(false)
const denied = ref(false)
// Drive the tenant-scoped APIs off the BOUND workspace, not the route uuid: the backend requires the
// path tenant to equal the X-Tenant-Id header, so keying on the route uuid would 403 the instant the
// sidebar switcher changes the active workspace (and the global 403-recovery would flick us back).
const targetUuid = computed(() => selectedUuid.value ?? '')
const enabled = computed(
  () => targetReady.value && access.access.manage_members && targetUuid.value !== '',
)
const { data: members, status } = useTenantMembers(targetUuid, enabled)
const mutations = useTenantMemberMutations(targetUuid)
const error = ref<string | null>(null)
const assignableRoles = ref<AssignableTenantRole[]>([])

watch(
  uuid,
  async (target) => {
    targetReady.value = false
    denied.value = false
    if (!target || !(await ensureTargetSelected(target))) {
      denied.value = true
      return
    }
    targetReady.value = true
    assignableRoles.value = await fetchAssignableRoles()
  },
  { immediate: true },
)

// Follow the sidebar switcher: when the active workspace changes while this page is open, replace the
// URL with that workspace's members so the route (and this page's target) stay in step.
watch(selectedUuid, (next) => {
  if (next && next !== uuid.value) {
    void router.replace(`/workspaces/${next}/members`)
  }
})

async function mutate(operation: () => Promise<unknown>): Promise<void> {
  error.value = null
  try {
    await operation()
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Membership update failed.'
  }
}
</script>

<template>
  <UDashboardPanel id="tenant-members">
    <template #header>
      <UDashboardNavbar title="Members" />
    </template>
    <template #body>
      <div class="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6">
        <UEmpty
          v-if="denied"
          variant="naked"
          icon="i-lucide-shield-x"
          title="Workspace unavailable"
        />
        <template v-else-if="targetReady">
          <MemberAddForm
            v-if="access.access.manage_members"
            :busy="mutations.add.isLoading.value"
            :error="error"
            :roles="assignableRoles"
            @submit="mutate(() => mutations.add.mutateAsync($event))"
          />
          <p v-if="error" class="mt-4 text-sm text-error" role="alert" data-testid="member-error">
            {{ error }}
          </p>
          <div v-if="status === 'pending'" class="grid gap-3 py-6">
            <USkeleton v-for="i in 3" :key="i" class="h-16 w-full" />
          </div>
          <ul v-else class="mt-6 divide-y divide-default" role="list">
            <li
              v-for="member in members ?? []"
              :key="member.user_uuid"
              class="flex flex-wrap items-center gap-3 py-4"
              :data-testid="`member-row-${member.user_uuid}`"
            >
              <code class="min-w-0 flex-1 break-all text-sm">{{ member.user_uuid }}</code>
              <RolePicker
                :model-value="member.role as TenantRole"
                :roles="assignableRoles"
                @update:model-value="
                  mutate(() =>
                    mutations.setRole.mutateAsync({ user_uuid: member.user_uuid, role: $event }),
                  )
                "
              />
              <UBadge color="neutral" variant="subtle">{{ member.status }}</UBadge>
              <UButton
                icon="i-lucide-user-minus"
                color="error"
                variant="ghost"
                aria-label="Remove member"
                @click="mutate(() => mutations.remove.mutateAsync(member.user_uuid))"
              />
            </li>
          </ul>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
