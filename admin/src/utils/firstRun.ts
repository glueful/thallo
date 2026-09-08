// Dashboard first-run target. A fresh install seeds Pages, Posts and Categories; the card
// must ask for a page (one click to something renderable), never for a taxonomy term.

export interface FirstRunType {
  slug: string
  name: string
}

const PREFERRED = ['pages', 'page', 'posts', 'post']
const TAXONOMY = /^(categor(y|ies)|tags?|topics?|taxonom(y|ies))$/i

export function pickFirstRunType<T extends FirstRunType>(types: T[]): T | undefined {
  for (const slug of PREFERRED) {
    const hit = types.find((t) => t.slug === slug)
    if (hit) return hit
  }
  return types.find((t) => !TAXONOMY.test(t.slug)) ?? types[0]
}

export function singularize(name: string): string {
  if (/(ss|us|is|ews|series)$/i.test(name)) return name
  if (/ies$/i.test(name)) return name.replace(/ies$/i, 'y')
  if (/s$/i.test(name)) return name.slice(0, -1)
  return name
}
