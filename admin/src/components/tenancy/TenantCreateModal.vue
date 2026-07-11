<script setup lang="ts">
import { computed, reactive } from 'vue'

const open = defineModel<boolean>('open', { default: false })
const props = defineProps<{ busy?: boolean; errors?: Record<string, string> }>()
const emit = defineEmits<{ submit: [value: { slug: string; name: string }] }>()
const form = reactive({ name: '', slug: '' })
const valid = computed(() => form.name.trim() !== '' && /^[a-z0-9][a-z0-9-]*$/.test(form.slug))

function submit(): void {
  if (valid.value) emit('submit', { name: form.name.trim(), slug: form.slug })
}
</script>

<template>
  <UModal v-model:open="open" title="New workspace">
    <template #body>
      <form id="tenant-create-form" class="space-y-4" @submit.prevent="submit">
        <UFormField label="Name" name="name" :error="props.errors?.name">
          <UInput
            v-model="form.name"
            name="name"
            autocomplete="organization"
            required
            class="w-full"
          />
        </UFormField>
        <UFormField label="Slug" name="slug" :error="props.errors?.slug">
          <UInput
            v-model="form.slug"
            name="slug"
            pattern="[a-z0-9][a-z0-9-]*"
            required
            class="w-full"
          />
        </UFormField>
      </form>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <UButton color="neutral" variant="ghost" @click="open = false">Cancel</UButton>
        <UButton
          type="submit"
          form="tenant-create-form"
          icon="i-lucide-plus"
          :loading="busy"
          :disabled="!valid"
          data-testid="tenant-create-submit"
        >
          Create workspace
        </UButton>
      </div>
    </template>
  </UModal>
</template>
