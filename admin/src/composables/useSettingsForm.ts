// One slice of the general settings, edited on its own page. The settings share an endpoint
// (`/settings/general`) but not a screen: Settings › General owns how the site behaves, Site ›
// Appearance how it looks. Each page edits and SAVES ONLY ITS OWN KEYS — the server leaves an
// omitted key unchanged, so a page holding a stale copy of another page's settings can never write
// it back over a change made there meanwhile.
import { nextTick, reactive, ref, watch, type Ref } from 'vue'
import type { GeneralSettings } from '@/queries/generalSettings'

export function useSettingsForm<K extends keyof GeneralSettings>(
  data: Ref<GeneralSettings | undefined>,
  defaults: Pick<GeneralSettings, K>,
) {
  const keys = Object.keys(defaults) as K[]
  const form = reactive({ ...defaults }) as Pick<GeneralSettings, K>

  // Server → form sync, but NEVER over unsaved edits: the query refetches on window refocus once
  // stale, and an unconditional assign would wipe in-progress changes (a freshly uploaded logo)
  // before Save.
  const dirty = ref(false)
  let syncing = false
  watch(
    data,
    (settings) => {
      if (!settings || dirty.value) return
      syncing = true
      for (const key of keys) {
        if (settings[key] !== undefined) form[key] = settings[key]
      }
      void nextTick(() => {
        syncing = false
      })
    },
    { immediate: true },
  )
  watch(
    form,
    () => {
      if (!syncing) dirty.value = true
    },
    { deep: true },
  )

  /** Exactly this page's keys, as they stand. */
  const payload = (): Pick<GeneralSettings, K> => {
    const out = {} as Pick<GeneralSettings, K>
    for (const key of keys) out[key] = form[key]
    return out
  }
  /** Saved: the form matches the server again, so the post-save refetch may sync. */
  const saved = (): void => {
    dirty.value = false
  }

  return { form, dirty, payload, saved }
}
