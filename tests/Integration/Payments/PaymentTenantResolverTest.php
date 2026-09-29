<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRequiredException;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Psr\Container\ContainerInterface;
use Thallo\Core\Payments\Tenancy\ThalloPayviaTenantResolver;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\Adoption\AdoptionGateBusyException;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Payments resolve their workspace from the shared three-mode policy: the single store's ''
 * before the schema is widened (held open against the adoption flip for the rest of the unit of
 * work), the recorded default workspace once it is, and — under enforcement — the request's
 * workspace, failing closed when there is none. Payvia's own resolver failed closed in every mode
 * whenever a shared resolver was bound, so checkout could not start a payment before enforcement.
 */
final class PaymentTenantResolverTest extends AppTestCase
{
    /** @var list<AdoptionGate> */
    private array $gates = [];

    protected function tearDown(): void
    {
        foreach ($this->gates as $gate) {
            $gate->release();
            $gate->releaseExclusive();
        }
        parent::tearDown();
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function gate(): AdoptionGate
    {
        return $this->gates[] = new AdoptionGate($this->connection());
    }

    private function resolver(
        ?CurrentTenantResolver $shared = null,
        ?AdoptionGate $gate = null,
    ): ThalloPayviaTenantResolver {
        return new ThalloPayviaTenantResolver($this->flags(), self::containerWith($shared), $gate ?? $this->gate());
    }

    public function testTheContainerResolvesThalloResolverOverPayvias(): void
    {
        self::assertInstanceOf(
            ThalloPayviaTenantResolver::class,
            $this->container()->get(PayviaTenantResolver::class),
        );
    }

    /** The regression: a bound shared resolver no longer makes the single store fail closed. */
    public function testTheSingleStoreResolvesTheSentinelEvenWithASharedResolverBound(): void
    {
        $gate = $this->gate();
        $resolver = $this->resolver(self::shared(''), $gate);

        self::assertSame('', $resolver->tenantUuid($this->appContext()));
        self::assertTrue($gate->isHeld(), 'the unit of work holds the adoption gate open');
    }

    public function testAHeldUnitOfWorkKeepsTheAdoptionFlipOut(): void
    {
        $unit = $this->gate();
        $this->resolver(null, $unit)->tenantUuid($this->appContext());

        $flip = $this->gate();
        try {
            $flip->acquireExclusive(200);
            self::fail('the flip must wait for the unit of work');
        } catch (AdoptionGateBusyException) {
        }

        $unit->release();
        $flip->acquireExclusive(200);
        self::assertTrue($flip->holdsExclusive());
    }

    public function testASentinelResolutionDuringTheFlipFailsClosed(): void
    {
        $this->gate()->acquireExclusive(200);

        $this->expectException(RetrofitInProgressException::class);
        $this->resolver()->tenantUuid($this->appContext());
    }

    public function testAWidenedSchemaResolvesTheDefaultWorkspaceWithoutHolding(): void
    {
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenDefault01');
        $gate = $this->gate();

        self::assertSame('tenDefault01', $this->resolver(self::shared(''), $gate)->tenantUuid($this->appContext()));
        self::assertFalse($gate->isHeld());
    }

    public function testEnforcementResolvesTheRequestWorkspace(): void
    {
        $this->enforce();
        $resolver = $this->resolver(self::shared('tenCurrent01'));
        self::assertSame('tenCurrent01', $resolver->tenantUuid($this->appContext()));
    }

    public function testEnforcementWithoutAWorkspaceFailsClosed(): void
    {
        $this->enforce();

        $this->expectException(TenantContextRequiredException::class);
        $this->resolver(self::shared(''))->tenantUuid($this->appContext());
    }

    private function enforce(): void
    {
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenDefault01');
        $this->flags()->put('tenancy.enabled', '1');
        $this->flags()->put('tenancy.enable_step', 'on');
    }

    private static function shared(string $tenant): CurrentTenantResolver
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
