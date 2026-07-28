<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Thallo\Commerce\Shop\ShopAssetMap;
use Thallo\Commerce\Shop\ShopUrlGenerator;
use Thallo\Commerce\Shop\ShopWishlistSurface;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Delivery\StorefrontWishlistResolver;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\TwigFactory;

/**
 * Storefront-v1 Task 4: {@see ShopWishlistSurface} — the pack implementation of the
 * {@see StorefrontWishlistResolver} seam.
 *
 * Pins (spec §5):
 *  - the storage scope is the unpadded base64url of SHA-256 over
 *    `"wishlist-v1\0" + normalizedTenant + "\0" + prefix` — opaque (never the raw tenant
 *    uuid), deterministic per tenant+prefix, `''` tenant normalized to the literal
 *    `shared` sentinel;
 *  - the tenant is resolved LIVE inside every storageScope() call
 *    (ThalloCommerceTenantResolution's contract forbids caching a resolved tenant, and
 *    this surface is a shared service) — proven by the A→B→A same-instance test;
 *  - wishlistUrl() delegates to the generator-owned `/{prefix}/wishlist` composition;
 *  - capability off ⇒ both methods null, re-checked per call;
 *  - the Twig helpers `shop_wishlist_scope()`/`shop_wishlist_url()` are null-safe
 *    pass-throughs (unbound seam ⇒ null, never an exception).
 */
final class ShopWishlistSurfaceTest extends AppTestCase
{
    /** Unpadded base64url of a SHA-256 digest: exactly 43 chars of the base64url alphabet. */
    private const SCOPE_SHAPE = '/^[A-Za-z0-9_-]{43}$/';

    private function urls(string $prefix = 'shop'): ShopUrlGenerator
    {
        // The asset map scans a directory at construction; a missing dir is simply empty,
        // and nothing in this test touches assets().
        return new ShopUrlGenerator($prefix, new ShopAssetMap(__DIR__ . '/no-such-assets'));
    }

    /** @return CommerceTenantResolution&object{current:string} */
    private function fakeTenants(string $tenant): CommerceTenantResolution
    {
        return new class ($tenant) implements CommerceTenantResolution {
            public function __construct(public string $current)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->current;
            }
        };
    }

    private function surface(
        CommerceTenantResolution $tenants,
        string $prefix = 'shop',
        bool $enabled = true,
    ): ShopWishlistSurface {
        return new ShopWishlistSurface(
            $this->appContext(),
            $this->urls($prefix),
            $tenants,
            static fn (): bool => $enabled,
        );
    }

    // ==================================================================
    // scope derivation: deterministic, tenant- and prefix-sensitive
    // ==================================================================

    public function testScopeIsDeterministicPerTenantAndPrefix(): void
    {
        $scopeA = $this->surface($this->fakeTenants('tenantA00001'))->storageScope();
        $scopeAAgain = $this->surface($this->fakeTenants('tenantA00001'))->storageScope();
        $scopeB = $this->surface($this->fakeTenants('tenantB00001'))->storageScope();
        $scopeAOtherPrefix = $this->surface($this->fakeTenants('tenantA00001'), 'boutique')->storageScope();

        self::assertNotNull($scopeA);
        self::assertSame($scopeA, $scopeAAgain, 'same tenant + prefix ⇒ same scope (deterministic)');
        self::assertNotSame($scopeA, $scopeB, 'a different tenant must land in a different scope');
        self::assertNotSame($scopeA, $scopeAOtherPrefix, 'a different prefix must land in a different scope');
    }

    public function testScopeResolvesTheTenantLiveOnEveryCallNeverCapturedAtConstruction(): void
    {
        // ONE instance, tenant flipping A → B → A between calls — exactly what a shared
        // service sees across requests under tenancy enforcement. The surface must reflect
        // each call's CURRENT tenant: no construction-time capture, no first-call latch.
        $tenants = $this->fakeTenants('tenantA00001');
        $surface = $this->surface($tenants);

        $first = $surface->storageScope();

        $tenants->current = 'tenantB00001';
        $second = $surface->storageScope();

        $tenants->current = 'tenantA00001';
        $third = $surface->storageScope();

        self::assertNotNull($first);
        self::assertNotSame($first, $second, 'the B call must not reuse a captured A tenant');
        self::assertSame($first, $third, 'returning to tenant A must return to the A scope');
        self::assertSame(
            $this->surface($this->fakeTenants('tenantB00001'))->storageScope(),
            $second,
            'the mid-flight B scope must equal a fresh B surface (no first-call capture either)',
        );
    }

    public function testScopeIsOpaqueUnpaddedBase64UrlOfSha256(): void
    {
        $tenant = 'tenantOpaque01';
        $scope = $this->surface($this->fakeTenants($tenant))->storageScope();

        self::assertNotNull($scope);
        self::assertMatchesRegularExpression(self::SCOPE_SHAPE, $scope);
        self::assertSame(43, strlen($scope), 'SHA-256 base64url without padding is exactly 43 chars');
        self::assertStringNotContainsString(
            $tenant,
            $scope,
            'the scope is opaque — it must never leak the raw tenant uuid',
        );
    }

    public function testEmptyTenantNormalizesToTheSharedSentinel(): void
    {
        $empty = $this->surface($this->fakeTenants(''))->storageScope();
        $emptyAgain = $this->surface($this->fakeTenants(''))->storageScope();

        self::assertNotNull($empty);
        self::assertSame($empty, $emptyAgain, 'two empty-tenant surfaces with the same prefix agree');
        self::assertSame(
            $this->surface($this->fakeTenants('shared'))->storageScope(),
            $empty,
            "'' normalizes to the literal 'shared' sentinel input (spec pin)",
        );
    }

    // ==================================================================
    // wishlistUrl(): generator-owned composition
    // ==================================================================

    public function testWishlistUrlComposesFromTheGeneratorOwnedPrefix(): void
    {
        self::assertSame('/shop/wishlist', $this->urls()->wishlist());
        self::assertSame(
            $this->urls()->wishlist(),
            $this->surface($this->fakeTenants(''))->wishlistUrl(),
            'the surface delegates to ShopUrlGenerator::wishlist() — one URL authority',
        );

        // A custom prefix is honored through the generator's own normalization…
        self::assertSame('/boutique/wishlist', $this->urls('/boutique/')->wishlist());
        self::assertSame(
            '/boutique/wishlist',
            $this->surface($this->fakeTenants(''), '/boutique/')->wishlistUrl(),
        );

        // …and composed WITHOUT encoding, exactly like shopIndex() (the prefix is the
        // trusted, boot-normalized value — rawurlencode would corrupt e.g. '&').
        self::assertSame('/shop&co/wishlist', $this->urls('shop&co')->wishlist());
    }

    // ==================================================================
    // capability gate: null while inactive, re-checked per call
    // ==================================================================

    public function testCapabilityOffMeansBothMethodsReturnNull(): void
    {
        $off = $this->surface($this->fakeTenants('tenantA00001'), enabled: false);
        self::assertNull($off->storageScope());
        self::assertNull($off->wishlistUrl());
    }

    public function testCapabilityIsReCheckedOnEveryCallNotLatched(): void
    {
        $gate = new \stdClass();
        $gate->enabled = true;
        $surface = new ShopWishlistSurface(
            $this->appContext(),
            $this->urls(),
            $this->fakeTenants('tenantA00001'),
            static fn (): bool => $gate->enabled,
        );

        self::assertNotNull($surface->storageScope());
        self::assertNotNull($surface->wishlistUrl());

        $gate->enabled = false;
        self::assertNull($surface->storageScope(), 'a mid-instance capability flip must be honored');
        self::assertNull($surface->wishlistUrl());

        $gate->enabled = true;
        self::assertNotNull($surface->storageScope(), 'and re-enabling is honored on the next call');
    }

    // ==================================================================
    // container: the contract resolves to this surface, spec-conform
    // ==================================================================

    public function testContainerBindsTheContractToTheSurface(): void
    {
        $resolver = $this->container()->get(StorefrontWishlistResolver::class);

        self::assertInstanceOf(ShopWishlistSurface::class, $resolver);
        // Primary boot: capability enabled, prefix 'shop', clean flags ⇒ mode (a) sentinel.
        self::assertSame('/shop/wishlist', $resolver->wishlistUrl());
        $scope = $resolver->storageScope();
        self::assertNotNull($scope);
        self::assertMatchesRegularExpression(self::SCOPE_SHAPE, $scope);
        self::assertSame(
            $this->surface($this->fakeTenants(''))->storageScope(),
            $scope,
            'the container surface derives the same sentinel-tenant scope as the spec formula',
        );
    }

    // ==================================================================
    // Twig helpers: null-safe pass-throughs
    // ==================================================================

    public function testTwigHelpersReturnSurfaceValuesWhenBoundAndNullWhenUnbound(): void
    {
        $targets = $this->container()->get(EntryTargetResolver::class);

        $fixed = new class implements StorefrontWishlistResolver {
            public function storageScope(): ?string
            {
                return 'scope-fixture';
            }

            public function wishlistUrl(): ?string
            {
                return '/shop/wishlist';
            }
        };
        $bound = new RenderContextExtension(null, $targets, wishlist: $fixed);
        self::assertSame('scope-fixture', $bound->shopWishlistScope());
        self::assertSame('/shop/wishlist', $bound->shopWishlistUrl());

        // Unbound seam (commerce absent/inactive): null, never an exception.
        $unbound = new RenderContextExtension(null, $targets);
        self::assertNull($unbound->shopWishlistScope());
        self::assertNull($unbound->shopWishlistUrl());

        // End-to-end through the CONTAINER environment: the provider soft-bind wired the
        // real surface, and the function names are registered under the pinned names.
        $rendered = $this->container()->get(TwigFactory::class)->environment()
            ->createTemplate('{{ shop_wishlist_url() }}|{{ shop_wishlist_scope() }}')
            ->render([]);
        [$url, $scope] = explode('|', $rendered);
        self::assertSame('/shop/wishlist', $url);
        self::assertMatchesRegularExpression(self::SCOPE_SHAPE, $scope);
    }
}
