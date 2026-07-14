<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Content\Authorization\AuthenticatedPrincipalResolver;
use App\Content\Authorization\PermissionAuthority;
use App\Http\Middleware\AdminTenantBindingMiddleware;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * The tenant-selection decision is the security boundary of the admin console: it decides which
 * workspace an operator's request binds to. These tests exercise that pure decision directly.
 */
final class AdminTenantBindingMiddlewareTest extends TestCase
{
    private function middleware(?TenantAdministration $tenants = null): AdminTenantBindingMiddleware
    {
        $context = new ApplicationContext(sys_get_temp_dir());

        return new AdminTenantBindingMiddleware(
            $context,
            new AuthenticatedPrincipalResolver(),
            new PermissionAuthority($context),
            $tenants,
            $this->createMock(TenantContextRunner::class),
            $this->createMock(FullTenantResolutionReadiness::class),
        );
    }

    public function testMemberSelectionBindsTheSelectedWorkspace(): void
    {
        $selected = $this->middleware()->selectTenant('t1', ['t1', 't2'], false);
        self::assertSame('t1', $selected);
    }

    public function testNonMemberWithoutOperatorAuthorityIsRejected(): void
    {
        $result = $this->middleware()->selectTenant('t9', ['t1'], false);
        self::assertInstanceOf(Response::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    public function testOperatorMayBindAForeignActiveWorkspace(): void
    {
        $tenants = $this->createMock(TenantAdministration::class);
        $tenants->method('getTenant')->willReturn(
            ['uuid' => 't9', 'slug' => 'nine', 'name' => 'Nine', 'status' => 'active'],
        );
        $selected = $this->middleware($tenants)->selectTenant('t9', ['t1'], true);
        self::assertSame('t9', $selected);
    }

    public function testOperatorCannotBindAnInactiveWorkspace(): void
    {
        $tenants = $this->createMock(TenantAdministration::class);
        $tenants->method('getTenant')->willReturn(
            ['uuid' => 't9', 'slug' => 'nine', 'name' => 'Nine', 'status' => 'trashed'],
        );
        $result = $this->middleware($tenants)->selectTenant('t9', ['t1'], true);
        self::assertInstanceOf(Response::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    public function testNoHeaderBindsTheFirstMembership(): void
    {
        $selected = $this->middleware()->selectTenant('', ['t1', 't2'], false);
        self::assertSame('t1', $selected);
    }

    public function testNoHeaderAndNoMembershipIsRejectedEvenForOperators(): void
    {
        $result = $this->middleware()->selectTenant('', [], true);
        self::assertInstanceOf(Response::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }
}
