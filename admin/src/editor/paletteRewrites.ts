// The colours the server's palette normalisation changed in a save (custom palette spec §4.5),
// adopted into the editor's document. A rewrite names its location by the innermost block's id
// plus the path relative to that block (`head00000001:settings.style.colors.text`), or by a plain
// dotted path outside blocks (`_presentation.style.colors.surface`). It lands only where the value
// still equals `from`: a block deleted, recoloured or re-typed during the request keeps its edit.

export interface PaletteRewrite {
  location: string
  from: string
  to: string
}

type Node = Record<string, unknown>

function isObject(value: unknown): value is Node {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isToken(value: unknown): value is { type: 'token'; value: string } {
  return isObject(value) && value.type === 'token' && typeof value.value === 'string'
}

/** The block with this id anywhere in the document (page fields, nested regions), or null. */
function findBlock(node: unknown, id: string): Node | null {
  if (Array.isArray(node)) {
    for (const item of node) {
      const found = findBlock(item, id)
      if (found !== null) return found
    }
    return null
  }
  if (!isObject(node)) return null
  if (node.id === id && typeof node.type === 'string') return node
  for (const value of Object.values(node)) {
    const found = findBlock(value, id)
    if (found !== null) return found
  }
  return null
}

/** The container and key a dotted path ends at, under `root`; null when the path is missing. */
function resolve(root: unknown, path: string): { parent: Node | unknown[]; key: string } | null {
  const segments = path.split('.')
  let node: unknown = root
  for (let i = 0; i < segments.length - 1; i++) {
    const segment = segments[i]!
    node = Array.isArray(node) ? node[Number(segment)] : isObject(node) ? node[segment] : undefined
    if (node === undefined || node === null) return null
  }
  if (!Array.isArray(node) && !isObject(node)) return null
  return { parent: node, key: segments[segments.length - 1]! }
}

/**
 * Apply each rewrite whose location still holds its `from` token. The input is not mutated.
 *
 * @returns the rewritten document and the rewrites that landed
 */
export function applyPaletteRewrites<T>(
  doc: T,
  rewrites: PaletteRewrite[],
): { doc: T; applied: PaletteRewrite[] } {
  if (rewrites.length === 0) return { doc, applied: [] }
  const out = structuredClone(doc) as T
  const applied: PaletteRewrite[] = []
  for (const rewrite of rewrites) {
    const colon = rewrite.location.indexOf(':')
    const root = colon === -1 ? out : findBlock(out, rewrite.location.slice(0, colon))
    if (root === null) continue // the block is gone: never land on another one
    const at = resolve(root, colon === -1 ? rewrite.location : rewrite.location.slice(colon + 1))
    if (at === null) continue
    const current = (at.parent as Record<string, unknown>)[at.key]
    if (!isToken(current) || current.value !== rewrite.from)
      continue // edited during the request
    ;(at.parent as Record<string, unknown>)[at.key] = { ...current, value: rewrite.to }
    applied.push(rewrite)
  }
  return { doc: out, applied }
}

/** Map every token node, at any depth, through `mapping`; everything else is returned as it was. */
export function mapPaletteTokens<T>(value: T, mapping: Record<string, string>): T {
  if (Object.keys(mapping).length === 0) return value
  const walk = (node: unknown): unknown => {
    if (Array.isArray(node)) return node.map(walk)
    if (!isObject(node)) return node
    if (isToken(node)) {
      const to = mapping[node.value]
      return to === undefined ? node : { ...node, value: to }
    }
    const out: Node = {}
    for (const [key, child] of Object.entries(node)) out[key] = walk(child)
    return out
  }
  return walk(value) as T
}
