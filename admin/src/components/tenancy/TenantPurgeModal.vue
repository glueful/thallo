<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { TenantSummary } from '@/queries/tenants'

const open = defineModel<boolean>('open', { default: false })
const props = defineProps<{ workspace: TenantSummary | null; busy?: boolean }>()
const emit = defineEmits<{ confirm: [value: { uuid: string; confirm: string }] }>()
const typed = ref('')
const matches = computed(() => props.workspace !== null && typed.value === props.workspace.slug)

watch(open, (value) => {
  if (!value) typed.value = ''
})

function submit(): void {
  if (props.workspace !== null && matches.value) {
    emit('confirm', { uuid: props.workspace.uuid, confirm: typed.value })
  }
}
</script>

<template>
  <UModal v-model:open="open" title="Permanently purge workspace">
    <template #body>
      <form id="tenant-purge-form" class="space-y-4" @submit.prevent="submit">
        <p class="text-sm text-muted">
          This permanently removes the workspace, its content, media, memberships, and domains. Type
          <strong class="text-highlighted">{{ workspace?.slug }}</strong> to continue.
        </p>
        <UFormField label="Workspace slug" name="purge-confirmation">
          <UInput
            v-model="typed"
            name="purge-confirmation"
            autocomplete="off"
            class="w-full"
            data-testid="purge-input"
          />
        </UFormField>
      </form>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" :disabled="busy" @click="() => { open = false }">
          Cancel
        </UButton>
        <UButton
          type="submit"
          form="tenant-purge-form"
          color="error"
          icon="i-lucide-trash-2"
          :loading="busy"
          :disabled="!matches"
          data-testid="purge-confirm"
        >
          Purge workspace
        </UButton>
      </div>
    </template>
  </UModal>
</template>
