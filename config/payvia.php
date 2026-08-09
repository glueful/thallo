<?php

declare(strict_types=1);

/*
 * glueful/payvia — payment gateway configuration, PUBLISHED INTO THE APP.
 *
 * WHY THIS FILE EXISTS (platform-payments-settings spec, Task 4 review finding).
 *
 * Payvia ships these same values as extension DEFAULTS via
 * `PayviaServiceProvider::register()` → `ServiceProvider::mergeConfig('payvia', …)`. But
 * `ExtensionManager::discover()` returns EARLY on an extensions-cache hit, so
 * `registerProviders()` — the only caller of any provider's `register()` — never runs on the boot
 * mode PRODUCTION IS REQUIRED TO USE (a cache miss there is fatal:
 * "Extension cache missing in production. Run: php glueful extensions:cache"). On such a boot
 * `mergeConfig()` never executes and `config('payvia.*')` is EMPTY — verified empirically, not
 * assumed:
 *
 *     live boot   → config('payvia.gateways') = ['paystack', 'stripe']
 *     cached boot → config('payvia.gateways') = []   ← before this file existed
 *
 * That is not a payments-settings problem, it is a payvia problem: with an empty gateway map,
 * `PayviaSettings::gateways()` returns nothing, `GatewayManager` can resolve no driver at all, and
 * `App\Settings\PlatformPayviaSettingsOverride`'s config-gated whitelist correctly refuses every
 * `payvia.gateways.{id}.*` key — so platform credentials would resolve nowhere in production while
 * every test (live-discovery boots) stayed green.
 *
 * The framework's own fix for this is the ordinary one: app config FILES are read by
 * `ConfigurationLoader` in BOTH boot modes. Precedence is
 * `extension defaults < app/env config file < process override`, deep-merged
 * (`ApplicationContext::mergeConfigDefaults()` + `deepMerge()`), so on a live-discovery boot this
 * file merges OVER byte-identical extension defaults and changes nothing — dev and production
 * resolve the same values. Same pattern as `config/users.php` / `config/audit.php` here.
 *
 * KEEPING IT HONEST: mirror payvia's own `config/payvia.php` when upgrading the extension. Every
 * value below is the extension's default expression verbatim.
 *
 * NEVER PUT A LITERAL SECRET IN THIS FILE. The credential entries below are `env()` references
 * only — they preserve payvia's env fallback so it behaves identically in both boot modes. Runtime
 * gateway credentials are edited through the platform Settings → Payments surface and stored
 * ENCRYPTED in the unscoped system channel (`App\Settings\PlatformPaymentSettingsStore`); the
 * override consults that store FIRST and only falls through to these config/env values when no
 * platform value is set.
 */

return [
    'default_gateway' => env('PAYVIA_DEFAULT_GATEWAY', 'paystack'),

    'gateways' => [
        'paystack' => [
            'enabled' => (bool) env('PAYVIA_PAYSTACK_ENABLED', true),
            'driver' => 'paystack',
            'secret_key' => env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null)),
            'webhook_secret' => env(
                'PAYVIA_PAYSTACK_WEBHOOK_SECRET',
                env('PAYVIA_PAYSTACK_SECRET_KEY', env('PAYSTACK_SECRET_KEY', null))
            ),
            // The maintainer's own declaration of what is configured, right now, on the
            // Paystack dashboard as this app's webhook URL. Paystack exposes no read API for
            // it, so `payvia:checkout:sandbox-proof` treats this as ground truth and fails
            // closed unless its path is exactly /payvia/webhooks/paystack.
            'webhook_url' => env('PAYVIA_PAYSTACK_WEBHOOK_URL', null),
            'base_url' => env('PAYVIA_PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'timeout' => (int) env('PAYVIA_PAYSTACK_TIMEOUT', 15),
        ],

        'stripe' => [
            'enabled' => (bool) env('PAYVIA_STRIPE_ENABLED', false),
            'driver' => 'stripe',
            'secret_key' => env('PAYVIA_STRIPE_SECRET_KEY', null),
            'webhook_secret' => env('PAYVIA_STRIPE_WEBHOOK_SECRET', null),
            'webhook_tolerance' => (int) env('PAYVIA_STRIPE_WEBHOOK_TOLERANCE', 300),
            'base_url' => env('PAYVIA_STRIPE_BASE_URL', 'https://api.stripe.com'),
            'timeout' => (int) env('PAYVIA_STRIPE_TIMEOUT', 15),
        ],
    ],

    'features' => [
        'store_raw_payload' => (bool) env('PAYVIA_STORE_RAW_PAYLOAD', true),
    ],

    'security' => [
        // Three ordered middleware profiles composed onto every /payvia/* route (except the
        // webhook route, which uses none of them and stays signature-authenticated/tenantless).
        // Payvia never names host-specific middleware aliases in these defaults — a tenancy-enabled
        // host configures profile 2 itself. Carried here because an empty `payvia.security.*` on a
        // cached boot would compose payvia's authenticated routes with NO middleware at all.
        'auth_middleware' => ['auth'],
        'tenant_context_middleware' => [],
        'manage_middleware' => ['admin'],
    ],

    'webhooks' => [
        'queue' => (bool) env('PAYVIA_WEBHOOKS_QUEUE', false),
        'queue_name' => env('PAYVIA_WEBHOOKS_QUEUE_NAME', 'default'),
        'relay_stale_seconds' => (int) env('PAYVIA_WEBHOOKS_RELAY_STALE_SECONDS', 300),
    ],
];
