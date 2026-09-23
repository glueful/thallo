<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { ApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import {
  type EmailMailerCapability,
  fetchEmailSettings,
  fetchEmailTemplates,
  saveEmailSettings,
  type EmailPartialRow,
  type EmailSettingsInput,
  type EmailSettingsPayload,
  type EmailTemplateRow,
} from '@/queries/email'
import PartialRow from './components/PartialRow.vue'
import TemplateRow from './components/TemplateRow.vue'
import TestEmailModal from './components/TestEmailModal.vue'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()

// ── Transport settings (glueful/email-notification /email/settings) ──
// GET is the NESTED redacted effectiveConfig shape; PUT is FLAT keys.
const status = ref<'pending' | 'success' | 'error'>('pending')
const passwordSet = ref(false)
const mailerOptions = ref<string[]>(['smtp'])
const saving = ref(false)

// 'none' is a UI sentinel — SelectItem rejects '' values; hydrate/save translate.
const encryptionOptions: { label: string; value: string }[] = [
  { label: 'STARTTLS (TLS)', value: 'tls' },
  { label: 'SSL/TLS', value: 'ssl' },
  { label: 'None', value: 'none' },
]

const form = reactive<Required<EmailSettingsInput>>({
  mailer: 'smtp',
  transport: '',
  host: '',
  port: '',
  username: '',
  password: '',
  encryption: '',
  from: '',
  from_name: '',
  logo_url: '',
})

// What the chosen mailer takes (glueful/email-notification 1.14). An older extension sends no
// capabilities: every SMTP box stays on screen, as before.
const capabilities = ref<Record<string, EmailMailerCapability>>({})
const capability = computed(() => capabilities.value[form.mailer])
const transportOptions = computed(() => capability.value?.transports ?? [])
const shownFields = computed<string[] | null>(() => {
  const cap = capability.value
  if (!cap) return null
  return cap.fields_by_transport[form.transport] ?? cap.fields
})
const shows = (field: string): boolean =>
  shownFields.value === null || shownFields.value.includes(field)
/** A transport that reads none of the SMTP settings sends through the provider's own API. */
const keyedTransport = computed(() => shownFields.value !== null && shownFields.value.length === 0)
const keyMissing = computed(() => keyedTransport.value && capability.value?.key_set === false)
// 'brevo+api' -> API, 'brevo+smtp' -> SMTP relay: the operator picks a way out, not a bridge name.
const transportItems = computed(() =>
  transportOptions.value.map((value) => ({
    label: value.endsWith('+api') ? 'API' : 'SMTP',
    value,
  })),
)

// What each mailer sends through today, so switching mailer carries its own transport, never
// the one belonging to the mailer left behind.
const mailerTransports = ref<Record<string, string>>({})

const dirty = ref(false)
let syncing = false
watch(form, () => {
  if (!syncing) dirty.value = true
})
watch(
  () => form.mailer,
  (mailer) => {
    if (syncing) return
    form.transport =
      mailerTransports.value[mailer] ?? capabilities.value[mailer]?.transports[0] ?? ''
  },
)

function hydrate(p: EmailSettingsPayload) {
  syncing = true
  // The chosen mailer's own settings, over the smtp mailer's as the floor: a provider bridge
  // defines only some of them, and a box left empty here would save over a real stored value
  // the moment the operator switched back to smtp.
  const smtp = {
    ...(p.settings.mailers?.smtp ?? {}),
    ...(p.settings.mailers?.[p.settings.default] ?? {}),
  }
  Object.assign(form, {
    mailer: p.settings.default,
    host: smtp.host ?? '',
    port: String(smtp.port ?? ''),
    username: smtp.username ?? '',
    encryption: smtp.encryption ? smtp.encryption : 'none',
    from: p.settings.from?.address ?? '',
    from_name: p.settings.from?.name ?? '',
    logo_url: p.settings.logo_url ?? '',
    password: '',
    transport: String(smtp.transport ?? ''),
  })
  passwordSet.value = p.password_set
  capabilities.value = p.capabilities ?? {}
  mailerTransports.value = Object.fromEntries(
    Object.entries(p.settings.mailers ?? {}).map(([name, m]) => [name, String(m.transport ?? '')]),
  )
  mailerOptions.value = [...new Set(['smtp', ...Object.keys(p.settings.mailers ?? {})])]
  void nextTick(() => {
    syncing = false
  })
}

async function loadSettings() {
  try {
    hydrate(await fetchEmailSettings())
    status.value = 'success'
  } catch (e) {
    status.value = 'error'
    notifyError(e, "Couldn't load email settings")
  }
}

async function onSave() {
  saving.value = true
  try {
    const input: EmailSettingsInput = { ...form }
    if (input.password === '') delete input.password // blank keeps the stored one
    if (input.encryption === 'none') input.encryption = '' // UI sentinel -> API value
    // Send the transport only where the mailer offers a choice; the server refuses one it does
    // not offer, and a mailer with a single way out has nothing to say.
    if (transportOptions.value.length < 2) delete input.transport
    // A setting the chosen transport does not read is not the operator's to change here.
    for (const field of ['host', 'port', 'username', 'password', 'encryption'] as const) {
      if (!shows(field)) delete input[field]
    }
    hydrate(await saveEmailSettings(input))
    dirty.value = false
    success('Email settings saved', 'Applies on the next send — no restart.')
  } catch (e) {
    notifyError(e, 'Couldn’t save email settings')
  } finally {
    saving.value = false
  }
}

// ── Mail templates (glueful/email-notification /email/templates) ──
// A 403 hides the section silently (an operator without the grant sees nothing broken).
const templates = ref<EmailTemplateRow[]>([])
const partials = ref<EmailPartialRow[]>([])
const templatesVisible = ref(true)

async function loadTemplates() {
  try {
    const result = await fetchEmailTemplates()
    // Commerce's order emails MOVED to Commerce › Settings › Emails (store-settings spec
    // §4.2 follow-up) and the customers' account emails live in Settings › Accounts — same
    // registry, same API, managed beside the settings they belong to instead of here.
    templates.value = result.templates.filter(
      (t) => t.owner !== 'thallo-commerce' && t.owner !== 'thallo-account',
    )
    partials.value = result.partials
    templatesVisible.value = true
  } catch (e) {
    if (e instanceof ApiError && e.status === 403) {
      templatesVisible.value = false
      return
    }
    notifyError(e, "Couldn't load email templates")
  }
}

// ── Send test email modal (transport option + one per template) ──
const testOpen = ref(false)
const testPreselect = ref<string>('')

function openTest(preselect = '') {
  testPreselect.value = preselect
  testOpen.value = true
}

onMounted(() => {
  void loadSettings()
  void loadTemplates()
})
</script>

<template>
  <UDashboardPanel id="settings-email">
    <template #header>
      <UDashboardNavbar title="Email">
        <template #right>
          <UChip :show="dirty" color="warning" size="sm">
            <UButton icon="i-lucide-save" :loading="saving" @click="onSave">Save</UButton>
          </UChip>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-6xl space-y-6">
        <div v-if="status === 'pending'" class="grid gap-6 lg:grid-cols-3">
          <USkeleton class="h-96" />
          <USkeleton class="h-96 lg:col-span-2" />
        </div>

        <template v-else>
          <!-- Two-column split (the Settings → General shape): transport config
               is the short set-once rail; templates are the tall working column. -->
          <div class="grid gap-6 pb-5 lg:grid-cols-3">
            <div class="space-y-6 lg:sticky lg:top-0 lg:self-start">
              <UCard>
                <template #header>
                  <div class="flex items-center justify-between gap-2">
                    <h2 class="font-semibold text-default">Mailer</h2>
                    <UButton
                      size="xs"
                      variant="ghost"
                      color="neutral"
                      icon="i-lucide-send"
                      label="Send test email"
                      data-test="transport-test-open"
                      @click="openTest('')"
                    />
                  </div>
                </template>
                <div class="space-y-4">
                  <div class="grid gap-4">
                    <UFormField label="Mailer">
                      <USelect
                        v-model="form.mailer"
                        :items="mailerOptions"
                        class="w-full"
                        data-test="mailer-select"
                      />
                    </UFormField>
                    <UFormField
                      v-if="transportItems.length > 1"
                      label="Send through"
                      hint="This provider offers both"
                    >
                      <USelect
                        v-model="form.transport"
                        :items="transportItems"
                        class="w-full"
                        data-test="transport-select"
                      />
                    </UFormField>
                    <UFormField v-if="shows('encryption')" label="Encryption">
                      <USelect
                        v-model="form.encryption"
                        :items="encryptionOptions"
                        class="w-full"
                      />
                    </UFormField>
                  </div>

                  <!-- A keyed bridge reads none of the SMTP settings: it takes its key from the
                       environment, so showing empty Host and Port boxes against it is a lie. -->
                  <p v-if="keyedTransport" class="text-sm text-muted" data-test="keyed-transport">
                    {{ form.mailer }} sends through its own API — no host, port or credentials here.
                    <span v-if="keyMissing" class="text-warning">
                      Its API key is not set in the environment yet.
                    </span>
                  </p>

                  <div v-if="shows('host') || shows('port')" class="grid gap-4">
                    <UFormField v-if="shows('host')" label="Host">
                      <UInput v-model="form.host" placeholder="smtp.example.com" class="w-full" />
                    </UFormField>
                    <UFormField v-if="shows('port')" label="Port">
                      <UInput
                        v-model="form.port"
                        inputmode="numeric"
                        placeholder="587"
                        class="w-full"
                      />
                    </UFormField>
                  </div>

                  <div v-if="shows('username') || shows('password')" class="grid gap-4">
                    <UFormField v-if="shows('username')" label="Username">
                      <UInput v-model="form.username" autocomplete="off" class="w-full" />
                    </UFormField>
                    <UFormField
                      v-if="shows('password')"
                      label="Password"
                      :hint="passwordSet ? 'A password is set' : undefined"
                    >
                      <UInput
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        :placeholder="passwordSet ? '•••••••• (unchanged)' : ''"
                        class="w-full"
                      />
                    </UFormField>
                  </div>
                </div>
              </UCard>

              <UCard>
                <template #header><h2 class="font-semibold text-default">Sender</h2></template>
                <div class="space-y-4">
                  <div class="grid gap-4">
                    <UFormField label="From address">
                      <UInput
                        v-model="form.from"
                        type="email"
                        placeholder="no-reply@example.com"
                        class="w-full"
                      />
                    </UFormField>
                    <UFormField label="From name">
                      <UInput v-model="form.from_name" placeholder="Thallo" class="w-full" />
                    </UFormField>
                  </div>
                  <div class="grid gap-4">
                    <UFormField label="Logo URL" hint="Optional">
                      <UInput v-model="form.logo_url" class="w-full" />
                    </UFormField>
                  </div>
                </div>
              </UCard>
            </div>

            <div class="space-y-6 lg:col-span-2">
              <UCard v-if="templatesVisible" data-test="templates-card">
                <template #header>
                  <div class="flex items-center justify-between gap-2">
                    <h2 class="font-semibold text-default">Mail templates</h2>
                    <UButton
                      size="xs"
                      variant="ghost"
                      color="neutral"
                      icon="i-lucide-send"
                      label="Send test email"
                      data-test="templates-test-open"
                      @click="openTest(templates[0]?.key ?? '')"
                    />
                  </div>
                </template>
                <div class="space-y-1">
                  <UCollapsible
                    v-for="t in templates"
                    :key="t.key"
                    :default-open="false"
                    :unmount-on-hide="false"
                  >
                    <UButton
                      class="w-full justify-between"
                      color="neutral"
                      variant="ghost"
                      :data-test="`template-toggle-${t.key}`"
                    >
                      <span class="flex min-w-0 items-center gap-2">
                        <UIcon name="i-lucide-file-pen-line" class="size-4 shrink-0 text-muted" />
                        <span class="truncate font-medium">{{ t.label }}</span>
                        <span
                          v-if="t.owner !== 'glueful/email-notification'"
                          class="truncate text-xs text-muted"
                        >
                          {{ t.owner }}
                        </span>
                      </span>
                      <span class="flex shrink-0 items-center gap-2">
                        <UBadge
                          size="xs"
                          :color="t.overridden ? 'primary' : 'neutral'"
                          variant="subtle"
                          :data-test="`template-badge-${t.key}`"
                        >
                          {{ t.overridden ? 'custom' : 'default' }}
                        </UBadge>
                        <UIcon
                          name="i-lucide-chevron-down"
                          class="size-4 text-muted transition-transform group-data-[state=open]:rotate-180"
                        />
                      </span>
                    </UButton>
                    <template #content>
                      <TemplateRow :template="t" @saved="loadTemplates" @reset="loadTemplates" />
                    </template>
                  </UCollapsible>
                </div>

                <template v-if="partials.length > 0">
                  <p class="mt-4 px-1 pb-1 text-xs font-semibold text-muted">
                    Layout &amp; partials
                  </p>
                  <div class="space-y-1">
                    <UCollapsible
                      v-for="pRow in partials"
                      :key="pRow.key"
                      :default-open="false"
                      :unmount-on-hide="false"
                    >
                      <UButton
                        class="w-full justify-between"
                        color="neutral"
                        variant="ghost"
                        :data-test="`partial-toggle-${pRow.key}`"
                      >
                        <span class="flex min-w-0 items-center gap-2">
                          <UIcon
                            :name="
                              pRow.language === 'css'
                                ? 'i-lucide-paintbrush'
                                : 'i-lucide-layout-template'
                            "
                            class="size-4 shrink-0 text-muted"
                          />
                          <span class="truncate font-medium">{{ pRow.label }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2">
                          <UBadge
                            size="xs"
                            :color="pRow.overridden ? 'primary' : 'neutral'"
                            variant="subtle"
                            :data-test="`partial-badge-${pRow.key}`"
                          >
                            {{ pRow.overridden ? 'custom' : 'default' }}
                          </UBadge>
                          <UIcon
                            name="i-lucide-chevron-down"
                            class="size-4 text-muted transition-transform group-data-[state=open]:rotate-180"
                          />
                        </span>
                      </UButton>
                      <template #content>
                        <PartialRow :partial="pRow" @saved="loadTemplates" @reset="loadTemplates" />
                      </template>
                    </UCollapsible>
                  </div>
                </template>
              </UCard>
            </div>
          </div>
        </template>
      </div>

      <TestEmailModal v-model:open="testOpen" :templates="templates" :preselect="testPreselect" />
    </template>
  </UDashboardPanel>
</template>
