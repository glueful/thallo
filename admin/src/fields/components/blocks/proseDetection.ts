import type { BlockType } from '@/queries/blockTypes'

// CONVENTION, not identity (spec §3): a block type whose schema is EXACTLY one
// rich text field renders as chromeless prose. The reserved durable escape hatch
// is block-type metadata (`editor_mode: prose | card`) — when that lands, this
// predicate consults it FIRST and the convention becomes the fallback. No other
// feature may treat this as a stable identity contract.
type SchemaField = { name: string; type: string; format?: string }

export function isProseBlockType(type: Pick<BlockType, 'schema'>): boolean {
  return proseRichFieldName(type) !== null
}

/** The rich field's name when the type is prose-shaped; null otherwise. */
export function proseRichFieldName(type: Pick<BlockType, 'schema'>): string | null {
  const schema = (type.schema ?? []) as SchemaField[]
  if (schema.length !== 1) return null
  const only = schema[0]!
  return only.type === 'text' && only.format === 'rich' ? only.name : null
}

/**
 * Tail-prose default (spec §3): allowed active `rich_text` -> first allowed
 * active prose-detected type -> null (affordance hidden).
 */
export function defaultProseType(types: BlockType[], allowlist: string[]): BlockType | null {
  const allowed = types.filter(
    (t) => t.active && (allowlist.length === 0 || allowlist.includes(t.slug)),
  )
  const richText = allowed.find((t) => t.slug === 'rich_text' && isProseBlockType(t))
  if (richText) return richText
  return allowed.find((t) => isProseBlockType(t)) ?? null
}
