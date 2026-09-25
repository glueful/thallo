import type { Ref } from 'vue'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { BlocksHost } from '@/fields/components/blocks/context'
import type { FieldDef } from '@/fields/types'
import type { OperationBody } from '@/editor/ops/types'

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
