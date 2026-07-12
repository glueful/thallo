<?php

declare(strict_types=1);

namespace App\Content\Http;

use App\Content\Authorization\OperatorBypass;
use App\Content\Authorization\AuthenticatedPrincipalResolver;
use App\Content\Authorization\PermissionAuthority;
use App\Content\Authorization\EffectiveRoleMatrix;
use App\Content\Authorization\RoleMatrix;
use App\Content\Authorization\TenantMembershipRoleReader;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Permissions\PermissionManager;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Requires the authenticated user to hold a specific Thallo RBAC permission.
 *
 * Registered under the `content_permission` alias and used on the fluent admin routes.
 * The required permission slug is the first middleware parameter; the check runs through
 * the same `PermissionManager::can()` that Aegis backs, scoped to the resource the route
 * targets: `locale:<code>` for routes carrying `{locale}`, else the coarse `thallo`.
 *
 * Fails closed: a missing/empty permission parameter, no authenticated identity, an
 * unresolvable PermissionManager, or a denied check all return 403. API-key principals
 * additionally need a key scope satisfying the permission slug (wildcards via fnmatch;
 * empty scope list = deny) — the owner's RBAC alone never authorizes a key.
 */
final class RequirePermission implements RouteMiddleware
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?TenantMembershipRoleReader $roleReader = null,
        private readonly ?EffectiveRoleMatrix $matrix = null,
        private readonly ?OperatorBypass $bypass = null,
        private readonly ?AuthenticatedPrincipalResolver $principals = null,
        private readonly ?PermissionAuthority $permissions = null,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $permission = isset($params[0]) && is_string($params[0]) ? trim($params[0]) : '';
        if ($permission === '') {
            return $this->forbidden();
        }

        // API-key principals must carry a scope matching the required permission slug —
        // authorizing on the key OWNER's RBAC alone would let any leaked key (however
        // narrowly scoped) inherit the owner's full admin rights. The framework's `auth`
        // middleware accepts jwt+api_key, so the provider is checked here. Scope-satisfying
        // keys still fall through to the owner RBAC check below (defense in depth); an
        // empty scope list is a deny, NOT the framework's legacy "full access".
        if ($request->attributes->get('auth_method') === 'api_key') {
            $scopes = array_values(array_filter(
                (array) $request->attributes->get('api_key_scopes', []),
                'is_string',
            ));
            if ($scopes === [] || !ApiKeyService::scopeSatisfies($scopes, $permission)) {
                return $this->forbidden();
            }
        }

        // Resolve the authenticated principal. Two shapes are accepted, both set by the
        // framework's `auth` middleware chain:
        //   - `auth.user`: a UserIdentity, present only when the optional
        //     AuthToRequestAttributesMiddleware enricher is wired into the container.
        //   - `user`: the plain identity array AuthMiddleware always sets after a
        //     successful authentication (uuid/roles/scopes/claims). This is the shape a
        //     lean install (no enricher binding) actually carries, so the gate must read
        //     it too — otherwise every permissioned route would fail closed even for a
        //     correctly authenticated user.
        $principals = $this->principals ?? new AuthenticatedPrincipalResolver();
        $principal = $principals->resolve($request);
        if ($principal === null) {
            return $this->forbidden();
        }

        $permissions = $this->permissions ?? new PermissionAuthority($this->context);
        if (!$permissions->manager() instanceof PermissionManager) {
            return $this->forbidden();
        }
        $context = $principals->aegisContext($request, $principal);
        $tenantContextPresent = $this->context->getRequestState('tenancy.tenant') !== null;
        if (!$tenantContextPresent) {
            $allowed = $permissions->can(
                $principal['uuid'],
                $permission,
                $this->resourceFor($request),
                $context,
            );
        } else {
            if ($this->roleReader === null || $this->matrix === null || $this->bypass === null) {
                return $this->forbidden();
            }
            $resolvedTenant = $this->roleReader->resolvedTenantUuid();
            if ($resolvedTenant === null) {
                return $this->forbidden();
            }
            $role = $this->roleReader->roleFor($request, $principal['uuid']);
            $allowed = ($role !== null && $this->matrix->allows($resolvedTenant, $role, $permission))
                || $this->bypass->evaluate(
                    $request,
                    $principal['uuid'],
                    $role,
                    $permission,
                    $resolvedTenant,
                    $context,
                )->granted;
        }

        if (!$allowed) {
            return $this->forbidden();
        }
        return $next($request);
    }

    /**
     * Derive the authorization resource from the matched route. Locale-specific routes
     * carry a `{locale}` parameter, set by the router as `_route_params` before the
     * middleware pipeline runs; those actions are scoped to `locale:<code>`. Routes
     * without a locale keep the coarse `thallo` resource.
     */
    private function resourceFor(Request $request): string
    {
        $params = (array) $request->attributes->get('_route_params');
        $locale = $params['locale'] ?? null;

        return is_string($locale) && $locale !== '' ? "locale:{$locale}" : 'thallo';
    }

    private function forbidden(): Response
    {
        return Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN']);
    }
}
