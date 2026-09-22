/**
 * What an entry form calls a field: its own label, else its name made readable
 * (`hero_image` → "Hero image"), so a form never shows a raw machine name.
 */
export function fieldLabel(field: { name: string; label?: string | null }): string {
  const label = field.label?.trim()
  if (label) return label
  const words = field.name.replace(/_/g, ' ').trim()
  return words.charAt(0).toUpperCase() + words.slice(1)
}
