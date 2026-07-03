<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Resolves an uploaded-media blob uuid to a publicly retrievable URL for RENDERED
 * pages (starter-library spec §3). Null when the blob cannot be served anonymously —
 * private, deleted, missing, uploads disabled, or the global uploads access mode
 * requires auth. Rendered pages are cached, so expiring signed URLs are NEVER
 * emitted; templates skip the element on null.
 */
interface MediaUrlResolver
{
    public function url(string $uuid): ?string;
}
