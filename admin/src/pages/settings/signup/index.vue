<script setup lang="ts">
definePage({ meta: { requiresAuth: true } })
</script>

<template>
  <UDashboardPanel id="settings-signup">
    <template #header>
      <UDashboardNavbar title="Signup">
        <template #right>
          <UButton
            to="/settings/signup/roles"
            color="neutral"
            variant="outline"
            icon="i-lucide-shield-check"
            data-testid="manage-signup-roles"
            >Manage roles</UButton
          >
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6">
        <p class="text-sm text-muted">
          Control how people join this site. Signup is off by default; when you turn it on, new
          members verify their email before their account becomes active.
        </p>
        <MemberSignupSettings scope="single-store" />
      </div>
    </template>
  </UDashboardPanel>
</template>
