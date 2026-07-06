<?php

/**
 * Documentation Generation Configuration
 *
 * Centralizes all settings for OpenAPI/Swagger documentation generation.
 * Used by ResourceRouteExpander, DocGenerator, CommentsDocGenerator, and OpenApiGenerator.
 */

$root = dirname(__DIR__);

return [
    /*
    |--------------------------------------------------------------------------
    | Documentation Enabled
    |--------------------------------------------------------------------------
    |
    | Controls whether API documentation is enabled. Enabled in all
    | environments (the admin's API Reference links to /docs/); set
    | API_DOCS_ENABLED=false to turn it off, e.g. in production.
    |
    */
    'enabled' => env('API_DOCS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | OpenAPI Specification Version
    |--------------------------------------------------------------------------
    |
    | The OpenAPI specification version to use for generated documentation.
    | Supported: "3.0.0", "3.0.3", "3.1.0"
    |
    | Key differences in 3.1.0:
    | - Uses JSON Schema draft 2020-12 (full alignment)
    | - Nullable types use array syntax: type: ["string", "null"]
    | - License supports SPDX identifier field
    | - $ref can have sibling keywords
    |
    */
    'openapi_version' => env('OPENAPI_VERSION', '3.1.0'),

    /*
    |--------------------------------------------------------------------------
    | API Information
    |--------------------------------------------------------------------------
    |
    | Metadata about your API that appears in the generated documentation.
    | These values populate the "info" section of the OpenAPI spec.
    |
    | TIP: After adding or modifying endpoints in your code, regenerate docs:
    |   php glueful generate:openapi -f -u
    |
    | If changes don't appear, try:
    |   1. Hard refresh the browser (Cmd+Shift+R / Ctrl+Shift+R)
    |   2. Clear browser cache
    |   3. Run with --clean flag: php glueful generate:openapi -f -u --clean
    |
    */
    'info' => [
        'title' => env('API_TITLE', 'Thallo CMS API'),
        'description' => env('API_DESCRIPTION', implode("\n", [
            'The Thallo headless CMS API.',
            '',
            '## Authentication',
            '',
            '- **Delivery API** (`/v1/content/*`) — send an API key in the `X-API-Key` header. '
                . 'The key must carry `read:content` or `read:content:{type}` unless the content '
                . 'type explicitly opts into public delivery. Keys are environment-prefixed '
                . '(`gf_live_*` / `gf_test_*`) and provisioned out of band.',
            '- **Admin API** (`/v1/admin/*`) — send a bearer JWT in the `Authorization` header '
                . '(`Authorization: Bearer <token>`). Each route also enforces a `thallo.*` '
                . 'permission (named in the operation description). Obtain a token from your '
                . 'Glueful auth endpoint (e.g. `POST /v1/auth/login`).',
            '- **Preview** (`GET /v1/preview/{token}`) — unauthenticated: the signed, '
                . 'short-lived token in the path is itself the capability.',
            '',
            'Only published content is ever returned by the delivery API; drafts are reachable '
                . 'only through a valid preview token.',
        ])),
        'version' => env('API_VERSION', '1') . '.0.0',
        'contact' => [
            'name' => env('API_CONTACT_NAME', ''),
            'email' => env('API_CONTACT_EMAIL', ''),
            'url' => env('API_CONTACT_URL', ''),
        ],
        'license' => [
            'name' => env('API_LICENSE_NAME', ''),
            'url' => env('API_LICENSE_URL', ''),
            // SPDX identifier for OpenAPI 3.1+ (e.g., 'MIT', 'Apache-2.0')
            'identifier' => env('API_LICENSE_IDENTIFIER', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Configuration
    |--------------------------------------------------------------------------
    |
    | Define the server URLs that appear in the generated documentation.
    | Multiple servers can be defined for different environments.
    |
    */
    'servers' => [
        [
            // Production first so SDK/codegen defaults to the live base URL. Falls back to BASE_URL
            // (this install's deployment URL, also used by config/app.php) when API_SERVER_URL is
            // not set — so the docs point at the actual host instead of a hardcoded one.
            'url' => env('API_SERVER_URL', env('BASE_URL', 'http://localhost')),
            'description' => 'Production',
        ],
        [
            'url' => 'http://localhost:8000',
            'description' => 'Local development',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Paths
    |--------------------------------------------------------------------------
    |
    | Paths where generated documentation files are stored.
    | All paths are relative to base_path() unless absolute.
    |
    */
    'paths' => [
        // Main documentation output directory
        'output' => $root . '/docs',

        // Final OpenAPI specification file location
        'openapi' => $root . '/docs/openapi.json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Paths
    |--------------------------------------------------------------------------
    |
    | Paths to scan for routes when generating documentation.
    | Extensions are discovered via Composer packages through ExtensionManager.
    |
    */
    'sources' => [
        // Directory containing project route files
        'routes' => $root . '/routes',

        // Include framework routes (auth/blobs/health/etc.) in the spec.
        // Default true: from a Thallo consumer's POV the platform's auth + blob endpoints are
        // part of Thallo's API surface. NOTE: there is no tag-level filter yet, so this also
        // brings the framework's infra tags (Health/Data/Documentation/Security) — group/hide
        // them in the docs UI, or set this false to drop all framework routes.
        'include_framework_routes' => (bool) env('API_DOCS_INCLUDE_FRAMEWORK_ROUTES', true),

        // Framework routes directory (auto-detected from vendor or local path)
        'framework_routes' => null, // Will be resolved at runtime

        /*
        |--------------------------------------------------------------------------
        | Route File Prefixes
        |--------------------------------------------------------------------------
        |
        | Map route files to their URL path prefixes. Routes in these files will
        | have the prefix prepended to their documented paths.
        |
        | Format: 'filename.php' => '/prefix' or '' for no prefix
        |
        */
        'route_prefixes' => [
            // Thallo route files register absolute /v1/... paths directly in the router.
            // The reflect generator reads those live route paths, so no prefix injection
            // is needed here.
            'content.php' => '',
            'admin.php' => '',
            'preview.php' => '',

            // Framework routes - no version prefix
            'health.php' => '',
            'docs.php' => '',

            // Framework auth routes - versioned
            'auth.php' => '/v1',
            'resource.php' => '/v1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Schemes
    |--------------------------------------------------------------------------
    |
    | Default security schemes to include in the generated documentation.
    | These define authentication methods for your API.
    |
    */
    'security_schemes' => [
        'BearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => 'JWT bearer token. Used by the admin authoring API (/v1/admin/*), '
                . 'combined with a per-route content_permission RBAC check.',
        ],
        'ApiKeyAuth' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-API-Key',
            'description' => 'Environment-prefixed API key (gf_live_* / gf_test_*) sent in the '
                . 'X-API-Key header. The public delivery API (/v1/content/*) requires a key '
                . 'carrying the read:content scope. (OpenAPI apiKey schemes cannot express '
                . 'scopes natively; the required scope is noted in each operation description.)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware → Security Scheme Map
    |--------------------------------------------------------------------------
    |
    | Maps route middleware names to the security schemes above. Each route
    | resolves its security from the middleware it carries: the admin API uses
    | `auth` (BearerAuth) and the delivery API uses `optional_api_key` (ApiKeyAuth),
    | so per-operation security is emitted natively for the key path. OpenAPI cannot
    | express Thallo's per-content-type anonymous opt-in; operation text documents it.
    |
    */
    'middleware_map' => [
        'auth' => ['BearerAuth'],
        'optional_api_key' => ['ApiKeyAuth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inferred Error Responses
    |--------------------------------------------------------------------------
    |
    | Body schema + descriptions the reflect generator attaches to auto-inferred
    | error responses (401/403 on secured routes, 429 on rate-limited routes, plus
    | any status listed in `always`). Pointed at Thallo's ErrorResponse DTO with
    | `envelope: false` so the inferred bodies match exactly what the controllers
    | document by hand — letting those repeated 401/403/429 attributes be dropped
    | without changing the spec. (`schema: null` would emit a slim inline
    | {success, message} shape instead.) Set `always: [500]` to document a server
    | error on every operation and drop the per-endpoint 500 attributes too.
    |
    */
    'errors' => [
        'schema'   => env('API_DOCS_ERROR_SCHEMA', \App\Http\DTOs\ErrorResponse::class),
        'envelope' => false,
        'always'   => [500],
        'descriptions' => [
            401 => 'Unauthenticated.',
            403 => 'Forbidden.',
            429 => 'Too Many Requests.',
            500 => 'Unexpected server error.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation Options
    |--------------------------------------------------------------------------
    |
    | Options that control how documentation is generated.
    |
    */
    'options' => [
        // Include route-based documentation from PHPDoc comments
        'include_routes' => true,

        // Include extension routes (users account/2FA, aegis RBAC, i18n, import/export).
        // Default true: from a Thallo consumer's POV these are part of Thallo's API surface.
        'include_extensions' => (bool) env('API_DOCS_INCLUDE_EXTENSIONS', true),

        // Pretty print JSON output
        'pretty_print' => true,

        // Generate resource/table routes (CRUD endpoints for all database tables)
        // Set to false to disable automatic generation of table-based API endpoints
        'include_resource_routes' => env('API_DOCS_INCLUDE_RESOURCE_ROUTES', false),

        // Tag allow/deny filter applied to the assembled spec before write. `include` is an
        // allow-list (empty = keep all tags); `exclude` is a deny-list and WINS over include.
        // Thallo's public spec drops the platform's infrastructure groups — generic table CRUD
        // (`Data`), ops probes (`Health`), the docs endpoints (`Documentation`), `Security` (CSRF),
        // `Admin` (the SPA-serving HTML routes mounted by serveFrontend at /admin — not an API),
        // `Theme Assets` (the render pack's static mount, path-derived like `Admin`),
        // and `Default` — untagged HTML routes, today exactly the render pack's catch-all
        // (`GET /`, `GET /{path}`) and `/_preview/{token}`; an UNTAGGED API route is itself a
        // bug (give it a tag). Committed as the default so regeneration
        // (locally or in CI) is reproducible; override with API_DOCS_EXCLUDE_TAGS /
        // API_DOCS_INCLUDE_TAGS (comma-separated; pass an empty string to keep everything).
        'tags' => [
            'include' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('API_DOCS_INCLUDE_TAGS', ''))
            ), static fn(string $v): bool => $v !== '')),
            'exclude' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env(
                    'API_DOCS_EXCLUDE_TAGS',
                    'Admin,Data,Default,Documentation,Health,Security,Theme Assets'
                ))
            ), static fn(string $v): bool => $v !== '')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables
    |--------------------------------------------------------------------------
    |
    | Tables to exclude from resource route expansion and documentation.
    | System/internal tables that shouldn't be exposed in the API.
    |
    */
    'excluded_tables' => [
        // System tables
        'migrations',
        'failed_jobs',
        'password_resets',
        'personal_access_tokens',
        'jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
        // Tables with explicit routes in api.php (avoid duplicate docs)
        'notifications',
        'notification_preferences',
        'notification_templates',
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation UI
    |--------------------------------------------------------------------------
    |
    | Settings for the interactive documentation UI.
    | Supported: "scalar", "swagger-ui", "redoc"
    |
    */
    'ui' => [
        // Default UI to generate (scalar, swagger-ui, redoc)
        'default' => env('API_DOCS_UI', 'scalar'),

        // Output filename for the generated HTML
        'filename' => 'index.html',

        // Page title
        'title' => env('API_DOCS_UI_TITLE', 'API Documentation'),

        // Scalar-specific settings
        'scalar' => [
            'theme' => env('API_DOCS_THEME', 'purple'),
            'dark_mode' => env('API_DOCS_DARK_MODE', true),
            'hide_download_button' => false,
            'hide_client_button' => true,
            'hide_models' => false,
            'default_open_all_tags' => false,
            'show_developer_tools' => 'never',
            'hide_powered_badge' => true,
        ],

        // Swagger UI-specific settings
        'swagger_ui' => [
            'deep_linking' => true,
            'display_request_duration' => true,
            'filter' => true,
        ],

        // Redoc-specific settings
        'redoc' => [
            'expand_responses' => '200,201',
            'hide_download_button' => false,
            'theme' => [],
        ],
    ],
];
