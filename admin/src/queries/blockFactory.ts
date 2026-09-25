import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { newBlockId, type BlockInstance } from '@/fields/components/blocks/useBlockListOps'

// The server block factory (visual builder spec §5.5): the server is authoritative for a fresh
// block's canonical structure and defaults and for the type's starter content; the editor is
// authoritative for draft block ids, so it mints one for the block and every nested starter
// block. One fetch per slug per session — a type's defaults do not change under an editor.

export interface FactoryBlock {
  type: string
  data: Record<string, unknown>
  settings: Record<string, unknown>
}

export interface FactoryResult {
  block: FactoryBlock
  starter: Record<string, unknown>
}

function record(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

export async function fetchBlockInstance(slug: string): Promise<FactoryResult> {
  const { data, error, response } = await client.POST('/block-types/{slug}/instance', {
    params: { path: { slug } },
  })
  if (error) throw toApiError(error, response)
  const payload = record((data as unknown as { data?: unknown })?.data)
  const block = record(payload.block)
  return {
    block: {
      type: typeof block.type === 'string' ? block.type : slug,
      data: record(block.data),
      settings: record(block.settings),
    },
    starter: record(payload.starter),
  }
}

/** A list of starter blocks: every item an object naming a `type`. */
function isBlockList(value: unknown): value is Record<string, unknown>[] {
  return (
    Array.isArray(value) &&
    value.length > 0 &&
    value.every(
      (item) => typeof item === 'object' && item !== null && typeof item.type === 'string',
    )
  )
}

/**
 * The id a new block instance takes. An E2E build (`VITE_E2E`, set only by the proofs' web server)
 * takes the next id a proof queued in `window.__thalloE2eBlockIds`, so its accepted documents name
 * blocks it can match; everywhere else — and a production bundle folds this branch away — a fresh
 * id. Block instances only: session, operation, transaction and drag ids never read the queue.
 */
export function nextInstanceId(): string {
  if (import.meta.env.VITE_E2E === '1') {
    const queued = (window as { __thalloE2eBlockIds?: unknown }).__thalloE2eBlockIds
    if (Array.isArray(queued) && queued.length > 0) return String(queued.shift())
  }
  return newBlockId()
}

/** Fresh ids for a block and every nested starter block inside its data, the block first. */
export function allocateIds(block: FactoryBlock): BlockInstance {
  const id = nextInstanceId()
  const data: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(block.data)) {
    data[key] = isBlockList(value)
      ? value.map((item) =>
          allocateIds({
            type: item.type as string,
            data: record(item.data),
            settings: record(item.settings),
          }),
        )
      : value
  }
  return { id, type: block.type, data, settings: { ...block.settings } }
}

/** The factory's block with its starter merged over the defaults. */
export function withStarter(result: FactoryResult): FactoryBlock {
  return {
    type: result.block.type,
    data: { ...result.block.data, ...result.starter },
    settings: result.block.settings,
  }
}

const cache = new Map<string, Promise<FactoryResult>>()

/** Forget every cached instance (tests, or a block type edit). */
export function resetBlockFactoryCache(): void {
  cache.clear()
}

export function useBlockFactory() {
  function make(slug: string): Promise<FactoryResult> {
    const cached = cache.get(slug)
    if (cached) return cached
    const pending = fetchBlockInstance(slug).catch((err: unknown) => {
      cache.delete(slug) // a failure is not a fact about the type
      throw err
    })
    cache.set(slug, pending)
    return pending
  }

  /** A ready-to-insert block: defaults, starter, and fresh ids throughout. */
  async function instance(slug: string): Promise<BlockInstance> {
    return allocateIds(withStarter(await make(slug)))
  }

  return { make, instance }
}
