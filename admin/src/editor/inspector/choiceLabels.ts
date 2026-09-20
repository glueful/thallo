// What a choice reads as, where the stored value is not the word for it. A property absent here
// shows its values as they are stored.
const percent = (values: string[]): Record<string, string> =>
  Object.fromEntries(values.map((v) => [v, `${v}%`]))

export const CHOICE_LABELS: Record<string, Record<string, string>> = {
  'colors.surface_opacity': percent(['100', '90', '80', '70', '60', '50']),
}
