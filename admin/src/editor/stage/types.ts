import type { Ref } from 'vue'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { BlocksHost } from '@/fields/components/blocks/context'
import type { FieldDef } from '@/fields/types'
import type { EditorDocument, OperationBody } from '@/editor/ops/types'
import type { CardRules, Legality } from '@/editor/structure/legality'
import type { Pattern } from '@/queries/patterns'

/** The accepted working-copy pair (visual builder spec §3.5). */
export interface RevisionPair {
  epoch: string
  revision: number
}

export interface ApplyPreviewOptions {
  /** The pair the client last accepted; both null before its first apply. */
  epoch: string | null
  base_revision: number | null
  /** The committed operations since `base_revision` (intent for the fragment path). */
  operations: unknown[]
}

export interface ApplyPreviewResult extends RevisionPair {
  /** The revision the stage showed before this one: what an in-place patch expects. */
  baseline: number
  style_generation: number
  applied_at: string
  /** Root block id => rendered wrapper (the fragment path, spec §3.5); null = refresh the page. */
  fragments: Record<string, string> | null
  /**
   * The layout the accepted document renders through (type layouts spec §6.3), or null; absent
   * where the host has no layouts (the header & footer, a layout's own stage).
   */
  layout?: { surface: string; target: string; label: string } | null
}

/** A preview session the stage loads: its token, its iframe URL and the pair it has accepted. */
export interface StageSession {
  token: string
  /** Null when rendered delivery is off: the stage explains instead of loading. */
  themeUrl: string | null
  accepted: RevisionPair | null
}

export interface StageRenewal extends StageSession {
  /**
   * True: load the new session and retry the refused apply with the pair already held (the entry
   * host). False: the host has already applied the whole document on the new session, and the
   * returned pair names it.
   */
  retryWithExistingPair: boolean
}

/**
 * What a page supplies to the stage editor (regions stage spec §5.2): the document's schema and
 * its hydrated tree, and how to mint, apply and renew its preview session.
 */
export interface StageHost {
  schema: Ref<FieldDef[]>
  /** The tree to hydrate from; each new value replaces the document (a save's lock bump, a load). */
  initial: Ref<Record<string, unknown> | null>
  mint(): Promise<StageSession>
  apply(
    token: string,
    fields: Record<string, unknown>,
    options: ApplyPreviewOptions,
  ): Promise<ApplyPreviewResult>
  /** One apply of the hydrated tree once the stage loads: an entry's stash can outlive a session. */
  reconcileOnOpen: boolean
  /**
   * The ONE owner of renewal (a 403/410 on apply, or the stage reporting an expired session).
   * Given the whole current document, it returns the new session to adopt.
   */
  renew(fields: Record<string, unknown>): Promise<StageRenewal>
  /**
   * Extra operations a starter page brings along, riding the same transaction as its blocks
   * (the Design page hides the theme's title above a page that opens with its own h1).
   */
  pageInsert?(blocks: BlockInstance[]): { ops: OperationBody[]; after?: () => void } | null
  /** The palette offers the Fields blocks (`layout_only`): only a layout's editor sets it. */
  allowLayoutOnly?: boolean
  /**
   * The Fields blocks this document may hold (a layout surface's palette); the palette offers these
   * and no other. Absent: every Fields block `allowLayoutOnly` lets through.
   */
  palette?: () => string[] | null
  /** The document's card rules (a layout surface's loops), which legality enforces. */
  cards?: () => CardRules | null
  /**
   * The library this page inserts from (a layout's editor: its surface's sections and templates for
   * its target, the shipped page sections, its saved sections). Absent: the shared page library.
   */
  patterns?: () => Pattern[]
  /**
   * The whole document with a library section inserted, judged before it lands (sections and
   * templates design §4): a layout refuses a second required block or a field its target lacks.
   * A refusal records nothing. Absent: the section's own legality is all.
   */
  candidateCheck?(doc: EditorDocument): Legality
  /**
   * Extra operations a template brings when it replaces the document (a layout template's Frame
   * settings), riding the same transaction as its blocks: one undo, one redo.
   */
  pageReplace?(pattern: Pattern): { ops: OperationBody[] } | null
  /**
   * Told of every ACCEPTED apply — a response the editor kept after its epoch/revision check, never
   * one it dropped as out of date — so the page can follow what the accepted document is.
   */
  onAccepted?(result: ApplyPreviewResult): void
}

/** What the page's FieldEditor exposes to the stage editor: the tree's single authority. */
export interface FieldEditorExposed {
  selectBlockById: (id: string) => boolean
  patchBlockSettingsById: (id: string, settings: Record<string, unknown>) => boolean
  blockById: (id: string) => BlockInstance | null
  moveBlockById: (id: string, delta: number) => { beforeId: string } | { afterId: string } | null
  duplicateBlockById: (id: string) => { newId: string; idMap: Record<string, string> } | null
  deleteBlockById: (id: string) => boolean
  insertAfterById: (id: string, typeSlug: string) => Promise<string | null>
  patchBlockDataById: (id: string, field: string, value: unknown) => boolean
  blockTypeOfBlock: (id: string) => string | null
  parentOfBlockById: (id: string) => BlockInstance | null
  blocksHostFor: (id: string) => BlocksHost | null
}

/**
 * Thrown by a host's `renew` when a newer renewal replaced it (a second page switch): the stage
 * belongs to the newer one, so the editor says nothing and keeps what it has.
 */
export class StageRenewalAbandoned extends Error {
  constructor() {
    super('A newer session replaced this one.')
    this.name = 'StageRenewalAbandoned'
  }
}
