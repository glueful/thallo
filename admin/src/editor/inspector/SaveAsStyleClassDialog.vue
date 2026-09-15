<script setup lang="ts">
// "Save as style class" (visual builder spec §4.5): names the class and lists the explicit
// declarations that will be lifted — never inherited or resolved values.
import { computed, ref, watch } from 'vue'
import { liftedDeclarations } from '@/style/lift'

const props = defineProps<{ open: boolean; style: Record<string, unknown>; saving: boolean }>()
const emit = defineEmits<{
  'update:open': [open: boolean]
  confirm: [name: string, description: string | null]
}>()

const name = ref('')
const description = ref('')
watch(
  () => props.open,
  (open) => {
    if (open) {
      name.value = ''
      description.value = ''
    }
  },
)
const lifted = computed(() => liftedDeclarations(props.style))
const label = (value: { type: string; value?: string }) =>
  value.type === 'reset' ? 'reset' : (value.value ?? '')
</script>

<template>
  <UModal
    :open="open"
    title="Save as style class"
    data-test="save-as-class-dialog"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <div class="space-y-3 text-sm">
        <UFormField label="Name" description="Unique on this site, in any letter case">
          <UInput v-model="name" class="w-full" data-test="save-as-class-name" />
        </UFormField>
        <UFormField label="Description">
          <UTextarea v-model="description" class="w-full" :rows="2" />
        </UFormField>
        <p class="text-muted">
          These declarations move into the class; values the block inherits from other classes or
          the theme stay where they are.
        </p>
        <ul class="space-y-1 text-xs" data-test="save-as-class-lifted">
          <li v-for="d in lifted" :key="`${d.path}:${d.breakpoint ?? ''}`" class="font-mono">
            {{ d.path }}{{ d.breakpoint ? ` @${d.breakpoint}` : '' }} = {{ label(d.value) }}
          </li>
        </ul>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton variant="ghost" color="neutral" @click="emit('update:open', false)"
          >Cancel</UButton
        >
        <UButton
          :loading="saving"
          :disabled="name.trim() === '' || lifted.length === 0"
          data-test="save-as-class-confirm"
          @click="emit('confirm', name.trim(), description.trim() || null)"
        >
          Save as style class
        </UButton>
      </div>
    </template>
  </UModal>
</template>
