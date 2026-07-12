<script setup lang="ts">
import TenantRolesPage from '@/pages/workspaces/[uuid]/roles.vue'

definePage({ meta: { requiresAuth: true } })
</script>

<template>
  <TenantRolesPage />
</template>
