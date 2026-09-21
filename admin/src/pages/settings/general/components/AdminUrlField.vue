<script setup lang="ts">
// The Admin URL setting. Thallo links back into its own admin with nothing set, so the field is
// for an admin hosted somewhere else. The one thing this field can know for certain is where THIS
// admin runs — so a value that points anywhere else is said out loud, with the correction one
// click away, instead of turning up later as a 404 on the preview bar.
import { computed } from 'vue'
import { runningAdminAddress } from '@/runtime/adminAddress'

const value = defineModel<string>({ required: true })

const running = runningAdminAddress()
const mismatch = computed(() => {
  const given = value.value.trim().replace(/\/+$/, '')
  return given !== '' && given.toLowerCase() !== running.toLowerCase()
})
</script>

<template>
  <UFormField
    label="Admin URL"
    :help="`Leave empty unless the admin is hosted somewhere else. Empty means this site's own admin: ${running}`"
  >
    <UInput
      v-model="value"
      type="url"
      placeholder="https://admin.example.com"
      class="w-full"
      data-test="admin-url-input"
    />
    <UAlert
      v-if="mismatch"
      class="mt-2"
      color="warning"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="This is not where this admin runs"
      :description="`You are using the admin at ${running}. The preview bar's Edit and Design links, and the billing return, will go to the address above instead.`"
      data-test="admin-url-mismatch"
    >
      <template #actions>
        <UButton
          size="xs"
          color="neutral"
          variant="outline"
          data-test="admin-url-use-running"
          @click="value = running"
        >
          Use {{ running }}
        </UButton>
      </template>
    </UAlert>
  </UFormField>
</template>
