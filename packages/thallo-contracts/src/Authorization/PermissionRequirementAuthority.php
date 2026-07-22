<?php

declare(strict_types=1);

namespace Thallo\Contracts\Authorization;

use Symfony\Component\HttpFoundation\Request;

/**
 * The single authorization authority for permission REQUIREMENTS (Task 8, admin-commerce-area
 * plan slice 3) — the SAME seam the `content_permission` route middleware evaluates against
 * ({@see \App\Content\Http\RequirePermission}, bound to the concrete
 * {@see \App\Content\Authorization\PermissionRequirementAuthority} in
 * `App\Providers\ThalloServiceProvider`), exposed as a neutral contract so a first-party pack can
 * compute the SAME effective-permission decision (e.g. an admin `/meta` endpoint's `can_view`/
 * `can_manage` flags) without depending on the engine app's `App\` namespace directly.
 *
 * A route (or an effective-flags endpoint) states a list of required permission alternatives;
 * the request passes when ANY required candidate is satisfied, with implications (e.g.
 * `commerce.manage` implying `commerce.view`) expanded by the concrete authority — never
 * reproduced by a caller of this interface.
 */
interface PermissionRequirementAuthority
{
    /**
     * @param list<string> $requirements required permission alternatives (any-of)
     */
    public function allows(Request $request, array $requirements): bool;
}
