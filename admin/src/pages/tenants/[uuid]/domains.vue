<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ApiError } from '@/api/errors'
import { useTenantTarget } from '@/composables/useTenantTarget'
import { useTenancyAccessStore } from '@/stores/tenancyAccess'
import {
  useTenantDomains,
  useTenantDomainMutations,
  type AddedTenantDomain,
} from '@/queries/tenantDomains'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const uuid = computed(() => String(route.params.uuid ?? ''))
const access = useTenancyAccessStore()
const { ensureTargetSelected } = useTenantTarget()
const targetReady = ref(false)
const denied = ref(false)
const enabled = computed(() => targetReady.value && access.access.manage_domains)
const { data: domains, status } = useTenantDomains(uuid, enabled)
const mutations = useTenantDomainMutations(uuid)
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

async function add(host: string): Promise<void> {
  error.value = null
  try {
    instructions.value = { ...(await mutations.add.mutateAsync(host)), host }
  } catch (caught) {
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
        <UEmpty v-if="denied" variant="naked" icon="i-lucide-shield-x" title="Tenant unavailable" />
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
                <p class="text-xs text-muted">{{ domain.verification_status }}</p>
              </div>
              <UBadge color="neutral" variant="subtle">{{ domain.status }}</UBadge>
              <UButton
                v-if="domain.verification_status !== 'verified'"
                icon="i-lucide-badge-check"
                color="neutral"
                variant="ghost"
                @click="mutate(() => mutations.verify.mutateAsync(domain.uuid))"
              >
                Verify
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
