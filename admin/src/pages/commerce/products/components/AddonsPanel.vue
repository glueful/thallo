<script setup lang="ts">
// Task 19c: product add-ons — PER-PRODUCT (unlike Tags/CategoriesTab/AttributesTab's dual-mode
// tenant-wide taxonomy design): there is no top-level "Add-ons" tab on products/index.vue, only
// this one panel, mounted from the product detail's own tab bar with `product` always given.
//
// Unlike every other product-scoped sub-resource (media/children/tag/category/attribute
// assignment), `GET /commerce/products/{uuid}/addons` IS a real admin read path
// (`AddonService::list()`/`AddonRepository::forProduct()`) — so this panel hydrates its list
// directly from `useCommerceProductAddons()`, no "assignment not loaded yet" placeholder dance
// (see `CommerceAddon`'s docblock in commerceCatalog.ts).
//
// Money: `price_delta` is a SIGNED minor-unit integer (checkbox/text add-ons carry it on the row;
// select add-ons carry it per-choice and force the row's own price_delta to 0 — AddonService's own
// docblock). The established `parseMajorAmountToMinorUnits` only accepts an UNSIGNED decimal, so
// this file wraps it with an explicit sign-strip (`parseSignedMajorAmountToMinorUnits` below) —
// same exact BigInt/regex parsing underneath, never `Number()`, never float math — and its
// inverse (`minorToDecimalString`) for pre-populating the edit form. Display always goes through
// `useMoney`/`money()` (VariantsPanel's meta-not-loaded-yet guard), never a raw digit string.
//
// Snapshot immutability: `AddonService`'s docblock is explicit that a definition edit/status flip
// never touches an existing cart/order line (`AddonSnapshot` bakes display+price into the line at
// selection time) — surfaced here as a standing notice, not just a one-off toast, since it's the
// kind of thing an admin needs to know BEFORE editing or deactivating, not just after.
import { computed, reactive, ref } from 'vue'
import {
  useCommerceProductAddons,
  useCommerceProductMutations,
  ADDON_FIELD_TYPES,
  ADDON_STATUSES,
  type CommerceAddon,
  type CommerceAddonFieldType,
  type CommerceAddonStatus,
  type CommerceProduct,
} from '@/queries/commerceCatalog'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useMoney, parseMajorAmountToMinorUnits } from '@/composables/useMoney'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ product: CommerceProduct; canManage: boolean }>()

const { success, error: notifyError } = useNotify()
const { data: meta } = useCommerceMeta()
const { format } = useMoney()
const currencyExponent = computed(() => meta.value?.currency_exponent ?? 2)

/** `useMoney().format()` throws until `/commerce/meta` resolves — guard every render site so an
 * unsettled meta query never crashes the panel (mirrors VariantsPanel's `money()`). */
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return String(minor)
  }
}

/** Reverse of `parseSignedMajorAmountToMinorUnits()` below — plain BigInt division/modulo, no
 * `Number()` on the way (mirrors DiscountForm.vue's `minorToDecimalString`, extended with a sign
 * since add-on price deltas — unlike a discount's value — can be negative). */
function minorToDecimalString(minor: number, exponent: number): string {
  const negative = minor < 0
  const abs = BigInt(Math.trunc(Math.abs(minor)))
  if (exponent === 0) return (negative ? '-' : '') + abs.toString()
  const scale = 10n ** BigInt(exponent)
  const major = abs / scale
  const fraction = (abs % scale).toString().padStart(exponent, '0')
  return `${negative ? '-' : ''}${major}.${fraction}`
}

/** `parseMajorAmountToMinorUnits()` only accepts an UNSIGNED decimal (its own docblock: rejects "a
 * sign" outright). Add-on price deltas are explicitly a SIGNED integer
 * (`AddonService::normalizeChoices()`'s own docblock: "a signed integer price_delta per choice"),
 * so this layers an optional leading '-' strip on top, keeping the SAME exact BigInt/regex parsing
 * underneath — never `Number()`, never float math. Returns `null` for anything the unsigned parser
 * would reject once the sign is removed. */
function parseSignedMajorAmountToMinorUnits(input: string, exponent: number): bigint | null {
  const trimmed = input.trim()
  const negative = trimmed.startsWith('-')
  const unsigned = negative ? trimmed.slice(1) : trimmed
  const minor = parseMajorAmountToMinorUnits(unsigned, exponent)
  if (minor === null) return null
  return negative ? -minor : minor
}

const productUuid = computed(() => props.product.uuid)
const { data: addonsData, status } = useCommerceProductAddons(productUuid)
const { createAddon, updateAddon, removeAddon } = useCommerceProductMutations()

const rows = computed<CommerceAddon[]>(() => addonsData.value ?? [])

const fieldTypeItems = ADDON_FIELD_TYPES.map((t) => ({ label: t, value: t }))
const statusItems = ADDON_STATUSES.map((s) => ({ label: s, value: s }))

// ── Create / edit (shared form, mirrors TagsTab/AttributesTab's single shared form) ────────────

interface AddonFormState {
  name: string
  fieldType: CommerceAddonFieldType
  required: boolean
  status: CommerceAddonStatus
  positionInput: string
  priceDeltaInput: string
}

function blankState(): AddonFormState {
  return {
    name: '',
    fieldType: 'text',
    required: false,
    status: 'active',
    positionInput: '',
    priceDeltaInput: '',
  }
}

interface ChoiceRow {
  id: string
  key: string
  label: string
  priceDeltaInput: string
}

let choiceRowSeq = 0
function blankChoiceRow(): ChoiceRow {
  return { id: `choice-${choiceRowSeq++}`, key: '', label: '', priceDeltaInput: '' }
}

const formOpen = ref(false)
const editingUuid = ref<string | null>(null)
const state = reactive(blankState())
const choices = reactive<ChoiceRow[]>([])
const formError = ref<string | null>(null)
const choicesError = ref<string | null>(null)

function openCreate() {
  editingUuid.value = null
  Object.assign(state, blankState())
  choices.splice(0, choices.length)
  formError.value = null
  choicesError.value = null
  formOpen.value = true
}

function openEdit(addon: CommerceAddon) {
  editingUuid.value = addon.uuid
  state.name = addon.name
  state.fieldType = ADDON_FIELD_TYPES.includes(addon.field_type as CommerceAddonFieldType)
    ? (addon.field_type as CommerceAddonFieldType)
    : 'text'
  state.required = addon.required
  state.status = ADDON_STATUSES.includes(addon.status as CommerceAddonStatus)
    ? (addon.status as CommerceAddonStatus)
    : 'active'
  state.positionInput = String(addon.position)
  state.priceDeltaInput =
    state.fieldType === 'select' ? '' : minorToDecimalString(addon.price_delta, currencyExponent.value)

  choices.splice(0, choices.length)
  for (const choice of addon.choices ?? []) {
    choices.push({
      id: `choice-${choiceRowSeq++}`,
      key: choice.key,
      label: choice.label,
      priceDeltaInput: minorToDecimalString(choice.price_delta, currencyExponent.value),
    })
  }

  formError.value = null
  choicesError.value = null
  formOpen.value = true
}

function cancelForm() {
  formOpen.value = false
}

function addChoiceRow() {
  choices.push(blankChoiceRow())
}

function removeChoiceRow(index: number) {
  choices.splice(index, 1)
}

async function submitForm() {
  formError.value = null
  choicesError.value = null

  const name = state.name.trim()
  if (name === '') {
    formError.value = 'Name is required.'
    return
  }

  let positionValue: number | null = null
  const positionTrimmed = state.positionInput.trim()
  if (positionTrimmed !== '') {
    if (!/^\d+$/.test(positionTrimmed)) {
      formError.value = 'Position must be a whole, non-negative number.'
      return
    }
    positionValue = Number(positionTrimmed)
  }

  let priceDelta = 0
  let builtChoices: Array<{ key: string; label: string; price_delta: number }> | null = null

  if (state.fieldType === 'select') {
    if (choices.length === 0) {
      choicesError.value = 'Add at least one choice.'
      return
    }
    const seenKeys = new Set<string>()
    builtChoices = []
    for (const row of choices) {
      const key = row.key.trim()
      const label = row.label.trim()
      if (key === '' || label === '') {
        choicesError.value = 'Every choice needs a key and a label.'
        return
      }
      if (seenKeys.has(key)) {
        choicesError.value = `Duplicate choice key “${key}”.`
        return
      }
      seenKeys.add(key)

      const rawDelta = row.priceDeltaInput.trim()
      const minor = parseSignedMajorAmountToMinorUnits(rawDelta === '' ? '0' : rawDelta, currencyExponent.value)
      if (minor === null) {
        choicesError.value = `Enter a valid price delta for “${label}”.`
        return
      }
      builtChoices.push({ key, label, price_delta: Number(minor) })
    }
  } else {
    const rawDelta = state.priceDeltaInput.trim()
    const minor = parseSignedMajorAmountToMinorUnits(rawDelta === '' ? '0' : rawDelta, currencyExponent.value)
    if (minor === null) {
      formError.value =
        currencyExponent.value === 0
          ? 'Enter a whole-number price delta.'
          : `Enter a valid price delta (up to ${currencyExponent.value} decimal places).`
      return
    }
    priceDelta = Number(minor)
  }

  const payload = {
    name,
    field_type: state.fieldType,
    required: state.required,
    choices: builtChoices,
    price_delta: state.fieldType === 'select' ? 0 : priceDelta,
    position: positionValue,
    status: state.status,
  }

  try {
    if (editingUuid.value) {
      await updateAddon.mutateAsync({
        uuid: editingUuid.value,
        productUuid: props.product.uuid,
        input: payload,
      })
      success('Add-on saved', `“${name}” was updated.`)
    } else {
      await createAddon.mutateAsync({ productUuid: props.product.uuid, input: payload })
      success('Add-on created', `“${name}” is ready.`)
    }
    formOpen.value = false
  } catch (e) {
    const err = toApiError(e)
    formError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, editingUuid.value ? 'Couldn’t update add-on' : 'Couldn’t create add-on')
  }
}

// ── Delete ───────────────────────────────────────────────────────────────────────────────────

const pendingDelete = ref<CommerceAddon | null>(null)
async function confirmDelete() {
  const addon = pendingDelete.value
  if (!addon) return
  try {
    await removeAddon.mutateAsync({ uuid: addon.uuid, productUuid: props.product.uuid })
    success('Add-on deleted', `“${addon.name}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete add-on')
  }
}
</script>

<template>
  <div class="space-y-8">
    <section class="space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-medium text-default">Add-ons</h3>
        <UButton
          v-if="canManage"
          size="xs"
          icon="i-lucide-plus"
          label="Add add-on"
          data-test="addon-add"
          @click="openCreate"
        />
      </div>

      <UAlert
        color="neutral"
        variant="subtle"
        icon="i-lucide-info"
        title="Edits apply to future selections only"
        description="Existing cart and order lines keep the add-on's original name and price — each line snapshots the add-on at the moment it was selected. Marking an add-on inactive only removes it from NEW selections; past orders are unaffected."
        data-test="addon-snapshot-notice"
      />

      <div v-if="status === 'pending'" class="flex justify-center py-6" data-test="addons-loading">
        <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
      </div>
      <UAlert
        v-else-if="status === 'error'"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Couldn’t load add-ons"
        data-test="addons-error"
      />
      <UAlert
        v-else-if="rows.length === 0"
        color="neutral"
        variant="subtle"
        icon="i-lucide-list-plus"
        title="No add-ons yet"
        data-test="addons-empty"
      />

      <div
        v-for="addon in rows"
        :key="addon.uuid"
        data-test="addon-row"
        :data-uuid="addon.uuid"
        class="space-y-2 rounded-md border border-default p-3"
      >
        <div class="flex flex-wrap items-center gap-3">
          <span class="font-medium text-default">{{ addon.name }}</span>
          <UBadge color="neutral" variant="subtle" size="sm">{{ addon.field_type }}</UBadge>
          <UBadge v-if="addon.required" color="primary" variant="subtle" size="sm">Required</UBadge>
          <UBadge :color="addon.status === 'active' ? 'success' : 'neutral'" variant="subtle" size="sm">
            {{ addon.status }}
          </UBadge>
          <span v-if="addon.field_type !== 'select'" data-test="addon-price" class="text-default">
            {{ money(addon.price_delta) }}
          </span>

          <div v-if="canManage" class="ml-auto flex gap-1">
            <UButton
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-pencil"
              aria-label="Edit add-on"
              data-test="addon-edit"
              @click="openEdit(addon)"
            />
            <UButton
              size="xs"
              color="error"
              variant="ghost"
              icon="i-lucide-trash-2"
              aria-label="Delete add-on"
              data-test="addon-delete"
              @click="() => { pendingDelete = addon }"
            />
          </div>
        </div>

        <div v-if="addon.field_type === 'select' && addon.choices" class="ml-1 space-y-1">
          <div
            v-for="choice in addon.choices"
            :key="choice.key"
            data-test="addon-choice-item"
            class="flex items-center gap-3 text-sm text-muted"
          >
            <span>{{ choice.label }}</span>
            <span data-test="addon-choice-price">{{ money(choice.price_delta) }}</span>
          </div>
        </div>
      </div>
    </section>

    <!-- Create/edit form ---------------------------------------------------------------------- -->
    <template v-if="canManage">
      <UAlert
        v-if="formError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        data-test="addon-form-error"
        :title="formError"
      />

      <form
        v-if="formOpen"
        id="addon-form"
        class="space-y-4 rounded-md border border-default p-3"
        @submit.prevent="submitForm"
      >
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <UFormField label="Name" name="name" required>
            <UInput v-model="state.name" class="w-full" data-test="addon-name-input" />
          </UFormField>
          <UFormField label="Field type" name="fieldType">
            <USelect v-model="state.fieldType" :items="fieldTypeItems" class="w-full" data-test="addon-field-type-input" />
          </UFormField>
          <UFormField label="Status" name="status" :help="state.status === 'inactive' ? 'Hidden from new selections; past orders keep it.' : undefined">
            <USelect v-model="state.status" :items="statusItems" class="w-full" data-test="addon-status-input" />
          </UFormField>
          <UFormField label="Position" name="position" help="Optional — leave blank to append.">
            <UInput v-model="state.positionInput" class="w-full" data-test="addon-position-input" />
          </UFormField>
        </div>

        <UCheckbox v-model="state.required" label="Required" data-test="addon-required-checkbox" />

        <UFormField
          v-if="state.fieldType !== 'select'"
          label="Price delta"
          name="priceDelta"
          help="Signed amount, e.g. -2.00 or 3.50. Leave blank for 0."
        >
          <UInput v-model="state.priceDeltaInput" placeholder="0.00" class="w-full" data-test="addon-price-delta-input" />
        </UFormField>

        <div v-else class="space-y-3">
          <div class="flex items-center justify-between">
            <h4 class="text-sm font-medium text-default">Choices</h4>
            <UButton
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-plus"
              label="Add choice"
              data-test="addon-choice-add"
              @click="addChoiceRow"
            />
          </div>

          <UAlert
            v-if="choicesError"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            data-test="addon-choices-error"
            :title="choicesError"
          />

          <div
            v-for="(row, index) in choices"
            :key="row.id"
            data-test="addon-choice-row"
            class="grid grid-cols-1 items-end gap-3 sm:grid-cols-3"
          >
            <UFormField label="Key">
              <UInput v-model="row.key" class="w-full" data-test="addon-choice-key-input" />
            </UFormField>
            <UFormField label="Label">
              <UInput v-model="row.label" class="w-full" data-test="addon-choice-label-input" />
            </UFormField>
            <div class="flex items-end gap-2">
              <UFormField label="Price delta" class="flex-1">
                <UInput
                  v-model="row.priceDeltaInput"
                  placeholder="0.00"
                  class="w-full"
                  data-test="addon-choice-price-delta-input"
                />
              </UFormField>
              <UButton
                size="xs"
                color="error"
                variant="ghost"
                icon="i-lucide-trash-2"
                aria-label="Remove choice"
                data-test="addon-choice-remove"
                @click="removeChoiceRow(index)"
              />
            </div>
          </div>
        </div>

        <div class="flex gap-2">
          <UButton
            type="submit"
            size="xs"
            :loading="createAddon.isLoading.value || updateAddon.isLoading.value"
            :label="editingUuid ? 'Save' : 'Create'"
            data-test="addon-form-submit"
          />
          <UButton size="xs" color="neutral" variant="ghost" label="Cancel" @click="cancelForm" />
        </div>
      </form>
    </template>
  </div>

  <UModal
    :open="pendingDelete !== null"
    title="Delete add-on"
    @update:open="(v: boolean) => { if (!v) pendingDelete = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.name }}”</span>? This can’t be undone. Existing
        cart and order lines that used it keep their own saved snapshot.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="removeAddon.isLoading.value"
          @click="() => { pendingDelete = null }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          data-test="addon-delete-confirm"
          :loading="removeAddon.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>
