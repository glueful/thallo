<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Content\Authorization\PermissionRequirementAuthority;
use App\Content\Http\RequirePermission;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fail-closed contract for the `content_permission` middleware.
 *
 * Every guard that cannot positively establish authorization must return 403. The three
 * pre-`can()` branches are exercised here with a bare {@see ApplicationContext} (no container),
 * which makes {@see ApplicationContext::hasContainer()} false so the PermissionManager never
 * resolves — the same deny path a misconfigured deployment would hit. No DB is required.
 */
final class RequirePermissionTest extends TestCase
{
    public function testEmptyPermissionParamIsForbidden(): void
    {
        $mw = new RequirePermission($this->contextWithoutContainer());
        $resp = $mw->handle(new Request(), fn() => new Response(), '');
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testNoAuthUserIsForbidden(): void
    {
        $mw = new RequirePermission($this->contextWithoutContainer());
        // Valid permission param, but no `auth.user` attribute on the request.
        $resp = $mw->handle(new Request(), fn() => new Response(), 'content.edit');
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testUnresolvedPermissionManagerIsForbidden(): void
    {
        $request = new Request();
        $request->attributes->set('auth.user', new \Glueful\Auth\UserIdentity(
            uuid: 'usr_test01',
            roles: ['administrator'],
            username: 'tester',
        ));

        // Valid param + authenticated user, but the context has no container, so the
        // PermissionManager cannot be resolved -> fail closed.
        $mw = new RequirePermission($this->contextWithoutContainer());
        $resp = $mw->handle($request, fn() => new Response(), 'content.edit');
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testApiKeyWithoutMatchingScopeIsForbidden(): void
    {
        // A fully authenticated api-key principal whose OWNER would pass the RBAC check,
        // but whose key is scoped for something else — the scope gate must deny before
        // owner permissions are ever consulted (leaked narrow keys can't reach admin).
        $mw = new RequirePermission($this->contextWithoutContainer());
        $resp = $mw->handle(
            $this->apiKeyRequest(['products.read']),
            fn() => new Response(),
            'collections.schema.manage',
        );
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testApiKeyWithEmptyScopesIsForbidden(): void
    {
        // The framework treats an empty scope list as "full access" (legacy keys); the
        // admin gate must treat it as no authority at all.
        $mw = new RequirePermission($this->contextWithoutContainer());
        $resp = $mw->handle($this->apiKeyRequest([]), fn() => new Response(), 'content.view');
        self::assertSame(403, $resp->getStatusCode());
    }

    private function apiKeyRequest(array $scopes): Request
    {
        $request = new Request();
        $request->attributes->set('user', ['uuid' => 'usr_owner1', 'roles' => ['administrator']]);
        $request->attributes->set('auth_method', 'api_key');
        $request->attributes->set('api_key_scopes', $scopes);

        return $request;
    }

    public function testResourceForDerivesLocaleScopedResourceFromRouteParam(): void
    {
        $authority = new PermissionRequirementAuthority($this->contextWithoutContainer());
        $request = new Request();
        $request->attributes->set('_route_params', ['uuid' => 'e1abcdefghij', 'locale' => 'fr']);

        self::assertSame('locale:fr', $this->resourceFor($authority, $request));
    }

    public function testResourceForFallsBackToCoarseWithoutLocaleParam(): void
    {
        $authority = new PermissionRequirementAuthority($this->contextWithoutContainer());

        self::assertSame('thallo', $this->resourceFor($authority, new Request()));

        $noLocale = new Request();
        $noLocale->attributes->set('_route_params', ['uuid' => 'e1abcdefghij']);
        self::assertSame('thallo', $this->resourceFor($authority, $noLocale));

        $empty = new Request();
        $empty->attributes->set('_route_params', ['locale' => '']);
        self::assertSame('thallo', $this->resourceFor($authority, $empty));
    }

    private function resourceFor(PermissionRequirementAuthority $authority, Request $request): string
    {
        $method = new \ReflectionMethod($authority, 'resourceFor');
        $method->setAccessible(true);
        return $method->invoke($authority, $request);
    }

    private function contextWithoutContainer(): ApplicationContext
    {
        // A bare context: hasContainer() is false, so permissionManager() returns null
        // and the middleware fails closed before ever calling can().
        return new ApplicationContext(basePath: \dirname(__DIR__, 3), environment: 'testing');
    }
}
