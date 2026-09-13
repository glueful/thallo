<script setup lang="ts">
import { ref } from 'vue'
import { useNotify } from '@/composables/useNotify'

// One-time reveal of a freshly minted (or rotated) key's plaintext. Shown once because only the
// hash is stored server-side — there is no way to retrieve it again.
const props = defineProps<{ secret: string; title?: string; subtitle?: string }>()
const open = defineModel<boolean>('open', { required: true })

const { success } = useNotify()
const copied = ref(false)

async function copy() {
  await navigator.clipboard.writeText(props.secret)
  copied.value = true
  success('API key copied')
  window.setTimeout(() => (copied.value = false), 2000)
}
</script>

<template>
  <UModal
    v-model:open="open"
    :title="title ?? 'Your new API key'"
    :dismissible="false"
    :close="false"
  >
    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Copy this key now"
          :description="
            subtitle ??
            'This is the only time the full key is shown. Store it somewhere safe — you won’t be able to see it again.'
          "
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
            aria-label="Copy API key"
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
