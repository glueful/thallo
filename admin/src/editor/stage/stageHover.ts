// The stage's forced hover (hover state spec §6.3): a Style tab asks while its Hover is on, naming
// exactly what it governs — every target an effective hover path lands on, or one part with its
// declared scope. Only the asker clears, since a block's tab and its parts' tabs are separate. The
// stage editor holds the force and sends it again whenever the stage reports ready: a reload forgets it.
import type { InjectionKey } from 'vue'
import type { BlockType } from '@/queries/blockTypes'
import { effectivePaths, HOVER_OF } from '@/style/capabilities'

export interface ForceHoverRequest {
  id: string
  /** A block's tab: every target an effective hover path lands on. */
  targets: string[]
  /** A part's tab: the part. */
  part: string | null
  /** Whose elements: the block's own, or (a part drawn by child blocks) its direct children's. */
  scope: 'own' | 'children'
}

export interface StageHover {
  force(owner: symbol, request: ForceHoverRequest): void
  clear(owner: symbol): void
}

export const StageHoverKey: InjectionKey<StageHover> = Symbol('thallo-stage-hover')

/** Every target an effective hover path lands on: its own mapping, else the `hover` group's, else root. */
export function hoverTargets(type: BlockType | null): string[] {
  const map = (type?.style_targets as { map?: Record<string, unknown> } | null | undefined)?.map
  const out: string[] = []
  for (const path of effectivePaths(type)) {
    if (HOVER_OF[path] === undefined) continue
    const mapped = map?.[path] ?? map?.hover
    const target = typeof mapped === 'string' ? mapped : 'root'
    if (!out.includes(target)) out.push(target)
  }
  return out
}

/** Whose elements a part's force reaches: the block's own, or its direct children's (`children: true`). */
export function partScope(type: BlockType | null, part: string): 'own' | 'children' {
  const parts = (
    type?.style_targets as { parts?: Record<string, { children?: boolean }> } | null | undefined
  )?.parts
  return parts?.[part]?.children === true ? 'children' : 'own'
}

export function createStageHover(
  send: (request: ForceHoverRequest | null) => void,
): StageHover & { clearAny(): void; resend(): void } {
  let active: { owner: symbol; request: ForceHoverRequest } | null = null
  return {
    force(owner, request) {
      active = { owner, request }
      send(request)
    },
    clear(owner) {
      if (active?.owner !== owner) return
      active = null
      send(null)
    },
    /** A new selection, or the stage editor going away: whoever asked, the force ends. */
    clearAny() {
      if (active === null) return
      active = null
      send(null)
    },
    /** A stage that reloaded forgot the force: send it again. */
    resend() {
      if (active) send(active.request)
    },
  }
}
