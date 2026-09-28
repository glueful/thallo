import { computed, ref } from 'vue'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { FieldDef } from '@/fields/types'
import { useNotify } from '@/composables/useNotify'
import { ApiError, apiErrorCode, apiErrorDetails } from '@/api/errors'
import {
  applyLayout,
  mintLayoutSession,
  removeLayout,
  saveLayout,
  type LayoutContent,
  type LayoutSession,
} from '@/queries/layouts'
import {
  StageRenewalAbandoned,
  type StageHost,
  type StageRenewal,
  type StageSession,
} from '@/editor/stage/types'
import type { StageEditor } from '@/editor/stage/useStageEditor'

// The layout host (type layouts spec §6.2): the layout editor's side of the stage editor. The editor
// edits ONE document — the layout's blocks as a root blocks field named `blocks`, its Frame options
// as a page-settings key so history covers them — and this host maps it to the server's
// `{blocks, settings}` on apply and save, keeps the save baseline (the version loaded), and runs the
// Regions restore sequence a sample switch or an expired session needs.

export const SETTINGS_KEY = '_layout_settings'

function record(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

/** The server's layout as the editor's document. */
export function toDocument(layout: LayoutContent): Record<string, unknown> {
  return {
    blocks: JSON.parse(JSON.stringify(layout.blocks ?? [])) as BlockInstance[],
    [SETTINGS_KEY]: JSON.parse(JSON.stringify(layout.settings ?? {})) as Record<string, unknown>,
  }
}

/** The editor's document as the server's layout. */
export function toPayload(fields: Record<string, unknown>): LayoutContent {
  const blocks = fields.blocks
  return {
    blocks: Array.isArray(blocks) ? (blocks as BlockInstance[]) : [],
    settings: record(fields[SETTINGS_KEY]),
  }
}

/**
 * The document's schema: one root blocks field. It names no allowlist — the general blocks and the
 * surface's field blocks are all welcome; the palette offers the field blocks because the host says
 * so (`allowLayoutOnly`), and the server validates what a layout may hold.
 */
export function layoutSchema(): FieldDef[] {
  return [{ name: 'blocks', label: 'Layout', type: 'blocks', blockTypes: [] }] as FieldDef[]
}

/** Why the server will not open this layout (a 422 on `target`), else null. */
function closedReason(e: unknown): string | null {
  const target = apiErrorDetails(e)?.target
  return (e as { status?: unknown } | null)?.status === 422 && typeof target === 'string'
    ? target
    : null
}

/** A removed layout's session: 410 LAYOUT_SESSION_RETIRED. */
function isRetired(e: unknown): boolean {
  return e instanceof ApiError && e.status === 410 && apiErrorCode(e) === 'LAYOUT_SESSION_RETIRED'
}

export function useLayoutHost(options: { surface: string; target: string }) {
  const { success, error: notifyError } = useNotify()

  const schema = computed(() => layoutSchema())
  const initial = ref<Record<string, unknown> | null>(null)
  /** The session as last minted: the required blocks, the palette, the sample, the labels. */
  const session = ref<LayoutSession | null>(null)
  /** Why this layout cannot be opened — its blocks not installed yet, say — when a mint says so. */
  const closed = ref<string | null>(null)
  /** The published item the stage shows; undefined = the newest (or a placeholder). */
  const sample = ref<string | undefined>(undefined)
  /** The version as first loaded; only a save advances it, only Reload replaces it. */
  const baseline = ref<number | null>(null)
  /** The layout as last saved (or loaded): what "dirty" is measured against. */
  const saved = ref('')
  /** A saved layout exists — loaded as one, or saved from here — so there is one to remove. */
  const live = ref(false)
  const saving = ref(false)
  const removing = ref(false)
  const conflict = ref(false)
  /** This editor's layout was removed (from here or elsewhere): nothing more can be applied. */
  const retired = ref(false)
  const switching = ref(false)

  let editor: StageEditor | null = null
  /** Every restore sequence takes a number; a newer one abandons any still in flight. */
  let generation = 0

  function loaded(next: LayoutSession): void {
    baseline.value = next.layout.lock_version
    live.value = !next.starter
    saved.value = JSON.stringify(toPayload(toDocument(next.layout)))
  }

  const asStage = (next: LayoutSession): StageSession => ({
    token: next.token,
    themeUrl: next.themeUrl,
    accepted: null, // a fresh session has accepted nothing
  })

  const host: StageHost = {
    schema,
    initial,
    // The field blocks belong in a layout: this is the one editor whose palette offers them —
    // this surface's own, and only while a session has said which they are.
    allowLayoutOnly: true,
    palette: () => session.value?.palette ?? [],
    cards: () =>
      session.value && session.value.loops.length > 0
        ? { loops: session.value.loops, palette: session.value.palette }
        : null,
    // The first session's baseline is the document; a mint never touches the save baseline again.
    async mint() {
      let minted: LayoutSession
      try {
        minted = await mintLayoutSession(options.surface, options.target, sample.value)
      } catch (e) {
        closed.value = closedReason(e)
        throw e
      }
      // A kept layout whose pages are off the site: the page shows why instead of the stage.
      closed.value = minted.closed
      session.value = minted
      if (baseline.value === null) {
        loaded(minted)
        initial.value = toDocument(minted.layout)
      }
      return asStage(minted)
    },
    apply: async (token, fields, applyOptions) => {
      try {
        return await applyLayout(token, toPayload(fields), applyOptions)
      } catch (e) {
        // Removed (here or in another tab): the stage editor will ask for a renewal, which
        // `renew` declines, so nothing brings the removed layout's session back.
        if (isRetired(e)) retired.value = true
        throw e
      }
    },
    // A fresh session has no working copy to reconcile.
    reconcileOnOpen: false,
    /**
     * The restore sequence (Regions spec §5.3): mint a session for the sample, apply the whole
     * current document to it with a null pair, and answer only once that apply is accepted. A newer
     * sequence abandons this one: its responses are ignored.
     */
    async renew(fields): Promise<StageRenewal> {
      if (retired.value) throw new StageRenewalAbandoned()
      const mine = ++generation
      let minted: LayoutSession
      try {
        minted = await mintLayoutSession(options.surface, options.target, sample.value)
      } catch (e) {
        // Closed since it opened (its blocks gone, or nothing kept there): the page says why.
        const reason = closedReason(e)
        if (reason === null) throw e
        closed.value = reason
        throw new StageRenewalAbandoned()
      }
      if (mine !== generation) throw new StageRenewalAbandoned()
      if (minted.closed !== null) {
        // Its pages were taken off the site meanwhile: nothing can be applied; the page says why.
        closed.value = minted.closed
        session.value = minted
        throw new StageRenewalAbandoned()
      }
      const result = await applyLayout(minted.token, toPayload(fields), {
        epoch: null,
        base_revision: null,
        operations: [],
      })
      if (mine !== generation) throw new StageRenewalAbandoned()
      session.value = minted
      return {
        token: minted.token,
        themeUrl: minted.themeUrl,
        accepted: { epoch: result.epoch, revision: result.revision },
        retryWithExistingPair: false,
      }
    },
  }

  /** The editor this host serves; set once, right after the editor is made. */
  function bind(stageEditor: StageEditor): void {
    editor = stageEditor
  }

  /** Preview against another sample: the restore sequence, carrying the unsaved layout over. */
  async function switchSample(next: string | undefined): Promise<void> {
    if (!editor || next === sample.value) return
    const previous = sample.value
    sample.value = next
    switching.value = true
    const mine = generation + 1
    let switched = false
    try {
      switched = await editor.switchSession(host.renew)
    } finally {
      if (mine === generation) {
        switching.value = false
        // A switch that failed leaves the stage on the sample it showed: so does the picker.
        if (!switched) sample.value = previous
      }
    }
  }

  function failed(e: unknown, what: string): void {
    if (
      e instanceof ApiError &&
      e.status === 409 &&
      apiErrorCode(e) === 'LAYOUT_VERSION_CONFLICT'
    ) {
      conflict.value = true
    } else if (isRetired(e)) {
      retired.value = true
    } else {
      notifyError(e, what)
    }
  }

  /**
   * Save (spec §5.5): the layout, the version loaded and the accepted pair. Success advances the
   * baseline and marks saved the history position that was submitted — an edit made while the save
   * was in flight stays dirty.
   */
  async function save(): Promise<boolean> {
    if (!editor || baseline.value === null) return false
    editor.commitNow() // save flushes pending edits first
    const sequence = editor.currentSequence()
    const layout = toPayload(editor.snapshotFields())
    const token = editor.previewToken.value
    saving.value = true
    try {
      const result = await saveLayout(options.surface, options.target, {
        token,
        layout,
        expected_lock_version: baseline.value,
        preview_revision: editor.accepted.value,
      })
      baseline.value = result.layout.lock_version
      saved.value = JSON.stringify(layout)
      live.value = true
      editor.markSaved(sequence)
      // Only the session the save came from was cleared; a newer one keeps its pair.
      if (result.previewCleared && editor.previewToken.value === token) {
        editor.accepted.value = null // the next apply starts a new epoch
        editor.displayed.value = null
      }
      success(
        'Layout saved',
        session.value?.reach ? `${session.value.reach}, from now on.` : undefined,
      )
      return true
    } catch (e) {
      failed(e, 'Couldn’t save the layout')
      return false
    } finally {
      saving.value = false
    }
  }

  /** Remove (spec §5.5): every page of the target goes back to the theme's template. */
  async function remove(): Promise<boolean> {
    if (!editor || baseline.value === null) return false
    removing.value = true
    try {
      await removeLayout(options.surface, options.target, {
        token: editor.previewToken.value,
        expected_lock_version: baseline.value,
      })
      live.value = false
      success('Layout removed', 'These pages use the theme’s design again.')
      return true
    } catch (e) {
      failed(e, 'Couldn’t remove the layout')
      return false
    } finally {
      removing.value = false
    }
  }

  /** Reload (after a conflict): discard the unsaved edits and start from the saved layout. */
  async function reload(): Promise<void> {
    if (!editor) return
    generation++ // abandon any restore in flight
    switching.value = false
    try {
      const minted = await mintLayoutSession(options.surface, options.target, sample.value)
      session.value = minted
      loaded(minted)
      conflict.value = false
      retired.value = false
      closed.value = minted.closed
      editor.restart(asStage(minted), toDocument(minted.layout))
    } catch (e) {
      const reason = closedReason(e)
      if (reason !== null) closed.value = reason
      else notifyError(e, 'Couldn’t reload the layout')
    }
  }

  return {
    host,
    bind,
    session,
    closed,
    sample,
    baseline,
    saved,
    live,
    saving,
    removing,
    conflict,
    retired,
    switching,
    switchSample,
    save,
    remove,
    reload,
  }
}

export type LayoutHost = ReturnType<typeof useLayoutHost>
