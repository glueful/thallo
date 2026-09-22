<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Http\Middleware\SiteSecurityHeaders;

/**
 * The rendered site sent no security headers of its own: thallo.dev had them only because its web
 * server adds them. The page routes now carry a baseline — never replacing a header something
 * nearer the page already set, and never forbidding the framing the Design view needs.
 */
final class SiteSecurityHeadersTest extends AppTestCase
{
    public function testARenderedPageCarriesTheBaselineThroughTheRealKernel(): void
    {
        $response = $this->handle(Request::create('/no-such-page-' . bin2hex(random_bytes(3)), 'GET'));

        self::assertSame(404, $response->getStatusCode(), 'a rendered 404 is a page too');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertFalse($response->headers->has('Strict-Transport-Security'), 'plain HTTP gets no HSTS');
    }

    public function testHttpsGetsHstsAndTheDesignViewsCanvasMayBeFramed(): void
    {
        $https = $this->through(Request::create('https://example.com/page', 'GET'));
        self::assertSame('max-age=31536000', $https->headers->get('Strict-Transport-Security'));

        // The Design view frames the site; with the admin on another host, SAMEORIGIN would break
        // every page the author walks to inside the stage.
        $canvas = Request::create('https://example.com/page', 'GET', [], ['thallo_preview_canvas' => '1']);
        $framed = $this->through($canvas);
        self::assertFalse($framed->headers->has('X-Frame-Options'));
        self::assertSame('nosniff', $framed->headers->get('X-Content-Type-Options'));

        // The canvas's first load is the preview URL, before its cookie exists; the appearance
        // preview frames the same route. A preview exists to be framed by the admin.
        $preview = $this->through(Request::create('https://example.com/_preview/tok123?canvas=1', 'GET'));
        self::assertFalse($preview->headers->has('X-Frame-Options'));
    }

    public function testWhatIsAlreadySetIsKept(): void
    {
        $upstream = new Response('<html></html>');
        $upstream->headers->set('Referrer-Policy', 'no-referrer');
        $upstream->headers->set('Content-Security-Policy', "frame-ancestors 'self' https://admin.example.com");
        $response = $this->through(Request::create('https://example.com/p', 'GET'), $upstream);

        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertFalse(
            $response->headers->has('X-Frame-Options'),
            'a frame-ancestors policy decides framing; X-Frame-Options would contradict it',
        );

        $reportOnly = new Response('<html></html>');
        $reportOnly->headers->set('Content-Security-Policy-Report-Only', "frame-ancestors 'self'");
        $still = $this->through(Request::create('https://example.com/p', 'GET'), $reportOnly);
        self::assertSame('SAMEORIGIN', $still->headers->get('X-Frame-Options'), 'report-only enforces nothing');
    }

    private function through(Request $request, ?Response $upstream = null): Response
    {
        $result = (new SiteSecurityHeaders())->handle(
            $request,
            static fn (): Response => $upstream ?? new Response('<html></html>'),
        );
        self::assertInstanceOf(Response::class, $result);
        return $result;
    }
}
