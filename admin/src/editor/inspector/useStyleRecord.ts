// Writing a style record from the Style tab's events, for a host that holds the record as a
// `v-model` — a style class's editor, a chrome region's Style tab. (A block's inspector writes
// through the editor's operations instead.)
import { nextTick } from 'vue'
import type { Breakpoint, StyleValue } from '@/style/types'
import { absent, present } from '@/editor/ops/types'
import { setPath, settingSegments } from '@/editor/ops/apply'

type Style = Record<string, unknown>
type Settings = Record<string, unknown>

export function useStyleRecord(current: () => Style, emit: (next: Style) => void) {
  /**
   * What was emitted earlier IN THIS TICK. One click can write several declarations — a linked box
   * writes every side — each emitted before the parent has fed the last one back as the model,
   * which happens a tick later. Built on the model alone, every write in the burst would start
   * from the same stale value and the last would overwrite the rest. So within a tick a write
   * builds on the one before it; once the tick has passed the model is the authority again,
   * whether or not the parent took what it was handed.
   */
  let pending: Style | null = null

  function write(mutate: (settings: Settings) => Settings): void {
    if (pending === null) {
      void nextTick(() => {
        pending = null
      })
    }
    const style = mutate({ style: pending ?? current() }).style
    pending = typeof style === 'object' && style !== null ? (style as Style) : {}
    emit(pending)
  }

  function set(path: string, bp: Breakpoint | null, value: StyleValue | null): void {
    write((s) => setPath(s, settingSegments(path, bp), value === null ? absent() : present(value)))
  }

  function setAll(path: string, value: StyleValue): void {
    write((s) => {
      let next = s
      for (const bp of ['base', 'md', 'lg'] as Breakpoint[]) {
        next = setPath(next, settingSegments(path, bp), present(value))
      }
      return next
    })
  }

  return { set, setAll }
}
