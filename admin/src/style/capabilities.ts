import type { BlockType } from '@/queries/blockTypes'
import { propertyDefinition, styleProperties } from './schema'

// What a block type offers (hover state spec §2.2.1). Real block types and their parts carry the
// server's expansion, `style_paths`; only synthetic types — a style class, a region — expand here,
// with the same rule as StyleCapabilities: a hover path exists only beside its resting path.

/**
 * Hover path → the resting path it changes (StyleSchema::HOVER), derived from the schema: the
 * `hover` group, each path the resting one under `hover.` (pinned on both sides by the parity tests).
 */
export const HOVER_OF: Record<string, string> = Object.fromEntries(
  styleProperties()
    .filter((row) => row.group === 'hover')
    .map((row) => [row.path, row.path.slice('hover.'.length)]),
)

/** A declaration's paths: a path, or a group naming its paths; then the hover rule. */
export function expandDeclaration(declaration: readonly string[] | null | undefined): Set<string> {
  const out = new Set<string>()
  for (const entry of declaration ?? []) {
    if (propertyDefinition(entry) !== null) out.add(entry)
    else for (const row of styleProperties()) if (row.group === entry) out.add(row.path)
  }
  for (const path of [...out]) {
    const resting = HOVER_OF[path]
    if (resting !== undefined && !out.has(resting)) out.delete(path)
  }
  return out
}

/** Every style path a type offers: the server's `style_paths.block`, or a synthetic type's own. */
export function effectivePaths(type: BlockType | null | undefined): Set<string> {
  const paths = type?.style_paths?.block
  return Array.isArray(paths) ? new Set(paths) : expandDeclaration(type?.style_capabilities)
}

/**
 * A part as the Style tab edits it: a type offering exactly what the part declares — the server's
 * `style_paths.parts[name]`, else (a type without it) the part's own capabilities expanded.
 */
export function partBlockType(type: BlockType, part: string): BlockType {
  const declared = (
    type.style_targets as { parts?: Record<string, { capabilities?: string[] }> } | null
  )?.parts?.[part]
  const served = type.style_paths?.parts?.[part]
  return {
    ...type,
    style_capabilities: declared?.capabilities ?? [],
    style_targets: null,
    style_paths: {
      block: Array.isArray(served) ? served : [...expandDeclaration(declared?.capabilities)],
      parts: {},
    },
  }
}
