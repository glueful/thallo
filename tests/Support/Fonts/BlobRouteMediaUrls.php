<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Fonts;

use Thallo\Contracts\Delivery\MediaUrlResolver;

/** Every blob served at the blob route, as a public file is. */
final class BlobRouteMediaUrls implements MediaUrlResolver
{
    public function url(string $uuid): ?string
    {
        return "/api/v1/blobs/{$uuid}";
    }
}
