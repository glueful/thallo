import type { ContentTypeField } from '@/queries/contentTypes'
import type { FieldDef } from './types'

/**
 * Backend field-schema entry (snake_case wire shape) → the camelCase FieldDef the
 * field widgets consume. The entry editor's schema computed does this mapping inline
 * for top-level fields; block schemas arrive raw from the block-types API, so nested
 * widgets need the same bridge (ReferenceField reads field.referenceType — passing a
 * raw snake_case field would break reference/asset editing inside blocks).
 */
export function toFieldDef(f: ContentTypeField): FieldDef {
  return {
    name: String(f.name ?? ''),
    type: (f.type ?? 'string') as FieldDef['type'],
    required: f.required ?? undefined,
    enum: f.enum ?? undefined,
    enumLabels: f.enum_labels ?? undefined,
    format: (f.format ?? undefined) as FieldDef['format'],
    referenceType: f.reference_type ?? undefined,
    multiple: f.multiple ?? undefined,
    maxItems: f.max_items ?? undefined,
    referenceSlugField: f.reference_slug_field ?? undefined,
    blockTypes: f.block_types ?? undefined,
    group: f.group ?? undefined,
  }
}
