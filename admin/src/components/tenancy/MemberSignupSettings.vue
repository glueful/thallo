<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import {
  fetchMemberSignupSettings,
  saveMemberSignupSettings,
  type MemberSignupSettings,
  type MemberSignupScope,
} from '@/queries/signupSettings'

const props = withDefaults(defineProps<{ scope?: MemberSignupScope }>(), {
  scope: 'workspace',
})

const settings = ref<MemberSignupSettings | null>(null)
const enabled = ref(false)
const role = ref('viewer')
const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)

const scopeNoun = computed(() => (props.scope === 'single-store' ? 'site' : 'workspace'))

const emailChannelAvailable = computed(() => settings.value?.email_channel_available ?? false)

const roleItems = computed(() =>
  (settings.value?.eligible_roles ?? []).map((slug) => ({
    label: slug.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()),
    value: slug,
  })),
)

// Turning signup on requires a verification email channel (the backend refuses otherwise), so the
// control set is locked when none is configured — the alert explains why, instead of surfacing a
// save-time error. A stuck-on state can still be turned off (see the Save `disabled` guard).
const canEdit = computed(() => emailChannelAvailable.value && roleItems.value.length > 0)

const dirty = computed(
  () =>
    settings.value !== null &&
    (enabled.value !== settings.value.enabled || role.value !== settings.value.role),
)

async function load(): Promise<void> {
  loading.value = true
  try {
    settings.value = await fetchMemberSignupSettings(props.scope)
    enabled.value = settings.value.enabled
    role.value = settings.value.role
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
    settings.value = await saveMemberSignupSettings(enabled.value, role.value, props.scope)
    enabled.value = settings.value.enabled
    role.value = settings.value.role
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
  <div class="rounded-lg border border-default" data-testid="member-signup-settings">
    <!-- Header: what this setting does + current state -->
    <div class="flex flex-col gap-1 border-b border-default px-5 py-4">
      <div class="flex items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-highlighted">Member signup</h2>
        <UBadge
          v-if="!loading"
          :color="enabled ? 'success' : 'neutral'"
          variant="subtle"
          size="sm"
        >
          {{ enabled ? 'On' : 'Off' }}
        </UBadge>
      </div>
      <p class="text-sm text-muted">
        Let people create their own account and join this {{ scopeNoun }}. New members must confirm
        their email address before the account activates, then receive the role you choose below.
      </p>
    </div>

    <!-- Body -->
    <div class="px-5 py-4">
      <div v-if="loading" class="flex flex-col gap-3">
        <USkeleton class="h-4 w-64" />
        <USkeleton class="h-9 w-full max-w-xs" />
      </div>

      <template v-else>
        <UAlert
          v-if="!emailChannelAvailable"
          color="warning"
          variant="subtle"
          icon="i-lucide-mail-warning"
          title="Email verification unavailable"
          description="Member signup sends a verification email, so it can't be turned on until an email channel is configured. Set one up in email settings first."
          class="mb-4"
          data-testid="member-signup-email-warning"
        />

        <UFormField
          label="Role for new members"
          help="Applied automatically once a new member confirms their email. Governance roles are not offered here."
          class="max-w-xs"
        >
          <USelect
            v-model="role"
            :items="roleItems"
            value-key="value"
            :disabled="saving || !canEdit"
            class="w-full"
            data-testid="member-signup-role"
          />
        </UFormField>

        <div
          class="mt-5 flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <USwitch
            v-model="enabled"
            :disabled="!canEdit && !enabled"
            :label="enabled ? 'Members can sign up' : 'Members cannot sign up'"
            data-testid="member-signup-toggle"
          />
          <div class="flex items-center gap-3">
            <span v-if="dirty" class="text-xs text-muted">Unsaved changes</span>
            <UButton
              icon="i-lucide-save"
              :loading="saving"
              :disabled="!dirty || (enabled && !canEdit)"
              data-testid="member-signup-save"
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
