<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useQueryCache } from '@pinia/colada'
import {
  useBlockTypeUsage,
  useBlockTypeMigrations,
  declareBlockTypeMigration,
  deleteBlockType,
  type BlockMigrationOp,
} from '@/queries/blockTypes'
import type { ContentTypeField } from '@/queries/contentTypes'
import { qk } from '@/queries/keys'
import { useNotify } from '@/composables/useNotify'

// Usage panel + hard-delete + schema-migration dialog (block-migrations spec §6/§7).
// Deactivate = editorial lifecycle (the header toggle); delete = destructive cleanup
// for UNUSED types only; rename/delete of fields = declared migration.

const props = defineProps<{
  slug: string
  schema: ContentTypeField[]
}>()

const router = useRouter()
const cache = useQueryCache()
const { success, warning, error: notifyError } = useNotify()

const { data: usage, status: usageStatus } = useBlockTypeUsage(() => props.slug)
const { data: migrations } = useBlockTypeMigrations(() => props.slug)

const activeMigration = computed(
  () => (migrations.value ?? []).find((m) => m.status === 'running' || m.status === 'failed') ?? null,
)
defineExpose({ activeMigration })

const deletable = computed(() => (usage.value?.total ?? 1) === 0 && activeMigration.value === null)

// ── Hard delete ────────────────────────────────────────────────────────────────
const confirmingDelete = ref(false)
const deleting = ref(false)

function askDelete() {
  confirmingDelete.value = true
}

function cancelDelete() {
  confirmingDelete.value = false
}

async function confirmDelete() {
  deleting.value = true
  try {
    await deleteBlockType(props.slug)
    success('Block type deleted')
    await cache.invalidateQueries({ key: qk.blockTypes() })
    await router.push('/settings/block-types')
  } catch (e) {
    // 409 races (someone used it, or a migration started) surface as-is.
    notifyError(e, 'Couldn’t delete the block type')
    await cache.invalidateQueries({ key: qk.blockTypeUsage(props.slug) })
  } finally {
    deleting.value = false
    confirmingDelete.value = false
  }
}

// ── Migrate fields dialog ──────────────────────────────────────────────────────
interface OpRow {
  op: 'rename' | 'delete'
  from: string
  to: string
}

const migrateOpen = ref(false)
const opRows = ref<OpRow[]>([])
const declaring = ref(false)

const fieldNames = computed(() => props.schema.map((f) => f.name))
const fieldOptions = computed(() => fieldNames.value.map((n) => ({ label: n, value: n })))

function openMigrate() {
  opRows.value = [{ op: 'rename', from: fieldNames.value[0] ?? '', to: '' }]
  migrateOpen.value = true
}

function closeMigrate() {
  migrateOpen.value = false
}

function addOpRow() {
  opRows.value.push({ op: 'rename', from: fieldNames.value[0] ?? '', to: '' })
}

function removeOpRow(index: number) {
  opRows.value.splice(index, 1)
}

const opsValid = computed(
  () =>
    opRows.value.length > 0 &&
    opRows.value.every(
      (r) => r.from !== '' && (r.op === 'delete' || (r.to.trim() !== '' && r.to.trim() !== r.from)),
    ),
)

async function declareMigration() {
  const ops: BlockMigrationOp[] = opRows.value.map((r) =>
    r.op === 'delete' ? { op: 'delete', name: r.from } : { op: 'rename', from: r.from, to: r.to.trim() },
  )
  declaring.value = true
  try {
    await declareBlockTypeMigration(props.slug, ops)
    warning(
      'Migration started',
      'Saves and publishes of entries using this block are blocked until the backfill completes.',
    )
    migrateOpen.value = false
    await cache.invalidateQueries({ key: qk.blockTypeMigrations(props.slug) })
    await cache.invalidateQueries({ key: qk.blockTypes() })
  } catch (e) {
    notifyError(e, 'Couldn’t start the migration')
  } finally {
    declaring.value = false
  }
}
</script>

<template>
  <UCard data-testid="block-usage">
    <template #header>
      <h2 class="font-semibold text-default">Usage &amp; lifecycle</h2>
    </template>

    <div class="space-y-4">
      <div v-if="usageStatus === 'pending'"><USkeleton class="h-8" /></div>
      <template v-else-if="usage">
        <p class="text-sm text-muted" data-testid="block-usage-total">
          Used in <strong>{{ usage.total }}</strong> current draft{{ usage.total === 1 ? '' : 's' }}/publication{{
            usage.total === 1 ? '' : 's'
          }}.
        </p>
        <ul v-if="usage.per_type.length" class="space-y-1 text-sm">
          <li v-for="row in usage.per_type" :key="row.type">
            <span class="font-medium">{{ row.type }}</span
            >: {{ row.drafts }} draft(s), {{ row.publications }} publication(s)
            <span v-if="row.sample.length" class="text-muted">
              — e.g. {{ row.sample.map((s) => s.title ?? s.entry_uuid).join(', ') }}
            </span>
          </li>
        </ul>
        <p v-if="usage.allowlists.length" class="text-xs text-muted">
          Listed in the block picker allowlist of: {{ usage.allowlists.join(', ') }} (does not block
          deletion).
        </p>
      </template>

      <div
        v-if="activeMigration"
        class="rounded border border-warning/40 bg-warning/10 p-3 text-sm"
        data-testid="block-migration-status"
      >
        <p class="font-medium">
          Migration {{ activeMigration.status }} — {{ activeMigration.work_items_done }}/{{
            activeMigration.work_items_total
          }}
          items<span v-if="activeMigration.work_items_failed > 0">
            ({{ activeMigration.work_items_failed }} failed)</span
          >.
        </p>
        <p class="text-muted">
          Entries containing this block cannot be saved or published until it completes.
          <span v-if="activeMigration.status === 'failed'">
            Re-drive it with <code>php glueful thallo:blocks:migration:backfill {{ activeMigration.uuid }}</code>.
          </span>
        </p>
      </div>

      <div class="flex items-center gap-2">
        <UButton
          variant="outline"
          color="warning"
          icon="i-lucide-git-branch"
          :disabled="activeMigration !== null"
          data-testid="block-migrate"
          @click="openMigrate"
        >
          Migrate fields
        </UButton>
        <UButton
          variant="outline"
          color="error"
          icon="i-lucide-trash-2"
          :disabled="!deletable"
          :loading="deleting"
          data-testid="block-delete"
          @click="askDelete"
        >
          Delete block type
        </UButton>
      </div>

      <div
        v-if="confirmingDelete"
        class="rounded border border-error/40 bg-error/10 p-3 text-sm"
        data-testid="block-delete-confirm"
      >
        <p class="mb-2">
          Permanently delete “{{ slug }}”? This cannot be undone — versions that referenced it can no
          longer be restored. Deactivating is the reversible alternative.
        </p>
        <div class="flex gap-2">
          <UButton color="error" size="xs" data-testid="block-delete-confirm-yes" @click="confirmDelete">
            Delete permanently
          </UButton>
          <UButton variant="ghost" color="neutral" size="xs" @click="cancelDelete">Cancel</UButton>
        </div>
      </div>

      <div v-if="migrateOpen" class="space-y-3 rounded border border-default p-3" data-testid="block-migrate-dialog">
        <p class="text-sm text-muted">
          Declare rename/delete operations. The schema flips immediately and a background backfill
          rewrites every current draft and publication; affected entries are locked until it
          completes.
        </p>
        <div
          v-for="(row, i) in opRows"
          :key="i"
          class="flex items-center gap-2"
          :data-testid="`block-migrate-op-${i}`"
        >
          <USelect
            v-model="row.op"
            :items="[
              { label: 'Rename', value: 'rename' },
              { label: 'Delete', value: 'delete' },
            ]"
            class="w-28"
          />
          <USelect v-model="row.from" :items="fieldOptions" class="w-40" />
          <template v-if="row.op === 'rename'">
            <span class="text-muted">→</span>
            <UInput v-model="row.to" placeholder="new name" class="w-40" />
          </template>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-x"
            size="xs"
            aria-label="Remove operation"
            @click="removeOpRow(i)"
          />
        </div>
        <div class="flex gap-2">
          <UButton variant="ghost" size="xs" icon="i-lucide-plus" @click="addOpRow">Add operation</UButton>
        </div>
        <div class="flex gap-2">
          <UButton
            color="warning"
            size="sm"
            :disabled="!opsValid"
            :loading="declaring"
            data-testid="block-migrate-submit"
            @click="declareMigration"
          >
            Start migration
          </UButton>
          <UButton variant="ghost" color="neutral" size="sm" @click="closeMigrate">Cancel</UButton>
        </div>
      </div>
    </div>
  </UCard>
</template>
