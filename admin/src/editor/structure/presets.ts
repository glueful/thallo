// Structure presets as pure plans (container-layout spec §6.4, §6.5, §7.2).
//
// A preset is not a set of edits to make "if needed": it declares the paths it OWNS, and for every
// owned path it writes the complete responsive result at all three breakpoints. That is the whole
// point. Deleting a declaration only exposes whatever a style class puts underneath it, so a
// preset that cleared values could be quietly defeated by a class with an `lg` override — the
// author would choose two columns and get three. Writing an explicit value at each breakpoint puts
// the instance layer above the class at each breakpoint, and the arrangement holds.
//
// Where a preset requires the theme's own result rather than a particular value, it writes a reset
// — also at every breakpoint, for the same reason.
//
// Planning is pure: it reads the container and its classes and returns operations and the children
// to create. Nothing here touches the document, allocates an id, or calls the block factory; the
// picker does that (Task 3.3) and commits the whole plan as one transaction.
import { resolve } from '@/style/resolver'
import { propertyDefinition } from '@/style/schema'
import { readPath, settingSegments } from '@/editor/ops/apply'
import { BREAKPOINTS } from '@/style/types'
import type { Breakpoint, Resolution, StyleClassRef, StyleValue } from '@/style/types'
import type { BlockInstance } from '@/fields/components/blocks/useBlockListOps'
import type { ChangeValue, OperationBody, Position } from '@/editor/ops/types'

/** A child a preset asks for: a type to create and where it goes, with the settings it carries. */
export interface PlannedChild {
  type: string
  position: Position
  /** The settings the created instance is given, merged over whatever the factory returns. */
  settings: { style: Record<string, unknown> }
  /** Data the child carries beyond the factory's defaults. */
  data?: Record<string, unknown>
  /** Children of this child, planned the same way — a Section's header group has three. */
  children?: PlannedChild[]
}

export interface PresetPlan {
  key: string
  /** The container's own writes, in plan order; empty when the preset changes nothing. */
  operations: (OperationBody & { type: 'SetSetting' | 'SetField' })[]
  children: PlannedChild[]
}

type Owned =
  | { kind: 'value'; value: StyleValue }
  | { kind: 'reset' }
  /** Only where the preset deliberately restores inheritance (spec §6.4). */
  | { kind: 'delete' }

/** What a preset writes for one path: one entry per breakpoint, all three always present. */
type OwnedPath = Record<Breakpoint, Owned>

interface PresetDefinition {
  label: string
  /** Owned style paths on the container. */
  style: Record<string, OwnedPath>
  /** Owned data fields on the container. */
  data?: Record<string, unknown>
  children: PlannedChild[]
  /**
   * Paths whose write is skipped where the RESOLVED value already equals what the preset wants.
   * Stack is the only preset that does this, so dismissing an already-stacked container records
   * nothing at all (spec §6.4).
   */
  onlyWhenDifferent?: boolean
}

const choice = (value: string): StyleValue => ({ type: 'choice', value })
const token = (value: string): StyleValue => ({ type: 'token', value })

/** The same value at every breakpoint. */
const everywhere = (value: StyleValue): OwnedPath => ({
  base: { kind: 'value', value },
  md: { kind: 'value', value },
  lg: { kind: 'value', value },
})
/** A reset at every breakpoint: the preset requires the theme's own result. */
const resetEverywhere = (): OwnedPath => ({
  base: { kind: 'reset' },
  md: { kind: 'reset' },
  lg: { kind: 'reset' },
})
/** One value per breakpoint, written out so a preset's responsive shape is readable here. */
const perBreakpoint = (base: Owned, md: Owned, lg: Owned): OwnedPath => ({ base, md, lg })

/**
 * A column container (spec §6.5): a flex column with the row gap that reproduces today's Columns
 * rhythm — space between the blocks in a column, none at its edges (§3.8).
 */
function columnChild(parent: string, index: number): PlannedChild {
  return {
    type: 'container',
    position: { parent, slot: 'content', index },
    settings: {
      style: {
        layout: {
          display: { base: choice('flex'), md: choice('flex'), lg: choice('flex') },
          direction: { base: choice('column'), md: choice('column'), lg: choice('column') },
          gap: { row: { base: token('spacing.md') } },
        },
      },
    },
  }
}

/** A multi-column preset: one track on mobile, the named split above it (spec §6.5). */
function columns(split: string, count: number): Omit<PresetDefinition, 'label'> {
  return {
    style: {
      'layout.display': everywhere(choice('grid')),
      'layout.columns': perBreakpoint(
        { kind: 'value', value: choice('1') },
        { kind: 'value', value: choice(split) },
        { kind: 'value', value: choice(split) },
      ),
      'layout.gap.column': everywhere(token('spacing.lg')),
      'layout.gap.row': everywhere(token('spacing.lg')),
    },
    children: Array.from({ length: count }, (_, index) => columnChild('', index)),
  }
}

/** The Section composition's children (spec §7.3): a header group, a content area and links. */
function sectionChildren(align: 'center' | 'start'): PlannedChild[] {
  const text = (value: string) => ({ base: choice(value), md: choice(value), lg: choice(value) })
  return [
    {
      type: 'container',
      position: { parent: '', slot: 'content', index: 0 },
      settings: {
        style: {
          layout: {
            display: { base: choice('flex'), md: choice('flex'), lg: choice('flex') },
            direction: { base: choice('column'), md: choice('column'), lg: choice('column') },
          },
        },
      },
      children: [
        {
          type: 'rich_text',
          position: { parent: '', slot: 'content', index: 0 },
          settings: {
            style: {
              colors: { text: { base: token('color.accent') } },
              typography: { weight: { base: choice('semibold') } },
              spacing: { margin: { bottom: { base: token('spacing.sm') } } },
              alignment: { text: text(align) },
            },
          },
        },
        {
          type: 'heading',
          position: { parent: '', slot: 'content', index: 1 },
          data: { level: 'h2' },
          settings: { style: { alignment: { text: text(align) } } },
        },
        {
          type: 'rich_text',
          position: { parent: '', slot: 'content', index: 2 },
          settings: {
            style: {
              colors: { text: { base: token('color.muted') } },
              typography: { size: { base: token('typography.size.lg') } },
              width: { base: token('width.content') },
              alignment: { text: text(align), self: text(align) },
              spacing: { margin: { top: { base: token('spacing.lg') } } },
            },
          },
        },
      ],
    },
    {
      type: 'container',
      position: { parent: '', slot: 'content', index: 1 },
      settings: { style: {} },
    },
    {
      type: 'container',
      position: { parent: '', slot: 'content', index: 2 },
      settings: {
        style: {
          layout: {
            display: { base: choice('flex'), md: choice('flex'), lg: choice('flex') },
            direction: { base: choice('row'), md: choice('row'), lg: choice('row') },
            wrap: { base: choice('wrap'), md: choice('wrap'), lg: choice('wrap') },
            gap: { column: { base: token('spacing.xl') }, row: { base: token('spacing.md') } },
          },
          alignment: { content: text(align) },
        },
      },
      children: [
        {
          type: 'button',
          position: { parent: '', slot: 'content', index: 0 },
          settings: { style: {} },
        },
      ],
    },
  ]
}

/** The style paths every Section variant owns (spec §7.2), whether or not a variant uses them. */
function sectionStyle(overrides: Record<string, OwnedPath>): Record<string, OwnedPath> {
  return {
    'spacing.padding.top': everywhere(token('spacing.3xl')),
    'spacing.padding.bottom': everywhere(token('spacing.3xl')),
    'layout.content_width': everywhere(token('width.container')),
    'layout.display': everywhere(choice('flex')),
    'layout.direction': everywhere(choice('column')),
    'layout.gap.row': everywhere(token('spacing.xl')),
    'layout.columns': resetEverywhere(),
    'layout.align_items': resetEverywhere(),
    'layout.gap.column': resetEverywhere(),
    ...overrides,
  }
}

export const PRESETS: Record<string, PresetDefinition> = {
  stack: {
    label: 'Stack',
    // A stack is a flex column (spec §6.5, §11.1) — which is also what the theme gives an
    // untouched container, so over one this preset plans nothing.
    style: {
      'layout.display': everywhere(choice('flex')),
      'layout.direction': everywhere(choice('column')),
    },
    children: [],
    onlyWhenDifferent: true,
  },
  row: {
    label: 'Row',
    style: {
      'layout.display': everywhere(choice('flex')),
      'layout.direction': everywhere(choice('row')),
    },
    children: [],
  },
  'cols-50-50': { label: 'Two columns', ...columns('2', 2) },
  'cols-33-67': { label: '33 / 67', ...columns('1-2', 2) },
  'cols-67-33': { label: '67 / 33', ...columns('2-1', 2) },
  'cols-25-75': { label: '25 / 75', ...columns('1-3', 2) },
  'cols-75-25': { label: '75 / 25', ...columns('3-1', 2) },
  'cols-thirds': { label: 'Three columns', ...columns('3', 3) },
  'cols-25-50-25': { label: '25 / 50 / 25', ...columns('1-2-1', 3) },
  'cols-50-25-25': { label: '50 / 25 / 25', ...columns('2-1-1', 3) },
  'cols-25-25-50': { label: '25 / 25 / 50', ...columns('1-1-2', 3) },
  'cols-quarters': { label: 'Four columns', ...columns('4', 4) },
  'grid-2x2': { label: 'Grid 2 × 2', ...columns('2', 4) },
  section: {
    label: 'Section',
    style: sectionStyle({}),
    data: { element: 'section' },
    children: sectionChildren('center'),
  },
  'section-split': {
    label: 'Section split',
    style: sectionStyle({
      // A flex column until lg, where it becomes two tracks (spec §7.5).
      'layout.display': perBreakpoint(
        { kind: 'value', value: choice('flex') },
        { kind: 'value', value: choice('flex') },
        { kind: 'value', value: choice('grid') },
      ),
      'layout.columns': perBreakpoint(
        { kind: 'reset' },
        { kind: 'reset' },
        { kind: 'value', value: choice('2') },
      ),
      'layout.align_items': perBreakpoint(
        { kind: 'reset' },
        { kind: 'reset' },
        { kind: 'value', value: choice('center') },
      ),
      'layout.gap.column': perBreakpoint(
        { kind: 'reset' },
        { kind: 'reset' },
        { kind: 'value', value: token('spacing.2xl') },
      ),
    }),
    data: { element: 'section' },
    children: sectionChildren('start'),
  },
}

function styleOf(block: BlockInstance): Record<string, unknown> {
  const style = (block.settings as { style?: unknown } | undefined)?.style
  return typeof style === 'object' && style !== null ? (style as Record<string, unknown>) : {}
}

/** The instance's own declaration at one breakpoint — what undo has to put back. */
function instanceValue(
  block: BlockInstance,
  path: string,
  breakpoint: Breakpoint,
): ChangeValue<StyleValue> {
  const read = readPath(styleOf(block), settingSegments(path, breakpoint).slice(1))
  return read.present
    ? { present: true, value: (read.value ?? null) as StyleValue | null }
    : { present: false }
}

/** What the block actually resolves to there, class layers included. */
function resolvedValue(
  block: BlockInstance,
  classes: StyleClassRef[],
  path: string,
  breakpoint: Breakpoint,
): StyleValue | null {
  const def = propertyDefinition(path)
  if (def === null) return null
  const out = resolve(path, classes, styleOf(block), def) as Record<string, Resolution>
  return out[def.responsive ? breakpoint : 'base']?.value ?? null
}

function sameValue(a: StyleValue | null, b: StyleValue | null): boolean {
  return JSON.stringify(a ?? null) === JSON.stringify(b ?? null)
}

/**
 * What the theme itself produces where nothing is declared (spec §3.8, §11.1): a flex column with
 * both gaps at `spacing.xl`. A skip-when-equal preset judges "already this" against it: Stack asks
 * whether the container already stacks, and a container with nothing declared does — writing flex
 * and column over it would record a transaction that changes nothing.
 */
export const THEME_DEFAULT: Record<string, StyleValue> = {
  'layout.display': choice('flex'),
  'layout.direction': choice('column'),
  'layout.gap.row': token('spacing.xl'),
  'layout.gap.column': token('spacing.xl'),
}

/** The value in force including the theme's own, so "already this" is judged on the real result. */
function effectiveValue(
  block: BlockInstance,
  classes: StyleClassRef[],
  path: string,
  breakpoint: Breakpoint,
): StyleValue | null {
  const resolved = resolvedValue(block, classes, path, breakpoint)
  if (resolved !== null && resolved.type !== 'reset') return resolved
  // A reset lands on the theme default too.
  return THEME_DEFAULT[path] ?? null
}

/** Fill a planned child's parent id, and its own children's, once the ids are known. */
function withParent(child: PlannedChild, parent: string): PlannedChild {
  return { ...child, position: { ...child.position, parent } }
}

/**
 * The plan for `key` over `container`, or null for a key no preset defines.
 *
 * Every owned path produces one operation per breakpoint, each carrying the instance value it
 * replaces so undo restores the document exactly — including the difference between a value that
 * was null and one that was never there.
 */
export function planPreset(
  key: string,
  container: BlockInstance,
  classes: StyleClassRef[],
): PresetPlan | null {
  const preset = PRESETS[key]
  if (!preset) return null

  const operations: PresetPlan['operations'] = []
  for (const [path, perBp] of Object.entries(preset.style)) {
    for (const breakpoint of BREAKPOINTS) {
      const owned = perBp[breakpoint]
      if (owned.kind === 'delete') {
        // Deliberate restoration of inheritance: the declaration goes, whatever is under it shows.
        const from = instanceValue(container, path, breakpoint)
        if (!from.present) continue
        operations.push({
          type: 'SetSetting',
          block: container.id,
          path,
          breakpoint,
          from,
          to: { present: false },
        })
        continue
      }
      const to: ChangeValue<StyleValue> =
        owned.kind === 'reset'
          ? { present: true, value: { type: 'reset' } }
          : { present: true, value: owned.value }
      if (preset.onlyWhenDifferent && owned.kind === 'value') {
        // Judged on the RESOLVED result, not the instance: a class supplying the same value means
        // there is nothing to change, and one supplying a different value means there is.
        if (sameValue(effectiveValue(container, classes, path, breakpoint), owned.value)) continue
      }
      operations.push({
        type: 'SetSetting',
        block: container.id,
        path,
        breakpoint,
        from: instanceValue(container, path, breakpoint),
        to,
      })
    }
  }

  for (const [field, value] of Object.entries(preset.data ?? {})) {
    const current = container.data?.[field]
    operations.push({
      type: 'SetField',
      block: container.id,
      field,
      from: current === undefined ? { present: false } : { present: true, value: current },
      to: { present: true, value },
    })
  }

  return {
    key,
    operations,
    children: preset.children.map((child) => withParent(child, container.id)),
  }
}

/** The height of the subtree a preset creates, counting the container itself as one. */
export function presetDepth(key: string): number {
  const preset = PRESETS[key]
  if (!preset) return 0
  const height = (children: PlannedChild[]): number =>
    children.length === 0 ? 0 : 1 + Math.max(...children.map((c) => height(c.children ?? [])))
  return 1 + height(preset.children)
}
