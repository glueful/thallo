// How a brand colour reads, worked out in the admin before anything is saved. The same sums as the
// server's ThemeColors (WCAG 2 relative luminance and contrast), which chooses the ink on the
// accent with them — so what the Appearance page says is what the site will do.

/** `#rgb`, `#rrggbb` or the same without the hash → `#rrggbb`, lower case; anything else → null. */
export function normalizeHex(value: string): string | null {
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value.trim())
  if (!m) return null
  const hex = m[1]!.toLowerCase()
  return (
    '#' +
    (hex.length === 3
      ? hex
          .split('')
          .map((c) => c + c)
          .join('')
      : hex)
  )
}

function luminance(hex: string): number {
  const channel = (i: number): number => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5)
}

/** The WCAG contrast ratio of two `#rrggbb` colours, 1 to 21. */
export function contrast(a: string, b: string): number {
  const la = luminance(a)
  const lb = luminance(b)
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}

export interface BrandReport {
  /** The label colour a button gets on the brand colour: whichever of white and black reads. */
  ink: '#ffffff' | '#000000'
  /** That label's contrast on the brand colour; always AA. */
  onAccent: number
  /** The brand colour's contrast on a white page, where it is links and small accents. */
  onPage: number
  /** `good` reads as body text (AA), `large` only as headings and large text, `poor` as neither. */
  asText: 'good' | 'large' | 'poor'
}

export function brandReport(hex: string): BrandReport {
  const white = contrast(hex, '#ffffff')
  const black = contrast(hex, '#000000')
  const ink = white >= black ? '#ffffff' : '#000000'
  return {
    ink,
    onAccent: Math.max(white, black),
    onPage: white,
    asText: white >= 4.5 ? 'good' : white >= 3 ? 'large' : 'poor',
  }
}
