<?php

declare(strict_types=1);

namespace Thallo\Account\Http;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * The ONE place per-visitor identity leaves the server. The `auth-state` block renders a
 * universal shell — both the signed-out and signed-in branches — that the shared page cache
 * stores byte-identically; this endpoint is what hydration reads to learn who is actually
 * looking. Its response is `private, no-store` so it can never be cached and served to the next
 * stranger, and deliberately minimal: `{ authenticated: bool }` only — no name, no navigation.
 * (The dashboard reads the `user` request attribute server-side instead; `AccountNavigationRegistry`
 * stays for that consumer.)
 *
 * Routed with `['session_cookie:optional', 'auth:optional']`: the cookie adapter turns a valid
 * cookie into a Bearer header (and drops a lapsed one to anonymous), `auth:optional` sets the
 * `user` attribute when present and lets a signed-out visitor through instead of 401-ing the
 * chrome. With the adapter alone nobody sets `user`, so every visitor would read as anonymous.
 */
final class AccountSessionController
{
    public function show(Request $request): Response
    {
        $user = $request->attributes->get('user');
        $authenticated = is_array($user) && (string) ($user['uuid'] ?? '') !== '';

        $response = Response::success(['authenticated' => $authenticated], 'Session state');

        // Never shared: identity must not enter any cache.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
