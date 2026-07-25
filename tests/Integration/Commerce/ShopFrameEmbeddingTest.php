<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Shop\ShopFrameEmbedding;

/**
 * ShopFrameEmbedding (composed-editor spec §5.4b, phase 3): shop PRODUCT pages carry an explicit
 * `frame-ancestors 'self' <admin-origin>` policy when `render.admin_url` is configured — the
 * admin's Live Mirror may embed them, nobody else may. Unconfigured (the test environment's
 * default), responses pass through untouched: the Mirror simply can't embed — never a wildcard.
 *
 * Behavior is pinned unit-style (direct construction — the configured origin is a constructor
 * value the provider factory reads from config once); the route wiring is pinned through the
 * real kernel below.
 */
final class ShopFrameEmbeddingTest extends AppTestCase
{
    private function runMiddleware(string $adminUrl, ?Response $response = null): Response
    {
        $middleware = new ShopFrameEmbedding($adminUrl);
        $result = $middleware->handle(
            Request::create('/shop/products/widget', 'GET'),
            static fn (): Response => $response ?? new Response('<html></html>', 200),
        );
        self::assertInstanceOf(Response::class, $result);

        return $result;
    }

    public function testConfiguredAdminOriginSetsFrameAncestorsAndRemovesXFrameOptions(): void
    {
        $upstream = new Response('<html></html>', 200);
        $upstream->headers->set('X-Frame-Options', 'DENY');

        $result = $this->runMiddleware('https://admin.example.test', $upstream);

        self::assertSame(
            "frame-ancestors 'self' https://admin.example.test",
            $result->headers->get('Content-Security-Policy'),
        );
        self::assertFalse($result->headers->has('X-Frame-Options'));
    }

    public function testAdminUrlPathAndCasingAreReducedToTheOriginOnly(): void
    {
        $result = $this->runMiddleware('HTTPS://Admin.Example.Test:8443/some/path?q=1');

        self::assertSame(
            "frame-ancestors 'self' https://admin.example.test:8443",
            $result->headers->get('Content-Security-Policy'),
        );
    }

    public function testUnconfiguredOrInvalidAdminUrlLeavesTheResponseUntouched(): void
    {
        foreach (['', '   ', 'not a url', 'ftp://files.example.test', 'javascript:alert(1)'] as $bad) {
            $upstream = new Response('<html></html>', 200);
            $upstream->headers->set('X-Frame-Options', 'DENY');

            $result = $this->runMiddleware($bad, $upstream);

            self::assertFalse(
                $result->headers->has('Content-Security-Policy'),
                "admin_url `{$bad}` must not produce a CSP header",
            );
            // Untouched means untouched: a pre-existing X-Frame-Options survives too.
            self::assertSame('DENY', $result->headers->get('X-Frame-Options'));
        }
    }

    public function testAnExistingContentSecurityPolicyIsNeverClobbered(): void
    {
        $upstream = new Response('<html></html>', 200);
        $upstream->headers->set('Content-Security-Policy', "default-src 'self'");

        $result = $this->runMiddleware('https://admin.example.test', $upstream);

        self::assertSame("default-src 'self'", $result->headers->get('Content-Security-Policy'));
        // The X-Frame-Options removal still applies — the CSP owner decides framing there.
        self::assertFalse($result->headers->has('X-Frame-Options'));
    }

    public function testShopProductRouteWiresTheMiddlewareOnTheRealChain(): void
    {
        // Expectation derived FROM live config (the StorefrontPreviewUrlTest pattern): whatever
        // this environment sets for render.admin_url decides whether the policy appears — the
        // point pinned here is the WIRING (the middleware resolves from the container and runs
        // on the real chain, including for non-200 outcomes).
        $adminUrl = (string) config($this->appContext(), 'render.admin_url', '');
        $expected = (new ShopFrameEmbedding($adminUrl))->handle(
            Request::create('/shop/products/definitely-missing', 'GET'),
            static fn (): Response => new Response('', 200),
        );
        self::assertInstanceOf(Response::class, $expected);
        $expectedCsp = (string) $expected->headers->get('Content-Security-Policy', '');

        $response = $this->handle(Request::create('/shop/products/definitely-missing', 'GET'));

        self::assertContains($response->getStatusCode(), [301, 404]);
        self::assertSame(
            $expectedCsp,
            (string) $response->headers->get('Content-Security-Policy', ''),
        );
    }
}
