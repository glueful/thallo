<?php

declare(strict_types=1);

namespace Thallo\Account\Http\Middleware;

use Glueful\Auth\Session\SameOriginGuard;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Same-origin provenance for the anonymous account POSTs (login, register, verify,
 * forgot-password, verify-reset, reset-password). No session exists yet on these routes, so there
 * is no token to bind — provenance is the whole control: the framework's {@see SameOriginGuard}
 * trusts `Sec-Fetch-Site: same-origin` when present, else an exact `Origin` match. A request that
 * proves neither is rejected before it can reach registration or a sign-in attempt.
 *
 * Safe methods (GET/HEAD/OPTIONS) change no state and carry no provenance, so they pass through.
 * Registered under the `account_same_origin` alias in {@see \Thallo\Account\AccountServiceProvider}.
 */
final class AccountSameOriginMiddleware implements RouteMiddleware
{
    public function __construct(private readonly SameOriginGuard $origin)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        if (!$this->origin->isSameOrigin($request)) {
            return new JsonResponse(['success' => false, 'message' => 'Request rejected.'], 403);
        }

        return $next($request);
    }
}
