import { watch, type Ref, type WatchStopHandle } from 'vue'

/**
 * Collapse the sidebar while a route that wants the whole width is shown (the Design page),
 * and put it back the way it was on leaving. Whatever the user chose before entering is what
 * they get after; a change they make by hand while on the page is theirs and survives.
 */
export function autoCollapseSidebar(collapsed: Ref<boolean>, wants: Ref<boolean>): WatchStopHandle {
  let before: boolean | null = null
  return watch(
    wants,
    (wanted) => {
      if (wanted) {
        before = collapsed.value
        collapsed.value = true
      } else if (before !== null) {
        // Restore only what the auto-collapse changed: a sidebar the user reopened stays open.
        if (collapsed.value) collapsed.value = before
        before = null
      }
    },
    { immediate: true },
  )
}
