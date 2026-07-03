// Central cache namespace. Every Colada query keys off these so invalidation is exhaustive and
// typo-proof. Keys are MaybeRefOrGetter-friendly (Pinia Colada): pass getters where a param is
// reactive (e.g. () => ['entries', typeSlug.value]).
export const qk = {
  home: () => ['home-overview'] as const,
  contentTypes: () => ['content-types'] as const,
  contentType: (slug: string) => ['content-type', slug] as const,
  blockTypes: () => ['block-types'] as const,
  entries: (type: string) => ['entries', type] as const,
  entry: (uuid: string) => ['entry', uuid] as const,
  entryLocales: (uuid: string) => ['entry-locales', uuid] as const,
  draft: (uuid: string, locale: string) => ['draft', uuid, locale] as const,
  routes: (uuid: string) => ['routes', uuid] as const,
  seoMeta: (uuid: string, locale: string) => ['seo-meta', uuid, locale] as const,
  schedules: (uuid: string) => ['schedules', uuid] as const,
  versions: (uuid: string) => ['versions', uuid] as const,
  redirects: (type: string) => ['redirects', type] as const,
  collections: () => ['collections'] as const,
  collection: (name: string) => ['collection', name] as const,
  collectionRows: (name: string) => ['collection-rows', name] as const,
  analyticsSummary: (from: string, to: string) => ['analytics', 'summary', from, to] as const,
  analyticsSeries: (metric: string, from: string, to: string) =>
    ['analytics', 'series', metric, from, to] as const,
  analyticsBreakdown: (event: string, from: string, to: string) =>
    ['analytics', 'breakdown', event, from, to] as const,
  workflowState: (uuid: string, locale: string) => ['workflow', 'state', uuid, locale] as const,
  workflowQueue: () => ['workflow', 'queue'] as const,
  navMenus: () => ['navigation', 'menus'] as const,
  navMenu: (slug: string, locale: string) => ['navigation', 'menu', slug, locale] as const,
}
