<script setup lang="ts">
import { computed } from 'vue'
import { TENANT_ROLES, type AssignableTenantRole, type TenantRole } from '@/queries/tenantMembers'

const model = defineModel<TenantRole>({ required: true })
const props = defineProps<{ roles?: AssignableTenantRole[] }>()
const items = computed(() =>
  props.roles?.length
    ? props.roles.map((role) => ({ label: role.name, value: role.slug }))
    : TENANT_ROLES.map((value) => ({
        label: value.charAt(0).toUpperCase() + value.slice(1),
        value,
      })),
)
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
