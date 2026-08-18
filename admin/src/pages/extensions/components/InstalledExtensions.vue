<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  useInstalledExtensions,
  useExtensionMutations,
  useExtensionReadme,
  extensionShortName,
  schemaChipColor,
  toggleBlockedReason,
  failedMigrationOf,
  type InstalledExtension,
} from '@/queries/extensions'
import { useNotify } from '@/composables/useNotify'

const { data, status } = useInstalledExtensions()
const { enable, disable } = useExtensionMutations()
const { success, error: notifyError } = useNotify()

const extensions = computed<InstalledExtension[]>(() => data.value ?? [])
const selectedName = ref<string>()
const selected = computed(() => extensions.value.find((e) => e.name === selectedName.value))
const busy = computed(() => enable.isLoading.value || disable.isLoading.value)
const blocked = computed(() => (selected.value ? toggleBlockedReason(selected.value) : null))
const { data: readme, status: readmeStatus } = useExtensionReadme(() => selectedName.value)

async function toggle(ext: InstalledExtension) {
  try {
    if (ext.enabled) {
      await disable.mutateAsync(ext.name)
      success('Extension disabled', extensionShortName(ext.name))
    } else {
      await enable.mutateAsync(ext.name)
      success('Extension enabled', extensionShortName(ext.name))
    }
  } catch (e) {
    // A failed migration is the actionable part of a 409 — put its name in the title.
    const failed = failedMigrationOf(e)
    notifyError(e, failed ? `Migration failed: ${failed}` : 'Could not update extension')
  }
}
</script>

<template>
  <div class="flex h-full min-h-0">
    <!-- List -->
    <div
      class="min-h-0 w-full overflow-y-auto lg:w-85 lg:shrink-0 lg:border-e lg:border-default lg:pe-4"
      :class="selectedName ? 'hidden lg:block' : 'block'"
    >
      <div v-if="status === 'pending'" class="flex justify-center py-10">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
      </div>
      <UEmpty
        v-else-if="!extensions.length"
        icon="i-lucide-package"
        title="No extensions installed"
        description="Install a glueful-extension package with Composer to see it here."
      />
      <div v-else class="flex flex-col gap-0.5">
        <button
          v-for="ext in extensions"
          :key="ext.name"
          type="button"
          class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors"
          :class="ext.name === selectedName ? 'bg-elevated' : 'hover:bg-elevated/50'"
          @click="() => { selectedName = ext.name }"
        >
          <UIcon name="i-lucide-package-check" class="size-5 shrink-0 text-muted" />
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-default">
              {{ extensionShortName(ext.name) }}
            </p>
            <p class="truncate text-xs text-muted">{{ ext.version ?? '—' }}</p>
          </div>
          <UBadge
            v-if="ext.schema_state === 'divergent' || ext.schema_state === 'pending'"
            :label="ext.schema_state"
            :color="schemaChipColor(ext.schema_state)"
            variant="subtle"
            size="xs"
            class="shrink-0"
          />
          <UBadge
            :label="ext.enabled ? 'Enabled' : 'Disabled'"
            :color="ext.enabled ? 'success' : 'neutral'"
            variant="subtle"
            size="xs"
            class="shrink-0"
          />
        </button>
      </div>
    </div>

    <!-- Detail -->
    <div
      class="min-w-0 flex-1 overflow-y-auto lg:ps-6"
      :class="selectedName ? 'block' : 'hidden lg:block'"
    >
      <div
        v-if="!selected"
        class="flex h-full items-center justify-center text-center text-sm text-muted"
      >
        <div>
          <UIcon name="i-lucide-mouse-pointer-click" class="mx-auto mb-2 size-6" />
          Select an extension to manage it
        </div>
      </div>
      <div v-else class="flex flex-col gap-5">
        <UButton
          class="self-start lg:hidden"
          color="neutral"
          variant="ghost"
          size="xs"
          icon="i-lucide-arrow-left"
          label="Back"
          @click="() => { selectedName = undefined }"
        />
        <header class="flex items-start justify-between gap-3">
          <div class="flex items-center gap-3">
            <UIcon name="i-lucide-package-check" class="size-8 text-muted" />
            <div class="min-w-0">
              <h1 class="text-lg font-semibold text-highlighted">
                {{ extensionShortName(selected.name) }}
              </h1>
              <p class="truncate text-sm text-muted">{{ selected.name }}</p>
            </div>
          </div>
          <UTooltip :text="blocked ?? undefined" :disabled="!blocked">
            <UButton
              :label="selected.enabled ? 'Disable' : 'Enable'"
              :color="selected.enabled ? 'neutral' : 'primary'"
              :variant="selected.enabled ? 'outline' : 'solid'"
              :icon="selected.enabled ? 'i-lucide-power-off' : 'i-lucide-power'"
              :loading="busy"
              :disabled="busy || blocked !== null"
              @click="toggle(selected)"
            />
          </UTooltip>
        </header>

        <p v-if="selected.description" class="text-sm text-default">{{ selected.description }}</p>

        <dl class="grid grid-cols-2 gap-x-6 gap-y-3">
          <div>
            <dt class="text-xs text-muted">Version</dt>
            <dd class="text-sm text-default">{{ selected.version ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Status</dt>
            <dd class="text-sm text-default">{{ selected.enabled ? 'Enabled' : 'Disabled' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Schema</dt>
            <dd class="text-sm text-default">
              <UBadge
                :label="selected.schema_state"
                :color="schemaChipColor(selected.schema_state)"
                variant="subtle"
                size="xs"
              />
            </dd>
          </div>
          <div v-if="selected.schema_reasons.length" class="col-span-2">
            <dt class="text-xs text-muted">Schema notes</dt>
            <dd class="text-sm text-default">
              <p v-for="reason in selected.schema_reasons" :key="reason">{{ reason }}</p>
              <code v-if="selected.cli_command" class="text-xs">{{ selected.cli_command }}</code>
            </dd>
          </div>
          <div class="col-span-2">
            <dt class="text-xs text-muted">Provider</dt>
            <dd class="break-all text-sm text-default">
              <code>{{ selected.provider }}</code>
            </dd>
          </div>
          <div>
            <dt class="text-xs text-muted">Author</dt>
            <dd class="truncate text-sm text-default">{{ selected.author ?? '—' }}</dd>
          </div>
          <div v-if="selected.requires_extensions.length">
            <dt class="text-xs text-muted">Requires extensions</dt>
            <dd class="text-sm text-default">{{ selected.requires_extensions.join(', ') }}</dd>
          </div>
        </dl>

        <!-- README (server-rendered + sanitized: raw HTML escaped, unsafe links blocked, images stripped) -->
        <section class="border-t border-default pt-5">
          <div class="mb-3 flex items-center gap-2">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">Readme</h2>
            <span v-if="readme?.source" class="text-xs text-muted">· {{ readme.source }}</span>
          </div>
          <div v-if="readmeStatus === 'pending'" class="flex justify-center py-6">
            <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
          </div>
          <!-- eslint-disable-next-line vue/no-v-html -- server-sanitized HTML -->
          <div
            v-else-if="readme?.found && readme.html"
            class="readme-prose text-sm text-default"
            v-html="readme.html"
          />
          <p v-else class="text-sm text-muted">This extension doesn’t ship a README.</p>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.readme-prose :deep(h1),
.readme-prose :deep(h2),
.readme-prose :deep(h3),
.readme-prose :deep(h4) {
  margin: 1.25rem 0 0.5rem;
  font-weight: 600;
  line-height: 1.3;
}
.readme-prose :deep(h1) {
  font-size: 1.125rem;
}
.readme-prose :deep(h2) {
  font-size: 1rem;
}
.readme-prose :deep(h3),
.readme-prose :deep(h4) {
  font-size: 0.9375rem;
}
.readme-prose :deep(:first-child) {
  margin-top: 0;
}
.readme-prose :deep(p),
.readme-prose :deep(ul),
.readme-prose :deep(ol),
.readme-prose :deep(blockquote),
.readme-prose :deep(pre),
.readme-prose :deep(table) {
  margin: 0.5rem 0;
}
.readme-prose :deep(ul),
.readme-prose :deep(ol) {
  padding-left: 1.25rem;
}
.readme-prose :deep(ul) {
  list-style: disc;
}
.readme-prose :deep(ol) {
  list-style: decimal;
}
.readme-prose :deep(li) {
  margin: 0.125rem 0;
}
.readme-prose :deep(a) {
  text-decoration: underline;
}
.readme-prose :deep(code) {
  font-family: ui-monospace, monospace;
  font-size: 0.85em;
  padding: 0.1em 0.35em;
  border-radius: 0.25rem;
  background-color: rgb(128 128 128 / 0.15);
}
.readme-prose :deep(pre) {
  padding: 0.75rem;
  border-radius: 0.5rem;
  overflow-x: auto;
  background-color: rgb(128 128 128 / 0.12);
}
.readme-prose :deep(pre code) {
  padding: 0;
  background: transparent;
}
.readme-prose :deep(blockquote) {
  padding-left: 0.75rem;
  border-left: 3px solid rgb(128 128 128 / 0.3);
  color: rgb(128 128 128);
}
.readme-prose :deep(table) {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85em;
}
.readme-prose :deep(th),
.readme-prose :deep(td) {
  border: 1px solid rgb(128 128 128 / 0.25);
  padding: 0.35rem 0.5rem;
  text-align: left;
}
.readme-prose :deep(hr) {
  margin: 1rem 0;
  border: 0;
  border-top: 1px solid rgb(128 128 128 / 0.2);
}
</style>
