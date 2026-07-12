<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import {
  fetchWorkspaceSignupSettings,
  saveWorkspaceSignupSettings,
  type WorkspaceSignupSettings,
} from '@/queries/signupSettings'

// Multi-workspace mode must be on to save workspace signup: the backend refuses (409) otherwise.
// The page passes this down because it can't be derived from the settings payload alone (both
// `enabled` and `effective` read false whether tenancy is off OR signup is simply off).
const props = withDefaults(defineProps<{ workspacesEnabled?: boolean }>(), {
  workspacesEnabled: false,
})

const settings = ref<WorkspaceSignupSettings | null>(null)
const enabled = ref(false)
const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)

const emailChannelAvailable = computed(() => settings.value?.email_channel_available ?? false)

// Saved-on but no longer effective: signup was turned on while workspaces were enabled, then
// workspaces were turned off again — so it can't provision until multi-workspace mode returns.
const savedButInactive = computed(
  () => settings.value?.enabled === true && settings.value.effective === false,
)

// Turning signup ON requires both multi-workspace mode and an email channel.
const canEnable = computed(() => props.workspacesEnabled && emailChannelAvailable.value)

const dirty = computed(() => settings.value !== null && enabled.value !== settings.value.enabled)

async function load(): Promise<void> {
  loading.value = true
  try {
    settings.value = await fetchWorkspaceSignupSettings()
    enabled.value = settings.value.enabled
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Unable to load signup settings.'
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    settings.value = await saveWorkspaceSignupSettings(enabled.value)
    enabled.value = settings.value.enabled
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
  <div class="rounded-lg border border-default" data-testid="workspace-signup-settings">
    <!-- Header -->
    <div class="flex flex-col gap-1 border-b border-default px-5 py-4">
      <div class="flex items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-highlighted">Workspace signup</h2>
        <div v-if="!loading" class="flex items-center gap-2">
          <UBadge v-if="savedButInactive" color="warning" variant="subtle" size="sm">
            Inactive
          </UBadge>
          <UBadge :color="enabled ? 'success' : 'neutral'" variant="subtle" size="sm">
            {{ enabled ? 'On' : 'Off' }}
          </UBadge>
        </div>
      </div>
      <p class="text-sm text-muted">
        Let visitors create their own workspace and become its owner. Signup sends a verification
        email before the workspace is provisioned. Takes effect only while multi-workspace mode is
        on.
      </p>
    </div>

    <!-- Body -->
    <div class="px-5 py-4">
      <div v-if="loading" class="flex flex-col gap-3">
        <USkeleton class="h-4 w-64" />
        <USkeleton class="h-9 w-48" />
      </div>

      <template v-else>
        <!-- Primary blocker: workspaces off. savedButInactive gets the same message but keeps the
             switch usable so a stuck 'on' can be turned off. -->
        <UAlert
          v-if="!workspacesEnabled"
          color="info"
          variant="subtle"
          icon="i-lucide-info"
          title="Requires multi-workspace mode"
          :description="
            savedButInactive
              ? 'Workspace signup is on but inactive — multi-workspace mode is off. Turn it on above, or switch signup off here.'
              : 'Turn on multi-workspace mode above before allowing visitors to create their own workspace.'
          "
          class="mb-4"
          data-testid="workspace-signup-requires-workspaces"
        />
        <UAlert
          v-else-if="!emailChannelAvailable"
          color="warning"
          variant="subtle"
          icon="i-lucide-mail-warning"
          title="Email verification unavailable"
          description="Workspace signup sends a verification email, so it can't be turned on until an email channel is configured. Set one up in email settings first."
          class="mb-4"
          data-testid="workspace-signup-email-warning"
        />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <USwitch
            v-model="enabled"
            :disabled="!canEnable && !enabled"
            :label="enabled ? 'Anyone can create a workspace' : 'Workspace creation is off'"
            data-testid="workspace-signup-toggle"
          />
          <div class="flex items-center gap-3">
            <span v-if="dirty" class="text-xs text-muted">Unsaved changes</span>
            <UButton
              icon="i-lucide-save"
              :loading="saving"
              :disabled="!dirty || (enabled && !canEnable)"
              data-testid="workspace-signup-save"
              @click="save"
              >Save changes</UButton
            >
          </div>
        </div>

        <p v-if="error" class="mt-3 text-sm text-error" role="alert">{{ error }}</p>
      </template>
    </div>
  </div>
</template>
