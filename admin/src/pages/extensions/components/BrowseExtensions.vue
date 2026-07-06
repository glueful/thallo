<script setup lang="ts">
import { computed, ref } from 'vue'
import { refDebounced } from '@vueuse/core'
import {
  useExtensionCatalog,
  useExtensionInstall,
  useExtensionMutations,
  extensionShortName,
  type CatalogExtension,
} from '@/queries/extensions'
import { useNotify } from '@/composables/useNotify'

const search = ref('')
const debounced = refDebounced(search, 350)
const { data, status } = useExtensionCatalog(debounced)
const { success, error } = useNotify()
const { install, installing } = useExtensionInstall()
const { enable, disable } = useExtensionMutations()

// Per-card busy state for the enable/disable toggle (the mutations are shared).
const togglingName = ref<string | null>(null)

const results = computed(() => data.value?.results ?? [])
const available = computed(() => data.value?.available !== false)

const numberFmt = new Intl.NumberFormat(undefined, { notation: 'compact' })
const fmt = (n: number) => numberFmt.format(n)

// Install in-place: composer require runs on the server synchronously (the request
// blocks until it finishes); on success the catalog refetches and this card flips to
// "Installed". The extension installs DISABLED — enable it from the Installed tab.
async function onInstall(name: string) {
  const short = extensionShortName(name)
  const result = await install(name)
  if (result.status === 'installed') {
    success('Extension installed', `${short} is installed. Enable it to activate.`)
  } else {
    error(new Error('Install failed'), result.error ? `${short}: ${result.error}` : `Couldn't install ${short}`)
  }
}

// Enable/disable an already-installed extension without leaving the Browse tab.
async function onToggle(pkg: CatalogExtension) {
  const short = extensionShortName(pkg.name)
  togglingName.value = pkg.name
  try {
    if (pkg.enabled) {
      await disable.mutateAsync(pkg.name)
      success('Extension disabled', short)
    } else {
      await enable.mutateAsync(pkg.name)
      success('Extension enabled', short)
    }
  } catch (e) {
    error(e instanceof Error ? e : new Error('Toggle failed'), `Couldn't update ${short}`)
  } finally {
    togglingName.value = null
  }
}
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-4">
    <UInput
      v-model="search"
      icon="i-lucide-search"
      placeholder="Search the Glueful extension catalog…"
      class="w-full max-w-md shrink-0"
    />

    <div class="min-h-0 flex-1 overflow-y-auto">
      <div v-if="status === 'pending'" class="flex justify-center py-10">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
      </div>

      <UAlert
        v-else-if="!available"
        color="warning"
        variant="subtle"
        icon="i-lucide-cloud-off"
        title="Catalog unavailable"
        description="Couldn't reach Packagist just now. Try again shortly."
      />

      <UEmpty
        v-else-if="!results.length"
        icon="i-lucide-package-search"
        title="No extensions found"
        description="Nothing on Packagist matches — try a different search."
      />

      <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 pb-5">
        <div
          v-for="pkg in results"
          :key="pkg.name"
          class="flex flex-col gap-3 rounded-xl border border-default p-4"
        >
          <div class="flex items-start gap-3">
            <UIcon name="i-lucide-blocks" class="size-7 shrink-0 text-muted" />
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-semibold text-highlighted">
                {{ extensionShortName(pkg.name) }}
              </p>
              <p class="truncate text-xs text-muted">{{ pkg.name }}</p>
            </div>
            <UBadge
              v-if="pkg.installed"
              :label="pkg.enabled ? 'Enabled' : 'Disabled'"
              :color="pkg.enabled ? 'success' : 'neutral'"
              variant="subtle"
              size="xs"
              class="shrink-0"
            />
          </div>

          <p class="line-clamp-2 min-h-10 text-sm text-muted">
            {{ pkg.description ?? 'No description provided.' }}
          </p>

          <div class="mt-auto flex items-center justify-between">
            <div class="flex items-center gap-3 text-xs text-muted">
              <span class="flex items-center gap-1">
                <UIcon name="i-lucide-download" class="size-3.5" />{{ fmt(pkg.downloads) }}
              </span>
              <span class="flex items-center gap-1">
                <UIcon name="i-lucide-star" class="size-3.5" />{{ fmt(pkg.favers) }}
              </span>
            </div>
            <div class="flex items-center gap-1">
              <UButton
                v-if="!pkg.installed"
                icon="i-lucide-download"
                :label="installing(pkg.name) ? 'Installing…' : 'Install'"
                color="primary"
                variant="solid"
                size="xs"
                :loading="installing(pkg.name)"
                :data-test="`install-${pkg.name}`"
                @click="onInstall(pkg.name)"
              />
              <UButton
                v-else
                :icon="pkg.enabled ? 'i-lucide-power-off' : 'i-lucide-power'"
                :label="pkg.enabled ? 'Disable' : 'Enable'"
                :color="pkg.enabled ? 'neutral' : 'primary'"
                :variant="pkg.enabled ? 'outline' : 'solid'"
                size="xs"
                :loading="togglingName === pkg.name"
                :data-test="`toggle-${pkg.name}`"
                @click="onToggle(pkg)"
              />
              <UButton
                icon="i-lucide-external-link"
                color="neutral"
                variant="ghost"
                size="xs"
                :to="pkg.url ?? pkg.repository ?? undefined"
                target="_blank"
                aria-label="View on Packagist"
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
