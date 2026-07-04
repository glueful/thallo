<script setup lang="ts">
// A live preview of the authoring form an entry of this content type would render. Driven by the
// field builder so editors see the shape of what they're defining; controls have no v-model, so any
// input is throwaway (never read or saved).
import type { ContentTypeField } from '@/queries/contentTypes'
import RichText from '@/components/RichText.vue'
import DateTimePicker from '@/components/DateTimePicker.vue'

defineProps<{
  fields: ContentTypeField[]
}>()
</script>

<template>
  <!-- BARE form preview — no card/heading of its own: the consumer supplies
       the chrome (new.vue wraps a card; the type editor titles a slideover). -->
  <div>
    <UEmpty
      v-if="fields.length === 0"
      icon="i-lucide-eye"
      title="Nothing to preview yet"
      description="Add fields to see how the entry form will look."
    />

    <!-- Interactive playground mirroring the real entry form. The controls have no v-model, so any
         input here is throwaway (never read or saved) — it just lets editors feel out the form. -->
    <div v-else class="space-y-4">
      <div v-for="(field, index) in fields" :key="index" class="space-y-1.5">
        <div class="flex flex-wrap items-center gap-1.5">
          <span class="text-sm font-medium text-default">
            {{ field.name || 'Untitled field' }}
          </span>
          <span v-if="field.required" class="text-error" aria-hidden="true">*</span>
          <UBadge color="neutral" variant="subtle" size="sm">{{ field.type }}</UBadge>
          <UBadge v-if="field.localized" color="neutral" variant="outline" size="sm">
            localized
          </UBadge>
          <UBadge v-if="field.filterable" color="neutral" variant="outline" size="sm">
            filterable
          </UBadge>
          <UBadge v-if="field.multiple" color="neutral" variant="outline" size="sm">
            multiple{{ field.max_items ? ` · max ${field.max_items}` : '' }}
          </UBadge>
        </div>

        <!-- rich text uses the same reusable editor the real entry form renders -->
        <RichText
          v-if="field.type === 'text' && field.format === 'rich'"
          placeholder="Rich text…"
        />
        <UTextarea
          v-else-if="field.type === 'text' || field.type === 'json'"
          :rows="field.type === 'json' ? 3 : 2"
          :class="['w-full', field.type === 'json' && 'font-mono']"
          :placeholder="field.type === 'json' ? '{ }' : 'Long text'"
        />
        <USwitch v-else-if="field.type === 'boolean'" />
        <USelect
          v-else-if="field.type === 'enum'"
          class="w-full"
          :items="(field.enum?.length ?? 0) > 0 ? field.enum : ['—']"
          :placeholder="field.enum?.[0] ?? 'Select…'"
        />
        <UFileUpload v-else-if="field.type === 'asset'" />
        <!-- blocks: the real form renders the block editor — the preview shows
             a representative empty-state panel instead of a bogus text input. -->
        <div
          v-else-if="field.type === 'blocks'"
          class="flex flex-col items-center gap-1.5 rounded-lg border border-dashed border-accented px-3 py-5 text-center"
        >
          <UIcon name="i-lucide-blocks" class="size-5 text-dimmed" />
          <p class="text-sm text-muted">Block editor</p>
          <p class="text-xs text-dimmed">
            {{
              field.block_types?.length
                ? `Allowed: ${field.block_types.join(', ')}`
                : 'All active block types'
            }}
          </p>
        </div>
        <DateTimePicker v-else-if="field.type === 'datetime'" />
        <UInput
          v-else
          class="w-full"
          :type="field.type === 'number' ? 'number' : 'text'"
          :icon="field.type === 'reference' ? 'i-lucide-link' : undefined"
          :placeholder="
            field.type === 'number' ? '0' : field.type === 'reference' ? 'Referenced entry' : 'Text'
          "
        />
      </div>
    </div>
  </div>
</template>
