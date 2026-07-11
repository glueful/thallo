<script setup lang="ts">
import { reactive } from 'vue'

const props = defineProps<{
  initialSlug?: string | null
  initialName?: string | null
  errors?: Record<string, string>
  busy?: boolean
}>()
const emit = defineEmits<{ submit: [value: { slug: string; name: string }] }>()

const form = reactive({ slug: props.initialSlug ?? '', name: props.initialName ?? '' })
</script>

<template>
  <form
    class="grid gap-4 max-w-xl"
    data-testid="first-tenant-confirm"
    @submit.prevent="emit('submit', form)"
  >
    <UFormField label="Tenant name" name="name" :error="errors?.name">
      <UInput v-model="form.name" name="name" autocomplete="organization" required class="w-full" />
    </UFormField>
    <UFormField
      label="Tenant slug"
      name="slug"
      :error="errors?.slug"
      hint="Lowercase letters, numbers, and hyphens"
    >
      <UInput
        v-model="form.slug"
        name="slug"
        pattern="[a-z0-9][a-z0-9-]*"
        required
        class="w-full"
      />
    </UFormField>
    <div>
      <UButton
        type="submit"
        icon="i-lucide-check"
        :loading="busy"
        data-testid="enablement-action-confirm"
      >
        Confirm tenant
      </UButton>
    </div>
  </form>
</template>
