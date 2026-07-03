<script setup lang="ts">
// Builder for a content type's field schema. v-models a ContentTypeField[]; the parent owns the
// array and persists it (create → POST /content-types, edit → PATCH /content-types/{slug}/schema).
// Also reused by the BLOCK-TYPE builder (context="block-type"): block schemas reject nested
// blocks/localized/filterable fields (block-builder spec §2), so those options disappear there.
import { computed } from 'vue'
import {
  FIELD_TYPES,
  useContentTypes,
  type ContentTypeField,
  type FieldType,
} from '@/queries/contentTypes'
import { useBlockTypes } from '@/queries/blockTypes'

const props = withDefaults(defineProps<{ context?: 'content-type' | 'block-type' }>(), {
  context: 'content-type',
})

const model = defineModel<ContentTypeField[]>({ required: true })

// Nesting amendment §A4: block schemas may use `blocks` fields too (the v1 exclusion
// is lifted); localized/filterable stay hidden in the block-type context below.
const typeItems = computed(() => [...FIELD_TYPES])

// Block-type allowlist options for `blocks` fields — ACTIVE types only (picker-only rule).
const { data: allBlockTypes } = useBlockTypes()
const blockTypeItems = computed(() =>
  (allBlockTypes.value ?? [])
    .filter((t) => t.active)
    .map((t) => ({ label: t.label, value: t.slug })),
)

// Target options for `reference` fields — the content types an entry can point at.
const { data: contentTypes } = useContentTypes()
const targetTypeItems = computed(() =>
  (contentTypes.value ?? []).map((t) => ({ label: t.name ?? t.slug, value: t.slug })),
)

// `text` fields choose an editing widget (both store a plain string / HTML string in a text column).
const formatItems: { label: string; value: 'plain' | 'rich' }[] = [
  { label: 'Plain textarea', value: 'plain' },
  { label: 'Rich text', value: 'rich' },
]

function addField() {
  model.value = [
    ...model.value,
    { name: '', type: 'string', required: false, localized: false, filterable: false, enum: [] },
  ]
}

function removeField(index: number) {
  model.value = model.value.filter((_, i) => i !== index)
}

function patch(index: number, changes: Partial<ContentTypeField>) {
  model.value = model.value.map((field, i) => (i === index ? { ...field, ...changes } : field))
}

function onTypeChange(index: number, type: FieldType) {
  patch(index, {
    type,
    // Enum values only make sense for enum fields; drop them when switching away.
    ...(type === 'enum' ? {} : { enum: [] }),
    // Text fields carry a presentation format (default plain); clear it for every other type.
    format: type === 'text' ? 'plain' : undefined,
    // The reference target and slug filter only apply to reference fields; clear them otherwise.
    ...(type === 'reference' ? {} : { reference_type: undefined, reference_slug_field: undefined }),
    // Multiple/max_items only apply to reference and asset fields; clear them otherwise.
    ...(type === 'reference' || type === 'asset' ? {} : { multiple: false, max_items: null }),
    // The block-type allowlist only applies to blocks fields; blocks are never filterable.
    ...(type === 'blocks' ? { filterable: false } : { block_types: undefined }),
  })
}

// Enum values edit as a comma-separated string for a compact single-input UX.
function enumText(field: ContentTypeField): string {
  return (field.enum ?? []).join(', ')
}

function setEnum(index: number, text: string) {
  patch(index, {
    enum: text
      .split(',')
      .map((value) => value.trim())
      .filter((value) => value !== ''),
  })
}
</script>

<template>
  <div class="space-y-3">
    <UEmpty
      v-if="model.length === 0"
      icon="i-lucide-list-plus"
      title="No fields yet"
      description="Add the fields authors will fill in for this content type."
    />

    <div
      v-for="(field, index) in model"
      :key="index"
      class="rounded-lg border border-default p-3 space-y-3"
    >
      <div class="flex items-end gap-2">
        <UFormField label="Field name" class="flex-1">
          <UInput
            :model-value="field.name"
            placeholder="title"
            class="w-full"
            @update:model-value="patch(index, { name: String($event) })"
          />
        </UFormField>

        <UFormField label="Type">
          <USelect
            :model-value="field.type"
            :items="typeItems"
            class="w-40"
            @update:model-value="onTypeChange(index, $event as FieldType)"
          />
        </UFormField>

        <UButton
          color="error"
          variant="ghost"
          icon="i-lucide-trash-2"
          aria-label="Remove field"
          @click="removeField(index)"
        />
      </div>

      <UFormField v-if="field.type === 'text'" label="Editor" hint="How authors edit this field">
        <URadioGroup
          :model-value="field.format ?? 'plain'"
          :items="formatItems"
          orientation="horizontal"
          @update:model-value="patch(index, { format: $event as 'plain' | 'rich' })"
        />
      </UFormField>

      <UFormField
        v-if="field.type === 'reference'"
        label="References"
        hint="The content type entries of this field point at"
      >
        <USelect
          :model-value="field.reference_type ?? undefined"
          :items="targetTypeItems"
          placeholder="Choose a content type"
          class="w-full"
          @update:model-value="patch(index, { reference_type: String($event) })"
        />
      </UFormField>

      <UFormField
        v-if="field.type === 'reference' || field.type === 'asset'"
        label="Multiple"
        hint="Store an ordered list of targets"
      >
        <USwitch
          :model-value="field.multiple ?? false"
          @update:model-value="patch(index, { multiple: $event })"
        />
      </UFormField>

      <UFormField
        v-if="(field.type === 'reference' || field.type === 'asset') && field.multiple"
        label="Max items"
        hint="Leave blank for no limit"
      >
        <UInput
          type="number"
          :min="1"
          :model-value="field.max_items ?? undefined"
          @update:model-value="
            patch(index, { max_items: Number($event) > 0 ? Number($event) : null })
          "
        />
      </UFormField>

      <UFormField
        v-if="field.type === 'reference'"
        label="Slug filter field"
        hint="Target field used to resolve slug filters (default: slug)"
      >
        <UInput
          :model-value="field.reference_slug_field ?? 'slug'"
          @update:model-value="patch(index, { reference_slug_field: String($event) || 'slug' })"
        />
      </UFormField>

      <UFormField v-if="field.type === 'enum'" label="Allowed values" hint="Comma-separated">
        <UInput
          :model-value="enumText(field)"
          placeholder="draft, review, published"
          class="w-full"
          @update:model-value="setEnum(index, String($event))"
        />
      </UFormField>

      <UFormField
        v-if="field.type === 'blocks'"
        label="Allowed block types"
        hint="Which block types the editor's picker offers; empty = all active types"
      >
        <USelect
          :model-value="field.block_types ?? []"
          :items="blockTypeItems"
          multiple
          placeholder="All active block types"
          class="w-full"
          data-test="field-block-types"
          @update:model-value="patch(index, { block_types: ($event as string[]) ?? [] })"
        />
      </UFormField>

      <div class="flex flex-wrap gap-4">
        <USwitch
          :model-value="field.required"
          label="Required"
          @update:model-value="patch(index, { required: $event })"
        />
        <!-- Block schemas: localized/filterable are rejected server-side (spec §2);
             blocks fields themselves are never filterable. -->
        <USwitch
          v-if="context === 'content-type'"
          :model-value="field.localized"
          label="Localized"
          @update:model-value="patch(index, { localized: $event })"
        />
        <USwitch
          v-if="context === 'content-type' && field.type !== 'blocks'"
          :model-value="field.filterable"
          label="Filterable"
          @update:model-value="patch(index, { filterable: $event })"
        />
      </div>
    </div>

    <UButton variant="subtle" color="neutral" icon="i-lucide-plus" @click="addField">
      Add field
    </UButton>
  </div>
</template>
