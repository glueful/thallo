<?php

/**
 * Application Service Providers
 *
 * The application's own service providers, loaded in declared order. These are
 * app-local classes (not composer-discovered extensions) and are always loaded.
 * Use string FQCNs (no ::class) so tooling can edit the list safely.
 *
 * Thallo's internal modules (packages/thallo-*) live here — they are library-typed
 * composer path packages, NOT installable extensions (modules-not-extensions spec,
 * 2026-07-25): always loaded, feature exposure controlled by the thallo.capabilities
 * switchboard. Their cross-phase order is declared on the providers themselves
 * (DeclaresLoadOrder: post-extension tier, list order as the stable tie-break) —
 * this list's order is documentation, not the dependency mechanism.
 */

return [
    'enabled' => [
        // Pre-extension tier (loadPriority -100), NOT a module: thallo-subscriptions' pre-engine
        // seam must boot BEFORE glueful/subscriptions so it can pre-empt that engine's own ungated
        // /subscriptions/plans* mounts and re-pin SubjectResolverInterface to Thallo's resolver.
        // Listed first as documentation; the ordering itself is declared on the class (see its
        // docblock for why neither job can live on the pack's main provider, which must loadAfter()
        // the engine, nor in any register(), which never runs on cached production boots).
        'Thallo\\Subscriptions\\EnginePreemptionServiceProvider',
        'Glueful\\Extensions\\Tenancy\\TenancyControlPlaneProvider',
        'App\\Providers\\ThalloServiceProvider',
        // Thallo modules — pre-conversion relative order preserved (Search, previously
        // disabled-by-absence, slots alphabetically; its capability default is OFF).
        'Thallo\\Account\\AccountServiceProvider',
        'Thallo\\Analytics\\AnalyticsServiceProvider',
        'Thallo\\Collections\\CollectionsServiceProvider',
        'Thallo\\Commerce\\CommerceIntegrationServiceProvider',
        'Thallo\\Importers\\ImportersServiceProvider',
        'Thallo\\Navigation\\NavigationServiceProvider',
        'Thallo\\Render\\RenderServiceProvider',
        'Thallo\\Search\\SearchServiceProvider',
        'Thallo\\Seo\\SeoServiceProvider',
        'Thallo\\Subscriptions\\SubscriptionsIntegrationServiceProvider',
        'Thallo\\Tenancy\\TenancyServiceProvider',
        'Thallo\\Workflow\\WorkflowServiceProvider',
    ],
];
