<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Container\ContainerInterface;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\Resolution\TenancyMode;
use Thallo\Tenancy\Resolution\TenancyModePolicy;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The one three-mode tenancy policy every Thallo-side resolver answers from (commerce's seam and
 * payments' resolver): the mode is read live from {@see SystemFlags} on every call, enforcement
 * wins over a widened schema, a widened schema never falls back to the sentinel, and enforcement
 * with no shared resolver fails closed.
 */
final class TenancyModePolicyTest extends AppTestCase
{
    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function policy(?CurrentTenantResolver $resolver = null): TenancyModePolicy
    {
        return new TenancyModePolicy($this->flags(), self::containerWith($resolver));
    }

    public function testTheModeFollowsTheFlagsOnEveryCall(): void
    {
        $policy = $this->policy();
        self::assertSame(TenancyMode::Sentinel, $policy->mode());
        self::assertSame('', $policy->tenantUuid($this->appContext()));

        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenDefault01');
        self::assertSame(TenancyMode::DefaultTenant, $policy->mode());
        self::assertSame('tenDefault01', $policy->tenantUuid($this->appContext()));
        self::assertSame('tenDefault01', $policy->defaultTenantUuid());
    }

    public function testEnforcementWinsOverTheWidenedDefault(): void
    {
        $policy = $this->policy(self::resolver('tenCurrent01'));
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenDefault01');
        $this->flags()->put('tenancy.enabled', '1');
        $this->flags()->put('tenancy.enable_step', 'on');

        self::assertSame(TenancyMode::Enforced, $policy->mode());
        self::assertSame('tenCurrent01', $policy->tenantUuid($this->appContext()));

        $this->flags()->put('tenancy.retrofit_active', '1');
        self::assertSame(TenancyMode::DefaultTenant, $policy->mode(), 'the reload barrier holds enforcement off');
    }

    public function testAWidenedSchemaWithoutADefaultTenantFailsClosed(): void
    {
        $this->flags()->put('tenancy.schema_state', 'widened');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Widened tenancy schema without a persisted default tenant.');
        $this->policy()->tenantUuid($this->appContext());
    }

    public function testEnforcementWithoutASharedResolverFailsClosed(): void
    {
        $this->flags()->put('tenancy.enabled', '1');
        $this->flags()->put('tenancy.enable_step', 'on');

        $this->expectException(\RuntimeException::class);
        $this->policy()->tenantUuid($this->appContext());
    }

    private static function resolver(string $tenant): CurrentTenantResolver
    {
        return new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private readonly string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }

    private static function containerWith(?CurrentTenantResolver $resolver): ContainerInterface
    {
        return new class ($resolver) implements ContainerInterface {
            public function __construct(private readonly ?CurrentTenantResolver $resolver)
            {
            }

            public function get(string $id): mixed
            {
                return $this->resolver;
            }

            public function has(string $id): bool
            {
                return $id === CurrentTenantResolver::class && $this->resolver !== null;
            }
        };
    }
}
