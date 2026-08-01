<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Commerce\Payments\ThalloOrderPaymentReturnUrlProvider;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;

/**
 * The host-bound payment return-URL provider (checkout-ui plan Task 3): composes ONLY from the
 * canonical trusted origin + ShopUrlGenerator — never the request Host — and returns null (no
 * URLs, gateway dashboard fallback) rather than gateway-noncompliant output when the origin is
 * not HTTPS. Receives the completed order, so retry parity is by construction (same input row).
 */
final class ThalloOrderPaymentReturnUrlProviderTest extends AppTestCase
{
    public function testComposesHttpsReturnAndCancelFromTheTrustedOriginWithEncodedRef(): void
    {
        $provider = new ThalloOrderPaymentReturnUrlProvider(
            $this->fixedOrigin('https://shop.example:8443'),
            $this->container()->get(ShopUrlGenerator::class),
        );

        // An order number carrying URL-significant characters is encoded, never spliced raw.
        $urls = $provider->urlsFor($this->appContext(), ['order_number' => 'THL 1/A']);

        self::assertSame('https://shop.example:8443/checkout/return/THL%201%2FA', $urls['return']);
        self::assertSame('https://shop.example:8443/checkout/cancel/THL%201%2FA', $urls['cancel']);
    }

    public function testAHostileHostHeaderIsIrrelevantByConstruction(): void
    {
        // The provider's ONLY origin input is the resolver — there is no request in its
        // signature at all, so a hostile Host header has no path into the composed URLs.
        $provider = new ThalloOrderPaymentReturnUrlProvider(
            $this->fixedOrigin('https://shop.example'),
            $this->container()->get(ShopUrlGenerator::class),
        );

        $urls = $provider->urlsFor($this->appContext(), ['order_number' => 'THL-2']);

        self::assertStringStartsWith('https://shop.example/', (string) $urls['return']);
    }

    public function testANonHttpsOriginYieldsNullNotMalformedUrls(): void
    {
        // A TLS-less local install cannot produce gateway-compliant callbacks: contractually
        // "no URLs" (placement proceeds exactly as before the provider existed) — never http
        // output that commerce's validation would degrade to init_failed.
        $provider = new ThalloOrderPaymentReturnUrlProvider(
            $this->fixedOrigin('http://localhost'),
            $this->container()->get(ShopUrlGenerator::class),
        );

        self::assertNull($provider->urlsFor($this->appContext(), ['order_number' => 'THL-3']));
    }

    public function testAMissingOrderNumberYieldsNull(): void
    {
        $provider = new ThalloOrderPaymentReturnUrlProvider(
            $this->fixedOrigin('https://shop.example'),
            $this->container()->get(ShopUrlGenerator::class),
        );

        self::assertNull($provider->urlsFor($this->appContext(), []));
    }

    private function fixedOrigin(string $origin): CanonicalPublicOriginResolver
    {
        return new class ($origin) implements CanonicalPublicOriginResolver {
            public function __construct(private readonly string $origin)
            {
            }

            public function currentOrigin(ApplicationContext $c): string
            {
                return $this->origin;
            }

            public function originForTenant(ApplicationContext $c, string $tenantUuid): string
            {
                return $this->origin;
            }
        };
    }
}
