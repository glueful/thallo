<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Cheap signature + expiry verification for preview tokens (no DB). Null on ANY
 * failure — malformed, bad signature, expired. Session detection middleware, the
 * render controller, and the preview-asset route share ONE verification per request
 * through the returned VO; route semantics never enter the cache layer.
 */
interface PreviewSessionVerifier
{
    public function verify(string $token): ?PreviewSession;
}
