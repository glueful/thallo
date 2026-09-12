<?php

/**
 * App-level overrides for the glueful/audit extension.
 *
 * Keys here are deep-merged OVER the extension's own config/audit.php defaults,
 * so this file only needs to carry the values Thallo changes — everything else
 * (capture toggles, ignore_tables, retention, …) keeps the extension defaults.
 */

declare(strict_types=1);

return [
    // Table => audit category overrides (defaults derive from the table name → 'data').
    //  - blobs: surfaced through the Media Library, so audit uploads as `media`.
    //  - api_keys: minting/rotating/revoking a programmatic credential is a security action
    //    (events come from the framework's ApiKeyService lifecycle); file it under `security`.
    // Both categories are in the audit-log filter dropdown — see admin/src/queries/audit.ts.
    'category_map' => [
        'blobs' => 'media',
        'api_keys' => 'security',
    ],
];
