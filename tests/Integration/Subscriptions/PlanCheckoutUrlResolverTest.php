<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Settings\GeneralSettings;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Billing\PlanCheckoutUrlResolver;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Subscriptions\Bridge\AdminBillingPlanCheckoutUrlResolver;

/**
 * Task 18 (Phase C, workspace self-serve checkout plan, spec §5.4): the pricing-blocks →
 * billing deep-link bridge's binding + degradation behavior. Companion to {@see
 * \App\Tests\Integration\Render\PricingPlanCheckoutBridgeTest}, which proves the
 * TEMPLATE-side consumption of this contract; this suite proves the resolver ITSELF —
 * binding identity, URL shape, and the capability/engine-off null verdicts, using the
 * same real-second-boot idioms {@see \App\Tests\Integration\Subscriptions\EngineGatewayTest}
 * and {@see \App\Tests\Integration\Subscriptions\CapabilityEngineTruthTableTest} establish.
 */
final class PlanCheckoutUrlResolverTest extends AppTestCase
{
    // ------------------------------------------------------------------
    // Binding
    // ------------------------------------------------------------------

    public function testBoundUnderTheContractIdAsTheConcreteResolverSharedInTheContainer(): void
    {
        $first = $this->container()->get(PlanCheckoutUrlResolver::class);
        $second = $this->container()->get(PlanCheckoutUrlResolver::class);

        self::assertInstanceOf(AdminBillingPlanCheckoutUrlResolver::class, $first);
        self::assertSame($first, $second, 'registered shared: the pack provider must bind it as such');
    }

    // ------------------------------------------------------------------
    // Happy path: everything available.
    // ------------------------------------------------------------------

    public function testResolvesTheAdminBillingDeepLinkFromTheConfiguredAdminOrigin(): void
    {
        self::assertTrue(
            $this->container()->get(CapabilityRegistry::class)->isEnabled('thallo.subscriptions'),
            'sanity check: the capability is default-enabled in this shared boot',
        );

        $resolver = $this->container()->get(PlanCheckoutUrlResolver::class);

        // phpunit.xml's RENDER_ADMIN_URL=https://admin.test is the deploy-time fallback
        // (no DB override in this test) — see GeneralSettings/EngineAdminUrlProvider.
        self::assertSame(
            'https://admin.test/billing?plan=pro',
            $resolver->resolve($this->appContext(), 'pro'),
        );
    }

    public function testUrlShapeUsesTheConfiguredAdminOriginNotAnyHostHeader(): void
    {
        // A DB row beats the env fallback (PreviewSessionTest establishes the same
        // precedence for the identical AdminUrlProvider chain) — proves the URL comes
        // from the CONFIGURED origin, never any request Host header (this call takes
        // no Request at all).
        $this->container()->get(GeneralSettings::class)->save(['admin_url' => 'https://other-admin.test']);

        $resolver = $this->container()->get(PlanCheckoutUrlResolver::class);

        self::assertSame(
            'https://other-admin.test/billing?plan=team',
            $resolver->resolve($this->appContext(), 'team'),
        );
    }

    public function testWellFormedUnknownKeyStillResolvesANonNullDeepLink(): void
    {
        // §5.4: the resolver makes no existence/purchasability promise — a well-formed
        // but unknown key still deep-links; the billing page itself renders
        // plan_not_purchasable rather than this seam ever querying the catalog.
        $resolver = $this->container()->get(PlanCheckoutUrlResolver::class);

        self::assertSame(
            'https://admin.test/billing?plan=definitely-not-a-real-plan',
            $resolver->resolve($this->appContext(), 'definitely-not-a-real-plan'),
        );
    }

    // ------------------------------------------------------------------
    // Null-safety: no configured admin origin.
    // ------------------------------------------------------------------

    public function testNullWhenNoAdminOriginIsConfigured(): void
    {
        $this->container()->get(GeneralSettings::class)->save(['admin_url' => '']);

        $resolver = $this->container()->get(PlanCheckoutUrlResolver::class);

        self::assertNull($resolver->resolve($this->appContext(), 'pro'));
    }

    // ------------------------------------------------------------------
    // Capability off.
    // ------------------------------------------------------------------

    public function testNullWhenTheCapabilityIsOff(): void
    {
        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.subscriptions' => false],
        ]);

        try {
            $registry = $disabledApp->getContainer()->get(CapabilityRegistry::class);
            self::assertFalse(
                $registry->isEnabled('thallo.subscriptions'),
                'sanity check: this boot really has the capability disabled',
            );

            $resolver = $disabledApp->getContainer()->get(PlanCheckoutUrlResolver::class);

            self::assertNull($resolver->resolve($disabledApp, 'pro'));
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
    }

    // ------------------------------------------------------------------
    // Engine off.
    // ------------------------------------------------------------------

    public function testNullWhenTheEngineIsUnavailable(): void
    {
        $disabledApp = $this->bootWithEngineProviderDisabled();

        try {
            self::assertFalse(
                $disabledApp->getContainer()->has(\Glueful\Extensions\Subscriptions\SubscriptionService::class),
                'sanity check: this boot really lacks the engine binding',
            );

            $resolver = $disabledApp->getContainer()->get(PlanCheckoutUrlResolver::class);

            self::assertNull($resolver->resolve($disabledApp, 'pro'));
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    /**
     * A REAL second boot with glueful/subscriptions' own provider filtered out of
     * config/extensions.php's `enabled` list — mirrors {@see EngineGatewayTest}'s own
     * `bootWithEngineProviderDisabled()` verbatim (including its `array_replace_recursive`
     * index-merge padding workaround — see that method's docblock for why it's needed).
     */
    private function bootWithEngineProviderDisabled(): ApplicationContext
    {
        $root = dirname(__DIR__, 3);
        $base = (array) require $root . '/config/extensions.php';
        $engineProvider = \Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class;

        /** @var list<string> $baseEnabled */
        $baseEnabled = (array) $base['enabled'];
        $withoutEngine = array_values(array_filter(
            $baseEnabled,
            static fn (string $provider): bool => $provider !== $engineProvider,
        ));
        while (count($withoutEngine) < count($baseEnabled)) {
            $withoutEngine[] = $withoutEngine[0];
        }

        return self::bootAppWithConfigOverride('extensions', ['enabled' => $withoutEngine]);
    }
}
