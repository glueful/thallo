<script setup lang="ts">
// Task 19d: digital downloads — PER-VARIANT (unlike AddonsPanel's per-product scope): `GET
// /commerce/variants/{uuid}/downloads` IS a real admin read path (`DownloadService::list()`), so
// this panel hydrates directly from it once a variant's section is expanded — no "unknown
// assignment" placeholder dance needed (mirrors AddonsPanel.vue's docblock / CommerceDownload's in
// commerceCatalog.ts). Only one variant's downloads are expanded at a time, mirroring
// VariantsPanel's one-at-a-time `adjustingUuid`/`editingUuid` pattern — so a single
// `useCommerceVariantDownloads()` call (keyed off the expanded uuid) is enough; no per-row query.
//
// Grants (revoke / refund-access override set-clear) are INTENTIONALLY NOT wired into this panel,
// or anywhere else in the admin SPA: there is no admin GET/listing endpoint for a download grant
// anywhere in the shipped backend surface (confirmed against AdminRouteCatalog and
// AdminOrderController::show()'s own payload — see `CommerceGrant`'s docblock in
// commerceCatalog.ts for the full reasoning). The three grant mutations
// (`useCommerceGrantMutations()`) are staged in the query layer against the real endpoints, but
// this is an honest cut: there is nowhere in this admin SPA a grant uuid can be discovered to act
// on, so no revoke/override UI is built here.
//
// Money-free domain: a download definition carries no price of its own (delivery is bundled into
// the owning variant's price) — no `useMoney` import anywhere in this file.
//
// Single-page product editor plan, Task C8 (C1 review "Important" carry-over): the three download
// mutations gained an OPTIONAL `productUuid` in Task C1 (invalidates the owning product + its six
// section reads when supplied, byte-for-byte pre-C1 when omitted) but this panel never passed it —
// now it always does, so a download attach/update/remove settles the shared revision coordinator
// (Task C3) the same way every other section mutation on this page does. Coordinator injected
// OPTIONALLY (`inject(..., null)`) so every pre-C8 spec that mounts this panel directly, with no
// ancestor `ProductRevisionCoordinator`, keeps working unchanged (mirrors AddonsPanel/VariantsPanel/
// MediaPanel's own established convention) — the panel renders inside the editor only, but this
// keeps it working standalone too.
import { computed, inject, reactive, ref } from 'vue'
import {
  useCommerceVariantDownloads,
  useCommerceProductMutations,
  DOWNLOAD_STATUSES,
  type CommerceDownload,
  type CommerceDownloadStatus,
  type CommerceProduct,
  type CommerceVariant,
} from '@/queries/commerceCatalog'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import { ProductRevisionCoordinatorKey } from '@/composables/useProductRevisionCoordinator'
import MediaPickerModal from '@/fields/components/MediaPickerModal.vue'

const props = defineProps<{ product: CommerceProduct; canManage: boolean }>()

const { success, error: notifyError } = useNotify()

// `DownloadService::attach()`'s own docblock: the variant must belong to a `digital`-type product,
// else a 422 ("Downloads can only be attached to a variant of a digital product."). Mirrors that
// exact message here so the SPA never lets an admin attempt an attach it already knows will fail.
const isDigital = computed(() => props.product.type === 'digital')
const statusItems = DOWNLOAD_STATUSES.map((s) => ({ label: s, value: s }))

// ── Expand one variant's downloads at a time ────────────────────────────────────────────────────

const expandedUuid = ref<string | null>(null)
const { data: downloadsData, status } = useCommerceVariantDownloads(
  computed(() => expandedUuid.value ?? ''),
)
const rows = computed<CommerceDownload[]>(() => downloadsData.value ?? [])

function toggleVariant(variantUuid: string) {
  expandedUuid.value = expandedUuid.value === variantUuid ? null : variantUuid
  closeForm()
}

const { attachDownload, updateDownload, removeDownload } = useCommerceProductMutations()
const coordinator = inject(ProductRevisionCoordinatorKey, null)

// ── Create / edit (shared form, mirrors AddonsPanel/VariantsPanel's single shared form) ────────

interface DownloadFormState {
  name: string
  downloadLimitInput: string
  expiryDaysInput: string
  positionInput: string
  status: CommerceDownloadStatus
}

function blankState(): DownloadFormState {
  return { name: '', downloadLimitInput: '', expiryDaysInput: '', positionInput: '', status: 'active' }
}

const formOpen = ref(false)
const editingUuid = ref<string | null>(null)
const pickerOpen = ref(false)
const pendingBlobUuid = ref<string | null>(null)
const state = reactive(blankState())
const formError = ref<string | null>(null)

function closeForm() {
  formOpen.value = false
  editingUuid.value = null
  pendingBlobUuid.value = null
  Object.assign(state, blankState())
  formError.value = null
}

function openCreate() {
  editingUuid.value = null
  pendingBlobUuid.value = null
  Object.assign(state, blankState())
  formError.value = null
  formOpen.value = true
}

function openEdit(download: CommerceDownload) {
  editingUuid.value = download.uuid
  pendingBlobUuid.value = null
  state.name = download.name
  state.downloadLimitInput = download.download_limit === null ? '' : String(download.download_limit)
  state.expiryDaysInput = download.expiry_days === null ? '' : String(download.expiry_days)
  state.positionInput = String(download.position)
  state.status = DOWNLOAD_STATUSES.includes(download.status as CommerceDownloadStatus)
    ? (download.status as CommerceDownloadStatus)
    : 'active'
  formError.value = null
  formOpen.value = true
}

function handlePicked(blobUuid: string) {
  pendingBlobUuid.value = blobUuid
}

/** Blank input = `null` — a REAL value (unlimited downloads / never expires), never "leave
 * unchanged": this form always submits every field on save (mirrors AddonsPanel's discipline).
 * Returns the literal string `'invalid'` for anything that isn't blank or a non-negative whole
 * number, so the caller can surface a message without a second parse. */
function parseNonNegativeIntOrNull(input: string): number | null | 'invalid' {
  const trimmed = input.trim()
  if (trimmed === '') return null
  if (!/^\d+$/.test(trimmed)) return 'invalid'
  return Number(trimmed)
}

async function submitForm(variantUuid: string) {
  formError.value = null

  const name = state.name.trim()
  if (name === '') {
    formError.value = 'Name is required.'
    return
  }
  if (!editingUuid.value && !pendingBlobUuid.value) {
    formError.value = 'Choose a file first.'
    return
  }

  const downloadLimit = parseNonNegativeIntOrNull(state.downloadLimitInput)
  if (downloadLimit === 'invalid') {
    formError.value = 'Download limit must be a whole, non-negative number, or blank for unlimited.'
    return
  }
  const expiryDays = parseNonNegativeIntOrNull(state.expiryDaysInput)
  if (expiryDays === 'invalid') {
    formError.value = 'Expiry days must be a whole, non-negative number, or blank for never.'
    return
  }

  let position: number | null = null
  const positionTrimmed = state.positionInput.trim()
  if (positionTrimmed !== '') {
    if (!/^\d+$/.test(positionTrimmed)) {
      formError.value = 'Position must be a whole, non-negative number.'
      return
    }
    position = Number(positionTrimmed)
  }

  try {
    if (editingUuid.value) {
      await updateDownload.mutateAsync({
        uuid: editingUuid.value,
        variantUuid,
        productUuid: props.product.uuid,
        input: {
          name,
          download_limit: downloadLimit,
          expiry_days: expiryDays,
          position,
          status: state.status,
        },
      })
      await coordinator?.afterMutation()
      success('Download saved', `“${name}” was updated.`)
    } else {
      await attachDownload.mutateAsync({
        variantUuid,
        productUuid: props.product.uuid,
        input: {
          blob_uuid: pendingBlobUuid.value as string,
          name,
          download_limit: downloadLimit,
          expiry_days: expiryDays,
          position,
        },
      })
      await coordinator?.afterMutation()
      success('Download attached', `“${name}” is ready.`)
    }
    closeForm()
  } catch (e) {
    const err = toApiError(e)
    formError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, editingUuid.value ? 'Couldn’t update download' : 'Couldn’t attach download')
  }
}

// ── Detach ───────────────────────────────────────────────────────────────────────────────────

const pendingDelete = ref<{ download: CommerceDownload; variantUuid: string } | null>(null)

async function confirmDelete() {
  const target = pendingDelete.value
  if (!target) return
  try {
    await removeDownload.mutateAsync({
      uuid: target.download.uuid,
      variantUuid: target.variantUuid,
      productUuid: props.product.uuid,
    })
    await coordinator?.afterMutation()
    success('Download detached', `“${target.download.name}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t detach download')
  }
}

function requestDelete(download: CommerceDownload, variant: CommerceVariant) {
  pendingDelete.value = { download, variantUuid: variant.uuid }
}
</script>

<template>
  <div class="space-y-8">
    <section class="space-y-4">
      <h3 class="text-sm font-medium text-default">Downloads</h3>

      <p v-if="canManage && !isDigital" class="text-xs text-muted" data-test="downloads-type-gate">
        Downloads can only be attached to variants of a digital product.
      </p>

      <UAlert
        v-if="product.variants.length === 0"
        color="neutral"
        variant="subtle"
        icon="i-lucide-package"
        title="No variants yet"
        description="Add a variant first (on the Variants tab), then attach downloads to it."
        data-test="downloads-no-variants"
      />

      <div
        v-for="variant in product.variants"
        :key="variant.uuid"
        data-test="download-variant-row"
        :data-uuid="variant.uuid"
        class="space-y-3 rounded-md border border-default p-3"
      >
        <div class="flex flex-wrap items-center gap-3">
          <span class="font-medium text-default">{{ variant.sku }}</span>
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            class="ml-auto"
            :icon="expandedUuid === variant.uuid ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            :label="expandedUuid === variant.uuid ? 'Hide downloads' : 'Show downloads'"
            data-test="download-variant-toggle"
            @click="toggleVariant(variant.uuid)"
          />
        </div>

        <div v-if="expandedUuid === variant.uuid" class="space-y-4 border-t border-default pt-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-muted">Download definitions</span>
            <UButton
              v-if="canManage && isDigital"
              size="xs"
              icon="i-lucide-plus"
              label="Add download"
              data-test="download-add"
              @click="openCreate"
            />
          </div>

          <div v-if="status === 'pending'" class="flex justify-center py-4" data-test="downloads-loading">
            <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
          </div>
          <UAlert
            v-else-if="status === 'error'"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            title="Couldn’t load downloads"
            data-test="downloads-error"
          />
          <UAlert
            v-else-if="rows.length === 0"
            color="neutral"
            variant="subtle"
            icon="i-lucide-file-x"
            title="No downloads yet"
            data-test="downloads-empty"
          />

          <div
            v-for="download in rows"
            :key="download.uuid"
            data-test="download-row"
            :data-uuid="download.uuid"
            class="space-y-2 rounded-md border border-default p-3"
          >
            <div class="flex flex-wrap items-center gap-3">
              <span class="font-medium text-default">{{ download.name }}</span>
              <UBadge :color="download.status === 'active' ? 'success' : 'neutral'" variant="subtle" size="sm">
                {{ download.status }}
              </UBadge>
              <span class="text-xs text-muted" data-test="download-limit">
                {{ download.download_limit === null ? 'Unlimited downloads' : `${download.download_limit} download(s)` }}
              </span>
              <span class="text-xs text-muted" data-test="download-expiry">
                {{ download.expiry_days === null ? 'Never expires' : `Expires ${download.expiry_days} day(s) after purchase` }}
              </span>

              <div v-if="canManage" class="ml-auto flex gap-1">
                <UButton
                  size="xs"
                  color="neutral"
                  variant="ghost"
                  icon="i-lucide-pencil"
                  aria-label="Edit download"
                  data-test="download-edit"
                  @click="openEdit(download)"
                />
                <UButton
                  size="xs"
                  color="error"
                  variant="ghost"
                  icon="i-lucide-trash-2"
                  aria-label="Detach download"
                  data-test="download-delete"
                  @click="requestDelete(download, variant)"
                />
              </div>
            </div>
          </div>

          <!-- Create/edit form ------------------------------------------------------------- -->
          <template v-if="canManage">
            <UAlert
              v-if="formError"
              color="error"
              variant="subtle"
              icon="i-lucide-triangle-alert"
              data-test="download-form-error"
              :title="formError"
            />

            <form
              v-if="formOpen"
              id="download-form"
              class="space-y-3 rounded-md border border-default p-3"
              @submit.prevent="submitForm(variant.uuid)"
            >
              <div v-if="!editingUuid" class="flex flex-wrap items-center gap-3">
                <UButton
                  size="xs"
                  color="neutral"
                  variant="subtle"
                  icon="i-lucide-file-plus"
                  label="Choose file"
                  data-test="download-choose-file"
                  @click="pickerOpen = true"
                />
                <span v-if="pendingBlobUuid" class="text-xs text-muted" data-test="download-chosen-blob">
                  Selected: {{ pendingBlobUuid }}
                </span>
              </div>

              <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <UFormField label="Name" name="name" required>
                  <UInput v-model="state.name" class="w-full" data-test="download-name-input" />
                </UFormField>
                <UFormField label="Download limit" name="downloadLimit" help="Blank = unlimited">
                  <UInput v-model="state.downloadLimitInput" class="w-full" data-test="download-limit-input" />
                </UFormField>
                <UFormField label="Expiry (days)" name="expiryDays" help="Blank = never">
                  <UInput v-model="state.expiryDaysInput" class="w-full" data-test="download-expiry-input" />
                </UFormField>
                <UFormField label="Position" name="position" help="Blank = append">
                  <UInput v-model="state.positionInput" class="w-full" data-test="download-position-input" />
                </UFormField>
              </div>

              <UFormField v-if="editingUuid" label="Status" name="status" class="max-w-48">
                <USelect v-model="state.status" :items="statusItems" class="w-full" data-test="download-status-input" />
              </UFormField>

              <div class="flex gap-2">
                <UButton
                  type="submit"
                  size="xs"
                  :loading="attachDownload.isLoading.value || updateDownload.isLoading.value"
                  :label="editingUuid ? 'Save' : 'Attach'"
                  data-test="download-form-submit"
                />
                <UButton size="xs" color="neutral" variant="ghost" label="Cancel" @click="closeForm" />
              </div>
            </form>
          </template>
        </div>
      </div>
    </section>

    <MediaPickerModal v-model:open="pickerOpen" visibility="private" media-type="" @select="handlePicked" />
  </div>

  <UModal
    :open="pendingDelete !== null"
    title="Detach download"
    @update:open="(v: boolean) => { if (!v) pendingDelete = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Detach <span class="text-default">“{{ pendingDelete?.download.name }}”</span>? This can’t be
        undone. The underlying file itself is never deleted.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="removeDownload.isLoading.value"
          @click="() => { pendingDelete = null }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Detach"
          data-test="download-delete-confirm"
          :loading="removeDownload.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>
