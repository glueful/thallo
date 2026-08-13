// Single-page product editor plan, Task C1: the six per-product section reads
// (categories/tags/attributes/media/children/stock), each keyed by product uuid + section.
export const COMMERCE_PRODUCT_SECTIONS = [
  'categories',
  'tags',
  'attributes',
  'media',
  'children',
  'stock',
] as const
export type CommerceProductSection = (typeof COMMERCE_PRODUCT_SECTIONS)[number]

// Central cache namespace. Every Colada query keys off these so invalidation is exhaustive and
// typo-proof. Keys are MaybeRefOrGetter-friendly (Pinia Colada): pass getters where a param is
// reactive (e.g. () => ['entries', typeSlug.value]).
export const qk = {
  home: () => ['home-overview'] as const,
  contentTypes: () => ['content-types'] as const,
  contentType: (slug: string) => ['content-type', slug] as const,
  blockTypes: () => ['block-types'] as const,
  blockTypeUsage: (slug: string) => ['block-type-usage', slug] as const,
  blockTypeMigrations: (slug: string) => ['block-type-migrations', slug] as const,
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
  formSubmissions: (formKey: string, status: string) =>
    ['form-submissions', 'list', formKey, status] as const,
  formSubmission: (uuid: string) => ['form-submissions', 'detail', uuid] as const,
  formSubmissionsUnread: () => ['form-submissions', 'unread'] as const,
  commerceMeta: () => ['commerce-meta'] as const,
  commerceStoreSettings: () => ['commerce-store-settings'] as const,
  commerceEmailSettings: () => ['commerce-email-settings'] as const,
  commerceMarketplaceSettings: () => ['commerce-marketplace-settings'] as const,
  commerceProducts: () => ['commerce-products'] as const,
  commerceProduct: (uuid: string) => ['commerce-product', uuid] as const,
  commerceProductAddons: (productUuid: string) => ['commerce-product-addons', productUuid] as const,
  commerceVariantDownloads: (variantUuid: string) => ['commerce-variant-downloads', variantUuid] as const,
  commerceProductSection: (uuid: string, section: CommerceProductSection) =>
    ['commerce-product-section', uuid, section] as const,
  commerceCategories: () => ['commerce-categories'] as const,
  commerceTags: () => ['commerce-tags'] as const,
  commerceAttributes: () => ['commerce-attributes'] as const,
  commerceLink: (productUuid: string) => ['commerce-link', productUuid] as const,
  commerceLinkByEntry: (entryUuid: string) => ['commerce-link-by-entry', entryUuid] as const,
  commerceEntrySearch: (q: string) => ['commerce-entry-search', q] as const,
  // Task 7 retired the old `useCommerceOrders()`/`fetchOrders()` list query (superseded by
  // `useOrderSearch()` in commerceOrderSearch.ts, whose key starts with this SAME prefix array) —
  // `commerceOrderSearch()` is the shared prefix both that query's key builder AND
  // `useCommerceOrderMutations()`'s list invalidation must use, so a lifecycle mutation's
  // `invalidateQueries({ key: qk.commerceOrderSearch() })` actually matches the live list query's
  // key via pinia-colada's element-wise `isSubsetOf` (a stale/different prefix silently never
  // matches anything — see the fix-round-2 note in commerceOrders.ts).
  commerceOrderSearch: () => ['commerce', 'orders', 'search'] as const,
  commerceOrder: (uuid: string) => ['commerce-order', uuid] as const,
  // Task 14 (admin-order-creation): the walk-in draft workspace — DELIBERATELY a different prefix
  // from `commerceOrder()` (drafts live on `/orders/drafts/{uuid}`, a distinct resource from
  // `/orders/{uuid}`, and `AdminOrderController::findByUuid()` is draft-blind by construction), so
  // a draft mutation's own invalidation never collides with (or accidentally invalidates) an
  // ordinary order's cache entry.
  commerceDraft: (uuid: string) => ['commerce-draft', uuid] as const,
  // Task 15 (admin-order-creation cycle 2): the drafts LIST view (`GET /orders/drafts`, 'view'-
  // graded server-side) — its own prefix, distinct from `commerceDraft()` above (a single draft)
  // and from `commerceOrderSearch()` (the finalized-order list, which stays draft-blind).
  commerceDraftsList: (page: number, perPage: number) => ['commerce-drafts-list', page, perPage] as const,
  commerceOrderPayments: (orderUuid: string) => ['commerce-order-payments', orderUuid] as const,
  // Payment links Task 13: the order's payment-link STATUS read (`GET /orders/{uuid}/payment-link`).
  // Its own prefix — a link's lifecycle is independent of the payment/attempt history above it,
  // and the one-time minted URL is never cached under this (or any) key.
  commerceOrderPaymentLink: (orderUuid: string) => ['commerce-order-payment-link', orderUuid] as const,
  commerceOrderRefunds: (orderUuid: string) => ['commerce-order-refunds', orderUuid] as const,
  commerceRefunds: () => ['commerce-refunds'] as const,
  commerceOrderNotes: (orderUuid: string) => ['commerce-order-notes', orderUuid] as const,
  commerceOrderInvoiceData: (orderUuid: string) => ['commerce-order-invoice-data', orderUuid] as const,
  commerceDiscounts: () => ['commerce-discounts'] as const,
  commerceDiscount: (uuid: string) => ['commerce-discount', uuid] as const,
  commerceShippingZones: () => ['commerce-shipping-zones'] as const,
  commerceShippingZone: (uuid: string) => ['commerce-shipping-zone', uuid] as const,
  commerceShippingZoneMethods: (zoneUuid: string) => ['commerce-shipping-zone-methods', zoneUuid] as const,
  commerceShippingClasses: () => ['commerce-shipping-classes'] as const,
  commerceShippingClass: (uuid: string) => ['commerce-shipping-class', uuid] as const,
  commerceTaxRates: () => ['commerce-tax-rates'] as const,
  commerceTaxRate: (uuid: string) => ['commerce-tax-rate', uuid] as const,
  commerceReviews: () => ['commerce-reviews'] as const,
  commerceReview: (uuid: string) => ['commerce-review', uuid] as const,
  commerceCustomers: () => ['commerce-customers'] as const,
  commerceCustomer: (key: string) => ['commerce-customer', key] as const,
  commerceReportSales: (from: string, to: string, group: string) =>
    ['commerce-report-sales', from, to, group] as const,
  commerceReportProducts: (from: string, to: string, sort: string, page: number, perPage: number) =>
    ['commerce-report-products', from, to, sort, page, perPage] as const,
  commerceReportCustomers: (from: string, to: string, group: string) =>
    ['commerce-report-customers', from, to, group] as const,
  commerceReportStock: (status: string, threshold: number | '', page: number, perPage: number) =>
    ['commerce-report-stock', status, threshold, page, perPage] as const,
}
