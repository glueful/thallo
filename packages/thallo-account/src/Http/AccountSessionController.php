<?php

declare(strict_types=1);

namespace Thallo\Account\Http;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Account\AccountNavigationItem;
use Thallo\Contracts\Account\AccountNavigationRegistry;
use Thallo\Contracts\Capability\CapabilityRegistry;

/**
 * The ONE place per-visitor identity leaves the server. The `account-link` block renders a
 * universal signed-out shell that the shared page cache stores byte-identically; this endpoint is
 * what hydration reads to learn who is actually looking. Its response is `private, no-store` so it
 * can never be cached and served to the next stranger.
 *
 * Routed with `['session_cookie:optional', 'auth:optional']`: the cookie adapter turns a valid
 * cookie into a Bearer header (and drops a lapsed one to anonymous), `auth:optional` sets the
 * `user` attribute when present and lets a signed-out visitor through instead of 401-ing the
 * chrome. With the adapter alone nobody sets `user`, so every visitor would read as anonymous.
 */
final class AccountSessionController
{
    public function __construct(
        private readonly AccountNavigationRegistry $navigation,
        private readonly CapabilityRegistry $capabilities,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = $request->attributes->get('user');
        $authenticated = is_array($user) && (string) ($user['uuid'] ?? '') !== '';

        $response = Response::success([
            'authenticated' => $authenticated,
            'display_name' => $authenticated ? $this->displayName(is_array($user) ? $user : []) : null,
            'links' => $authenticated ? $this->visibleNavigation() : [],
        ], 'Session state');

        // Never shared: identity must not enter any cache.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** @param array<string,mixed> $user */
    private function displayName(array $user): string
    {
        $profile = is_array($user['profile'] ?? null) ? $user['profile'] : [];
        $first = trim((string) ($profile['first_name'] ?? ''));
        $last = trim((string) ($profile['last_name'] ?? ''));
        $full = trim($first . ' ' . $last);
        if ($full !== '') {
            return $full;
        }
        $username = trim((string) ($user['username'] ?? ''));
        if ($username !== '') {
            return $username;
        }

        return trim((string) ($user['email'] ?? '')) ?: 'Account';
    }

    /**
     * The signed-in visitor's account sections, capability-filtered exactly like the dashboard —
     * a section whose capability is disabled disappears rather than 404-ing from the chrome.
     *
     * @return list<array{label: string, path: string}>
     */
    private function visibleNavigation(): array
    {
        $visible = array_filter(
            $this->navigation->items(),
            fn (AccountNavigationItem $item): bool =>
                $item->capability === null || $this->capabilities->isEnabled($item->capability),
        );

        return array_values(array_map(
            static fn (AccountNavigationItem $item): array => ['label' => $item->label, 'path' => $item->path],
            $visible,
        ));
    }
}
