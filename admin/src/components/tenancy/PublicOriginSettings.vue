<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { fetchPublicOrigin, savePublicOrigin, type PublicOriginStatus } from '@/queries/publicOrigin'

// When embedded inside the Domain-routing card, drop the standalone card chrome + header.
const props = withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

const status = ref<PublicOriginStatus | null>(null)
const baseDomain = ref('')
const hostsText = ref('')
const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)

// Activation must be INACTIVE to change the origin; once activation is under way the form is frozen.
const frozen = computed(() => status.value !== null && status.value.step !== 'inactive')
const restartRequired = computed(() => status.value?.origin_restart_required ?? false)

// Once routing is live the fields collapse to a compact read-only summary.
const live = computed(() => status.value?.step === 'full')
const hostsSummary = computed(() => (status.value?.default_hosts ?? []).join(', ') || '—')
const baseSummary = computed(() => status.value?.base_domain ?? '')

function parseHosts(text: string): string[] {
  return text
    .split(/[\n,]+/)
    .map((h) => h.trim())
    .filter((h) => h !== '')
}

const desiredBase = computed(() => (baseDomain.value.trim() === '' ? null : baseDomain.value.trim()))
const desiredHosts = computed(() => parseHosts(hostsText.value))

const dirty = computed(() => {
  if (status.value === null) return false
  const hostsChanged =
    JSON.stringify(desiredHosts.value) !== JSON.stringify(status.value.default_hosts)
  return desiredBase.value !== status.value.base_domain || hostsChanged
})

// The boot-applied origin (a snapshot) differs from the desired values until the app restarts.
const appliedDiffers = computed(() => {
  if (status.value === null) return false
  return (
    status.value.applied_base_domain !== status.value.base_domain ||
    JSON.stringify(status.value.applied_default_hosts) !== JSON.stringify(status.value.default_hosts)
  )
})

const appliedHostsLabel = computed(() => (status.value?.applied_default_hosts ?? []).join(', '))

// A sensible starting point when nothing is persisted yet: the domain this admin is served from.
// Purely a prefill for the (editable) inputs — Save still persists whatever the operator chooses.
function currentHost(): string {
  return typeof window !== 'undefined' ? window.location.hostname : ''
}

function apply(next: PublicOriginStatus): void {
  status.value = next
  const fallback = currentHost()
  // Bind the DESIRED values (not the applied snapshot) so a pending change stays visible until restart.
  // When nothing is set yet, prefill with the current host so the operator has an editable default.
  baseDomain.value = next.base_domain !== null && next.base_domain !== '' ? next.base_domain : fallback
  hostsText.value = next.default_hosts.length > 0 ? next.default_hosts.join('\n') : fallback
}

async function load(): Promise<void> {
  loading.value = true
  try {
    apply(await fetchPublicOrigin())
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Unable to load the public origin.'
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    apply(await savePublicOrigin({ base_domain: desiredBase.value, default_hosts: desiredHosts.value }))
  } catch (caught) {
    error.value =
      caught instanceof ApiError || caught instanceof Error ? caught.message : 'Unable to save.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div
    :class="embedded ? '' : 'rounded-lg border border-default'"
    data-testid="public-origin-settings"
  >
    <!-- Header (standalone only; the Domain-routing card supplies its own) -->
    <div v-if="!embedded" class="flex flex-col gap-1 border-b border-default px-5 py-4">
      <h2 class="text-sm font-semibold text-highlighted">Public origin</h2>
      <p class="text-sm text-muted">
        The base domain and default hosts workspace resolution routes on. Setting these here means
        activating full resolution no longer needs an environment edit — a restart applies the change.
      </p>
    </div>

    <!-- Body -->
    <div :class="embedded ? '' : 'px-5 py-4'">
      <div v-if="loading" class="flex flex-col gap-3">
        <USkeleton class="h-9 w-64" />
        <USkeleton class="h-20 w-full" />
      </div>

      <template v-else>
        <!-- Standalone-only activation messaging (the Domain-routing card owns it when embedded). -->
        <div v-if="!embedded && frozen" class="mb-4" data-testid="public-origin-frozen">
          <UAlert
            color="info"
            variant="subtle"
            icon="i-lucide-lock"
            title="Locked while resolution is activating"
            description="The public origin can't be changed while workspace resolution is activating or active. Reset or deactivate it first."
          />
        </div>

        <div
          v-if="!embedded && (restartRequired || appliedDiffers)"
          class="mb-4"
          data-testid="public-origin-restart-note"
        >
          <UAlert
            color="warning"
            variant="subtle"
            icon="i-lucide-refresh-cw"
            title="Restart required to continue"
            :description="
              appliedHostsLabel === ''
                ? 'The saved public origin has not been applied yet — restart the app to continue activating resolution.'
                : `Currently applied until restart: ${status?.applied_base_domain ?? '(none)'} · hosts: ${appliedHostsLabel}.`
            "
          />
        </div>

        <!-- Live: compact read-only summary instead of the editor. -->
        <p v-if="live" class="text-sm text-muted" data-testid="public-origin-summary">
          Answering on <code class="text-highlighted">{{ hostsSummary }}</code
          ><span v-if="baseSummary">
            · base <code class="text-highlighted">{{ baseSummary }}</code></span
          >
        </p>

        <div v-else class="flex flex-col gap-4">
          <UFormField label="Base domain" name="base_domain" hint="Optional">
            <UInput
              v-model="baseDomain"
              :disabled="frozen"
              placeholder="example.com"
              data-testid="public-origin-base-domain"
              class="w-full"
            />
          </UFormField>

          <UFormField label="Default hosts" name="default_hosts" hint="One per line or comma-separated">
            <UTextarea
              v-model="hostsText"
              :disabled="frozen"
              :rows="4"
              placeholder="app.example.com"
              data-testid="public-origin-hosts"
              class="w-full"
            />
          </UFormField>

          <div class="flex items-center justify-end gap-3">
            <span v-if="dirty && !frozen" class="text-xs text-muted">Unsaved changes</span>
            <UButton
              icon="i-lucide-save"
              :loading="saving"
              :disabled="frozen || !dirty"
              data-testid="public-origin-save"
              @click="save"
              >Save changes</UButton
            >
          </div>

          <p v-if="error" class="text-sm text-error" role="alert">{{ error }}</p>
        </div>
      </template>
    </div>
  </div>
</template>
