import { computed, ref } from 'vue'
import { useUpdateStatus, type UpdateStatus } from '@/queries/updates'

/** Per-browser, per-version dismissal: dismissing beta.22 does not hide beta.23. */
export const DISMISSED_KEY = 'thallo.update.dismissed'

export function readDismissed(): string | null {
  try {
    return localStorage.getItem(DISMISSED_KEY)
  } catch {
    return null
  }
}

export function dismissVersion(version: string): void {
  try {
    localStorage.setItem(DISMISSED_KEY, version)
  } catch {
    // Storage unavailable (private mode, quota): the notice simply shows again next time.
  }
}

/** The whole decision, pure: available, not a development checkout, not this version dismissed. */
export function shouldShowNotice(
  status: UpdateStatus | null | undefined,
  dismissed: string | null,
): boolean {
  if (!status || !status.available || status.development || status.latest === null) return false
  return status.latest !== dismissed
}

export function useUpdateNotice() {
  const { data: status } = useUpdateStatus()
  const dismissed = ref(readDismissed())
  const visible = computed(() => shouldShowNotice(status.value, dismissed.value))

  function dismiss(): void {
    const latest = status.value?.latest
    if (!latest) return
    dismissVersion(latest)
    dismissed.value = latest
  }

  return { status, visible, dismiss }
}
