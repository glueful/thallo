import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'

/**
 * The block a server validation path names. The API addresses a refused field through the
 * document — `body.3.content.0.content.0.title`: a root blocks field, an index, then a block's
 * data field, an index, … and last the refused field — and the editor turns it back into the
 * block (to select it) and the field (to name it). Null when the path never enters a block.
 */
export function blockAtValidationPath(
  fields: Record<string, unknown>,
  path: string,
): { id: string; type: string; field: string } | null {
  const segments = path.split('.')
  if (segments.length < 3) return null
  let list = fields[segments[0]!]
  let block: BlockInstance | null = null
  let i = 1
  while (i < segments.length) {
    if (!Array.isArray(list)) return null
    const index = Number(segments[i])
    const next = list[index] as BlockInstance | undefined
    if (!Number.isInteger(index) || !next || typeof next.id !== 'string') return null
    block = next
    const field = segments[i + 1]
    if (field === undefined) return null
    if (i + 1 === segments.length - 1) return { id: block.id, type: block.type, field }
    list = block.data?.[field]
    i += 2
  }
  return null
}
