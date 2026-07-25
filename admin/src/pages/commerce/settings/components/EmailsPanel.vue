<script setup lang="ts">
// The Emails settings tab (store-settings spec §4.2 follow-up): the four buyer order emails,
// moved here from Settings › Email so commerce email management lives with commerce settings.
// Each template gets an on/off switch (commerce-owned, `GET/PUT /commerce/emails`) and the SAME
// subject/body editor the global email page uses — TemplateRow is reused verbatim against the
// email-notification extension's /email/templates API, filtered to this pack's owner.
import { computed, ref } from 'vue'
import {
  useCommerceEmailSettings,
  useSaveCommerceEmailSettings,
} from '@/queries/commerceSettings'
import { fetchEmailTemplates, type EmailTemplateRow } from '@/queries/email'
import TemplateRow from '@/pages/settings/email/components/TemplateRow.vue'
import { useNotify } from '@/composables/useNotify'
import { toApiError } from '@/api/errors'

defineProps<{ canManage: boolean }>()

/** CommerceEmailTemplates::OWNER — the registry owner that marks a template as this pack's. */
const COMMERCE_OWNER = 'thallo-commerce'

const { success, error: notifyError } = useNotify()
const { data: switches, status: switchesStatus } = useCommerceEmailSettings()
const save = useSaveCommerceEmailSettings()

// Template content rows (subject/body/overridden) from the email-notification extension —
// same fetch the global email page uses, filtered to commerce-owned templates.
const templates = ref<EmailTemplateRow[]>([])
const templatesStatus = ref<'pending' | 'error' | 'success'>('pending')

async function loadTemplates(): Promise<void> {
  templatesStatus.value = 'pending'
  try {
    const result = await fetchEmailTemplates()
    templates.value = result.templates.filter((t) => t.owner === COMMERCE_OWNER)
    templatesStatus.value = 'success'
  } catch (e) {
    templatesStatus.value = 'error'
    notifyError(toApiError(e), "Couldn't load order email templates")
  }
}
void loadTemplates()

const rows = computed(() =>
  (switches.value?.templates ?? []).map((sw) => ({
    switch: sw,
    template: templates.value.find((t) => t.key === sw.key) ?? null,
  })),
)

/** Switch changes save immediately — a single boolean, not a form worth batching. */
async function toggle(template: string, enabled: boolean): Promise<void> {
  try {
    await save.mutateAsync({ templates: { [template]: enabled } })
    success(enabled ? 'Email enabled' : 'Email disabled', 'Applies to the next order event.')
  } catch (e) {
    notifyError(toApiError(e), "Couldn't update the email switch")
  }
}
</script>

<template>
  <div
    v-if="switchesStatus === 'pending'"
    class="flex justify-center py-10"
    data-test="emails-loading"
  >
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="switchesStatus === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn’t load order email settings"
    data-test="emails-error"
  />

  <div v-else class="max-w-3xl space-y-4" data-test="emails-panel">
    <UAlert
      v-if="switches?.commerce_mailer_active"
      color="warning"
      variant="subtle"
      icon="i-lucide-info"
      title="Commerce’s built-in mailer is active"
      description="commerce.email.enabled is on, so the commerce extension sends these emails itself and Thallo’s sender stands down — the switches below have no effect until that flag is turned off."
      data-test="emails-mailer-active"
    />

    <div
      v-for="row in rows"
      :key="row.switch.template"
      class="space-y-3 rounded-lg border border-default p-4"
      data-test="emails-template-card"
    >
      <div class="flex items-center gap-3">
        <div class="min-w-0">
          <p class="font-medium">{{ row.template?.label ?? row.switch.key }}</p>
          <p v-if="row.template" class="truncate text-xs text-muted">
            {{ row.template.description }}
          </p>
        </div>
        <USwitch
          :model-value="row.switch.enabled.value"
          :disabled="!canManage"
          class="ml-auto"
          :data-test="`emails-toggle-${row.switch.template}`"
          @update:model-value="(v: boolean) => toggle(row.switch.template, v)"
        />
      </div>

      <!-- The shared subject/body editor (test-send + reset included), collapsed by default —
           the same UCollapsible + ghost-trigger shape as Settings › Email. Only meaningful once
           the /email/templates row is loaded; a registry miss still leaves the switch usable. -->
      <UCollapsible v-if="row.template" :default-open="false" :unmount-on-hide="false">
        <UButton
          class="group w-full justify-between"
          color="neutral"
          variant="ghost"
          :data-test="`emails-edit-toggle-${row.switch.template}`"
        >
          <span class="flex min-w-0 items-center gap-2">
            <UIcon name="i-lucide-file-pen-line" class="size-4 shrink-0 text-muted" />
            <span class="truncate font-medium">Edit template</span>
          </span>
          <span class="flex shrink-0 items-center gap-2">
            <UBadge
              size="xs"
              :color="row.template.overridden ? 'primary' : 'neutral'"
              variant="subtle"
              :data-test="`emails-edit-badge-${row.switch.template}`"
            >
              {{ row.template.overridden ? 'custom' : 'default' }}
            </UBadge>
            <UIcon
              name="i-lucide-chevron-down"
              class="size-4 text-muted transition-transform group-data-[state=open]:rotate-180"
            />
          </span>
        </UButton>
        <template #content>
          <TemplateRow :template="row.template" @saved="loadTemplates" @reset="loadTemplates" />
        </template>
      </UCollapsible>
    </div>

    <p class="text-xs text-muted">
      Transport settings (SMTP, sender address, logo) stay in
      <RouterLink to="/settings/email" class="font-medium text-default hover:underline">
        Settings › Email</RouterLink
      >; these four order emails are managed here.
    </p>
  </div>
</template>
