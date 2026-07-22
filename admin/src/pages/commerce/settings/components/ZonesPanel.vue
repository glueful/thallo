<script setup lang="ts">
// Task 15a (admin-commerce-area plan, slice 3): shipping zone CRUD, per-zone locations set-list
// editing, and nested per-zone shipping method CRUD — all in one panel (mirrors CategoriesTab.vue's
// single-file "list + inline forms" precedent rather than splitting into further sub-components,
// since the task's own file list names only ZonesPanel.vue).
//
// Rate/amount fields on a method's `config` (amount/free_over/default_amount/classes[slug]) are
// genuine minor-unit currency amounts (`ShippingZoneService::nonNegativeInt()` — see
// commerceSettings.ts's own CommerceShippingMethod docblock), so they follow the SAME
// decimal-string -> minor-units discipline as DiscountForm.vue/RefundSlideover.vue:
// `parseMajorAmountToMinorUnits()` on the way in, `useMoney`/`minorToDecimalString()` on the way
// out — never `Number()`, never float division.
import { computed, ref } from 'vue'
import {
  useCommerceShippingZones,
  useCommerceShippingZoneMutations,
  SHIPPING_LOCATION_KINDS,
  SHIPPING_METHOD_KINDS,
  type CommerceShippingZone,
  type CommerceShippingMethod,
  type ShippingLocationKind,
  type ShippingMethodKind,
} from '@/queries/commerceSettings'
import { parseMajorAmountToMinorUnits, useMoney } from '@/composables/useMoney'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'

const props = defineProps<{ canManage: boolean }>()

const { success, warning, error: notifyError } = useNotify()
const { data: meta } = useCommerceMeta()
const currencyExponent = computed(() => meta.value?.currency_exponent ?? 2)
const { format } = useMoney()

// useMoney().format() throws until /commerce/meta resolves — guard so an unsettled meta query never
// crashes the render (mirrors DiscountsTable.vue's identical `money()` helper).
function money(minor: number): string {
  try {
    return format(minor)
  } catch {
    return '—'
  }
}

/** Reverse of `parseMajorAmountToMinorUnits()` — plain BigInt division/modulo, no `Number()` on the
 * way, for pre-populating an existing minor-unit integer as an editable decimal string (mirrors
 * DiscountForm.vue's identical helper). */
function minorToDecimalString(minor: number, exponent: number): string {
  if (exponent === 0) return String(minor)
  const abs = BigInt(Math.trunc(Math.abs(minor)))
  const scale = 10n ** BigInt(exponent)
  const major = abs / scale
  const fraction = (abs % scale).toString().padStart(exponent, '0')
  return `${major}.${fraction}`
}

// ── Zones list ────────────────────────────────────────────────────────────────

const page = ref(1)
const perPage = ref(24)
const filters = computed(() => ({ page: page.value, perPage: perPage.value }))
const { data, status } = useCommerceShippingZones(filters)
const zones = computed<CommerceShippingZone[]>(() => data.value?.zones ?? [])

const {
  createZone,
  updateZone,
  deleteZone,
  setLocations,
  createMethod,
  updateMethod,
  deleteMethod,
} = useCommerceShippingZoneMutations()

function locationsSummary(zone: CommerceShippingZone): string {
  if (zone.locations.length === 0) return 'Everywhere'
  return zone.locations.map((l) => l.value).join(', ')
}

// ── Expand/collapse per zone ──────────────────────────────────────────────────

const expandedZones = ref<Set<string>>(new Set())
function toggleExpand(uuid: string) {
  const next = new Set(expandedZones.value)
  if (next.has(uuid)) next.delete(uuid)
  else next.add(uuid)
  expandedZones.value = next
}
function isExpanded(uuid: string): boolean {
  return expandedZones.value.has(uuid)
}

// ── Zone create/edit (shared slideover) ───────────────────────────────────────

const zoneFormOpen = ref(false)
const editingZone = ref<CommerceShippingZone | null>(null)
const zoneNameInput = ref('')
const zonePositionInput = ref('0')
const zoneFormError = ref<string | null>(null)

function openCreateZone() {
  editingZone.value = null
  zoneNameInput.value = ''
  zonePositionInput.value = '0'
  zoneFormError.value = null
  zoneFormOpen.value = true
}
function openEditZone(zone: CommerceShippingZone) {
  editingZone.value = zone
  zoneNameInput.value = zone.name
  zonePositionInput.value = String(zone.position)
  zoneFormError.value = null
  zoneFormOpen.value = true
}
function closeZoneForm() {
  zoneFormOpen.value = false
}

const zoneMutationLoading = computed(() => createZone.isLoading.value || updateZone.isLoading.value)

async function submitZoneForm() {
  zoneFormError.value = null
  const name = zoneNameInput.value.trim()
  if (name === '') {
    zoneFormError.value = 'Name is required.'
    return
  }
  const parsedPosition = Number.parseInt(zonePositionInput.value, 10)
  const position = Number.isFinite(parsedPosition) ? parsedPosition : 0

  try {
    if (editingZone.value) {
      await updateZone.mutateAsync({ uuid: editingZone.value.uuid, input: { name, position } })
      success('Zone saved', `“${name}” was updated.`)
    } else {
      await createZone.mutateAsync({ name, position })
      success('Zone created', `“${name}” is ready.`)
    }
    zoneFormOpen.value = false
  } catch (e) {
    const err = toApiError(e)
    zoneFormError.value = err.fieldErrors.name ?? err.message
    notifyError(err, editingZone.value ? 'Couldn’t save zone' : 'Couldn’t create zone')
  }
}

// ── Zone delete ────────────────────────────────────────────────────────────────

const pendingDeleteZone = ref<CommerceShippingZone | null>(null)
async function confirmDeleteZone() {
  const zone = pendingDeleteZone.value
  if (!zone) return
  try {
    await deleteZone.mutateAsync(zone.uuid)
    success('Zone deleted', `“${zone.name}” was removed.`)
    pendingDeleteZone.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete zone')
    pendingDeleteZone.value = null
  }
}

// ── Locations editor (one zone open at a time) ────────────────────────────────

interface LocationRow {
  kind: ShippingLocationKind
  value: string
}

const locationKindItems = SHIPPING_LOCATION_KINDS.map((k) => ({ label: k, value: k }))

const editingLocationsZoneUuid = ref<string | null>(null)
const locationRows = ref<LocationRow[]>([])
const locationsError = ref<string | null>(null)

/** `CommerceShippingLocation.kind` is a loosely-typed `string` at the query-layer boundary (the
 * server is the sole validator); narrow it back to the closed UI vocabulary here, falling back to
 * `country` for anything unrecognized rather than producing a `USelect` with no matching item. */
function toLocationKind(kind: string): ShippingLocationKind {
  return (SHIPPING_LOCATION_KINDS as readonly string[]).includes(kind) ? (kind as ShippingLocationKind) : 'country'
}

function openLocationsEditor(zone: CommerceShippingZone) {
  editingLocationsZoneUuid.value = zone.uuid
  locationRows.value =
    zone.locations.length > 0
      ? zone.locations.map((l) => ({ kind: toLocationKind(l.kind), value: l.value }))
      : [{ kind: 'country', value: '' }]
  locationsError.value = null
}
function cancelLocationsEditor() {
  editingLocationsZoneUuid.value = null
  locationRows.value = []
  locationsError.value = null
}
function addLocationRow() {
  locationRows.value = [...locationRows.value, { kind: 'country', value: '' }]
}
function removeLocationRow(index: number) {
  locationRows.value = locationRows.value.filter((_, i) => i !== index)
}

async function saveLocations(zone: CommerceShippingZone) {
  locationsError.value = null
  const payload = locationRows.value
    .map((r) => ({ kind: r.kind, value: r.value.trim() }))
    .filter((r) => r.value !== '')

  try {
    await setLocations.mutateAsync({ zoneUuid: zone.uuid, locations: payload })
    success('Locations updated', `“${zone.name}”’s locations were saved.`)
    editingLocationsZoneUuid.value = null
    locationRows.value = []
  } catch (e) {
    const err = toApiError(e)
    locationsError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, 'Couldn’t update locations')
  }
}

// ── Method create/edit (one method form open at a time) ──────────────────────

const methodKindItems = SHIPPING_METHOD_KINDS.map((k) => ({ label: k, value: k }))

interface ClassRow {
  slug: string
  amountInput: string
}

interface MethodFormState {
  zoneUuid: string
  methodUuid: string | null
  kind: ShippingMethodKind
  label: string
  enabled: boolean
  positionInput: string
  amountInput: string
  freeOverInput: string
  defaultAmountInput: string
  classRows: ClassRow[]
}

const methodForm = ref<MethodFormState | null>(null)
const methodFormError = ref<string | null>(null)

function blankMethodForm(zoneUuid: string): MethodFormState {
  return {
    zoneUuid,
    methodUuid: null,
    kind: 'flat',
    label: '',
    enabled: true,
    positionInput: '',
    amountInput: '',
    freeOverInput: '',
    defaultAmountInput: '',
    classRows: [],
  }
}

function openCreateMethod(zone: CommerceShippingZone) {
  methodForm.value = blankMethodForm(zone.uuid)
  methodFormError.value = null
}

function openEditMethod(zone: CommerceShippingZone, method: CommerceShippingMethod) {
  const config = method.config
  const classes =
    typeof config.classes === 'object' && config.classes !== null && !Array.isArray(config.classes)
      ? (config.classes as Record<string, unknown>)
      : {}
  methodForm.value = {
    zoneUuid: zone.uuid,
    methodUuid: method.uuid,
    kind: method.kind === 'free_over' || method.kind === 'per_class_table' ? method.kind : 'flat',
    label: method.label,
    enabled: method.enabled,
    positionInput: String(method.position),
    amountInput:
      typeof config.amount === 'number' ? minorToDecimalString(config.amount, currencyExponent.value) : '',
    freeOverInput:
      typeof config.free_over === 'number'
        ? minorToDecimalString(config.free_over, currencyExponent.value)
        : '',
    defaultAmountInput:
      typeof config.default_amount === 'number'
        ? minorToDecimalString(config.default_amount, currencyExponent.value)
        : '',
    classRows: Object.entries(classes).map(([slug, amount]) => ({
      slug,
      amountInput: typeof amount === 'number' ? minorToDecimalString(amount, currencyExponent.value) : '',
    })),
  }
  methodFormError.value = null
}

function cancelMethodForm() {
  methodForm.value = null
  methodFormError.value = null
}

function addClassRow() {
  if (!methodForm.value) return
  methodForm.value.classRows = [...methodForm.value.classRows, { slug: '', amountInput: '' }]
}
function removeClassRow(index: number) {
  if (!methodForm.value) return
  methodForm.value.classRows = methodForm.value.classRows.filter((_, i) => i !== index)
}

/** Parses a single money field; sets `methodFormError` and returns `null` on any failure — callers
 * bail out on `null` without submitting. */
function parseMoneyField(input: string, fieldLabel: string): number | null {
  const trimmed = input.trim()
  if (trimmed === '') {
    methodFormError.value = `Enter ${fieldLabel}.`
    return null
  }
  const minor = parseMajorAmountToMinorUnits(trimmed, currencyExponent.value)
  if (minor === null || minor < 0n) {
    methodFormError.value =
      currencyExponent.value === 0
        ? `Enter a whole-number amount for ${fieldLabel}.`
        : `Enter a valid amount (up to ${currencyExponent.value} decimal places) for ${fieldLabel}.`
    return null
  }
  return Number(minor)
}

/** Builds the `config` object for the form's current kind, mirroring
 * `ShippingZoneService::validateMethodConfig()`'s per-kind shape exactly. Returns `null` (having
 * already set `methodFormError`) on the first invalid field. */
function buildMethodConfig(form: MethodFormState): Record<string, unknown> | null {
  if (form.kind === 'flat') {
    const amount = parseMoneyField(form.amountInput, 'the rate')
    if (amount === null) return null
    return { amount }
  }
  if (form.kind === 'free_over') {
    const amount = parseMoneyField(form.amountInput, 'the rate')
    if (amount === null) return null
    const freeOver = parseMoneyField(form.freeOverInput, 'the free-over threshold')
    if (freeOver === null) return null
    return { amount, free_over: freeOver }
  }
  // per_class_table
  const defaultAmount = parseMoneyField(form.defaultAmountInput, 'the default rate')
  if (defaultAmount === null) return null
  const classes: Record<string, number> = {}
  for (const row of form.classRows) {
    const slug = row.slug.trim()
    if (slug === '') continue
    const amount = parseMoneyField(row.amountInput, `the “${slug}” class rate`)
    if (amount === null) return null
    classes[slug] = amount
  }
  return { default_amount: defaultAmount, classes }
}

const methodMutationLoading = computed(
  () => createMethod.isLoading.value || updateMethod.isLoading.value,
)

async function submitMethodForm() {
  const form = methodForm.value
  if (!form) return
  methodFormError.value = null

  const label = form.label.trim()
  if (label === '') {
    methodFormError.value = 'Label is required.'
    return
  }

  const config = buildMethodConfig(form)
  if (config === null) return

  const position = form.positionInput.trim() === '' ? null : Number.parseInt(form.positionInput, 10)

  try {
    if (form.methodUuid) {
      const result = await updateMethod.mutateAsync({
        uuid: form.methodUuid,
        zoneUuid: form.zoneUuid,
        input: { label, config, position, enabled: form.enabled },
      })
      success('Method saved', `“${label}” was updated.`)
      if (result.warnings.length > 0) warning('Saved with warnings', result.warnings.join(' '))
    } else {
      const result = await createMethod.mutateAsync({
        zoneUuid: form.zoneUuid,
        input: { kind: form.kind, label, config, position, enabled: form.enabled },
      })
      success('Method created', `“${label}” is ready.`)
      if (result.warnings.length > 0) warning('Created with warnings', result.warnings.join(' '))
    }
    methodForm.value = null
  } catch (e) {
    const err = toApiError(e)
    methodFormError.value = Object.values(err.fieldErrors)[0] ?? err.message
    notifyError(err, form.methodUuid ? 'Couldn’t save method' : 'Couldn’t create method')
  }
}

// ── Method delete ──────────────────────────────────────────────────────────────

const pendingDeleteMethod = ref<{ zoneUuid: string; method: CommerceShippingMethod } | null>(null)
async function confirmDeleteMethod() {
  const pending = pendingDeleteMethod.value
  if (!pending) return
  try {
    await deleteMethod.mutateAsync({ uuid: pending.method.uuid, zoneUuid: pending.zoneUuid })
    success('Method deleted', `“${pending.method.label}” was removed.`)
    pendingDeleteMethod.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete method')
    pendingDeleteMethod.value = null
  }
}

// ── Method row display helpers ─────────────────────────────────────────────────

function methodRateSummary(method: CommerceShippingMethod): string {
  const config = method.config
  if (method.kind === 'flat') {
    return typeof config.amount === 'number' ? money(config.amount) : '—'
  }
  if (method.kind === 'free_over') {
    const amount = typeof config.amount === 'number' ? money(config.amount) : '—'
    const freeOver = typeof config.free_over === 'number' ? money(config.free_over) : '—'
    return `${amount}, free over ${freeOver}`
  }
  const defaultAmount = typeof config.default_amount === 'number' ? money(config.default_amount) : '—'
  return `Default ${defaultAmount}`
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-medium text-default">Shipping zones</h2>
      <UButton
        v-if="props.canManage"
        icon="i-lucide-plus"
        data-test="new-zone"
        @click="openCreateZone"
      >
        New zone
      </UButton>
    </div>

    <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="zones-loading">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
    </div>

    <UAlert
      v-else-if="status === 'error'"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Couldn’t load shipping zones"
      description="Something went wrong loading shipping zones. Try again."
      data-test="zones-error"
    />

    <UEmpty
      v-else-if="zones.length === 0"
      icon="i-lucide-map"
      title="No shipping zones"
      description="Create a zone to start configuring shipping rates."
      data-test="zones-empty"
    />

    <div v-else class="space-y-3">
      <div
        v-for="zone in zones"
        :key="zone.uuid"
        data-test="zone-row"
        :data-uuid="zone.uuid"
        class="rounded-md border border-default"
      >
        <div class="flex flex-wrap items-center gap-3 p-3">
          <UButton
            color="neutral"
            variant="ghost"
            size="xs"
            :icon="isExpanded(zone.uuid) ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'"
            aria-label="Toggle zone details"
            data-test="zone-expand-toggle"
            @click="toggleExpand(zone.uuid)"
          />

          <span data-test="zone-name" class="font-medium text-default">{{ zone.name }}</span>
          <UBadge color="neutral" variant="subtle" size="sm">position {{ zone.position }}</UBadge>
          <span data-test="zone-locations-summary" class="text-sm text-muted">{{ locationsSummary(zone) }}</span>
          <UBadge color="neutral" variant="subtle" size="sm" data-test="zone-methods-count">
            {{ zone.methods.length }} method{{ zone.methods.length === 1 ? '' : 's' }}
          </UBadge>
          <UBadge
            v-if="zone.shadows_later_zones"
            color="warning"
            variant="subtle"
            size="sm"
            icon="i-lucide-triangle-alert"
            data-test="zone-shadow-warning"
          >
            Shadows later zones
          </UBadge>

          <div v-if="props.canManage" class="ml-auto flex gap-1">
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-pencil"
              aria-label="Edit zone"
              data-test="zone-edit"
              @click="openEditZone(zone)"
            />
            <UButton
              color="error"
              variant="ghost"
              size="xs"
              icon="i-lucide-trash-2"
              aria-label="Delete zone"
              data-test="zone-delete"
              @click="() => { pendingDeleteZone = zone }"
            />
          </div>
        </div>

        <div v-if="isExpanded(zone.uuid)" class="space-y-6 border-t border-default p-4">
          <!-- Locations ---------------------------------------------------------------------- -->
          <section class="space-y-3">
            <div class="flex items-center justify-between">
              <h3 class="text-xs font-medium tracking-wide text-muted uppercase">Locations</h3>
              <UButton
                v-if="props.canManage && editingLocationsZoneUuid !== zone.uuid"
                size="xs"
                color="neutral"
                variant="ghost"
                label="Edit locations"
                data-test="zone-locations-edit"
                @click="openLocationsEditor(zone)"
              />
            </div>

            <div v-if="editingLocationsZoneUuid !== zone.uuid" class="flex flex-wrap gap-1">
              <UBadge
                v-for="(loc, idx) in zone.locations"
                :key="`${loc.kind}-${loc.value}-${idx}`"
                color="neutral"
                variant="subtle"
                size="sm"
              >
                {{ loc.kind }}: {{ loc.value }}
              </UBadge>
              <span v-if="zone.locations.length === 0" class="text-sm text-muted">Everywhere (no locations set)</span>
            </div>

            <form
              v-else
              class="space-y-3"
              data-test="zone-locations-form"
              @submit.prevent="saveLocations(zone)"
            >
              <div
                v-for="(row, idx) in locationRows"
                :key="idx"
                data-test="zone-location-row"
                class="flex items-center gap-2"
              >
                <USelect
                  v-model="row.kind"
                  :items="locationKindItems"
                  class="w-40"
                  data-test="zone-location-kind-input"
                />
                <UInput
                  v-model="row.value"
                  placeholder="e.g. US"
                  class="flex-1"
                  data-test="zone-location-value-input"
                />
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-x"
                  aria-label="Remove location row"
                  data-test="zone-location-remove"
                  @click="removeLocationRow(idx)"
                />
              </div>

              <UButton
                size="xs"
                color="neutral"
                variant="ghost"
                icon="i-lucide-plus"
                label="Add location"
                data-test="zone-location-add"
                @click="addLocationRow"
              />

              <UAlert
                v-if="locationsError"
                color="error"
                variant="subtle"
                icon="i-lucide-triangle-alert"
                :title="locationsError"
                data-test="zone-locations-error"
              />

              <div class="flex gap-2">
                <UButton
                  type="submit"
                  size="xs"
                  :loading="setLocations.isLoading.value"
                  label="Save locations"
                  data-test="zone-locations-save"
                />
                <UButton
                  size="xs"
                  color="neutral"
                  variant="ghost"
                  label="Cancel"
                  data-test="zone-locations-cancel"
                  @click="cancelLocationsEditor"
                />
              </div>
            </form>
          </section>

          <!-- Methods ------------------------------------------------------------------------- -->
          <section class="space-y-3">
            <div class="flex items-center justify-between">
              <h3 class="text-xs font-medium tracking-wide text-muted uppercase">Shipping methods</h3>
              <UButton
                v-if="props.canManage"
                size="xs"
                color="neutral"
                variant="ghost"
                icon="i-lucide-plus"
                label="Add method"
                data-test="method-add"
                @click="openCreateMethod(zone)"
              />
            </div>

            <UEmpty
              v-if="zone.methods.length === 0"
              icon="i-lucide-truck"
              title="No shipping methods"
              description="Add a method so this zone can quote a rate."
              data-test="zone-methods-empty"
            />

            <div
              v-for="method in zone.methods"
              :key="method.uuid"
              data-test="method-row"
              :data-uuid="method.uuid"
              class="flex flex-wrap items-center gap-3 rounded-md border border-default p-3"
            >
              <span data-test="method-label" class="font-medium text-default">{{ method.label }}</span>
              <UBadge color="neutral" variant="subtle" size="sm" data-test="method-kind">{{ method.kind }}</UBadge>
              <span data-test="method-rate" class="text-sm text-muted">{{ methodRateSummary(method) }}</span>
              <UBadge :color="method.enabled ? 'success' : 'neutral'" variant="subtle" size="sm" data-test="method-enabled">
                {{ method.enabled ? 'Enabled' : 'Disabled' }}
              </UBadge>

              <div v-if="props.canManage" class="ml-auto flex gap-1">
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-pencil"
                  aria-label="Edit method"
                  data-test="method-edit"
                  @click="openEditMethod(zone, method)"
                />
                <UButton
                  color="error"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-trash-2"
                  aria-label="Delete method"
                  data-test="method-delete"
                  @click="() => { pendingDeleteMethod = { zoneUuid: zone.uuid, method } }"
                />
              </div>
            </div>

            <!-- Method create/edit form (this zone only) -->
            <form
              v-if="methodForm && methodForm.zoneUuid === zone.uuid"
              class="space-y-3 rounded-md border border-default p-3"
              data-test="method-form"
              @submit.prevent="submitMethodForm"
            >
              <div class="grid grid-cols-2 gap-3">
                <UFormField label="Kind" name="kind">
                  <USelect
                    v-model="methodForm.kind"
                    :items="methodKindItems"
                    :disabled="methodForm.methodUuid !== null"
                    class="w-full"
                    data-test="method-kind-input"
                  />
                </UFormField>
                <UFormField label="Label" name="label" required>
                  <UInput v-model="methodForm.label" class="w-full" data-test="method-label-input" />
                </UFormField>
              </div>

              <UFormField v-if="methodForm.kind === 'flat'" label="Rate" name="amount" help="e.g. 5.00">
                <UInput v-model="methodForm.amountInput" placeholder="0.00" class="w-full" data-test="method-amount-input" />
              </UFormField>

              <template v-else-if="methodForm.kind === 'free_over'">
                <UFormField label="Rate" name="amount" help="e.g. 5.00">
                  <UInput v-model="methodForm.amountInput" placeholder="0.00" class="w-full" data-test="method-amount-input" />
                </UFormField>
                <UFormField label="Free over" name="freeOver" help="Order subtotal above which shipping is free">
                  <UInput v-model="methodForm.freeOverInput" placeholder="0.00" class="w-full" data-test="method-free-over-input" />
                </UFormField>
              </template>

              <template v-else>
                <UFormField label="Default rate" name="defaultAmount">
                  <UInput
                    v-model="methodForm.defaultAmountInput"
                    placeholder="0.00"
                    class="w-full"
                    data-test="method-default-amount-input"
                  />
                </UFormField>

                <div class="space-y-2">
                  <div class="flex items-center justify-between">
                    <span class="text-xs text-muted">Per-class rates</span>
                    <UButton
                      size="xs"
                      color="neutral"
                      variant="ghost"
                      icon="i-lucide-plus"
                      label="Add class rate"
                      data-test="method-class-add"
                      @click="addClassRow"
                    />
                  </div>
                  <div
                    v-for="(row, idx) in methodForm.classRows"
                    :key="idx"
                    data-test="method-class-row"
                    class="flex items-center gap-2"
                  >
                    <UInput v-model="row.slug" placeholder="class slug" class="flex-1" data-test="method-class-slug-input" />
                    <UInput v-model="row.amountInput" placeholder="0.00" class="w-28" data-test="method-class-amount-input" />
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      icon="i-lucide-x"
                      aria-label="Remove class rate"
                      data-test="method-class-remove"
                      @click="removeClassRow(idx)"
                    />
                  </div>
                </div>
              </template>

              <UFormField label="Position" name="position">
                <UInput v-model="methodForm.positionInput" placeholder="Auto" class="w-32" data-test="method-position-input" />
              </UFormField>

              <UCheckbox v-model="methodForm.enabled" label="Enabled" data-test="method-enabled-checkbox" />

              <UAlert
                v-if="methodFormError"
                color="error"
                variant="subtle"
                icon="i-lucide-triangle-alert"
                :title="methodFormError"
                data-test="method-form-error"
              />

              <div class="flex gap-2">
                <UButton
                  type="submit"
                  size="xs"
                  :loading="methodMutationLoading"
                  :label="methodForm.methodUuid ? 'Save' : 'Create'"
                  data-test="method-form-submit"
                />
                <UButton
                  size="xs"
                  color="neutral"
                  variant="ghost"
                  label="Cancel"
                  data-test="method-form-cancel"
                  @click="cancelMethodForm"
                />
              </div>
            </form>
          </section>
        </div>
      </div>
    </div>

    <TablePagination
      v-if="(data?.total ?? 0) > 0"
      v-model:page="page"
      v-model:per-page="perPage"
      :total="data?.total ?? 0"
      label="zones"
    />
  </div>

  <!-- Zone create/edit slideover -->
  <USlideover
    :open="zoneFormOpen"
    :title="editingZone ? 'Edit zone' : 'Create zone'"
    :ui="{ content: 'sm:max-w-md' }"
    @update:open="(v: boolean) => { if (!v) closeZoneForm() }"
  >
    <template #body>
      <form id="zone-form" class="space-y-4" @submit.prevent="submitZoneForm">
        <UFormField label="Name" name="name" required :error="zoneFormError ?? undefined">
          <UInput v-model="zoneNameInput" class="w-full" data-test="zone-name-input" />
        </UFormField>
        <UFormField label="Position" name="position" help="Lower positions are evaluated first.">
          <UInput v-model="zonePositionInput" type="number" class="w-full" data-test="zone-position-input" />
        </UFormField>
      </form>
    </template>
    <template #footer>
      <div class="flex w-full items-center justify-between">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          data-test="zone-dismiss"
          @click="closeZoneForm"
        />
        <UButton
          type="submit"
          form="zone-form"
          data-test="zone-form-submit"
          :loading="zoneMutationLoading"
          :label="editingZone ? 'Save' : 'Create'"
        />
      </div>
    </template>
  </USlideover>

  <!-- Zone delete confirm -->
  <UModal
    :open="pendingDeleteZone !== null"
    title="Delete zone"
    @update:open="(v: boolean) => { if (!v) pendingDeleteZone = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDeleteZone?.name }}”</span>? This also removes its
        locations and shipping methods. This can’t be undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="deleteZone.isLoading.value"
          @click="() => { pendingDeleteZone = null }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          data-test="zone-delete-confirm"
          :loading="deleteZone.isLoading.value"
          @click="confirmDeleteZone"
        />
      </div>
    </template>
  </UModal>

  <!-- Method delete confirm -->
  <UModal
    :open="pendingDeleteMethod !== null"
    title="Delete shipping method"
    @update:open="(v: boolean) => { if (!v) pendingDeleteMethod = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDeleteMethod?.method.label }}”</span>? This can’t be
        undone.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="deleteMethod.isLoading.value"
          @click="() => { pendingDeleteMethod = null }"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          data-test="method-delete-confirm"
          :loading="deleteMethod.isLoading.value"
          @click="confirmDeleteMethod"
        />
      </div>
    </template>
  </UModal>
</template>
