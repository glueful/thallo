// What the stage renders a block's target in, for the inspector (block typeface plan Task 10): the
// stage editor provides it; the Style tab asks for the target its Typography group maps to, or for
// its part, and asks again after each stage render.
import type { InjectionKey, Ref } from 'vue'
import type { ComputedTypography } from '@/composables/useCanvasBridge'
import type { BlockType } from '@/queries/blockTypes'

export interface StageTypography {
  request(id: string, target: string): Promise<ComputedTypography | null>
  /** Counts the stage's renders — a load or an in-place patch: what was measured may be stale. */
  renders: Readonly<Ref<number>>
}

export const StageTypographyKey: InjectionKey<StageTypography> = Symbol('thallo-stage-typography')

/** The target a type's typeface lands on: its map's entry for the property or the group, else root. */
export function typographyTarget(type: BlockType | null): string {
  const map = (type?.style_targets as { map?: Record<string, unknown> } | null | undefined)?.map
  const target = map?.['typography.family'] ?? map?.typography
  return typeof target === 'string' ? target : 'root'
}
