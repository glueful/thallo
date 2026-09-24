import type { FieldDef } from './types'

/**
 * The select's sentinel for "not set". An optional enum stores null for it — the validator
 * accepts null on an optional field, and a template reads it as the field's default — so a
 * chosen value can always be undone. The sentinel never reaches the document.
 */
export const UNSET = '__unset__'

/**
 * An optional list that already names its own `default` (the Code block's size, the carousel's
 * style) uses that entry as its one "Default" instead of adding a second beside it. It means the
 * same thing — not set: shown for null, and stored as null.
 */
function ownDefault(field: FieldDef | undefined): boolean {
  return !!field && !field.required && (field.enum ?? []).includes('default')
}

export function enumItems(field: FieldDef): { label: string; value: string }[] {
  const own = ownDefault(field)
  const values = (field.enum ?? []).map((v) => ({
    label: field.enumLabels?.[v] ?? (own && v === 'default' ? 'Default' : v),
    value: v,
  }))
  if (field.required || own) return values
  return [{ label: 'Default', value: UNSET }, ...values]
}

export function toSelected(value: string | null | undefined, field?: FieldDef): string {
  if (value === null || value === undefined) return ownDefault(field) ? 'default' : UNSET
  return value
}

export function fromSelected(value: string, field?: FieldDef): string | null {
  if (value === UNSET) return null
  return ownDefault(field) && value === 'default' ? null : value
}
