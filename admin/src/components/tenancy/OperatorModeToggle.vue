<script setup lang="ts">
import { useTenantStore } from '@/stores/tenant'

const tenant = useTenantStore()
</script>

<template>
  <div class="flex items-center gap-3">
    <USwitch
      :model-value="tenant.operatorMode"
      aria-label="Operate as platform administrator"
      data-testid="operator-mode-toggle"
      @update:model-value="tenant.setOperatorMode"
    />
    <span class="text-sm">Operate as platform administrator</span>
  </div>
</template>
