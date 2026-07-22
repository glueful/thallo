<script setup lang="ts">
import type { CommerceReview } from '@/queries/commerceReviews'
import ReviewRow from './ReviewRow.vue'

const props = defineProps<{
  rows: CommerceReview[]
  status: 'pending' | 'error' | 'success' | 'idle'
  canManage: boolean
  selected: string[]
  approveLoading: boolean
  spamLoading: boolean
}>()

const emit = defineEmits<{
  'toggle-select': [uuid: string]
  'approve-request': [review: CommerceReview]
  'spam-request': [review: CommerceReview]
  'delete-request': [review: CommerceReview]
}>()

function isSelected(uuid: string): boolean {
  return props.selected.includes(uuid)
}
</script>

<template>
  <div v-if="status === 'pending'" class="flex justify-center py-10" data-test="reviews-loading">
    <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
  </div>

  <UAlert
    v-else-if="status === 'error'"
    color="error"
    variant="subtle"
    icon="i-lucide-triangle-alert"
    title="Couldn’t load reviews"
    description="Something went wrong loading reviews. Try again."
    data-test="reviews-error"
  />

  <UEmpty
    v-else-if="rows.length === 0"
    icon="i-lucide-message-square"
    title="No reviews"
    description="Reviews submitted by customers will appear here for moderation."
    data-test="reviews-empty"
  />

  <UCard v-else :ui="{ body: 'p-0 sm:p-0' }">
    <ul class="divide-y divide-default">
      <li v-for="review in rows" :key="review.uuid">
        <ReviewRow
          :review="review"
          :can-manage="canManage"
          :selected="isSelected(review.uuid)"
          :approve-loading="approveLoading"
          :spam-loading="spamLoading"
          @toggle-select="(uuid) => emit('toggle-select', uuid)"
          @approve-request="(row) => emit('approve-request', row)"
          @spam-request="(row) => emit('spam-request', row)"
          @delete-request="(row) => emit('delete-request', row)"
        />
      </li>
    </ul>
  </UCard>
</template>
