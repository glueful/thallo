<?php

declare(strict_types=1);

namespace Thallo\Account;

/**
 * The one return-path authority for the account surface (public-account-surface plan Task 3).
 *
 * A safe redirect target is a normalized, application-relative path: exactly ONE leading `/`, no
 * host, no scheme, no backslash, no control character, and no percent-encoding (any `%XX` that
 * decodes to something is treated as a bypass attempt and rejected — internal targets never need
 * it). This single gate is applied to BOTH the visitor-supplied `?next=` and the operator-stored
 * redirect settings, so neither path can become an open redirect.
 *
 * Pure and dependency-free — unit-tested against a hostile corpus.
 */
final class AccountReturnPath
{
    /**
     * Return the candidate unchanged when it is a safe application-relative path, or null when it
     * is unsafe for any reason.
     */
    public function validate(string $candidate): ?string
    {
        if ($candidate === '') {
            return null;
        }
        // Any control character (NUL, tab, CR, LF, DEL, …) anywhere is a smuggling attempt.
        if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }
        // Leading/trailing whitespace (e.g. a space before `//evil`) is never a legitimate target.
        if ($candidate !== trim($candidate)) {
            return null;
        }
        // Percent-encoding is rejected wholesale: if decoding changes the string, it was hiding a
        // `/`, `\`, control char, or another delimiter (e.g. `%2f%2fevil`, `%5c`, `%00`).
        if (rawurldecode($candidate) !== $candidate) {
            return null;
        }
        // Must be rooted at exactly one `/` — rules out schemes (`javascript:`), absolute URLs
        // (`https://…`), and bare relative paths (`account/orders`).
        if ($candidate[0] !== '/') {
            return null;
        }
        // Protocol-relative (`//host`) and backslash tricks (`/\host`, `\\host`) are host-bearing.
        if (str_starts_with($candidate, '//') || str_contains($candidate, '\\')) {
            return null;
        }

        return $candidate;
    }

    /**
     * The PATH-ONLY variant for a posted `return_to` (form-blocks plan Task 3): everything
     * {@see validate()} enforces, PLUS no `?` or `#`. The error-return controller appends its one
     * allowlisted query parameter to the accepted value, so a path-only contract removes any
     * merge/duplicate-key/fragment ambiguity. `next` destinations keep the richer validate().
     */
    public function validatePagePath(string $candidate): ?string
    {
        $safe = $this->validate($candidate);
        if ($safe === null || str_contains($safe, '?') || str_contains($safe, '#')) {
            return null;
        }

        return $safe;
    }

    /**
     * Resolve the effective redirect: a valid `next` wins, else a valid configured default, else
     * the fixed fallback. Both candidates are validated INDEPENDENTLY — a hostile `next` never
     * suppresses a valid configured default, and a hostile configured value never leaks through.
     * The fallback is assumed to be a trusted literal supplied by the caller.
     */
    public function resolve(?string $next, ?string $configured, string $fallback): string
    {
        $safeNext = $next !== null ? $this->validate($next) : null;
        if ($safeNext !== null) {
            return $safeNext;
        }
        $safeConfigured = $configured !== null ? $this->validate($configured) : null;
        if ($safeConfigured !== null) {
            return $safeConfigured;
        }

        return $fallback;
    }
}
