<script setup lang="ts">
import { TENANT_ROLES, type TenantRole } from '@/queries/tenantMembers'

const model = defineModel<TenantRole>({ required: true })
const items = TENANT_ROLES.map((value) => ({
  label: value.charAt(0).toUpperCase() + value.slice(1),
  value,
}))
</script>

<template>
  <USelect
    v-model="model"
    :items="items"
    value-key="value"
    class="min-w-32"
    data-testid="role-picker"
  />
</template>
