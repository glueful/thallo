<script setup lang="ts">
// Task 12 (admin-commerce-area plan, slice 3): the ONE shared bidirectional product<->entry
// linkage panel, mounted from BOTH sides — the product detail's "Content" tab (mode="product")
// and the entry editor's "Commerce" side-tab (mode="entry", via CommerceLinkPanel.vue's thin
// wrapper). Both hooks below are called unconditionally regardless of `mode` (their own
// `enabled` guard keeps the irrelevant side's query from ever firing) so this component's own
// hook calls stay simple and order-stable.
import { computed, ref, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useCommerceMeta } from '@/queries/commerceMeta'
import { useCommerceProduct, type CommerceProduct } from '@/queries/commerceCatalog'
import {
  useProductLink,
  useEntryLink,
  useEntrySearch,
  useProductSearchForLink,
  useCommerceLinkMutations,
  type EntrySearchResult,
} from '@/queries/commerceLinking'
import { ApiError, toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{
  mode: 'product' | 'entry'
  productUuid?: string
  entryUuid?: string
}>()

const { success, error: notifyError } = useNotify()

const isProductMode = computed(() => props.mode === 'product')
const isEntryMode = computed(() => props.mode === 'entry')

const productUuid = computed(() => props.productUuid ?? '')
const entryUuid = computed(() => props.entryUuid ?? '')

const { data: meta } = useCommerceMeta()
const canManage = computed(() => meta.value?.can_manage ?? false)

// ── Load the current link, from whichever side this mount owns ─────────────────────────────
// Product mode: `showByProduct` is always 200 for an accessible product (`link` nullable).
const {
  data: productLinkData,
  status: productLinkStatus,
  refetch: refetchProductLink,
} = useProductLink(productUuid, isProductMode)

// Entry mode: `showByEntry` 404s into `null` (see fetchEntryLink) — "not linked" is not a failure.
const {
  data: entryLinkData,
  status: entryLinkStatus,
  refetch: refetchEntryLink,
} = useEntryLink(entryUuid, isEntryMode)

// Entry mode additionally resolves the linked product's own record (name, slug, status) for
// display + the product-detail link — product mode has no equivalent enrichment available (see
// the "known entry" note below).
const linkedProductUuid = computed(() => entryLinkData.value?.product_uuid ?? '')
const { data: linkedProduct } = useCommerceProduct(linkedProductUuid, isEntryMode)

const currentLink = computed(() =>
  isProductMode.value ? (productLinkData.value?.link ?? null) : entryLinkData.value,
)
const currentEntryUuid = computed(() => currentLink.value?.entry_uuid ?? null)
const currentProductUuid = computed(() => currentLink.value?.product_uuid ?? null)
const isCurrentlyLinked = computed(() => currentLink.value !== null)

// There is no admin GET that returns an entry's title/type/status by uuid — only the manage-only
// search endpoint does — so the CURRENT link's entry side stays "title unknown" until a
// search-and-pick in THIS session positively establishes it (mirrors MediaPanel/CategoriesTab's
// "never guess an unobserved state" convention). Cleared whenever the current link's entry uuid
// changes away from what was last known.
const knownEntry = ref<EntrySearchResult | null>(null)
watch(currentEntryUuid, (uuid) => {
  if (knownEntry.value && knownEntry.value.uuid !== uuid) knownEntry.value = null
})

const loading = computed(() =>
  isProductMode.value ? productLinkStatus.value === 'pending' : entryLinkStatus.value === 'pending',
)
const loadFailed = computed(() =>
  isProductMode.value ? productLinkStatus.value === 'error' : entryLinkStatus.value === 'error',
)

// ── Search (manage only) ─────────────────────────────────────────────────────────────────────
const searchTerm = ref('')
const debouncedSearch = refDebounced(searchTerm, 250)
const { data: entryResults } = useEntrySearch(debouncedSearch)
const { data: productResults } = useProductSearchForLink(debouncedSearch)

const selectedEntry = ref<EntrySearchResult | null>(null)
const selectedProduct = ref<CommerceProduct | null>(null)

function pickEntry(entry: EntrySearchResult) {
  selectedEntry.value = entry
}
function pickProduct(product: CommerceProduct) {
  selectedProduct.value = product
}

const hasNewSelection = computed(() =>
  isProductMode.value
    ? selectedEntry.value !== null && selectedEntry.value.uuid !== currentEntryUuid.value
    : selectedProduct.value !== null && selectedProduct.value.uuid !== currentProductUuid.value,
)

function resetPickers() {
  selectedEntry.value = null
  selectedProduct.value = null
  searchTerm.value = ''
}

// ── Labels for the relink confirm step ──────────────────────────────────────────────────────
const currentLabel = computed(() => {
  if (isProductMode.value) {
    if (knownEntry.value && knownEntry.value.uuid === currentEntryUuid.value) return knownEntry.value.title
    return currentEntryUuid.value ? `entry ${currentEntryUuid.value}` : 'nothing'
  }
  if (linkedProduct.value) return linkedProduct.value.name
  return currentProductUuid.value ? `product ${currentProductUuid.value}` : 'nothing'
})
const nextLabel = computed(() =>
  isProductMode.value ? (selectedEntry.value?.title ?? '') : (selectedProduct.value?.name ?? ''),
)

// ── Link / relink / unlink ───────────────────────────────────────────────────────────────────
const { link, unlink } = useCommerceLinkMutations()

const mutationError = ref<string | null>(null)
const pendingRelink = ref(false)
const pendingUnlink = ref(false)
const conflict = ref(false)

async function submitFirstLink() {
  mutationError.value = null
  try {
    if (isProductMode.value && selectedEntry.value) {
      await link.mutateAsync({ productUuid: productUuid.value, entryUuid: selectedEntry.value.uuid })
      knownEntry.value = selectedEntry.value
    } else if (isEntryMode.value && selectedProduct.value) {
      await link.mutateAsync({ productUuid: selectedProduct.value.uuid, entryUuid: entryUuid.value })
    }
    resetPickers()
    success('Linked', 'The product and entry are now linked.')
  } catch (e) {
    mutationError.value = toApiError(e).message
    notifyError(e, 'Couldn’t link')
  }
}

/**
 * Entry-mode move partial state: the unlink succeeded but the follow-up link failed —
 * the entry is genuinely unlinked now. Holds the target product's label for the message.
 */
const moveIncomplete = ref<string | null>(null)

function requestRelink() {
  moveIncomplete.value = null
  pendingRelink.value = true
}
function cancelRelink() {
  pendingRelink.value = false
}

async function confirmRelink() {
  mutationError.value = null
  try {
    if (isProductMode.value && selectedEntry.value) {
      // The CAS token: the product's link only replaces the entry it EXPECTS to currently hold.
      await link.mutateAsync({
        productUuid: productUuid.value,
        entryUuid: selectedEntry.value.uuid,
        expectedEntryUuid: currentEntryUuid.value ?? undefined,
        previousEntryUuid: currentEntryUuid.value ?? undefined,
      })
      knownEntry.value = selectedEntry.value
    } else if (isEntryMode.value && selectedProduct.value && currentProductUuid.value) {
      // Moving an entry to a DIFFERENT product has no single CAS'd call — the CAS token is
      // scoped to the TARGET product's own link, not this entry's previous one — so this
      // unlinks the current product first, then links the new one. A failure AFTER the
      // unlink succeeded is a distinct partial state (the entry is now unlinked, the move
      // did not complete) and must be messaged as such — never as a concurrent-change 409.
      await unlink.mutateAsync({ productUuid: currentProductUuid.value, entryUuid: entryUuid.value })
      try {
        await link.mutateAsync({ productUuid: selectedProduct.value.uuid, entryUuid: entryUuid.value })
      } catch (linkError) {
        pendingRelink.value = false
        moveIncomplete.value = selectedProduct.value?.name ?? 'the selected product'
        resetPickers()
        notifyError(linkError, 'Move did not complete')
        return
      }
    }
    pendingRelink.value = false
    resetPickers()
    success('Relinked', 'The link was updated.')
  } catch (e) {
    pendingRelink.value = false
    if (e instanceof ApiError && e.status === 409) {
      conflict.value = true
    } else {
      mutationError.value = toApiError(e).message
      notifyError(e, 'Couldn’t relink')
    }
  }
}

async function refreshLink() {
  conflict.value = false
  if (isProductMode.value) await refetchProductLink()
  else await refetchEntryLink()
}

function requestUnlink() {
  pendingUnlink.value = true
}
function cancelUnlink() {
  pendingUnlink.value = false
}

async function confirmUnlink() {
  mutationError.value = null
  try {
    if (isProductMode.value) {
      await unlink.mutateAsync({ productUuid: productUuid.value, entryUuid: currentEntryUuid.value ?? undefined })
    } else if (currentProductUuid.value) {
      await unlink.mutateAsync({ productUuid: currentProductUuid.value, entryUuid: entryUuid.value })
    }
    knownEntry.value = null
    pendingUnlink.value = false
    success('Unlinked')
  } catch (e) {
    pendingUnlink.value = false
    mutationError.value = toApiError(e).message
    notifyError(e, 'Couldn’t unlink')
  }
}
</script>

<template>
  <div class="space-y-4">
    <div v-if="loading" class="flex justify-center py-6" data-test="link-loading">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
    </div>

    <!-- Non-revealing: a load failure never distinguishes "unknown" from "forbidden" from any
         other reason — same convention as ProductForm/ProductsTable's own error banners. -->
    <UAlert
      v-else-if="loadFailed"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="Couldn’t load the link"
      data-test="link-error"
    />

    <template v-else>
      <a
        v-if="isProductMode && productLinkData"
        :href="productLinkData.storefront_url"
        target="_blank"
        rel="noopener noreferrer"
        data-test="link-preview"
        class="inline-flex items-center gap-1 text-sm text-primary"
      >
        <UIcon name="i-lucide-external-link" class="size-4" />
        Preview on storefront
      </a>

      <div data-test="link-state">
        <template v-if="isProductMode">
          <p v-if="currentLink" data-test="link-current">
            Linked to
            <span v-if="knownEntry && knownEntry.uuid === currentLink.entry_uuid">
              “{{ knownEntry.title }}” ({{ knownEntry.content_type }}, {{ knownEntry.status }})
            </span>
            <span v-else data-test="link-current-unknown">entry {{ currentLink.entry_uuid }}</span>
          </p>
          <p v-else class="text-sm text-muted" data-test="link-none">Not linked to any entry yet.</p>
        </template>
        <template v-else>
          <p v-if="currentLink" data-test="link-current">
            Linked to
            <RouterLink :to="`/commerce/products/${currentLink.product_uuid}`" data-test="link-product-detail">
              {{ linkedProduct?.name ?? currentLink.product_uuid }}
            </RouterLink>
          </p>
          <p v-else class="text-sm text-muted" data-test="link-none">Not linked to any product yet.</p>
        </template>
      </div>

      <UAlert
        v-if="moveIncomplete"
        color="warning"
        variant="subtle"
        icon="i-lucide-unlink"
        title="The entry is now unlinked."
        :description="`The previous link was removed, but linking to ${moveIncomplete} did not complete. Link again to finish the move.`"
        data-test="link-move-incomplete"
      />

      <UAlert
        v-if="conflict"
        color="warning"
        variant="subtle"
        icon="i-lucide-refresh-cw"
        title="The link changed underneath you."
        description="Someone else changed this link. Refresh to see the current state before trying again."
        data-test="link-conflict"
      >
        <template #actions>
          <UButton size="xs" data-test="link-conflict-refresh" @click="refreshLink">Refresh</UButton>
        </template>
      </UAlert>

      <UAlert
        v-if="mutationError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :title="mutationError"
        data-test="link-mutation-error"
      />

      <!-- Search + link/relink/unlink controls — manage only; view-only keeps state + preview. -->
      <section v-if="canManage" class="space-y-3 border-t border-default pt-4" data-test="link-search-section">
        <UInput
          v-model="searchTerm"
          icon="i-lucide-search"
          :placeholder="isProductMode ? 'Search entries…' : 'Search products…'"
          class="w-full"
          data-test="link-search-input"
        />

        <ul v-if="isProductMode" class="space-y-1">
          <li v-for="entry in entryResults ?? []" :key="entry.uuid">
            <button
              type="button"
              class="w-full rounded-md border border-default p-2 text-left text-sm hover:bg-elevated"
              data-test="entry-search-result"
              :data-uuid="entry.uuid"
              @click="pickEntry(entry)"
            >
              {{ entry.title }} — {{ entry.content_type }} ({{ entry.status }})
            </button>
          </li>
        </ul>
        <ul v-else class="space-y-1">
          <li v-for="product in productResults ?? []" :key="product.uuid">
            <button
              type="button"
              class="w-full rounded-md border border-default p-2 text-left text-sm hover:bg-elevated"
              data-test="product-search-result"
              :data-uuid="product.uuid"
              @click="pickProduct(product)"
            >
              {{ product.name }} — {{ product.slug }}
            </button>
          </li>
        </ul>

        <div class="flex flex-wrap gap-2">
          <UButton
            v-if="hasNewSelection && !isCurrentlyLinked"
            size="sm"
            icon="i-lucide-link"
            data-test="link-set"
            :loading="link.isLoading.value"
            @click="submitFirstLink"
          >
            Link
          </UButton>
          <UButton
            v-else-if="hasNewSelection && isCurrentlyLinked"
            size="sm"
            color="warning"
            icon="i-lucide-link"
            data-test="link-relink"
            @click="requestRelink"
          >
            Relink
          </UButton>

          <UButton
            v-if="isCurrentlyLinked"
            size="sm"
            color="error"
            variant="outline"
            icon="i-lucide-unlink"
            data-test="link-unlink"
            @click="requestUnlink"
          >
            Unlink
          </UButton>
        </div>

        <!-- Relink confirm — explicit, shows what will be replaced BEFORE submitting. -->
        <div v-if="pendingRelink" class="rounded-md border border-warning p-3 text-sm" data-test="relink-confirm">
          <p>
            This will replace <strong data-test="relink-confirm-current">{{ currentLabel }}</strong> with
            <strong data-test="relink-confirm-next">{{ nextLabel }}</strong>.
          </p>
          <div class="mt-2 flex gap-2">
            <UButton
              size="xs"
              color="warning"
              data-test="relink-confirm-submit"
              :loading="link.isLoading.value || unlink.isLoading.value"
              @click="confirmRelink"
            >
              Confirm relink
            </UButton>
            <UButton size="xs" color="neutral" variant="ghost" @click="cancelRelink">Cancel</UButton>
          </div>
        </div>

        <!-- Unlink confirm. -->
        <div v-if="pendingUnlink" class="rounded-md border border-error p-3 text-sm" data-test="unlink-confirm">
          <p>Unlink this {{ isProductMode ? 'entry' : 'product' }}?</p>
          <div class="mt-2 flex gap-2">
            <UButton
              size="xs"
              color="error"
              data-test="link-unlink-confirm"
              :loading="unlink.isLoading.value"
              @click="confirmUnlink"
            >
              Confirm unlink
            </UButton>
            <UButton size="xs" color="neutral" variant="ghost" @click="cancelUnlink">Cancel</UButton>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
