import { getCurrentScope, onScopeDispose } from 'vue'

// The appearance changed somewhere (block typeface plan Task 11): after a font library change or an
// Appearance save, the tab says so, and every other tab of the admin hears it — an open stage then
// reloads to show it. BroadcastChannel where there is one; else a `localStorage` write, which other
// tabs hear as a `storage` event. A tab never hears its own.

export type AppearanceChangeKind = 'fonts' | 'appearance'

export interface AppearanceChange {
  kind: AppearanceChangeKind
  at: number
}

const CHANNEL = 'thallo-appearance'
const STORAGE_KEY = 'thallo.appearance.change'

function changeOf(value: unknown): AppearanceChange | null {
  if (typeof value !== 'object' || value === null) return null
  const c = value as Record<string, unknown>
  if ((c.kind !== 'fonts' && c.kind !== 'appearance') || typeof c.at !== 'number') return null
  return { kind: c.kind, at: c.at }
}

export function useAppearanceChanges() {
  const listeners = new Set<(change: AppearanceChange) => void>()
  const emit = (value: unknown): void => {
    const change = changeOf(value)
    if (change !== null) for (const listener of listeners) listener(change)
  }

  const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel(CHANNEL) : null
  const onStorage = (event: StorageEvent): void => {
    if (event.key !== STORAGE_KEY || event.newValue === null) return
    try {
      emit(JSON.parse(event.newValue))
    } catch {
      // Not ours.
    }
  }
  if (channel !== null) channel.onmessage = (event: MessageEvent) => emit(event.data)
  else window.addEventListener('storage', onStorage)

  function notify(kind: AppearanceChangeKind): void {
    const change: AppearanceChange = { kind, at: Date.now() }
    if (channel !== null) {
      channel.postMessage(change)
      return
    }
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(change))
    } catch {
      // Storage refused (a private window): other tabs find out on their next freshness check.
    }
  }

  /** Hear another tab's changes; the returned function stops listening. */
  function onChange(listener: (change: AppearanceChange) => void): () => void {
    listeners.add(listener)
    return () => listeners.delete(listener)
  }

  function dispose(): void {
    listeners.clear()
    if (channel !== null) channel.close()
    else window.removeEventListener('storage', onStorage)
  }

  if (getCurrentScope()) onScopeDispose(dispose)

  return { notify, onChange, dispose }
}
