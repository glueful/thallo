<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Http\Shop\ShopCsrfGuard;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 9 (storefront-rendering spec §6/§11 verbatim): the `/_shop` CSRF matrix. Runs in mode
 * (b) (widened schema + a persisted default tenant, {@see SystemFlags}) — mirroring
 * ShopCatalogTest/TenantResolutionModesTest's identical single-store convention in this same
 * directory — so `ShopCsrfGuard`'s expected origin is always
 * `CanonicalPublicOriginResolver::currentOrigin()`'s single-store fallback
 * (`config('app.urls.base')`), read dynamically rather than hardcoded so this suite never
 * silently drifts from the resolver it exercises. The mode (c) enforcement cases (default-host/
 * custom-domain/subdomain origin parity with media-URL generation) are a separate, DEV_LINK-
 * gated block at the bottom that self-skips without `THALLO_TENANCY_DEV_LINK=1`, mirroring
 * TenantResolutionModesTest's own end-to-end gate.
 */
final class ShopCsrfTest extends AppTestCase
{
    private const TENANT_A = 'csrftesttena';

    private static int $seq = 0;

    /** @var list<string> */
    private array $seededTenants = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateCommerceCatalog();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
        $this->seededTenants = [];
    }

    protected function tearDown(): void
    {
        $this->truncateCommerceCatalog();
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        $this->flags()->forget('tenancy.enabled');
        $this->flags()->forget('tenancy.enable_step');
        if ($this->seededTenants !== []) {
            $placeholders = implode(',', array_fill(0, count($this->seededTenants), '?'));
            $pdo = $this->connection()->getPDO();
            $pdo->prepare("DELETE FROM tenant_domains WHERE tenant_uuid IN ({$placeholders})")
                ->execute($this->seededTenants);
            $pdo->prepare("DELETE FROM tenants WHERE uuid IN ({$placeholders})")
                ->execute($this->seededTenants);
        }
        parent::tearDown();
    }

    private function truncateCommerceCatalog(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM commerce_cart_lines');
        $pdo->exec('DELETE FROM commerce_carts');
        $pdo->exec('DELETE FROM commerce_variants');
        $pdo->exec('DELETE FROM commerce_products');
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    // ------------------------------------------------------------------
    // single-store matrix (unconditional — spec §11)
    // ------------------------------------------------------------------

    public function testCrossOriginOriginIsRejected(): void
    {
        $response = $this->postAdd(['HTTP_ORIGIN' => 'https://evil.attacker.test']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testSecFetchSiteCrossSiteIsRejectedEvenWithAMatchingOrigin(): void
    {
        $response = $this->postAdd([
            'HTTP_ORIGIN' => $this->expectedOrigin(),
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testSecFetchSiteSameOriginWithNoOriginOrRefererIsRejected(): void
    {
        $response = $this->postAdd(['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testNoOriginSignalAtAllIsRejected(): void
    {
        $response = $this->postAdd([]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAbsentOriginWithAnExactSameOriginRefererSucceeds(): void
    {
        $variant = $this->seedVariant();

        $response = $this->postAdd([
            'HTTP_REFERER' => $this->expectedOrigin() . '/shop/products/whatever',
            'HTTP_ACCEPT' => 'application/json',
        ], $variant);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAbsentOriginWithACrossOriginRefererIsRejected(): void
    {
        $response = $this->postAdd(['HTTP_REFERER' => 'https://evil.attacker.test/whatever']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testSpoofedHostDoesNotAlterTheExpectedOrigin(): void
    {
        $variant = $this->seedVariant();

        // The real Origin matches the canonical origin; Host is a completely unrelated,
        // attacker-controlled value. The guard must accept this — it never reads Host at all.
        $response = $this->postAdd([
            'HTTP_ORIGIN' => $this->expectedOrigin(),
            'HTTP_HOST' => 'evil.attacker.test',
            'HTTP_ACCEPT' => 'application/json',
        ], $variant);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSpoofedHostMatchingOriginIsStillRejectedWhenNeitherIsTheRealCanonicalOrigin(): void
    {
        // An attacker sets BOTH Origin and Host to their own host — proving the guard doesn't
        // fall back to "Origin equals Host" as some kind of secondary trust signal.
        $response = $this->postAdd([
            'HTTP_ORIGIN' => 'https://evil.attacker.test',
            'HTTP_HOST' => 'evil.attacker.test',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testSameOriginNoJsPrgSucceedsAndRedirectsToTheCartPage(): void
    {
        $variant = $this->seedVariant();

        $response = $this->postAdd(['HTTP_ORIGIN' => $this->expectedOrigin()], $variant);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/cart', $response->headers->get('Location'));
    }

    public function testJsonNegotiationSucceedsAndReturnsTheCartViewModel(): void
    {
        $variant = $this->seedVariant();

        $response = $this->postAdd([
            'HTTP_ORIGIN' => $this->expectedOrigin(),
            'HTTP_ACCEPT' => 'application/json',
        ], $variant, quantity: 3);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(3, $data['item_count']);
        self::assertArrayHasKey('items', $data);
    }

    public function testCsrfRejectionIsJsonNegotiated(): void
    {
        $response = $this->postAdd(['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
    }

    public function testCsrfRejectionAppliesToEveryMutationRouteNotJustAdd(): void
    {
        $bad = ['HTTP_ORIGIN' => 'https://evil.attacker.test'];

        self::assertSame(403, $this->handle(Request::create('/_shop/cart/update', 'POST', [], [], [], $bad))
            ->getStatusCode());
        self::assertSame(403, $this->handle(Request::create('/_shop/cart/remove', 'POST', [], [], [], $bad))
            ->getStatusCode());
        self::assertSame(403, $this->handle(Request::create('/_shop/cart/discount', 'POST', [], [], [], $bad))
            ->getStatusCode());
    }

    public function testGetRoutesCarryNoCsrfGuardAtAll(): void
    {
        // Plain GETs are never mutating and never CSRF-guarded — a cross-origin Origin must not
        // affect them at all.
        $cart = $this->handle(Request::create(
            '/_shop/cart',
            'GET',
            [],
            [],
            [],
            ['HTTP_ORIGIN' => 'https://evil.attacker.test'],
        ));
        $page = $this->handle(Request::create(
            '/cart',
            'GET',
            [],
            [],
            [],
            ['HTTP_ORIGIN' => 'https://evil.attacker.test'],
        ));

        self::assertSame(200, $cart->getStatusCode());
        self::assertSame(200, $page->getStatusCode());
    }

    // ------------------------------------------------------------------
    // normalizeOrigin()/originsMatch() — the comparison primitive itself
    // ------------------------------------------------------------------

    public function testNormalizeOriginIsCaseInsensitiveOnSchemeAndHost(): void
    {
        self::assertSame('https://example.test', ShopCsrfGuard::normalizeOrigin('HTTPS://Example.TEST/some/path'));
    }

    public function testNormalizeOriginPreservesAnExplicitNonDefaultPort(): void
    {
        self::assertSame('http://example.test:8080', ShopCsrfGuard::normalizeOrigin('http://example.test:8080/x'));
    }

    public function testNormalizeOriginRejectsTheLiteralNullOrigin(): void
    {
        self::assertNull(ShopCsrfGuard::normalizeOrigin('null'));
    }

    public function testOriginsMatchIsFalseForDifferentPorts(): void
    {
        self::assertFalse(ShopCsrfGuard::originsMatch('http://example.test:8080', 'http://example.test'));
    }

    // ------------------------------------------------------------------
    // mode (c) enforcement: default-host / custom-domain / subdomain parity with media URLs
    // (storefront-rendering spec §11: "single-store/default-host/custom-domain/subdomain
    // origins match media URL generation") — DEV_LINK-gated exactly like
    // TenantResolutionModesTest::testEndToEndCatalogReaderReadLandsInModeCEnforcedTenant.
    // ------------------------------------------------------------------

    public function testEnforcedOriginsMatchAcrossDefaultHostCustomDomainAndSubdomain(): void
    {
        if (!$this->container()->has(CurrentTenantResolver::class)) {
            self::markTestSkipped(
                'Enforcement provider not bound in this test env (default suite strips '
                . 'glueful/tenancy — see config/testing/extensions.php). Re-run with '
                . 'THALLO_TENANCY_DEV_LINK=1 to exercise the real request-resolved origin match '
                . 'shared with media-URL generation.'
            );
        }

        $defaultTenant = $this->seedTenant('csrf-default-host');
        $subdomainTenant = $this->seedTenant('csrf-subdomain');
        $customDomainTenant = $this->seedTenant('csrf-custom-domain');

        $domains = $this->container()->get(TenantDomainAdministration::class);
        // Lower-cased: domain hosts are normalized to lowercase on write (addPreverifiedDomain),
        // and the resolved origin must match that stored form exactly.
        $customHost = strtolower('csrf-shop-' . substr($customDomainTenant, 0, 6) . '.example');
        $domains->addPreverifiedDomain($this->appContext(), $customDomainTenant, $customHost);

        $this->flags()->put('tenancy.default_tenant_uuid', $defaultTenant);
        $this->flags()->put('tenancy.enabled', '1');
        $this->flags()->put('tenancy.enable_step', 'on');

        $runner = $this->container()->get(TenantContextRunner::class);
        $resolver = $this->container()->get(CanonicalPublicOriginResolver::class);
        $guard = $this->container()->get(ShopCsrfGuard::class);

        $expectDefaultHost = (string) config($this->appContext(), 'tenancy.public_origin.default_hosts.0');
        self::assertNotSame('', $expectDefaultHost, 'TENANCY_DEFAULT_HOSTS must be configured for this env.');
        $scheme = (string) config($this->appContext(), 'tenancy.public_origin.scheme', 'https');
        $baseDomain = (string) config($this->appContext(), 'tenancy.public_origin.base_domain');
        self::assertNotSame('', $baseDomain, 'TENANCY_BASE_DOMAIN must be configured for this env.');

        // default-host tenant: origin == the configured default host.
        $runner->runAsTenant($defaultTenant, function () use ($resolver, $guard, $scheme, $expectDefaultHost): void {
            $expected = $scheme . '://' . $expectDefaultHost;
            self::assertSame($expected, $resolver->currentOrigin($this->appContext()));
            self::assertSame(200, $this->probe($guard, $expected)->getStatusCode());
            self::assertSame(403, $this->probe($guard, 'https://not-this-tenant.test')->getStatusCode());
        });

        // custom-domain tenant: origin == the verified custom domain, not the base subdomain.
        $runner->runAsTenant(
            $customDomainTenant,
            function () use ($resolver, $guard, $scheme, $customHost): void {
                $expected = $scheme . '://' . $customHost;
                self::assertSame($expected, $resolver->currentOrigin($this->appContext()));
                self::assertSame(200, $this->probe($guard, $expected)->getStatusCode());
                self::assertSame(403, $this->probe($guard, 'https://not-this-tenant.test')->getStatusCode());
            }
        );

        // no custom domain, not the default tenant -> falls back to slug + base domain.
        $runner->runAsTenant(
            $subdomainTenant,
            function () use ($resolver, $guard, $scheme, $baseDomain, $subdomainTenant): void {
                $tenants = $this->container()->get(TenantAdministration::class);
                $tenant = $tenants->getTenant($this->appContext(), $subdomainTenant);
                self::assertIsArray($tenant);
                $expected = $scheme . '://' . $tenant['slug'] . '.' . $baseDomain;
                self::assertSame($expected, $resolver->currentOrigin($this->appContext()));
                self::assertSame(200, $this->probe($guard, $expected)->getStatusCode());
                self::assertSame(403, $this->probe($guard, 'https://not-this-tenant.test')->getStatusCode());
            }
        );
    }

    private function probe(ShopCsrfGuard $guard, string $origin): Response
    {
        $next = static fn (Request $r): Response => new Response('ok', 200);

        $result = $guard->handle(
            Request::create('/_shop/cart/add', 'POST', [], [], [], ['HTTP_ORIGIN' => $origin]),
            $next,
        );

        self::assertInstanceOf(Response::class, $result);
        /** @var Response $result */

        return $result;
    }

    private function seedTenant(string $slugSuffix): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'csrf-' . $slugSuffix . '-' . substr($uuid, 0, 6),
            'name' => 'CSRF ' . $slugSuffix,
            'status' => 'active',
        ]);
        $this->seededTenants[] = $uuid;

        return $uuid;
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function expectedOrigin(): string
    {
        return $this->container()->get(CanonicalPublicOriginResolver::class)->currentOrigin($this->appContext());
    }

    /** @param array<string,string> $server */
    private function postAdd(array $server, ?string $variant = null, int $quantity = 1): Response
    {
        $params = $variant !== null ? ['variant_uuid' => $variant, 'quantity' => $quantity] : [];

        return $this->handle(Request::create('/_shop/cart/add', 'POST', $params, [], [], $server));
    }

    private function seedVariant(): string
    {
        // type: 'digital' -> StockRepository::ensureRow() creates the row UNTRACKED, so
        // CartService::assertVariantCanSupply() never rejects the add for "exceeding stock" —
        // this suite cares about CSRF/cookie/convergence behavior, not inventory levels.
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => 'csrf-test-' . (++self::$seq),
            'name' => 'Csrf test product',
            'status' => 'active',
            'type' => 'digital',
            'variants' => [[
                'sku' => 'csrf-sku-' . self::$seq,
                'price' => 1000,
                'currency' => 'USD',
                'option_values' => [],
            ]],
        ]);

        return (string) $product['variants'][0]['uuid'];
    }
}
