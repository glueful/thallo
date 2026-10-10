/**
 * What a person types into a link box, as the link they meant: `www.example.com` alone would be a
 * page on this site, so a click on it lands on a 404. An email gains `mailto:`, a phone number
 * `tel:`, a dotted host `https://`. A scheme, or a leading `/` `#` `?`, is left as typed; so is
 * anything else (a relative `about`). The stage bridge's normalizeLinkUrl follows the same rules.
 */
export function normalizeLinkUrl(url: string): string {
  const s = url.trim()
  if (s === '' || /^[/#?]/.test(s) || /^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(s)) return s
  if (/^[^\s@/]+@[^\s@/]+\.[^\s@/]+$/.test(s)) return `mailto:${s}`
  if (/^\+?[0-9][0-9\s().-]{5,}$/.test(s)) return `tel:${s.replace(/[\s().-]/g, '')}`
  const host = s.split(/[/?#]/)[0] ?? ''
  if (/^[^\s.]+(\.[^\s.]+)+$/.test(host)) return `https://${s}`
  return s
}
