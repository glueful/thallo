// One slice of the general settings, edited on its own page. The settings share an endpoint
// (`/settings/general`) but not a screen: Settings › General owns how the site behaves, Site ›
// Appearance how it looks. Each page edits and SAVES ONLY ITS OWN KEYS — the server leaves an
// omitted key unchanged, so a page holding a stale copy of another page's settings can never write
// it back over a change made there meanwhile.
//
// A page may own some keys itself (`manual`): the server→form sync leaves them alone, so the page
// can install a value together with what it depends on (Appearance's brand colours and their
// revision). adopt() writes a value the page takes from the server without counting it as an edit,
// and saved(sent) marks the form clean only if nothing was edited while the save was in flight.
import { nextTick, reactive, ref, watch, type Ref } from 'vue'
import type { GeneralSettings } from '@/queries/generalSettings'

export function useSettingsForm<K extends keyof GeneralSettings>(
  data: Ref<GeneralSettings | undefined>,
  defaults: Pick<GeneralSettings, K>,
  options: { manual?: NoInfer<K>[] } = {},
) {
  const keys = Object.keys(defaults) as K[]
  /** The keys the server→form sync writes: every key but the ones the page owns. */
  const synced = keys.filter((key) => !(options.manual ?? []).includes(key))
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
      for (const key of synced) {
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
  /** The values adopted since the last save: a save in flight compares against them too. */
  const adopted = new Map<K, unknown>()
  /** A value the page takes from the server (a save's ids, a colour that left): never an edit. */
  const adopt = (patch: Partial<Pick<GeneralSettings, K>>): void => {
    for (const key of Object.keys(patch) as K[]) adopted.set(key, patch[key])
    syncing = true
    Object.assign(form, patch)
    void nextTick(() => {
      syncing = false
    })
  }
  /**
   * Saved: the form matches the server again, so the post-save refetch may sync — unless it was
   * edited while the save was in flight (`sent` is what was sent), when it stays dirty and keeps
   * the edit.
   */
  const saved = (sent?: Pick<GeneralSettings, K>): void => {
    // A key differs from what was sent only by an edit: a value adopted meanwhile is not one.
    const edited = (key: K) =>
      form[key] !== sent![key] && !(adopted.has(key) && adopted.get(key) === form[key])
    const stillDirty = sent !== undefined && keys.some(edited)
    adopted.clear()
    if (stillDirty) return
    dirty.value = false
  }

  return { form, dirty, payload, saved, adopt }
}
