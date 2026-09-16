import type { FieldDef } from './types'

/**
 * The select's sentinel for "not set". An optional enum stores null for it — the validator
 * accepts null on an optional field, and a template reads it as the field's default — so a
 * chosen value can always be undone. The sentinel never reaches the document.
 */
export const UNSET = '__unset__'

export function enumItems(field: FieldDef): { label: string; value: string }[] {
  const values = (field.enum ?? []).map((v) => ({ label: field.enumLabels?.[v] ?? v, value: v }))
  return field.required ? values : [{ label: 'Default', value: UNSET }, ...values]
}

export function toSelected(value: string | null | undefined): string {
  return value === null || value === undefined ? UNSET : value
}

export function fromSelected(value: string): string | null {
  return value === UNSET ? null : value
}
