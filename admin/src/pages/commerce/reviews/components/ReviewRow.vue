<script setup lang="ts">
import { computed } from 'vue'
import type { CommerceReview } from '@/queries/commerceReviews'

const props = defineProps<{
  review: CommerceReview
  canManage: boolean
  selected: boolean
  approveLoading: boolean
  spamLoading: boolean
}>()

const emit = defineEmits<{
  'toggle-select': [uuid: string]
  'approve-request': [review: CommerceReview]
  'spam-request': [review: CommerceReview]
  'delete-request': [review: CommerceReview]
}>()

function statusColor(s: string): 'warning' | 'success' | 'error' | 'neutral' {
  switch (s) {
    case 'pending':
      return 'warning'
    case 'approved':
      return 'success'
    case 'spam':
      return 'error'
    default:
      return 'neutral'
  }
}

// Only offer transitions the backend actually allows (ReviewService's transition matrix) — an
// action that would always 409/404 gets no affordance rather than a button that just fails.
const canApprove = computed(() => props.canManage && props.review.status === 'pending')
const canSpam = computed(
  () => props.canManage && (props.review.status === 'pending' || props.review.status === 'approved'),
)
// Guarded delete only ever allows pending/spam — an approved review must be spammed first
// (ReviewService::delete()'s own docblock).
const canDelete = computed(
  () => props.canManage && (props.review.status === 'pending' || props.review.status === 'spam'),
)

function fmtDate(v: string | null): string {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { dateStyle: 'medium' })
}
</script>

<template>
  <div data-test="review-row" :data-uuid="review.uuid" class="flex items-start gap-3 px-4 py-4">
    <UCheckbox
      v-if="canManage"
      :model-value="selected"
      aria-label="Select review"
      data-test="review-select"
      class="mt-1"
      @update:model-value="emit('toggle-select', review.uuid)"
    />

    <div class="min-w-0 flex-1 space-y-1.5">
      <div class="flex flex-wrap items-center gap-2">
        <span data-test="review-rating" class="flex items-center gap-0.5 text-sm font-medium text-default">
          <UIcon
            v-for="n in 5"
            :key="n"
            name="i-lucide-star"
            :class="n <= review.rating ? 'text-warning fill-current' : 'text-muted'"
            class="size-4"
          />
          <span class="ml-1 text-xs text-muted">{{ review.rating }}/5</span>
        </span>

        <UBadge :color="statusColor(review.status)" variant="subtle" size="sm" data-test="review-status">
          {{ review.status }}
        </UBadge>

        <UBadge color="neutral" variant="subtle" size="sm" data-test="review-product">
          product: {{ review.product_uuid }}
        </UBadge>
      </div>

      <p class="text-sm text-default" data-test="review-author">
        <span class="font-medium">{{ review.author_name }}</span>
        <span class="text-muted"> · {{ review.author_email }} · {{ fmtDate(review.created_at) }}</span>
      </p>

      <!-- Plain text interpolation only -- reviewer-authored content is never rendered as HTML. -->
      <p class="whitespace-pre-line text-sm text-muted" data-test="review-body">{{ review.body }}</p>
    </div>

    <div v-if="canManage" class="flex shrink-0 items-center gap-1">
      <UButton
        v-if="canApprove"
        color="success"
        variant="ghost"
        size="xs"
        icon="i-lucide-check"
        aria-label="Approve review"
        data-test="review-approve"
        :disabled="approveLoading"
        @click="emit('approve-request', review)"
      />
      <UButton
        v-if="canSpam"
        color="warning"
        variant="ghost"
        size="xs"
        icon="i-lucide-flag"
        aria-label="Mark review as spam"
        data-test="review-spam"
        :disabled="spamLoading"
        @click="emit('spam-request', review)"
      />
      <UButton
        v-if="canDelete"
        color="error"
        variant="ghost"
        size="xs"
        icon="i-lucide-trash-2"
        aria-label="Delete review"
        data-test="review-delete"
        @click="emit('delete-request', review)"
      />
    </div>
  </div>
</template>
