import type { EditorDocument } from '@/editor/ops/types'
import type { Legality } from './legality'

// A section checked against the WHOLE layout before it lands (sections and templates design §4):
// the working copy with the section inserted, judged by the server's own rules — the session hands
// them over (bindable fields, what each field block shows and binds by default, the formats that
// need a field of their kind) — so a section this accepts is one apply accepts. The cases are shared
// with the server (tests/fixtures/layouts/candidate-cases.json).
//
// One allowance: the candidate may LACK a required block. The working copy can be mid-edit; apply
// and save run the full validation and refuse an incomplete layout, as they always have.

export interface LayoutRules {
  /** The document field the layout's blocks live in. */
  field: string
  /** Blocks a layout holds exactly once: here, at most once (and `entry_content` per field). */
  required: { type: string; field?: string }[]
  /** The target's fields a block can bind: name => type (`text:rich` for a rich text field). */
  bindable: Record<string, string>
  /** Each bindable field's label. */
  fieldLabels: Record<string, string>
  /** Each binding block => the field types it can show. */
  bindings: Record<string, string[]>
  /** Each binding block => the field it binds when none is chosen, where the type has it. */
  defaultFields: Record<string, string>
  /** An Entry field format => the field types it needs. */
  formatNeeds: Record<string, string[]>
  /** The target type's name; null off a content type. */
  typeName: string | null
  /** A block type's label. */
  label: (type: string) => string
}

interface Block {
  type: string
  data?: Record<string, unknown>
}

const isBlock = (value: unknown): value is Block =>
  typeof value === 'object' && value !== null && typeof (value as Block).type === 'string'

/** Every block of a list, and every block of every block list inside it, in document order. */
function* walk(list: unknown): Generator<Block> {
  if (!Array.isArray(list)) return
  for (const block of list) {
    if (!isBlock(block)) continue
    yield block
    for (const value of Object.values(block.data ?? {})) {
      if (Array.isArray(value) && value.length > 0 && value.every(isBlock)) yield* walk(value)
    }
  }
}

const human = (name: string) => name.charAt(0).toUpperCase() + name.slice(1).replace(/_/g, ' ')

const refuse = (message: string): Legality => ({ ok: false, reason: 'type-not-allowed', message })

export function checkLayoutCandidate(doc: EditorDocument, rules: LayoutRules): Legality {
  const blocks = [...walk(doc.fields[rules.field])]

  // 1. A required block without a field appears at most once.
  for (const req of rules.required) {
    if (req.field !== undefined) continue
    if (blocks.filter((b) => b.type === req.type).length > 1) {
      return refuse(`The ${rules.label(req.type)} can appear only once`)
    }
  }

  const shown = new Set<string>()
  for (const block of blocks) {
    // 2. Only a binding block binds.
    const accepted = rules.bindings[block.type]
    if (accepted === undefined) continue
    const data = block.data ?? {}
    // 3. Its own field, else its default where the type has one it can show, else — Entry content
    // only — `body`, as the server counts it.
    let field = typeof data.field === 'string' && data.field !== '' ? data.field : null
    if (field === null) {
      const fallback = rules.defaultFields[block.type]
      const fallbackType = fallback === undefined ? undefined : rules.bindable[fallback]
      if (fallback !== undefined && fallbackType !== undefined && accepted.includes(fallbackType)) {
        field = fallback
      } else if (block.type === 'entry_content') {
        field = 'body'
      }
    }
    if (field === null) continue
    const label = rules.fieldLabels[field] ?? human(field)
    const fieldType = rules.bindable[field]
    // 4. The field exists.
    if (fieldType === undefined) {
      return refuse(
        `This section shows “${label}”, which ${rules.typeName ?? 'this page'} doesn’t have`,
      )
    }
    // 5. The block can show it.
    if (!accepted.includes(fieldType)) {
      return refuse(`The ${rules.label(block.type)} block can’t show “${label}”`)
    }
    // 6. An Entry field's format suits it.
    const format = typeof data.format === 'string' ? data.format : null
    const needs =
      block.type === 'entry_field' && format !== null ? rules.formatNeeds[format] : undefined
    if (needs !== undefined && !needs.includes(fieldType)) {
      return refuse(`“${label}” can’t be shown as a ${format}`)
    }
    // 7. No blocks field is shown twice.
    if (block.type === 'entry_content') {
      if (shown.has(field)) return refuse(`“${label}” is already shown by another block`)
      shown.add(field)
    }
  }
  return { ok: true }
}
