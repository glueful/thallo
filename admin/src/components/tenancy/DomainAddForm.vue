<script setup lang="ts">
import { computed, ref } from 'vue'

defineProps<{ busy?: boolean; error?: string | null }>()
const emit = defineEmits<{ submit: [host: string] }>()
const host = ref('')
const valid = computed(() => host.value.trim() !== '')

function submit(): void {
  if (valid.value) emit('submit', host.value.trim())
}
</script>

<template>
  <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="submit">
    <UFormField label="Domain" name="host" :error="error || undefined" class="flex-1">
      <UInput
        v-model="host"
        name="host"
        inputmode="url"
        required
        class="w-full"
        placeholder="www.example.com"
      />
    </UFormField>
    <UButton type="submit" icon="i-lucide-plus" :loading="busy" :disabled="!valid"
      >Add domain</UButton
    >
  </form>
</template>
