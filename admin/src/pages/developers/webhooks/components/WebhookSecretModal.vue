<script setup lang="ts">
import { ref } from 'vue'
import { useNotify } from '@/composables/useNotify'

// One-time reveal of a subscription's signing secret (shown on create + rotate). The backend stores
// it but never returns it again on reads, so the operator must copy it now to verify signatures.
const props = defineProps<{ secret: string; title?: string }>()
const open = defineModel<boolean>('open', { required: true })

const { success } = useNotify()
const copied = ref(false)

async function copy() {
  await navigator.clipboard.writeText(props.secret)
  copied.value = true
  success('Signing secret copied')
  window.setTimeout(() => (copied.value = false), 2000)
}
</script>

<template>
  <UModal
    v-model:open="open"
    :title="title ?? 'Signing secret'"
    :dismissible="false"
    :close="false"
  >
    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Copy this secret now"
          description="Use it to verify the X-Webhook-Signature on incoming deliveries. It isn’t shown again — only rotated."
        />

        <div
          class="flex items-center gap-2 rounded-lg border border-default bg-elevated/50 p-3 font-mono text-sm"
        >
          <code class="min-w-0 flex-1 break-all text-default">{{ secret }}</code>
          <UButton
            :icon="copied ? 'i-lucide-check' : 'i-lucide-copy'"
            :color="copied ? 'success' : 'neutral'"
            variant="ghost"
            size="sm"
            aria-label="Copy signing secret"
            @click="copy"
          />
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-end">
        <UButton
          label="Done"
          @click="
            () => {
              open = false
            }
          "
        />
      </div>
    </template>
  </UModal>
</template>
