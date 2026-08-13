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

    // How long a PROVIDER-CONFIRMED "this hosted session is still live" answer is trusted before
    // ensure-live asks the provider again. Repeat initiations inside the window reuse the stored
    // checkout URL with no provider I/O, so a shopper clicking "pay" repeatedly (or an abusive
    // client) cannot turn one checkout into a stream of provider round trips — which would invite
    // provider rate limiting, and a rate-limited answer is an UNKNOWN state that fails closed for
    // every shopper at once. Only a confirmed-live probe refreshes the stamp; dead/unknown answers
    // never do, and a brand-new attempt is never suppressed. Set to 0 to always probe.
    'session_liveness_cooldown_seconds' => (int) env('PAYVIA_SESSION_LIVENESS_COOLDOWN_SECONDS', 30),

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
            // OPERATOR REQUIREMENT: the Paystack integration setting `payment_session_timeout`
            // (GET/PUT /integration/payment_session_timeout) MUST stay at its default of 0 (never
            // expire). A non-zero value that elapses dead-ends the hosted checkout page while
            // /transaction/verify still reports the transaction as `abandoned` — indistinguishable
            // from a live one, so payvia would keep serving a URL nobody can pay, and a resumed
            // Thallo payment link would silently strand the payer. See the PaystackGateway class
            // docblock; payvia deliberately does not guess at elapsed time.
            // Hosted-redirect trust boundary: the ONLY hosts a returned `authorization_url` may
            // live on. Matching is case-normalized but otherwise exact — no subdomains, no
            // ports, no userinfo, HTTPS only (see Support\HostedCheckoutUrl). Narrow this (or
            // point it at a sandbox host) only if you know why; an empty array trusts nothing
            // and refuses every checkout URL.
            'checkout_hosts' => ['checkout.paystack.com'],
        ],

        'stripe' => [
            'enabled' => (bool) env('PAYVIA_STRIPE_ENABLED', false),
            'driver' => 'stripe',
            'secret_key' => env('PAYVIA_STRIPE_SECRET_KEY', null),
            'webhook_secret' => env('PAYVIA_STRIPE_WEBHOOK_SECRET', null),
            'webhook_tolerance' => (int) env('PAYVIA_STRIPE_WEBHOOK_TOLERANCE', 300),
            'base_url' => env('PAYVIA_STRIPE_BASE_URL', 'https://api.stripe.com'),
            'timeout' => (int) env('PAYVIA_STRIPE_TIMEOUT', 15),
            // See the paystack note above — same trust boundary, applied to the Checkout
            // Session `url` for both one-time and subscription sessions.
            'checkout_hosts' => ['checkout.stripe.com'],
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
