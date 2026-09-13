<script setup lang="ts">
import { computed, ref } from 'vue'
import type { DropdownMenuItem } from '@nuxt/ui'
import { useColorMode } from '@vueuse/core'
import { useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'

const colorMode = useColorMode({ initialValue: 'system' })
const router = useRouter()
const session = useSessionStore()

defineProps<{
  collapsed?: boolean
}>()

const showLogoutConfirm = ref(false)
const loggingOut = ref(false)

// Identity shown on the trigger and the menu's account header. Email is the only field the session
// carries (Glueful auth returns uuid + email); the avatar falls back to its first letter.
const userEmail = computed(() => session.user?.email ?? '')
const userInitial = computed(() => (userEmail.value.charAt(0) || 'A').toUpperCase())

async function confirmLogout() {
  loggingOut.value = true
  try {
    // The store's logout() always clears the session locally (even if the API call fails), so once
    // this resolves the guard treats us as signed out and /login is reachable.
    await session.logout()
    showLogoutConfirm.value = false
    await router.push('/login')
  } finally {
    loggingOut.value = false
  }
}

const items = computed<DropdownMenuItem[][]>(() => [
  [
    {
      type: 'label',
      label: userEmail.value || 'Account',
    },
  ],
  [
    {
      label: 'Appearance',
      icon: 'i-lucide-monitor',
      children: [
        {
          label: 'System',
          icon: 'i-lucide-sun',
          type: 'checkbox',
          checked: colorMode.value === 'system',
          onSelect(e: Event) {
            e.preventDefault()

            colorMode.value = 'system'
          },
        },
        {
          label: 'Light',
          icon: 'i-lucide-sun',
          type: 'checkbox',
          checked: colorMode.value === 'light',
          onSelect(e: Event) {
            e.preventDefault()

            colorMode.value = 'light'
          },
        },
        {
          label: 'Dark',
          icon: 'i-lucide-moon',
          type: 'checkbox',
          checked: colorMode.value === 'dark',
          onUpdateChecked(checked: boolean) {
            if (checked) {
              colorMode.value = 'dark'
            }
          },
          onSelect(e: Event) {
            e.preventDefault()
          },
        },
      ],
    },
  ],
  [
    {
      label: 'Profile',
      icon: 'i-lucide-user',
    },
    {
      label: 'Security',
      icon: 'i-lucide-lock',
    },
    {
      label: 'Log out',
      icon: 'i-lucide-log-out',
      onSelect: () => {
        showLogoutConfirm.value = true
      },
    },
  ],
])
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-48' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      :label="collapsed ? undefined : userEmail || 'Account'"
      :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
      class="data-[state=open]:bg-elevated"
      :ui="{
        trailingIcon: 'text-dimmed',
      }"
    >
      <template #leading>
        <UAvatar :text="userInitial" size="2xs" />
      </template>
    </UButton>

    <template #chip-leading="{ item }">
      <div class="inline-flex items-center justify-center shrink-0 size-5">
        <span
          class="rounded-full ring ring-bg bg-(--chip-light) dark:bg-(--chip-dark) size-2"
          :style="{
            '--chip-light': `var(--color-${(item as any).chip}-500)`,
            '--chip-dark': `var(--color-${(item as any).chip}-400)`,
          }"
        />
      </div>
    </template>
  </UDropdownMenu>

  <UModal v-model:open="showLogoutConfirm" title="Log out" :dismissible="!loggingOut">
    <template #body>
      <p class="text-sm text-muted">
        You'll be signed out of the admin and returned to the login screen.
      </p>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="loggingOut"
          @click="
            () => {
              showLogoutConfirm = false
            }
          "
        />
        <UButton
          color="error"
          icon="i-lucide-log-out"
          label="Log out"
          :loading="loggingOut"
          @click="confirmLogout"
        />
      </div>
    </template>
  </UModal>
</template>
