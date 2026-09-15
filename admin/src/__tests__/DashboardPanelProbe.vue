<script setup lang="ts">
// A page-shaped probe for dashboardPanelHeight.spec.ts: the real UDashboardPanel as every
// admin page mounts it (an SFC, so the auto-imported Nuxt UI components resolve).
</script>

<template>
  <UDashboardPanel id="height-probe">
    <template #header><UDashboardNavbar title="Probe" /></template>
    <template #body><p>body</p></template>
  </UDashboardPanel>
</template>
