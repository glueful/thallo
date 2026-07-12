<script setup lang="ts">
import { computed, ref } from 'vue'
import type { CapabilityDefinition, WorkspaceRole } from '@/queries/tenantRoles'

const props = defineProps<{
  role: WorkspaceRole
  catalog: Record<string, CapabilityDefinition>
  busy: boolean
  preview: { added: string[]; removed: string[] } | null
}>()

const emit = defineEmits<{
  preview: [grants: string[], revokes: string[]]
  save: [grants: string[], revokes: string[]]
}>()

const ownerFloor = new Set(['tenant.roles.manage', 'tenant.members.manage'])
const assigned = ref<Set<string>>(new Set(props.role.effective))
const selectedAvailable = ref<Set<string>>(new Set())
const selectedAssigned = ref<Set<string>>(new Set())
const availableSearch = ref('')
const assignedSearch = ref('')

interface CatalogEntry {
  slug: string
  label: string
  group: string
  locked: boolean
}

const entries = computed<CatalogEntry[]>(() =>
  Object.entries(props.catalog)
    .filter(([, definition]) => !definition.platform_only)
    .map(([slug, definition]) => ({
      slug,
      label: definition.label,
      group: definition.group,
      locked: props.role.slug === 'owner' && ownerFloor.has(slug),
    }))
    .sort((left, right) =>
      left.group === right.group
        ? left.label.localeCompare(right.label)
        : left.group.localeCompare(right.group),
    ),
)

function matches(entry: CatalogEntry, term: string): boolean {
  const normalized = term.trim().toLowerCase()
  if (!normalized) return true
  return [entry.label, entry.slug, entry.group].some((value) =>
    value.toLowerCase().includes(normalized),
  )
}

const available = computed(() =>
  entries.value
    .filter((entry) => !assigned.value.has(entry.slug))
    .filter((entry) => matches(entry, availableSearch.value)),
)
const assignedEntries = computed(() =>
  entries.value
    .filter((entry) => assigned.value.has(entry.slug))
    .filter((entry) => matches(entry, assignedSearch.value)),
)
const removableAssigned = computed(() => assignedEntries.value.filter((entry) => !entry.locked))

function toggled(set: Set<string>, slug: string): Set<string> {
  const next = new Set(set)
  if (next.has(slug)) next.delete(slug)
  else next.add(slug)
  return next
}

function toggleAvailable(slug: string): void {
  selectedAvailable.value = toggled(selectedAvailable.value, slug)
}

function toggleAssigned(slug: string): void {
  selectedAssigned.value = toggled(selectedAssigned.value, slug)
}

function selectAllAvailable(): void {
  selectedAvailable.value = new Set(available.value.map((entry) => entry.slug))
}

function selectAllAssigned(): void {
  selectedAssigned.value = new Set(removableAssigned.value.map((entry) => entry.slug))
}

function assignSelected(): void {
  assigned.value = new Set([...assigned.value, ...selectedAvailable.value])
  selectedAvailable.value = new Set()
}

function assignAll(): void {
  assigned.value = new Set([...assigned.value, ...available.value.map((entry) => entry.slug)])
  selectedAvailable.value = new Set()
}

function removeSelected(): void {
  const next = new Set(assigned.value)
  for (const slug of selectedAssigned.value) {
    if (!(props.role.slug === 'owner' && ownerFloor.has(slug))) next.delete(slug)
  }
  assigned.value = next
  selectedAssigned.value = new Set()
}

function removeAll(): void {
  assigned.value = new Set(props.role.slug === 'owner' ? ownerFloor : [])
  selectedAssigned.value = new Set()
}

function deltas(): { grants: string[]; revokes: string[] } {
  const effective = [...assigned.value].sort()
  if (!props.role.builtin) return { grants: effective, revokes: [] }

  const baseline = new Set(props.role.baseline)
  return {
    grants: effective.filter((slug) => !baseline.has(slug)),
    revokes: [...baseline].filter((slug) => !assigned.value.has(slug)).sort(),
  }
}

function send(event: 'preview' | 'save'): void {
  const { grants, revokes } = deltas()
  if (event === 'preview') emit('preview', grants, revokes)
  else emit('save', grants, revokes)
}
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-4">
    <div class="flex min-h-0 flex-1 flex-col gap-3 xl:grid xl:grid-cols-[1fr_auto_1fr]">
      <div class="flex min-h-60 flex-col rounded-xl border border-default xl:min-h-0">
        <div class="flex items-center justify-between border-b border-default px-3 py-2">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-highlighted">Available</span>
            <UBadge :label="String(available.length)" color="neutral" variant="subtle" size="xs" />
          </div>
          <UButton
            label="Select all"
            color="primary"
            variant="link"
            size="xs"
            :disabled="!available.length"
            @click="selectAllAvailable"
          />
        </div>
        <div class="px-3 py-2">
          <UInput
            v-model="availableSearch"
            icon="i-lucide-search"
            placeholder="Search…"
            aria-label="Search available permissions"
            size="sm"
            class="w-full"
          />
        </div>
        <div class="flex-1 overflow-y-auto px-1 pb-2">
          <div
            v-if="!available.length"
            class="flex items-center justify-center py-8 text-xs text-muted"
          >
            All permissions assigned
          </div>
          <button
            v-for="entry in available"
            :key="entry.slug"
            type="button"
            class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors"
            :class="
              selectedAvailable.has(entry.slug)
                ? 'bg-primary/10 text-primary'
                : 'text-toned hover:bg-elevated'
            "
            :data-testid="`available-${entry.slug}`"
            :aria-pressed="selectedAvailable.has(entry.slug)"
            @click="toggleAvailable(entry.slug)"
          >
            <span
              class="size-2 shrink-0 rounded-full"
              :class="selectedAvailable.has(entry.slug) ? 'bg-primary' : 'bg-accented'"
            />
            <span class="min-w-0">
              <span class="block truncate">{{ entry.label }}</span>
              <code class="block truncate text-xs text-muted">{{ entry.slug }}</code>
            </span>
          </button>
        </div>
      </div>

      <div class="flex flex-row items-center justify-center gap-2 xl:flex-col">
        <UButton
          icon="i-lucide-chevron-right"
          color="neutral"
          variant="outline"
          size="xs"
          aria-label="Assign selected"
          data-testid="assign-selected"
          :disabled="!selectedAvailable.size"
          @click="assignSelected"
        />
        <UButton
          icon="i-lucide-chevrons-right"
          color="neutral"
          variant="outline"
          size="xs"
          aria-label="Assign all"
          :disabled="!available.length"
          @click="assignAll"
        />
        <UButton
          icon="i-lucide-chevron-left"
          color="neutral"
          variant="outline"
          size="xs"
          aria-label="Remove selected"
          data-testid="remove-selected"
          :disabled="!selectedAssigned.size"
          @click="removeSelected"
        />
        <UButton
          icon="i-lucide-chevrons-left"
          color="neutral"
          variant="outline"
          size="xs"
          aria-label="Remove all"
          :disabled="!removableAssigned.length"
          @click="removeAll"
        />
      </div>

      <div class="flex min-h-60 flex-col rounded-xl border border-default xl:min-h-0">
        <div class="flex items-center justify-between border-b border-default px-3 py-2">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-highlighted">Assigned</span>
            <UBadge :label="String(assigned.size)" color="primary" variant="subtle" size="xs" />
          </div>
          <UButton
            label="Select all"
            color="primary"
            variant="link"
            size="xs"
            :disabled="!removableAssigned.length"
            @click="selectAllAssigned"
          />
        </div>
        <div class="px-3 py-2">
          <UInput
            v-model="assignedSearch"
            icon="i-lucide-search"
            placeholder="Search…"
            aria-label="Search assigned permissions"
            size="sm"
            class="w-full"
          />
        </div>
        <div class="flex-1 overflow-y-auto px-1 pb-2">
          <div
            v-if="!assigned.size"
            class="flex items-center justify-center py-8 text-xs text-muted"
          >
            No permissions assigned
          </div>
          <button
            v-for="entry in assignedEntries"
            :key="entry.slug"
            type="button"
            class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors"
            :class="[
              selectedAssigned.has(entry.slug)
                ? 'bg-primary/10 text-primary'
                : 'text-toned hover:bg-elevated',
              entry.locked ? 'cursor-not-allowed opacity-70' : '',
            ]"
            :disabled="entry.locked"
            :data-testid="`assigned-${entry.slug}`"
            :aria-pressed="entry.locked ? undefined : selectedAssigned.has(entry.slug)"
            @click="toggleAssigned(entry.slug)"
          >
            <UIcon
              v-if="entry.locked"
              name="i-lucide-lock-keyhole"
              class="size-3.5 shrink-0 text-muted"
            />
            <span
              v-else
              class="size-2 shrink-0 rounded-full"
              :class="selectedAssigned.has(entry.slug) ? 'bg-primary' : 'bg-primary/40'"
            />
            <span class="min-w-0 flex-1">
              <span class="block truncate">{{ entry.label }}</span>
              <code class="block truncate text-xs text-muted">{{ entry.slug }}</code>
            </span>
            <span v-if="entry.locked" class="text-xs text-muted">Required</span>
          </button>
        </div>
      </div>
    </div>

    <div class="flex w-full flex-wrap items-center justify-end gap-2">
      <span v-if="preview" class="me-auto text-xs text-muted">
        +{{ preview.added.length }} / -{{ preview.removed.length }}
      </span>
      <UButton
        label="Preview"
        color="neutral"
        variant="outline"
        data-testid="overrides-preview"
        :disabled="busy"
        @click="send('preview')"
      />
      <UButton
        icon="i-lucide-check"
        label="Save permissions"
        data-testid="overrides-save"
        :loading="busy"
        @click="send('save')"
      />
    </div>
  </div>
</template>
