<script setup lang="ts">
// The two mails a site's customers get: verification and password reset, registered by the
// account pack (AccountEmailTemplates::OWNER) apart from the admin's own. Edited with the same
// subject/body editor Settings › Email uses, against the email extension's /email/templates API.
import { onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { fetchEmailTemplates, type EmailTemplateRow } from '@/queries/email'
import TemplateRow from '@/pages/settings/email/components/TemplateRow.vue'

/** AccountEmailTemplates::OWNER. */
const ACCOUNT_OWNER = 'thallo-account'

const templates = ref<EmailTemplateRow[]>([])
const state = ref<'pending' | 'ready' | 'forbidden' | 'error'>('pending')

async function load(): Promise<void> {
  try {
    const result = await fetchEmailTemplates()
    templates.value = result.templates.filter((t) => t.owner === ACCOUNT_OWNER)
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.status === 403 ? 'forbidden' : 'error'
  }
}

onMounted(load)
</script>

<template>
  <section class="rounded-lg border border-default" data-testid="account-emails">
    <div class="border-b border-default px-5 py-3">
      <h2 class="text-sm font-semibold text-highlighted">Emails</h2>
      <p class="mt-1 text-sm text-muted">
        What a visitor receives when they register or reset their password. The admin's own emails
        are edited in
        <RouterLink to="/settings/email" class="font-medium text-default hover:underline"
          >Settings › Email</RouterLink
        >, which also holds the sender and mail transport.
      </p>
    </div>

    <div v-if="state === 'pending'" class="flex flex-col gap-3 px-5 py-4">
      <USkeleton class="h-4 w-48" />
      <USkeleton class="h-4 w-64" />
    </div>
    <p v-else-if="state === 'forbidden'" class="px-5 py-4 text-sm text-muted">
      Editing emails needs permission to manage email settings.
    </p>
    <p
      v-else-if="state === 'error' || templates.length === 0"
      class="px-5 py-4 text-sm text-muted"
      data-testid="account-emails-unavailable"
    >
      These emails can be edited once the email extension is enabled. Until then, visitors get the
      built-in verification and password reset emails.
    </p>
    <ul v-else class="divide-y divide-default">
      <li
        v-for="template in templates"
        :key="template.key"
        class="px-5 py-3"
        data-testid="account-email"
      >
        <UCollapsible :default-open="false" :unmount-on-hide="false">
          <UButton
            class="group w-full justify-between"
            color="neutral"
            variant="ghost"
            :data-testid="`account-email-toggle-${template.key}`"
          >
            <span class="min-w-0 text-left">
              <span class="block truncate font-medium">{{ template.label }}</span>
              <span class="block truncate text-xs text-muted">{{ template.description }}</span>
            </span>
            <span class="flex shrink-0 items-center gap-2">
              <UBadge
                size="xs"
                :color="template.overridden ? 'primary' : 'neutral'"
                variant="subtle"
              >
                {{ template.overridden ? 'custom' : 'default' }}
              </UBadge>
              <UIcon
                name="i-lucide-chevron-down"
                class="size-4 text-muted transition-transform group-data-[state=open]:rotate-180"
              />
            </span>
          </UButton>
          <template #content>
            <TemplateRow :template="template" @saved="load" @reset="load" />
          </template>
        </UCollapsible>
      </li>
    </ul>
  </section>
</template>
