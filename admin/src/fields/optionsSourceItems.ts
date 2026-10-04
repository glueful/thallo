import type { FieldOption } from '@/queries/fieldOptions'

/** The select cannot hold an empty value; "All results" (stored as '') rides as this sentinel. */
export const ALL = '__all__'

/** Server choices as select items: unavailable ones disabled, with their reason in the label. */
export function optionItems(
  options: FieldOption[],
): { label: string; value: string; disabled: boolean }[] {
  return options.map((o) => ({
    label: o.available ? o.label : `${o.label} (${(o.reason ?? '').toLowerCase()})`,
    value: o.value === '' ? ALL : o.value,
    disabled: !o.available,
  }))
}

export const toSelectValue = (stored: string): string => (stored === '' ? ALL : stored)
export const fromSelectValue = (value: unknown): string => {
  const v = String(value ?? '')
  return v === ALL ? '' : v
}
