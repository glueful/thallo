import { computed, ref, type Ref } from 'vue'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { FieldDef } from '@/fields/types'
import { useNotify } from '@/composables/useNotify'
import { ApiError, apiErrorCode } from '@/api/errors'
import {
  applyRegions,
  mintRegionSession,
  saveRegions,
  type RegionContent,
  type RegionData,
  type RegionsPayload,
  type RegionSession,
} from '@/queries/regions'
import {
  StageRenewalAbandoned,
  type StageHost,
  type StageRenewal,
  type StageSession,
} from '@/editor/stage/types'
import type { StageEditor } from '@/editor/stage/useStageEditor'

// The region host (regions stage spec §5.1–§5.3): the Regions page's side of the stage editor. The
// editor edits ONE document — both regions' block lists as root blocks fields, their settings as
// page-settings keys so history covers them — and this host maps it to the server's
// `{header: {blocks, settings}, footer: …}` on apply and save, keeps the save baseline, and runs the
// restore sequence a page switch or an expired session needs.

export const REGION_SLUGS = ['header', 'footer'] as const
export type RegionSlug = (typeof REGION_SLUGS)[number]

const settingsKey = (slug: RegionSlug) => `_region_${slug}` as const

function record(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

/** The server's two regions as the editor's one document. */
export function toDocument(
  regions: Partial<Record<string, RegionContent>>,
): Record<string, unknown> {
  const doc: Record<string, unknown> = {}
  for (const slug of REGION_SLUGS) {
    const region = regions[slug]
    doc[slug] = JSON.parse(JSON.stringify(region?.blocks ?? [])) as BlockInstance[]
    doc[settingsKey(slug)] = JSON.parse(JSON.stringify(region?.settings ?? {})) as Record<
      string,
      unknown
    >
  }
  return doc
}

/** The editor's document as the server's two regions. */
export function toPayload(fields: Record<string, unknown>): RegionsPayload {
  const out = {} as RegionsPayload
  for (const slug of REGION_SLUGS) {
    const blocks = fields[slug]
    out[slug] = {
      blocks: Array.isArray(blocks) ? (blocks as BlockInstance[]) : [],
      settings: record(fields[settingsKey(slug)]),
    }
  }
  return out
}

/**
 * The document's schema: each region a root blocks field, carrying that region's palette — which
 * constrains its root only; inside a block, the slot's own rules apply. `first` leads, so the
 * Blocks tab inserts into the current region when nothing is selected.
 */
export function regionSchema(regions: RegionData[], first: RegionSlug = 'header'): FieldDef[] {
  const order: RegionSlug[] = first === 'footer' ? ['footer', 'header'] : ['header', 'footer']
  return order.map((slug) => ({
    name: slug,
    label: slug === 'header' ? 'Header' : 'Footer',
    type: 'blocks',
    blockTypes: regions.find((r) => r.slug === slug)?.palette ?? [],
  })) as FieldDef[]
}

export type SaveBaseline = Record<RegionSlug, number | null>

const versionsOf = (
  regions: Partial<Record<string, { lock_version: number | null }>>,
): SaveBaseline => ({
  header: regions.header?.lock_version ?? null,
  footer: regions.footer?.lock_version ?? null,
})

export function useRegionHost(options: { regions: Ref<RegionData[] | undefined> }) {
  const { success, error: notifyError } = useNotify()

  /** The region the Blocks, Region and Outline tabs work on. */
  const currentRegion = ref<RegionSlug>('header')
  const schema = computed(() => regionSchema(options.regions.value ?? [], currentRegion.value))
  const initial = ref<Record<string, unknown> | null>(null)
  /** The published page the stage shows; undefined = the homepage. */
  const page = ref<string | undefined>(undefined)
  /** Whether the page the stage shows hides the header or the footer. */
  const hidden = ref<Record<RegionSlug, boolean>>({ header: false, footer: false })
  /** Both regions' versions as first loaded; only a save advances them, only Reload replaces them. */
  const baseline = ref<SaveBaseline | null>(null)
  /** Each region as last saved (or loaded): what "dirty" is measured against, per region. */
  const saved = ref<Record<RegionSlug, string>>({ header: '', footer: '' })
  const saving = ref(false)
  const conflict = ref(false)
  const switching = ref(false)

  let editor: StageEditor | null = null
  /** Every restore sequence takes a number; a newer one abandons any still in flight. */
  let generation = 0

  function loaded(session: RegionSession): void {
    baseline.value = versionsOf(session.regions)
    const payload = toPayload(toDocument(session.regions))
    saved.value = {
      header: JSON.stringify(payload.header),
      footer: JSON.stringify(payload.footer),
    }
  }

  const asStage = (session: RegionSession): StageSession => ({
    token: session.token,
    themeUrl: session.themeUrl,
    accepted: null, // a fresh session has accepted nothing
  })

  const host: StageHost = {
    schema,
    initial,
    // The first session's baseline is the document; a mint never touches the save baseline again.
    async mint() {
      const session = await mintRegionSession(page.value)
      hidden.value = session.hidden
      if (baseline.value === null) {
        loaded(session)
        initial.value = toDocument(session.regions)
      }
      return asStage(session)
    },
    apply: (token, fields, applyOptions) => applyRegions(token, toPayload(fields), applyOptions),
    // A fresh session has no working copy to reconcile.
    reconcileOnOpen: false,
    /**
     * The restore sequence (spec §5.3): mint a session for the page, apply the whole current
     * document to it with a null pair, and answer only once that apply is accepted. A newer
     * sequence abandons this one: its responses are ignored.
     */
    async renew(fields): Promise<StageRenewal> {
      const mine = ++generation
      const session = await mintRegionSession(page.value)
      if (mine !== generation) throw new StageRenewalAbandoned()
      const result = await applyRegions(session.token, toPayload(fields), {
        epoch: null,
        base_revision: null,
        operations: [],
      })
      if (mine !== generation) throw new StageRenewalAbandoned()
      hidden.value = session.hidden
      return {
        token: session.token,
        themeUrl: session.themeUrl,
        accepted: { epoch: result.epoch, revision: result.revision },
        retryWithExistingPair: false,
      }
    },
  }

  /** The editor this host serves; set once, right after the editor is made. */
  function bind(stageEditor: StageEditor): void {
    editor = stageEditor
  }

  /** Show another published page: the restore sequence, through the editor's own path. */
  async function switchPage(next: string | undefined): Promise<void> {
    if (!editor || next === page.value) return
    const previous = page.value
    page.value = next
    switching.value = true
    const mine = generation + 1
    let switched = false
    try {
      switched = await editor.switchSession(host.renew)
    } finally {
      if (mine === generation) {
        switching.value = false
        // A switch that failed leaves the stage on the page it showed: so does the picker.
        if (!switched) page.value = previous
      }
    }
  }

  /** The regions whose content differs from what was last saved. */
  function dirtyRegions(fields: Record<string, unknown>): Partial<RegionsPayload> {
    const payload = toPayload(fields)
    const out: Partial<RegionsPayload> = {}
    for (const slug of REGION_SLUGS) {
      if (JSON.stringify(payload[slug]) !== saved.value[slug]) out[slug] = payload[slug]
    }
    return out
  }

  /**
   * Save (spec §5.3): one call with the dirty regions, both expected versions from the save
   * baseline and the accepted pair. Success advances the baseline and marks saved the history
   * position that was submitted — an edit made while the save was in flight stays dirty.
   */
  async function save(): Promise<boolean> {
    if (!editor || baseline.value === null) return false
    editor.commitNow() // save flushes pending edits first
    const sequence = editor.currentSequence()
    const regions = dirtyRegions(editor.snapshotFields())
    const token = editor.previewToken.value
    saving.value = true
    try {
      const result = await saveRegions({
        regions,
        expected: baseline.value,
        token: token || null,
        preview_revision: editor.accepted.value,
      })
      baseline.value = versionsOf(result.regions)
      for (const slug of REGION_SLUGS) {
        const posted = regions[slug]
        if (posted) saved.value = { ...saved.value, [slug]: JSON.stringify(posted) }
      }
      editor.markSaved(sequence)
      // Only the session the save came from was cleared; a newer one keeps its pair.
      if (result.previewCleared && editor.previewToken.value === token) {
        editor.accepted.value = null // the next apply starts a new epoch
        editor.displayed.value = null
      }
      success('Header and footer saved', 'They are live on the site now.')
      return true
    } catch (e) {
      if (
        e instanceof ApiError &&
        e.status === 409 &&
        apiErrorCode(e) === 'REGION_VERSION_CONFLICT'
      ) {
        conflict.value = true
      } else {
        notifyError(e, 'Couldn’t save the header and footer')
      }
      return false
    } finally {
      saving.value = false
    }
  }

  /** Reload (after a conflict): discard the unsaved edits and start from the saved regions. */
  async function reload(): Promise<void> {
    if (!editor) return
    generation++ // abandon any restore in flight
    switching.value = false
    try {
      const session = await mintRegionSession(page.value)
      hidden.value = session.hidden
      loaded(session)
      conflict.value = false
      editor.restart(asStage(session), toDocument(session.regions))
    } catch (e) {
      notifyError(e, 'Couldn’t reload the header and footer')
    }
  }

  return {
    host,
    bind,
    currentRegion,
    page,
    hidden,
    baseline,
    saving,
    conflict,
    switching,
    switchPage,
    save,
    reload,
  }
}

export type RegionHost = ReturnType<typeof useRegionHost>
