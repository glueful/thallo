// One field in a content type's schema. Mirrors the backend FieldDefinition (the `type` union is
// the same set the OpenAPI schema enumerates for content-type field definitions).
export interface FieldDef {
  name: string
  /** Display label override; empty string suppresses the inner label (outer form labels it). */
  label?: string
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
    | 'box'
    | 'token'
  required?: boolean
  enum?: string[]
  /** Display labels per enum value (presentation only — stored values stay bare). */
  enumLabels?: Record<string, string>
  /** Presentation widget: `text` plain|rich; `string` icon|brand-icon|color; `json` link-list. */
  format?: 'plain' | 'rich' | 'icon' | 'brand-icon' | 'color' | 'link-list'
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
  /** Editor grouping: fields with the same group fold into a collapsible section. */
  group?: string
  /** The vocabulary domain a `token` field picks from (`color`, `spacing`, …). */
  domain?: string
}
