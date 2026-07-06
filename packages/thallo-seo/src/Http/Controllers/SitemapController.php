<?php

declare(strict_types=1);

namespace Thallo\Seo\Http\Controllers;

use Thallo\Seo\Sitemap\SitemapBuilder;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves sitemap.xml (adaptive urlset/index) and page files. Raw XML — not the JSON
 * envelope. 409 (plain text) when no absolute origin is configured.
 */
final class SitemapController
{
    private const ERR = 'SEO origin (thallo.seo.public_url_base) is not configured.';

    public function __construct(private readonly SitemapBuilder $builder)
    {
    }

    /**
     * The well-known sitemap entry. Adaptive: a `<urlset>` at or below 50 000 URLs, a
     * `<sitemapindex>` listing page files above it.
     */
    #[ApiOperation(summary: 'sitemap.xml (adaptive urlset/index)', tags: ['Lemma SEO'])]
    #[ApiResponse(
        200,
        envelope: false,
        contentType: 'application/xml',
        body: 'text',
        description: 'Sitemap XML.',
    )]
    #[ApiResponse(
        409,
        envelope: false,
        contentType: 'text/plain',
        body: 'text',
        description: 'No public_url_base configured.',
    )]
    public function index(Request $request): Response
    {
        if (!$this->builder->hasOrigin()) {
            return $this->conflict();
        }
        return $this->xml($this->builder->rootXml());
    }

    /**
     * One sitemap page file (`1`-based), up to 50 000 URLs. Out-of-range pages are 404 —
     * without the bound, every distinct {n} would mint a no-TTL cache entry plus a
     * deep-OFFSET enumeration, an anonymous cache-fill vector.
     */
    #[ApiOperation(summary: 'sitemap page file', tags: ['Lemma SEO'])]
    #[ApiResponse(
        200,
        envelope: false,
        contentType: 'application/xml',
        body: 'text',
        description: 'Sitemap page XML.',
    )]
    #[ApiResponse(
        404,
        envelope: false,
        contentType: 'text/plain',
        body: 'text',
        description: 'Page number out of range.',
    )]
    #[ApiResponse(
        409,
        envelope: false,
        contentType: 'text/plain',
        body: 'text',
        description: 'No public_url_base configured.',
    )]
    public function page(Request $request, int $n): Response
    {
        if (!$this->builder->hasOrigin()) {
            return $this->conflict();
        }
        if ($n < 1 || $n > $this->builder->pageCount()) {
            return new Response('Not Found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return $this->xml($this->builder->pageXml($n));
    }

    private function xml(string $body): Response
    {
        return new Response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function conflict(): Response
    {
        return new Response(self::ERR, 409, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
