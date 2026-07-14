<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTenantTarget } from '@/composables/useTenantTarget'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import {
  useTenantMembers,
  useTenantMemberMutations,
  type TenantRole,
  type TenantMember,
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

// Human label for a member: full name → email → username (never the raw uuid).
function memberLabel(m: TenantMember): string {
  return m.name || m.email || m.username || 'Unknown user'
}
// Show the email underneath only when the primary label isn't already the email.
function memberSubtitle(m: TenantMember): string {
  const primary = memberLabel(m)
  return m.email && m.email !== primary ? m.email : ''
}
function memberInitial(m: TenantMember): string {
  return memberLabel(m).charAt(0).toUpperCase()
}
function statusColor(status: string): 'success' | 'warning' | 'info' | 'neutral' {
  switch (status) {
    case 'active':
      return 'success'
    case 'suspended':
      return 'warning'
    case 'invited':
      return 'info'
    default:
      return 'neutral'
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

          <!-- Loading -->
          <div v-if="status === 'pending'" class="mt-6 grid gap-3">
            <USkeleton v-for="i in 3" :key="i" class="h-16 w-full rounded-lg" />
          </div>

          <!-- Empty -->
          <div
            v-else-if="(members ?? []).length === 0"
            class="mt-6 flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-default px-6 py-14 text-center"
          >
            <div class="flex size-11 items-center justify-center rounded-full bg-elevated">
              <UIcon name="i-lucide-users" class="size-5 text-dimmed" />
            </div>
            <p class="text-sm font-medium text-default">No members yet</p>
            <p class="text-sm text-muted">Add someone by email to give them access to this workspace.</p>
          </div>

          <!-- List -->
          <div v-else class="mt-6 overflow-hidden rounded-lg border border-default">
            <ul class="divide-y divide-default" role="list">
              <li
                v-for="member in members ?? []"
                :key="member.user_uuid"
                class="flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3.5 transition-colors hover:bg-elevated/40"
                :data-testid="`member-row-${member.user_uuid}`"
              >
                <UAvatar :text="memberInitial(member)" size="md" class="shrink-0" />
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium text-default">{{ memberLabel(member) }}</p>
                  <p v-if="memberSubtitle(member)" class="truncate text-xs text-muted">
                    {{ memberSubtitle(member) }}
                  </p>
                </div>
                <RolePicker
                  :model-value="member.role as TenantRole"
                  :roles="assignableRoles"
                  @update:model-value="
                    mutate(() =>
                      mutations.setRole.mutateAsync({ user_uuid: member.user_uuid, role: $event }),
                    )
                  "
                />
                <UBadge
                  :label="member.status"
                  :color="statusColor(member.status)"
                  variant="subtle"
                  size="xs"
                  class="shrink-0 capitalize"
                />
                <UTooltip text="Remove member">
                  <UButton
                    icon="i-lucide-user-minus"
                    color="error"
                    variant="ghost"
                    size="sm"
                    aria-label="Remove member"
                    @click="mutate(() => mutations.remove.mutateAsync(member.user_uuid))"
                  />
                </UTooltip>
              </li>
            </ul>
          </div>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
