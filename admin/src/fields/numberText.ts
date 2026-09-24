/**
 * A number typed into a plain text box. What cannot be part of a number is dropped as it is
 * typed (so "480px" stays 480), and an empty box is "not set" — null — not zero.
 */
export function cleanNumberText(text: string, opts: { integer?: boolean } = {}): string {
  if (opts.integer) return text.replace(/\D/g, '')
  let out = ''
  let dot = false
  for (const [i, ch] of [...text].entries()) {
    if (ch >= '0' && ch <= '9') out += ch
    else if (ch === '.' && !dot) {
      out += ch
      dot = true
    } else if (ch === '-' && i === 0) out += ch
  }
  return out
}

export function parseNumberText(text: string): number | null {
  if (text === '' || text === '-' || text === '.' || text === '-.') return null
  const n = Number(text)
  return Number.isFinite(n) ? n : null
}

export function toNumberText(value: unknown): string {
  return typeof value === 'number' && Number.isFinite(value) ? String(value) : ''
}
