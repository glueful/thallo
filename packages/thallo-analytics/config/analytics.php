<?php

declare(strict_types=1);

return [
    // NOTE: enable/disable is NOT configured here — the capability switchboard in the app's
    // config/lemma.php ('capabilities' => ['lemma.analytics' => false]) is the only gate.

    // Raw analytics_facts older than this many days are pruned; rollups are kept forever.
    'retention_days' => (int) env('ANALYTICS_RETENTION_DAYS', 90),

    // HMAC key for the one-way actor hash in analytics_active_actors. Falls back to APP_KEY.
    'hash_key' => env('ANALYTICS_HASH_KEY', env('APP_KEY', '')),

    // Maximum from..to span (in days) a query may request. series() zero-fills one bucket per day,
    // so an unbounded range is a multi-million-iteration DoS; requests beyond this are rejected 422.
    'max_range_days' => (int) env('ANALYTICS_MAX_RANGE_DAYS', 366),
];
