<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, apiErrorDetails } from '@/api/errors'
import { useTenantTarget } from '@/composables/useTenantTarget'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import {
  useTenantDomains,
  useTenantDomainMutations,
  type AddedTenantDomain,
} from '@/queries/tenantDomains'

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
  () => targetReady.value && access.access.manage_domains && targetUuid.value !== '',
)
const { data: domains, status } = useTenantDomains(targetUuid, enabled)
const mutations = useTenantDomainMutations(targetUuid)
const instructions = ref<(AddedTenantDomain & { host: string }) | null>(null)
const error = ref<string | null>(null)

watch(
  uuid,
  async (target) => {
    targetReady.value = false
    denied.value = false
    instructions.value = null
    if (!target || !(await ensureTargetSelected(target))) {
      denied.value = true
      return
    }
    targetReady.value = true
  },
  { immediate: true },
)

// Follow the sidebar switcher: when the active workspace changes while this page is open, replace the
// URL with that workspace's domains so the route (and this page's target) stay in step. Without this
// the URL would keep the old workspace's uuid while the list showed the newly-bound one.
watch(selectedUuid, (next) => {
  if (next && next !== uuid.value) {
    void router.replace(`/workspaces/${next}/domains`)
  }
})

async function add(host: string): Promise<void> {
  error.value = null
  try {
    instructions.value = { ...(await mutations.add.mutateAsync(host)), host }
  } catch (caught) {
    if (caught instanceof ApiError && caught.status === 409) {
      const details = apiErrorDetails(caught)
      if (details?.code === 'HOST_COOLDOWN' && typeof details.available_after === 'string') {
        error.value = `Host in cooldown - available after ${details.available_after}`
        return
      }
    }
    error.value =
      caught instanceof ApiError || caught instanceof Error
        ? caught.message
        : 'Could not add domain.'
  }
}

async function mutate(operation: () => Promise<unknown>): Promise<void> {
  error.value = null
  try {
    await operation()
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Domain update failed.'
  }
}
</script>

<template>
  <UDashboardPanel id="tenant-domains">
    <template #header>
      <UDashboardNavbar title="Domains" />
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
          <DomainAddForm
            v-if="access.access.manage_domains"
            :busy="mutations.add.isLoading.value"
            :error="error"
            @submit="add"
          />
          <DomainVerifyInstructions
            v-if="instructions"
            class="mt-6"
            :name="instructions.txt_record"
            :value="instructions.token"
            :busy="mutations.verify.isLoading.value"
            @verify="mutate(() => mutations.verify.mutateAsync(instructions!.uuid))"
          />
          <div v-if="status === 'pending'" class="grid gap-3 py-6">
            <USkeleton v-for="i in 3" :key="i" class="h-16 w-full" />
          </div>
          <ul v-else class="mt-6 divide-y divide-default" role="list">
            <li
              v-for="domain in domains ?? []"
              :key="domain.uuid"
              class="flex flex-wrap items-center gap-3 py-4"
              :data-testid="`domain-row-${domain.uuid}`"
            >
              <div class="min-w-0 flex-1">
                <p class="font-medium break-all">{{ domain.host }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                  <span>{{ domain.verification_status }}</span>
                  <span
                    v-if="domain.last_check_status"
                    :data-testid="`domain-check-${domain.uuid}`"
                  >
                    Last check: {{ domain.last_check_status }}
                  </span>
                  <span v-if="domain.consecutive_failures > 0">
                    {{ domain.consecutive_failures }} consecutive failures
                  </span>
                  <span v-if="domain.last_checked_at">{{ domain.last_checked_at }}</span>
                </div>
              </div>
              <UBadge
                :color="domain.verification_status === 'revoked' ? 'error' : 'neutral'"
                variant="subtle"
              >
                {{ domain.verification_status === 'revoked' ? 'Not resolving' : domain.status }}
              </UBadge>
              <UButton
                v-if="domain.verification_status === 'pending'"
                icon="i-lucide-badge-check"
                color="neutral"
                variant="ghost"
                :data-testid="`domain-verify-${domain.uuid}`"
                @click="mutate(() => mutations.verify.mutateAsync(domain.uuid))"
              >
                Verify
              </UButton>
              <UButton
                v-else-if="['verified', 'revoked'].includes(domain.verification_status)"
                icon="i-lucide-refresh-cw"
                color="neutral"
                variant="ghost"
                :loading="mutations.reverify.isLoading.value"
                :data-testid="`domain-reverify-${domain.uuid}`"
                @click="mutate(() => mutations.reverify.mutateAsync(domain.uuid))"
              >
                Re-verify now
              </UButton>
              <UButton
                v-if="domain.status === 'active'"
                icon="i-lucide-pause"
                color="neutral"
                variant="ghost"
                @click="mutate(() => mutations.disable.mutateAsync(domain.uuid))"
              >
                Disable
              </UButton>
              <UButton
                v-else
                icon="i-lucide-play"
                color="neutral"
                variant="ghost"
                @click="mutate(() => mutations.enable.mutateAsync(domain.uuid))"
              >
                Enable
              </UButton>
              <UButton
                icon="i-lucide-trash-2"
                color="error"
                variant="ghost"
                aria-label="Remove domain"
                @click="mutate(() => mutations.remove.mutateAsync(domain.uuid))"
              />
            </li>
          </ul>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
