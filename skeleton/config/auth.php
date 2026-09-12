<?php

/**
 * Authentication Configuration
 *
 * Core email-PIN two-factor authentication (2FA). The feature is opt-in:
 * `enabled` defaults to false, so a fresh install behaves exactly like a
 * pre-2FA framework until TWO_FACTOR_ENABLED=true and the migration is run.
 */

return [
    'api_keys' => [
        // Brand segment of generated API keys: <prefix>_live_<random> in production,
        // <prefix>_test_<random> elsewhere. Defaults to 'gf' (Glueful); set API_KEY_PREFIX to
        // rebrand (e.g. 'lm' → lm_live_… / lm_test_…). Keep it short — only the first 16 chars of a
        // key are stored as the indexed lookup prefix.
        'prefix' => env('API_KEY_PREFIX', 'gf'),
    ],
    'two_factor' => [
        // Master switch. When false, TwoFactorService::isEnabled() short-circuits
        // before any DB read and the /2fa/* routes are not registered.
        'enabled' => env('TWO_FACTOR_ENABLED', false),

        // Number of digits in the emailed PIN.
        'pin_length' => (int) env('TWO_FACTOR_PIN_LENGTH', 6),

        // How long an emailed PIN remains valid (seconds).
        'pin_ttl' => (int) env('TWO_FACTOR_PIN_TTL', 300),

        // How long a challenge_token remains valid (seconds).
        'challenge_ttl' => (int) env('TWO_FACTOR_CHALLENGE_TTL', 300),

        // Notification template name (rendered by glueful/email-notification).
        'template_name' => env('TWO_FACTOR_TEMPLATE', 'two-factor-pin'),

        // How long after a 2FA login a session may call /2fa/disable without
        // re-verifying (seconds). Session-scoped marker, not user-scoped.
        'disable_freshness' => (int) env('TWO_FACTOR_DISABLE_FRESHNESS', 300),
    ],

    // HttpOnly session-cookie transport (framework ≥ 1.73.0). Thallo ships it ON by default
    // because the storefront account pages (glueful/thallo-account) sign visitors in over this
    // cookie — shipping it off would leave an enabled account capability whose login cannot work
    // without an undocumented deployment step. Operators disable it with SESSION_COOKIE_ENABLED=false.
    //
    // This block deliberately sets ONLY `enabled`. Every cookie attribute — Secure, HttpOnly,
    // SameSite=Lax and the host-only (null) domain — is centralized in the framework's
    // SessionCookieConfig defaults, so it stays production-safe without being restated here.
    // Enabling the transport does not cookie-authenticate any route: the `session_cookie`
    // middleware is opt-in per route, bearer authentication is unchanged, and `thallo.accounts`
    // gates only the themed account surfaces, never the framework's /auth/* identity endpoints.
    'session_cookie' => [
        'enabled' => env('SESSION_COOKIE_ENABLED', true),
    ],
];
