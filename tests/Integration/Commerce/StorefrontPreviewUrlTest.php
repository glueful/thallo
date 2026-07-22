<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Shop\StorefrontPreviewUrlBuilder;

/**
 * Task 5 (Thallo admin-commerce-area plan, slice 3): {@see StorefrontPreviewUrlBuilder} — the
 * ONLY place absolute storefront URLs are assembled. Pure composition of the Task-6
 * {@see \Thallo\Contracts\Delivery\CanonicalPublicOriginResolver} (the trusted, request-Host-
 * independent origin authority) and {@see \Thallo\Commerce\Shop\ShopUrlGenerator} (the
 * `/`-prefixed relative shop paths).
 *
 * Runs single-store (enforcement off — the default test boot; {@see App\Tests\Support\AppTestCase}
 * / config/testing/extensions.php strips the enforcement provider), so `currentOrigin()` always
 * takes the `app.urls.base` fallback. The expected origin is derived FROM that config value
 * directly (never hardcoded) so this suite can never silently drift from the resolver it composes.
 */
final class StorefrontPreviewUrlTest extends AppTestCase
{
    // ------------------------------------------------------------------
    // productUrl(): origin + path, hostile-Host independence, slug encoding
    // ------------------------------------------------------------------

    public function testProductUrlIsTheConfiguredOriginPlusTheShopProductPath(): void
    {
        $builder = $this->container()->get(StorefrontPreviewUrlBuilder::class);

        $actual = $builder->productUrl($this->appContext(), 'widget');

        self::assertSame($this->expectedOrigin() . '/shop/products/widget', $actual);
    }

    public function testProductUrlIsByteIdenticalRegardlessOfAHostileRequestHostHeader(): void
    {
        $builder = $this->container()->get(StorefrontPreviewUrlBuilder::class);

        // Drive two real kernel requests to the same route, differing ONLY in the Host header —
        // one plausible, one attacker-controlled — then compare the builder's output taken right
        // after each dispatch. Nothing in the builder's resolution chain ever reads a Request, so
        // the two must be byte-identical.
        $this->handle(Request::create('/shop', 'GET', [], [], [], ['HTTP_HOST' => 'localhost']));
        $afterPlausibleHost = $builder->productUrl($this->appContext(), 'widget');

        $this->handle(Request::create('/shop', 'GET', [], [], [], ['HTTP_HOST' => 'evil.test']));
        $afterHostileHost = $builder->productUrl($this->appContext(), 'widget');

        self::assertSame($afterPlausibleHost, $afterHostileHost);
        self::assertSame($this->expectedOrigin() . '/shop/products/widget', $afterHostileHost);
    }

    public function testProductUrlRawUrlEncodesTheSlug(): void
    {
        $builder = $this->container()->get(StorefrontPreviewUrlBuilder::class);

        $actual = $builder->productUrl($this->appContext(), 'a slug with spaces');

        self::assertSame($this->expectedOrigin() . '/shop/products/a%20slug%20with%20spaces', $actual);
    }

    // ------------------------------------------------------------------
    // shopIndexUrl(): shape
    // ------------------------------------------------------------------

    public function testShopIndexUrlStartsWithSchemeAndEndsWithShop(): void
    {
        $builder = $this->container()->get(StorefrontPreviewUrlBuilder::class);

        $actual = $builder->shopIndexUrl($this->appContext());

        self::assertMatchesRegularExpression('#^[a-z][a-z0-9+.-]*://#', $actual);
        self::assertStringEndsWith('/shop', $actual);
        self::assertSame($this->expectedOrigin() . '/shop', $actual);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Independently re-derives the expected single-store origin from `app.urls.base` —
     * deliberately NOT by calling the resolver/builder under test (that would be tautological).
     * Mirrors {@see \App\Content\Delivery\ThalloCanonicalPublicOriginResolver::normalizedBase()}.
     */
    private function expectedOrigin(): string
    {
        $base = (string) config($this->appContext(), 'app.urls.base', 'http://localhost');
        $parts = parse_url($base);
        self::assertIsArray($parts, 'app.urls.base must be an absolute URL');
        self::assertArrayHasKey('scheme', $parts);
        self::assertArrayHasKey('host', $parts);

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
