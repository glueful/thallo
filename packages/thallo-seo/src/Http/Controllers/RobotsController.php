<?php

declare(strict_types=1);

namespace Thallo\Seo\Http\Controllers;

use Thallo\Seo\Sitemap\RobotsBuilder;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

/** Serves robots.txt (plain text). 409 when no absolute origin is configured. */
final class RobotsController
{
    private const ERR = 'SEO origin (thallo.seo.public_url_base) is not configured.';

    public function __construct(private readonly RobotsBuilder $builder)
    {
    }

    /**
     * robots.txt from config groups, with the Sitemap: line appended from public_url_base.
     */
    #[ApiOperation(summary: 'robots.txt', tags: ['Thallo SEO'])]
    #[ApiResponse(
        200,
        envelope: false,
        contentType: 'text/plain',
        body: 'text',
        description: 'robots.txt content.',
    )]
    #[ApiResponse(
        409,
        envelope: false,
        contentType: 'text/plain',
        body: 'text',
        description: 'No public_url_base configured.',
    )]
    public function show(): Response
    {
        if (!$this->builder->hasOrigin()) {
            return new Response(self::ERR, 409, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return new Response($this->builder->render(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
