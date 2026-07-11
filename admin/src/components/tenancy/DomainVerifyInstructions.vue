<script setup lang="ts">
defineProps<{ name: string; value: string; busy?: boolean }>()
defineEmits<{ verify: [] }>()
</script>

<template>
  <div class="border-y border-default py-4" data-testid="domain-verify-instructions">
    <div class="grid gap-3 sm:grid-cols-2">
      <div>
        <p class="text-xs text-muted">TXT name</p>
        <code class="mt-1 block break-all text-sm">{{ name }}</code>
      </div>
      <div>
        <p class="text-xs text-muted">TXT value</p>
        <code class="mt-1 block break-all text-sm">{{ value }}</code>
      </div>
    </div>
    <UButton class="mt-4" icon="i-lucide-badge-check" :loading="busy" @click="$emit('verify')">
      Verify record
    </UButton>
  </div>
</template>
