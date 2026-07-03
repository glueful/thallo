// One field in a content type's schema. Mirrors the backend FieldDefinition (the `type` union is
// the same set the OpenAPI schema enumerates for content-type field definitions).
export interface FieldDef {
  name: string
  type:
    | 'string'
    | 'text'
    | 'number'
    | 'boolean'
    | 'datetime'
    | 'enum'
    | 'reference'
    | 'asset'
    | 'json'
    | 'blocks'
  required?: boolean
  enum?: string[]
  /** Presentation widget for `text` fields: 'plain' (textarea) or 'rich' (editor). */
  format?: 'plain' | 'rich'
  /** Target content-type slug for a `reference` field — drives the searchable entry picker. */
  referenceType?: string
  /** Ordered-array reference/asset field. */
  multiple?: boolean
  /** Max items for a multiple field. */
  maxItems?: number
  /** Target field used to resolve reference slug filters (default `slug`). */
  referenceSlugField?: string
  /** Picker-only block-type allowlist for a `blocks` field ([] / absent = all active). */
  blockTypes?: string[]
}
